<?php
/**
 * Endpoints REST que consume el bridge. Contrato preservado: namespace
 * caag-bot/v1, rutas /incoming, /status, /update-bridge-url, autenticadas
 * con el header X-Caag-Token (token compartido con el bridge).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_REST {

	private $store;
	private $engine;

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Engine $engine ) {
		$this->store  = $store;
		$this->engine = $engine;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'caag-bot/v1', '/incoming', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_incoming' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
		register_rest_route( 'caag-bot/v1', '/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
		register_rest_route( 'caag-bot/v1', '/update-bridge-url', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_update_bridge_url' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
	}

	public function check_token( WP_REST_Request $request ) {
		$provided = (string) $request->get_header( 'x-caag-token' );
		$stored   = $this->store->shared_token();
		if ( $stored === '' || $provided === '' ) {
			return new WP_Error( 'cead_acad_wa_unauthorized', 'Token no configurado.', [ 'status' => 401 ] );
		}
		if ( ! hash_equals( $stored, $provided ) ) {
			return new WP_Error( 'cead_acad_wa_unauthorized', 'Token inválido.', [ 'status' => 401 ] );
		}
		return true;
	}

	public function handle_incoming( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$from = (string) ( $body['from'] ?? '' );

		/*
		 * Solo se atienden chats individuales.
		 *
		 * WhatsApp entrega por el mismo canal cosas que NO son un mensaje para
		 * el bot, y se distinguen por el dominio del JID:
		 *
		 *   5959xxxx@s.whatsapp.net  chat individual   → sí
		 *   5959xxxx@c.us            ídem (whatsapp-web.js)
		 *   status@broadcast         un ESTADO publicado
		 *   xxxxx@g.us               un grupo
		 *   xxxxx@newsletter         un canal
		 *
		 * Antes se hacía explode('@') y se tomaban los dígitos sin mirar el
		 * dominio. Con eso, publicar un estado terminaba con CEADI saludando de
		 * la nada: el bridge reporta al autor del estado y el motor lo tomaba
		 * como si esa persona le hubiera escrito. Lo mismo habría pasado en un
		 * grupo donde estuviera el número del bot.
		 */
		$dominios_ok = [ 's.whatsapp.net', 'c.us' ];
		if ( false !== strpos( $from, '@' ) ) {
			$dominio = strtolower( trim( substr( $from, strpos( $from, '@' ) + 1 ) ) );
			if ( ! in_array( $dominio, $dominios_ok, true ) ) {
				return new WP_REST_Response( [ 'ok' => true, 'ignored' => $dominio ], 200 );
			}
		}
		// Algunos bridges marcan el estado aparte en vez de en el JID.
		if ( ! empty( $body['isStatus'] ) || ! empty( $body['broadcast'] ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'ignored' => 'status' ], 200 );
		}

		$phone = preg_replace( '/[^0-9]/', '', explode( '@', $from )[0] );
		$media = ( isset( $body['media'] ) && is_array( $body['media'] ) ) ? $body['media'] : null;
		$message = [
			'from'     => $phone,
			'body'     => (string) ( $body['body'] ?? '' ),
			'pushName' => (string) ( $body['pushName'] ?? '' ),
			'media'    => $media,
		];
		// Procesar si hay texto o imagen.
		if ( $phone === '' || ( trim( $message['body'] ) === '' && ! $media ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'skipped' => true ], 200 );
		}
		// Mensajes viejos: al reconectarse, el bridge puede reentregar lo que
		// quedó sin confirmar hace horas. Procesarlo es peor que perderlo — el
		// bot contesta de la nada a algo que la persona escribió a la tarde, y
		// como la sesión ya venció lo trata como charla nueva y saluda solo.
		// Solo aplica si el bridge manda la marca de tiempo; si no, queda la
		// dedup por ID de abajo como red.
		$ts_raw = $body['timestamp'] ?? ( $body['t'] ?? ( $body['messageTimestamp'] ?? null ) );
		if ( is_numeric( $ts_raw ) ) {
			$ts = (float) $ts_raw;
			if ( $ts > 1e11 ) { $ts /= 1000; } // vino en milisegundos
			$edad = time() - (int) $ts;
			$max  = (int) apply_filters( 'cead_acad_wa_max_message_age', 10 * MINUTE_IN_SECONDS );
			if ( $edad > $max ) {
				return new WP_REST_Response( [ 'ok' => true, 'stale' => true, 'age' => $edad ], 200 );
			}
		}

		// Dedup defensiva por ID de mensaje: si el bridge reenvía el mismo mensaje
		// (reintento de red o reentrega al reconectar), no lo procesamos dos
		// veces. La ventana era de 2 minutos, que cubría el reintento inmediato
		// pero no una reentrega horas después: el candado ya había vencido y el
		// mensaje entraba como nuevo.
		$msg_id = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		if ( $msg_id !== '' ) {
			$lock = 'cead_acad_wa_seen_' . md5( $msg_id );
			if ( get_transient( $lock ) ) {
				return new WP_REST_Response( [ 'ok' => true, 'duplicate' => true ], 200 );
			}
			set_transient( $lock, 1, DAY_IN_SECONDS );
		}
		// Dedup por CONTENIDO (red de seguridad para bridges que no mandan 'id'):
		// si el mismo número manda el mismo texto dentro de una ventana corta, es
		// una reentrega de WhatsApp/Baileys → se ignora. Ventana ajustable por
		// filtro `cead_acad_wa_dedup_seconds` (en segundos, admite decimales).
		$cooldown = (float) apply_filters( 'cead_acad_wa_dedup_seconds', 2.0 );
		if ( $cooldown > 0 ) {
			$sig  = 'cead_acad_wa_dup_' . md5( $phone . '|' . trim( $message['body'] ) . '|' . ( $media ? '1' : '0' ) );
			$last = get_transient( $sig );
			$now  = microtime( true );
			if ( false !== $last && ( $now - (float) $last ) < $cooldown ) {
				return new WP_REST_Response( [ 'ok' => true, 'duplicate' => true ], 200 );
			}
			set_transient( $sig, $now, 30 );
		}
		// Rate limit por teléfono: corta loops/abuso sin afectar uso normal.
		// Default 15 mensajes por minuto; ajustable por filtros.
		if ( ! $this->within_rate_limit( $phone ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'limited' => true ], 200 );
		}
		try {
			$this->engine->process_message( $message );
		} catch ( \Throwable $e ) {
			error_log( '[CeadAcadWA] engine error: ' . $e->getMessage() );
			return new WP_REST_Response( [ 'ok' => false ], 200 );
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Rate limit por número de teléfono (ventana deslizante simple con
	 * transients, mismo patrón que cead_acad_rate_limit() pero keyed por
	 * teléfono: todos los mensajes llegan desde la IP del bridge).
	 *
	 * Filtros: `cead_acad_wa_rate_max` (default 15) y
	 * `cead_acad_wa_rate_window` (segundos, default 60). Max <= 0 desactiva.
	 */
	protected function within_rate_limit( $phone ) {
		$max    = (int) apply_filters( 'cead_acad_wa_rate_max', 15 );
		$window = max( 1, (int) apply_filters( 'cead_acad_wa_rate_window', 60 ) );
		if ( $max <= 0 ) {
			return true;
		}
		$key  = 'cead_acad_wa_rl_' . md5( $phone );
		$now  = time();
		$data = get_transient( $key );

		// Ventana FIJA: guardamos { count, reset }. El TTL del transient no se
		// renueva en cada mensaje (eso convertía el contador en acumulativo y
		// podía bloquear a un usuario legítimo de tráfico sostenido). Cuando la
		// ventana vence, arranca una nueva.
		if ( ! is_array( $data ) || empty( $data['reset'] ) || $now >= (int) $data['reset'] ) {
			set_transient( $key, [ 'count' => 1, 'reset' => $now + $window ], $window );
			return true;
		}

		if ( (int) $data['count'] >= $max ) {
			// Log una sola vez por ventana para no inundar wa_logs.
			$flag = $key . '_logged';
			if ( ! get_transient( $flag ) ) {
				set_transient( $flag, 1, max( 1, (int) $data['reset'] - $now ) );
				$this->store->log( $phone, 'in', '', 'rate_limited' );
				error_log( '[CeadAcadWA] rate limit excedido para ' . $phone );
			}
			return false;
		}

		// Incrementa SIN extender el TTL: la ventana mantiene su reset original.
		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, max( 1, (int) $data['reset'] - $now ) );
		return true;
	}

	public function handle_status( WP_REST_Request $request ) {
		$s = $this->store->session();
		return new WP_REST_Response( [
			'ok'             => true,
			'connection'     => $s->connection_status ?? 'unknown',
			'linked_number'  => $s->linked_number ?? null,
			'last_heartbeat' => $s->last_heartbeat ?? null,
		], 200 );
	}

	public function handle_update_bridge_url( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$url  = esc_url_raw( (string) ( $body['bridge_url'] ?? '' ) );
		if ( $url === '' ) {
			return new WP_Error( 'cead_acad_wa_bad_request', 'Falta bridge_url.', [ 'status' => 400 ] );
		}
		$this->store->update_session( [ 'bridge_url' => $url ] );
		return new WP_REST_Response( [ 'ok' => true, 'bridge_url' => $url ], 200 );
	}
}
