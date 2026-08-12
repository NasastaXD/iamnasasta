<?php
/**
 * Instagram → borrador de nota.
 *
 * Revisa cada tanto la cuenta del colegio, y cuando aparece una publicación
 * nueva le pide a CEADI que redacte una nota a partir del pie de foto y se la
 * manda por WhatsApp al número de dirección, listo para publicar.
 *
 * ---------------------------------------------------------------------------
 * ADVERTENCIA HONESTA SOBRE DE DÓNDE SALEN LOS DATOS
 *
 * Instagram no deja leer una cuenta sin autenticación. No hay endpoint público
 * estable: los que circulaban se cerraron, y raspar el HTML da bloqueos por IP
 * y deja de andar sin aviso. Cualquiera que prometa lo contrario está
 * describiendo algo que va a romperse.
 *
 * Por eso la lectura está separada del resto en `traer_publicaciones()`, con
 * tres orígenes posibles:
 *
 *   1. `graph`     — API oficial de Instagram. Es la única confiable. Necesita
 *                    acceso a la cuenta del colegio, que todavía no hay.
 *   2. `proveedor` — un servicio externo que devuelva JSON (hay varios pagos
 *                    que hacen justo esto). Se configura URL y clave.
 *   3. `off`       — apagado (por defecto).
 *
 * Todo lo demás —detectar que algo es nuevo, redactar con CEADI, mandarlo al
 * director— ya funciona y no cambia según el origen. Cuando consigan el acceso
 * a la cuenta, se completa el token de `graph` en Ajustes y listo: no hay que
 * tocar código.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Instagram {

	const EVENTO      = 'cead_acad_wa_instagram';
	/** Publicaciones ya avisadas, para no repetir. */
	const OPT_VISTAS  = 'cead_acad_wa_ig_vistas';
	const OPT_ERROR   = 'cead_acad_wa_ig_error';
	/** Cuántos ids recordar. Alcanza de sobra y no deja crecer la opción sin fin. */
	const RECORDAR    = 60;

	public function boot() {
		add_action( self::EVENTO, [ $this, 'revisar' ] );
		add_action( 'init', [ $this, 'programar' ] );
	}

	public function programar() {
		if ( self::modo() === 'off' ) {
			$t = wp_next_scheduled( self::EVENTO );
			if ( $t ) { wp_unschedule_event( $t, self::EVENTO ); }
			return;
		}
		if ( ! wp_next_scheduled( self::EVENTO ) ) {
			// Cada media hora: una cuenta de colegio no publica más seguido que
			// eso, y consultar de más solo gasta cuota del proveedor.
			wp_schedule_event( time() + 300, 'cead_acad_wa_30min', self::EVENTO );
		}
	}

	/* ------------------------------------------------------------ config */

	public static function modo() {
		$m = (string) get_option( 'cead_acad_wa_ig_modo', 'off' );
		return in_array( $m, [ 'graph', 'proveedor' ], true ) ? $m : 'off';
	}
	public static function usuario() {
		return (string) get_option( 'cead_acad_wa_ig_usuario', 'cead_felix_de_guarania' );
	}
	public static function ultimo_error() {
		return (string) get_option( self::OPT_ERROR, '' );
	}

	protected static function anotar_error( $msg ) {
		update_option( self::OPT_ERROR, $msg ? current_time( 'mysql' ) . ' — ' . $msg : '', false );
		if ( $msg ) { error_log( '[CeadAcadWA][IG] ' . $msg ); }
	}

	/* ------------------------------------------------------------- ciclo */

	/** Corre en el cron: mira si hay algo nuevo y avisa. */
	public function revisar() {
		if ( self::modo() === 'off' ) { return; }

		$posts = self::traer_publicaciones();
		if ( is_wp_error( $posts ) ) {
			self::anotar_error( $posts->get_error_message() );
			return;
		}
		self::anotar_error( '' );
		if ( ! $posts ) { return; }

		$estado = self::separar_nuevas( $posts, (array) get_option( self::OPT_VISTAS, [] ) );
		update_option( self::OPT_VISTAS, $estado['vistas'], false );

		if ( $estado['primera'] ) {
			error_log( '[CeadAcadWA][IG] primera corrida: ' . count( $estado['vistas'] ) . ' publicaciones tomadas como ya vistas.' );
			return;
		}

		foreach ( $estado['nuevas'] as $p ) {
			$this->avisar( $p );
		}
	}

	/**
	 * Decide qué es nuevo y qué ya se avisó.
	 *
	 * Lo importante acá es la PRIMERA corrida: cuando todavía no hay nada
	 * anotado, no se avisa nada — solo se toma nota de lo que ya existe. Sin
	 * eso, activar la función le dispararía al director doce borradores de
	 * publicaciones viejas de un saque, que es la peor forma posible de
	 * estrenar la herramienta.
	 *
	 * Las nuevas se devuelven de más vieja a más nueva: Instagram las entrega
	 * al revés, y llegando así se leen en el orden en que pasaron.
	 *
	 * @return array{nuevas:array,vistas:array,primera:bool}
	 */
	public static function separar_nuevas( array $posts, array $vistas ) {
		$primera = empty( $vistas );
		$nuevas  = [];

		foreach ( $posts as $p ) {
			$id = (string) ( $p['id'] ?? '' );
			if ( '' === $id || in_array( $id, $vistas, true ) ) { continue; }
			$vistas[] = $id;
			if ( ! $primera ) { $nuevas[] = $p; }
		}

		if ( count( $vistas ) > self::RECORDAR ) {
			$vistas = array_slice( $vistas, -self::RECORDAR );
		}

		return [
			'nuevas'  => array_reverse( $nuevas ),
			'vistas'  => array_values( $vistas ),
			'primera' => $primera,
		];
	}

	/**
	 * Le pide a CEADI un borrador y se lo manda al director.
	 *
	 * El borrador NO se publica: se manda como texto para que lo lea, lo
	 * corrija si hace falta y lo publique él. Es el mismo criterio que el resto
	 * del bot — la IA propone, la persona decide.
	 */
	protected function avisar( $post ) {
		$telefono = (string) get_option( 'cead_acad_wa_director_phone', '' );
		if ( '' === trim( $telefono ) ) {
			self::anotar_error( 'Hay publicaciones nuevas pero no está configurado el número de dirección.' );
			return;
		}

		$pie   = trim( (string) ( $post['caption'] ?? '' ) );
		$enlace = (string) ( $post['url'] ?? '' );
		$borrador = self::redactar( $pie, $enlace );

		$msg  = "📸 *Publicación nueva en Instagram*\n";
		if ( $enlace ) { $msg .= $enlace . "\n"; }
		$msg .= "\n" . $borrador;
		$msg .= "\n\n_Borrador hecho por mí a partir del pie de foto. Si te sirve, decime «publicá esto» y lo subimos al sitio; si no, corregilo y mandámelo._";

		$store  = new Cead_Acad_WA_Store();
		$bridge = new Cead_Acad_WA_Bridge_Client( $store );
		$bridge->send_message( $telefono, $msg );
	}

	/**
	 * Redacta la nota con CEADI. Si la IA está apagada o falla, se manda el pie
	 * de foto tal cual: mejor avisar sin borrador que no avisar.
	 */
	protected static function redactar( $pie, $enlace ) {
		if ( '' === $pie ) {
			return 'La publicación no trae texto. Contame de qué se trata y te armo la nota.';
		}
		if ( ! class_exists( 'Cead_Acad_WA_AI' ) || ! Cead_Acad_WA_AI::enabled() ) {
			return "Pie de foto:\n" . $pie;
		}

		$prompt = "Se publicó esto en el Instagram del colegio. Redactá una nota corta para el sitio web "
			. "a partir de este texto, en tercera persona y con tono institucional.\n\n"
			. "Formato: primero una línea con el TÍTULO, después una línea en blanco, después el cuerpo "
			. "en dos o tres párrafos.\n\n"
			. "No inventes datos que no estén acá (fechas, nombres, resultados). Si algo falta, escribilo "
			. "como «a confirmar». No copies hashtags ni emojis.\n\n"
			. "Texto de la publicación:\n" . mb_substr( $pie, 0, 1500 );

		$r = Cead_Acad_WA_AI::route( $prompt, '', '', [], 'Estás preparando un borrador de nota para el sitio del colegio.' );
		$texto = is_array( $r ) ? trim( (string) ( $r['reply'] ?? '' ) ) : '';

		return '' !== $texto ? $texto : ( "Pie de foto:\n" . $pie );
	}

	/* --------------------------------------------------------- la lectura */

	/**
	 * Trae las publicaciones recientes. Devuelve una lista de
	 * [ id, caption, url, imagen ] o un WP_Error.
	 *
	 * Es la única parte que depende de Instagram, y está aislada acá a
	 * propósito: cuando cambie la forma de leer (o cuando llegue el acceso a la
	 * cuenta) se toca solo esta función.
	 *
	 * @return array<int,array<string,string>>|WP_Error
	 */
	public static function traer_publicaciones() {
		switch ( self::modo() ) {
			case 'graph':     return self::desde_graph();
			case 'proveedor': return self::desde_proveedor();
		}
		return [];
	}

	/**
	 * API oficial. Es el camino bueno: estable, permitido y sin bloqueos. Pide
	 * un token de acceso de larga duración de la cuenta de empresa vinculada.
	 */
	protected static function desde_graph() {
		$token = (string) get_option( 'cead_acad_wa_ig_token', '' );
		$cuenta = (string) get_option( 'cead_acad_wa_ig_cuenta_id', '' );
		if ( '' === $token || '' === $cuenta ) {
			return new WP_Error( 'cead_ig_config', 'Falta el token o el ID de cuenta de Instagram.' );
		}

		$url = add_query_arg( [
			'fields'       => 'id,caption,permalink,media_url,timestamp',
			'limit'        => 10,
			'access_token' => $token,
		], 'https://graph.facebook.com/v21.0/' . rawurlencode( $cuenta ) . '/media' );

		$r = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code ) {
			$detalle = (string) ( $body['error']['message'] ?? ( 'HTTP ' . $code ) );
			return new WP_Error( 'cead_ig_http', 'Instagram respondió: ' . $detalle );
		}

		$out = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $m ) {
			$out[] = [
				'id'      => (string) ( $m['id'] ?? '' ),
				'caption' => (string) ( $m['caption'] ?? '' ),
				'url'     => (string) ( $m['permalink'] ?? '' ),
				'imagen'  => (string) ( $m['media_url'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Servicio externo que devuelve JSON. Pensado para los proveedores que
	 * hacen esta lectura por vos mientras no haya acceso a la cuenta.
	 *
	 * Se espera una lista de objetos con `id`/`caption`/`url`; se aceptan
	 * también los nombres alternativos más comunes (`shortcode`, `text`,
	 * `permalink`) porque cada proveedor los llama distinto y no vale la pena
	 * atarse a uno solo.
	 */
	protected static function desde_proveedor() {
		$url = trim( (string) get_option( 'cead_acad_wa_ig_proveedor_url', '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'cead_ig_config', 'Falta la URL del proveedor de Instagram.' );
		}
		$url = str_replace( '{usuario}', rawurlencode( self::usuario() ), $url );

		$headers = [];
		$clave   = trim( (string) get_option( 'cead_acad_wa_ig_proveedor_key', '' ) );
		if ( '' !== $clave ) {
			$headers['Authorization'] = 'Bearer ' . $clave;
			// Varios servicios de RapidAPI esperan la clave con este nombre.
			$headers['x-api-key']     = $clave;
		}

		$r = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => $headers ] );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 200 !== $code ) {
			return new WP_Error( 'cead_ig_http', 'El proveedor respondió HTTP ' . $code . '.' );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'cead_ig_json', 'El proveedor no devolvió JSON válido.' );
		}
		// La lista puede venir suelta o adentro de `data` / `items` / `posts`.
		$lista = $body;
		foreach ( [ 'data', 'items', 'posts', 'result' ] as $k ) {
			if ( isset( $body[ $k ] ) && is_array( $body[ $k ] ) ) { $lista = $body[ $k ]; break; }
		}

		$out = [];
		foreach ( (array) $lista as $m ) {
			if ( ! is_array( $m ) ) { continue; }
			$id = (string) ( $m['id'] ?? $m['shortcode'] ?? $m['code'] ?? '' );
			if ( '' === $id ) { continue; }
			$out[] = [
				'id'      => $id,
				'caption' => (string) ( $m['caption'] ?? $m['text'] ?? $m['description'] ?? '' ),
				'url'     => (string) ( $m['url'] ?? $m['permalink'] ?? $m['link'] ?? '' ),
				'imagen'  => (string) ( $m['media_url'] ?? $m['image'] ?? $m['thumbnail'] ?? '' ),
			];
		}
		return $out;
	}
}
