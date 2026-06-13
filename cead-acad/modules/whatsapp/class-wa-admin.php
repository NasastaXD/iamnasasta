<?php
/**
 * Panel de control del bot en wp-admin (submenú de "CEAD Académico").
 * Estado/QR, Configuración, Comunicados, Bandeja de reportes, Mensajes, Métricas.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Admin {

	private $store;
	private $bridge;
	private $broadcaster;

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Bridge_Client $bridge, Cead_Acad_WA_Broadcaster $broadcaster ) {
		$this->store       = $store;
		$this->bridge      = $bridge;
		$this->broadcaster = $broadcaster;
	}

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register' ], 20 );
	}

	public function register() {
		if ( ! cead_acad_user_is_staff() ) {
			return;
		}
		add_submenu_page( 'cead-acad', __( 'WhatsApp', 'cead-acad' ), __( 'WhatsApp', 'cead-acad' ), 'read', 'cead-acad-whatsapp', [ $this, 'page_status' ] );
		add_submenu_page( 'cead-acad', __( 'WA · Comunicados', 'cead-acad' ), __( 'WA · Comunicados', 'cead-acad' ), 'cead_acad_publish_broadcast', 'cead-acad-wa-broadcast', [ $this, 'page_broadcast' ] );
		add_submenu_page( 'cead-acad', __( 'WA · Reportes', 'cead-acad' ), __( 'WA · Reportes', 'cead-acad' ), 'cead_acad_manage_reports', 'cead-acad-wa-reports', [ $this, 'page_reports' ] );
		add_submenu_page( 'cead-acad', __( 'WA · Mensajes', 'cead-acad' ), __( 'WA · Mensajes', 'cead-acad' ), 'manage_options', 'cead-acad-wa-messages', [ $this, 'page_messages' ] );
		add_submenu_page( 'cead-acad', __( 'WA · Métricas', 'cead-acad' ), __( 'WA · Métricas', 'cead-acad' ), 'cead_acad_view_metrics', 'cead-acad-wa-metrics', [ $this, 'page_metrics' ] );
		add_submenu_page( 'cead-acad', __( 'WA · Mensaje directo', 'cead-acad' ), __( 'WA · Mensaje directo', 'cead-acad' ), 'cead_acad_publish_broadcast', 'cead-acad-wa-direct', [ $this, 'page_direct' ] );
		add_submenu_page( 'cead-acad', __( 'CEADI · IA', 'cead-acad' ), __( 'CEADI · IA', 'cead-acad' ), 'manage_options', 'cead-acad-wa-ai', [ $this, 'page_ai' ] );
		add_submenu_page( 'cead-acad', __( 'CEADI · Perfil', 'cead-acad' ), __( 'CEADI · Perfil', 'cead-acad' ), 'manage_options', 'cead-acad-wa-profile', [ $this, 'page_profile' ] );
	}

	private function guard( $cap = 'read' ) {
		if ( ! cead_acad_user_is_staff() || ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
	}

	private function notice( $msg, $type = 'success' ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $msg ) . '</p></div>';
	}

	// -------------------------------------------------- Estado + Configuración
	public function page_status() {
		$this->guard();
		$notice = null;

		if ( isset( $_POST['cead_acad_wa_action'] ) && check_admin_referer( 'cead_acad_wa_status' ) ) {
			$action = sanitize_key( wp_unslash( $_POST['cead_acad_wa_action'] ) );
			if ( $action === 'save_settings' ) {
				$this->store->update_session( [
					'bridge_url'   => esc_url_raw( wp_unslash( $_POST['bridge_url'] ?? '' ) ),
					'shared_token' => sanitize_text_field( wp_unslash( $_POST['shared_token'] ?? '' ) ),
				] );
				update_option( 'cead_acad_wa_report_forward_number', preg_replace( '/[^0-9]/', '', (string) ( $_POST['report_forward_number'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_reminder_days', max( 1, (int) ( $_POST['reminder_days'] ?? 1 ) ), false );
				update_option( 'cead_acad_wa_country_code', preg_replace( '/[^0-9]/', '', (string) ( $_POST['country_code'] ?? '595' ) ) ?: '595', false );
				update_option( 'cead_acad_wa_bot_number', preg_replace( '/[^0-9]/', '', (string) ( $_POST['bot_number'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_registered_only', ! empty( $_POST['registered_only'] ) ? 1 : 0, false );
				update_option( 'cead_acad_panel_intro', sanitize_textarea_field( wp_unslash( $_POST['panel_intro'] ?? '' ) ), false );
				update_option( 'cead_acad_ceadi_intro', sanitize_textarea_field( wp_unslash( $_POST['ceadi_intro'] ?? '' ) ), false );
				update_option( 'cead_acad_banned_words', sanitize_textarea_field( wp_unslash( $_POST['banned_words'] ?? '' ) ), false );
				$notice = [ 'ok', __( 'Configuración guardada.', 'cead-acad' ) ];
			} elseif ( $action === 'restart' ) {
				$res = $this->bridge->restart();
				$notice = isset( $res['error'] ) ? [ 'err', $res['error'] ] : [ 'ok', __( 'Reinicio solicitado.', 'cead-acad' ) ];
			} elseif ( $action === 'logout' ) {
				$res = $this->bridge->logout();
				$notice = isset( $res['error'] ) ? [ 'err', $res['error'] ] : [ 'ok', __( 'Sesión cerrada en el bridge.', 'cead-acad' ) ];
			} elseif ( $action === 'refresh' ) {
				$res = $this->bridge->status();
				if ( isset( $res['error'] ) ) {
					$notice = [ 'err', $res['error'] ];
				} else {
					$this->store->update_session( [
						'connection_status' => ! empty( $res['connected'] ) ? 'connected' : 'disconnected',
						'linked_number'     => isset( $res['number'] ) ? (string) $res['number'] : null,
						'qr_data'           => isset( $res['qr'] ) ? (string) $res['qr'] : null,
						'last_heartbeat'    => current_time( 'mysql' ),
					] );
					$notice = [ 'ok', __( 'Estado actualizado.', 'cead-acad' ) ];
				}
			}
		}

		$s = $this->store->session();
		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Estado y configuración', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' );
		}

		$connected = ( $s->connection_status ?? '' ) === 'connected';
		echo '<div class="card" style="max-width:720px"><h2>' . esc_html__( 'Conexión del bridge', 'cead-acad' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Estado', 'cead-acad' ) . ':</strong> ' . ( $connected ? '🟢 conectado' : '🔴 ' . esc_html( $s->connection_status ?? 'desconocido' ) ) . '</p>';
		if ( ! empty( $s->linked_number ) ) {
			echo '<p><strong>' . esc_html__( 'Número vinculado', 'cead-acad' ) . ':</strong> ' . esc_html( $s->linked_number ) . '</p>';
		}
		if ( ! empty( $s->last_heartbeat ) ) {
			echo '<p><strong>' . esc_html__( 'Último heartbeat', 'cead-acad' ) . ':</strong> ' . esc_html( $s->last_heartbeat ) . '</p>';
		}
		if ( ! $connected && ! empty( $s->qr_data ) && str_starts_with( (string) $s->qr_data, 'data:image' ) ) {
			echo '<p>' . esc_html__( 'Escaneá este QR desde WhatsApp → Dispositivos vinculados:', 'cead-acad' ) . '</p>';
			echo '<img src="' . esc_attr( $s->qr_data ) . '" alt="QR" style="width:260px;height:260px;border:1px solid #ddd" />';
		}
		echo '<p style="margin-top:12px">';
		$this->inline_button( 'refresh', __( 'Refrescar estado', 'cead-acad' ) );
		$this->inline_button( 'restart', __( 'Reiniciar bridge', 'cead-acad' ) );
		$this->inline_button( 'logout', __( 'Cerrar sesión', 'cead-acad' ), 'delete' );
		echo '</p></div>';

		echo '<div class="card" style="max-width:720px"><h2>' . esc_html__( 'Configuración', 'cead-acad' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cead_acad_wa_status' );
		echo '<input type="hidden" name="cead_acad_wa_action" value="save_settings" />';
		echo '<table class="form-table"><tbody>';
		$this->field( 'bridge_url', __( 'URL del bridge', 'cead-acad' ), $s->bridge_url ?? '', 'https://tu-tunnel.trycloudflare.com' );
		$this->field( 'shared_token', __( 'Token compartido (X-Caag-Token)', 'cead-acad' ), $s->shared_token ?? '', '', 'text' );
		$this->field( 'report_forward_number', __( 'Número que recibe reportes', 'cead-acad' ), get_option( 'cead_acad_wa_report_forward_number', '' ), '595991123456' );
		$this->field( 'reminder_days', __( 'Días de anticipación de recordatorios', 'cead-acad' ), get_option( 'cead_acad_wa_reminder_days', 1 ), '', 'number' );
		$this->field( 'country_code', __( 'Código de país (para reconocer alumnos)', 'cead-acad' ), get_option( 'cead_acad_wa_country_code', '595' ), '595' );
			$this->field( 'bot_number', __( 'Número de CEADI (botón «Agregar a CEADI»)', 'cead-acad' ), get_option( 'cead_acad_wa_bot_number', '' ), '595991123456' );

			// Acceso: a quién atiende CEADI.
			echo '<tr><th scope="row">' . esc_html__( 'Acceso', 'cead-acad' ) . '</th><td>';
			echo '<label><input type="checkbox" name="registered_only" value="1" ' . checked( get_option( 'cead_acad_wa_registered_only', 1 ), 1, false ) . '> ' . esc_html__( 'Atender solo a números registrados en el panel', 'cead-acad' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Activado (recomendado): CEADI solo responde a números cargados en la ficha de un usuario. Desactivalo temporalmente si querés probar desde un número que no está registrado.', 'cead-acad' ) . '</p>';
			echo '</td></tr>';

			$this->field_textarea( 'panel_intro', __( 'Landing: ¿qué es el panel del CEAD?', 'cead-acad' ), get_option( 'cead_acad_panel_intro', '' ) );
			$this->field_textarea( 'ceadi_intro', __( 'Landing: ¿qué es CEADI?', 'cead-acad' ), get_option( 'cead_acad_ceadi_intro', '' ) );
			$this->field_textarea( 'banned_words', __( 'Palabras prohibidas (una por línea)', 'cead-acad' ), get_option( 'cead_acad_banned_words', '' ) );
		echo '</tbody></table>';
		submit_button( __( 'Guardar configuración', 'cead-acad' ) );
		echo '</form></div>';

		// Acceso directo a la configuración de IA (vive en su propia pantalla).
		$ai_on = (bool) get_option( 'cead_acad_wa_ai_enabled', 0 ) && Cead_Acad_WA_AI::key() !== '';
		echo '<div class="card" style="max-width:720px"><h2>' . esc_html__( '🤖 CEADI inteligente (IA)', 'cead-acad' ) . '</h2>';
		echo '<p>' . ( $ai_on
			? '🟢 ' . esc_html__( 'La IA está activa: CEADI entiende lenguaje natural.', 'cead-acad' )
			: '🔴 ' . esc_html__( 'La IA está desactivada: CEADI usa solo el menú numérico.', 'cead-acad' ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=cead-acad-wa-ai' ) ) . '">' . esc_html__( 'Configurar la IA de CEADI', 'cead-acad' ) . '</a></p>';
		echo '</div>';

		echo '<p><em>' . esc_html__( 'El bot reutiliza los datos del plugin: horarios/eventos, comunicados y cursos. Los alumnos se reconocen por el teléfono cargado en su ficha (meta _cead_acad_phone).', 'cead-acad' ) . '</em></p>';
		echo '</div>';
	}

	// -------------------------------------------------- CEADI inteligente (IA)
	public function page_ai() {
		$this->guard( 'manage_options' );
		$notice = null;

		if ( isset( $_POST['cead_acad_wa_ai_action'] ) && check_admin_referer( 'cead_acad_wa_ai' ) ) {
			$action = sanitize_key( wp_unslash( $_POST['cead_acad_wa_ai_action'] ) );
			update_option( 'cead_acad_wa_ai_enabled', ! empty( $_POST['ai_enabled'] ) ? 1 : 0, false );
			update_option( 'cead_acad_wa_ai_key', sanitize_text_field( wp_unslash( $_POST['ai_key'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_ai_model', sanitize_text_field( wp_unslash( $_POST['ai_model'] ?? '' ) ) ?: 'deepseek-chat', false );
			update_option( 'cead_acad_wa_ai_endpoint', esc_url_raw( wp_unslash( $_POST['ai_endpoint'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_ai_temp', is_numeric( $_POST['ai_temp'] ?? '' ) ? (float) $_POST['ai_temp'] : 0.2, false );
			update_option( 'cead_acad_wa_ai_maxtokens', max( 50, (int) ( $_POST['ai_maxtokens'] ?? 500 ) ), false );
			update_option( 'cead_acad_wa_ai_prompt', sanitize_textarea_field( wp_unslash( $_POST['ai_prompt'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_ai_knowledge', sanitize_textarea_field( wp_unslash( $_POST['ai_knowledge'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_ai_memory', max( 0, min( 20, (int) ( $_POST['ai_memory'] ?? 4 ) ) ), false );
			$dm = sanitize_key( wp_unslash( $_POST['ai_default_mode'] ?? 'auto' ) );
			update_option( 'cead_acad_wa_default_mode', in_array( $dm, [ 'auto', 'ia', 'menu' ], true ) ? $dm : 'auto', false );

			// Transcripción de notas de voz (speech-to-text), endpoint aparte.
			update_option( 'cead_acad_wa_stt_enabled', ! empty( $_POST['stt_enabled'] ) ? 1 : 0, false );
			update_option( 'cead_acad_wa_stt_endpoint', esc_url_raw( wp_unslash( $_POST['stt_endpoint'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_stt_model', sanitize_text_field( wp_unslash( $_POST['stt_model'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_stt_key', sanitize_text_field( wp_unslash( $_POST['stt_key'] ?? '' ) ), false );

			if ( $action === 'ai_test' ) {
				$msg = sanitize_text_field( wp_unslash( $_POST['ai_test_message'] ?? '' ) ) ?: '¿qué clases tengo hoy?';
				$t   = Cead_Acad_WA_AI::test( $msg );
				$notice = [ $t['ok'] ? 'ok' : 'err', __( 'Prueba IA', 'cead-acad' ) . ': ' . $t['summary'] ];
			} else {
				$notice = [ 'ok', __( 'Configuración de IA guardada.', 'cead-acad' ) ];
			}
		}

		$enabled = (bool) get_option( 'cead_acad_wa_ai_enabled', 0 );
		$has_key = Cead_Acad_WA_AI::key() !== '';

		echo '<div class="wrap"><h1>' . esc_html__( 'CEADI inteligente — IA', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' );
		}

		// Banner de estado claro.
		if ( $enabled && $has_key ) {
			$this->notice( '🟢 ' . __( 'IA activa: CEADI entiende lenguaje natural. El alumno puede escribir su consulta y CEADI responde o lo lleva a la función correcta; el menú numérico sigue funcionando como apoyo.', 'cead-acad' ), 'success' );
			// Si una llamada real falló hace poco, mostrar el error exacto (clave para
			// diagnosticar cuando el bot contesta «no puedo procesar tu mensaje»).
			$last_err = get_transient( 'cead_acad_wa_ai_last_error' );
			if ( is_array( $last_err ) && ! empty( $last_err['error'] ) ) {
				$this->notice(
					'⚠️ ' . sprintf(
						/* translators: 1: fecha/hora, 2: mensaje de error */
						__( 'Última falla de la IA (%1$s): %2$s — Mientras falle, CEADI responde con el menú. Usá «Guardar y probar» tras corregir.', 'cead-acad' ),
						(string) $last_err['time'],
						(string) $last_err['error']
					),
					'warning'
				);
			}
		} elseif ( $enabled && ! $has_key ) {
			$this->notice( '⚠️ ' . __( 'IA activada pero falta la API key: hasta que la cargues, CEADI sigue usando solo el menú numérico.', 'cead-acad' ), 'warning' );
		} else {
			$this->notice( '🔴 ' . __( 'IA desactivada: CEADI usa solo el menú numérico. Activala abajo y cargá una API key para que entienda lenguaje natural.', 'cead-acad' ), 'info' );
		}

		echo '<div class="card" style="max-width:760px"><form method="post">';
		wp_nonce_field( 'cead_acad_wa_ai' );
		echo '<input type="hidden" name="cead_acad_wa_ai_action" value="save_ai" />';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Activar', 'cead-acad' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ai_enabled" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Entender lenguaje natural con IA (modo IA-first; el menú numérico queda de apoyo)', 'cead-acad' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Cuando alguien escribe en vez de elegir un número, la IA interpreta y dispara la función o responde con el conocimiento/FAQ. Si la IA falla o está apagada, cae al menú.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';

		// Modo por defecto (asistente vs menú numérico) para conversaciones nuevas.
		$dm = (string) get_option( 'cead_acad_wa_default_mode', 'auto' );
		echo '<tr><th scope="row">' . esc_html__( 'Modo por defecto', 'cead-acad' ) . '</th><td>';
		echo '<select name="ai_default_mode">';
		foreach ( [
			'auto' => __( 'Automático (asistente si la IA está activa)', 'cead-acad' ),
			'ia'   => __( 'Asistente (IA): la persona escribe en lenguaje natural', 'cead-acad' ),
			'menu' => __( 'Menú numérico clásico', 'cead-acad' ),
		] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $dm, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Con qué modo arranca cada conversación (alumnado y personal). Cada persona puede cambiarlo desde WhatsApp escribiendo «modo asistente» o «modo menú»; su preferencia se recuerda.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';

		$this->field( 'ai_endpoint', __( 'Endpoint (API compatible OpenAI)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_endpoint', '' ), Cead_Acad_WA_AI::ENDPOINT_DEFAULT, 'url' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Ej.: DeepSeek https://api.deepseek.com/chat/completions · OpenRouter https://openrouter.ai/api/v1/chat/completions · tokenlb https://tokenlb.net/v1/chat/completions · OpenAI https://api.openai.com/v1/chat/completions', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_model', __( 'Modelo', 'cead-acad' ), get_option( 'cead_acad_wa_ai_model', 'deepseek-chat' ), 'deepseek-chat' );
		$this->field( 'ai_key', __( 'API key', 'cead-acad' ), get_option( 'cead_acad_wa_ai_key', '' ), 'sk-... (o definir CEAD_ACAD_AI_KEY en wp-config)', 'password' );
		if ( Cead_Acad_WA_AI::key() !== '' && defined( 'CEAD_ACAD_AI_KEY' ) && CEAD_ACAD_AI_KEY ) {
			echo '<tr><th></th><td><p class="description">' . esc_html__( 'La API key está fijada por constante en wp-config.php (CEAD_ACAD_AI_KEY); este campo se ignora.', 'cead-acad' ) . '</p></td></tr>';
		}
		$this->field( 'ai_temp', __( 'Temperatura (0–1)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_temp', '0.5' ), '0.5', 'text' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( '0.5 (recomendado) = respuestas naturales y con criterio. Más bajo = más rígido; más alto = más creativo.', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_maxtokens', __( 'Máx. tokens de respuesta', 'cead-acad' ), get_option( 'cead_acad_wa_ai_maxtokens', 800 ), '800', 'number' );
		$this->field_textarea( 'ai_prompt', __( 'System prompt (personalidad / instrucciones)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_prompt', '' ) ?: Cead_Acad_WA_AI::default_persona(), 6 );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Definí el tono y rol de CEADI. El formato de salida (ruteo en JSON) se agrega automáticamente, no hace falta escribirlo.', 'cead-acad' ) . '</p></td></tr>';
		$this->field_textarea( 'ai_knowledge', __( 'Conocimiento (base de datos para responder)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_knowledge', '' ), 8 );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Texto libre con info del colegio (horarios generales, reglas, contactos, fechas, etc.). La IA lo usa para responder dudas. Las FAQ del panel se suman a esto.', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_memory', __( 'Memoria (turnos a recordar)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_memory', 4 ), '4', 'number' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( '0 = sin memoria (cada mensaje es independiente). 4 (recomendado) = recuerda los últimos 4 intercambios de esa persona, por 30 minutos, para que las charlas fluyan. Más memoria usa más tokens.', 'cead-acad' ) . '</p></td></tr>';

		// --- Transcripción de notas de voz (speech-to-text) ---
		$stt_on = (bool) get_option( 'cead_acad_wa_stt_enabled', 0 );
		echo '<tr><th scope="row" colspan="2"><hr><h2 style="margin:0">' . esc_html__( '🎤 Notas de voz (transcripción)', 'cead-acad' ) . '</h2></th></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Activar', 'cead-acad' ) . '</th><td>';
		echo '<label><input type="checkbox" name="stt_enabled" value="1" ' . checked( $stt_on, true, false ) . '> ' . esc_html__( 'Transcribir las notas de voz que mande la gente y procesarlas como texto', 'cead-acad' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Necesita un endpoint compatible con OpenAI /audio/transcriptions (ej.: Whisper). Si está apagado o falla, CEADI pide que escriban el mensaje.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		$this->field( 'stt_endpoint', __( 'Endpoint de transcripción', 'cead-acad' ), get_option( 'cead_acad_wa_stt_endpoint', '' ), 'https://api.openai.com/v1/audio/transcriptions', 'url' );
		$this->field( 'stt_model', __( 'Modelo de transcripción', 'cead-acad' ), get_option( 'cead_acad_wa_stt_model', '' ), 'whisper-1' );
		$this->field( 'stt_key', __( 'API key de transcripción', 'cead-acad' ), get_option( 'cead_acad_wa_stt_key', '' ), __( 'vacío = reusar la API key de la IA (o CEAD_ACAD_STT_KEY en wp-config)', 'cead-acad' ), 'password' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Si lo dejás vacío, usa la misma API key de la IA. Ponelo solo si el proveedor de transcripción es distinto.', 'cead-acad' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Probar', 'cead-acad' ) . '</th><td>';
		echo '<input type="text" name="ai_test_message" class="regular-text" placeholder="' . esc_attr__( '¿qué clases tengo hoy?', 'cead-acad' ) . '">';
		echo ' <button type="submit" class="button" name="cead_acad_wa_ai_action" value="ai_test">' . esc_html__( 'Guardar y probar', 'cead-acad' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Guarda la configuración y hace una consulta de prueba a la API; el resultado aparece arriba.', 'cead-acad' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary" name="cead_acad_wa_ai_action" value="save_ai">' . esc_html__( 'Guardar configuración de IA', 'cead-acad' ) . '</button></p>';
		echo '</form></div>';

		echo '<p><em>' . esc_html__( 'Recordá: CEADI solo atiende a los números habilitados según el acceso configurado en WhatsApp → Estado y configuración.', 'cead-acad' ) . '</em></p>';
		echo '</div>';
	}

	private function inline_button( $action, $label, $class = 'secondary' ) {
		echo '<form method="post" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( 'cead_acad_wa_status' );
		echo '<input type="hidden" name="cead_acad_wa_action" value="' . esc_attr( $action ) . '" />';
		echo '<button type="submit" class="button button-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private function field( $name, $label, $value, $placeholder = '', $type = 'text' ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" />';
		echo '</td></tr>';
	}

	private function field_textarea( $name, $label, $value, $rows = 3 ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) $rows . '" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
		echo '</td></tr>';
	}

	// -------------------------------------------------- Comunicados
	public function page_broadcast() {
		$this->guard( 'cead_acad_publish_broadcast' );
		$notice = null;
		if ( isset( $_POST['cead_acad_wa_send'] ) && check_admin_referer( 'cead_acad_wa_broadcast' ) ) {
			$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
			$target  = in_array( $_POST['target'] ?? '', [ 'students', 'staff', 'all' ], true ) ? $_POST['target'] : 'all';
			if ( $target === 'all' && ! current_user_can( 'cead_acad_publish_broadcast_all' ) ) {
				$notice = [ 'err', __( 'No tenés permiso para enviar a todos.', 'cead-acad' ) ];
			} elseif ( trim( $message ) === '' ) {
				$notice = [ 'err', __( 'El mensaje no puede estar vacío.', 'cead-acad' ) ];
			} else {
				// Crear el comunicado como post (se ve en el panel web) y enviarlo por WA.
				Cead_Acad_WA_Broadcaster::create_broadcast_post( $message, $target );
				$res = $this->broadcaster->enqueue_for( $message, $target );
				if ( ! empty( $res['busy'] ) ) {
					$notice = [ 'err', __( 'Ya hay un envío en curso.', 'cead-acad' ) ];
				} elseif ( empty( $res['queued'] ) ) {
					$notice = [ 'err', __( 'No hay destinatarios para esa audiencia.', 'cead-acad' ) ];
				} else {
					/* translators: %d: cantidad de destinatarios */
					$notice = [ 'ok', sprintf( __( 'Comunicado publicado y encolado para %d destinatario(s).', 'cead-acad' ), (int) $res['total'] ) ];
				}
			}
		}

		$progress = $this->broadcaster->progress();
		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Comunicados', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' );
		}
		if ( ( $progress['status'] ?? '' ) === 'running' ) {
			/* translators: 1: mensajes enviados, 2: total a enviar */
			$this->notice( sprintf( __( 'Envío en curso: %1$d/%2$d enviados.', 'cead-acad' ), $progress['sent'], $progress['total'] ), 'info' );
		}
		echo '<div class="card" style="max-width:720px"><form method="post">';
		wp_nonce_field( 'cead_acad_wa_broadcast' );
		echo '<p><label><strong>' . esc_html__( 'Audiencia', 'cead-acad' ) . '</strong></label><br/>';
		echo '<select name="target">';
		echo '<option value="students">' . esc_html__( 'Alumnado', 'cead-acad' ) . ' (' . (int) $this->broadcaster->count_for( 'students' ) . ')</option>';
		echo '<option value="staff">' . esc_html__( 'Personal', 'cead-acad' ) . ' (' . (int) $this->broadcaster->count_for( 'staff' ) . ')</option>';
		if ( current_user_can( 'cead_acad_publish_broadcast_all' ) ) {
			echo '<option value="all">' . esc_html__( 'Todos', 'cead-acad' ) . ' (' . (int) $this->broadcaster->count_for( 'all' ) . ')</option>';
		}
		echo '</select></p>';
		echo '<p><label><strong>' . esc_html__( 'Mensaje', 'cead-acad' ) . '</strong></label><br/>';
		echo '<textarea name="message" rows="5" class="large-text" required></textarea></p>';
		echo '<p><button type="submit" name="cead_acad_wa_send" value="1" class="button button-primary">' . esc_html__( 'Enviar por WhatsApp', 'cead-acad' ) . '</button></p>';
		echo '</form></div>';
		echo '<p><em>' . esc_html__( 'El envío es escalonado (lotes con pausa) para no saturar WhatsApp.', 'cead-acad' ) . '</em></p>';
		echo '</div>';
	}

	// -------------------------------------------------- Mensaje directo
	public function page_direct() {
		$this->guard( 'cead_acad_publish_broadcast' );
		$notice = null;
		if ( isset( $_POST['cead_acad_wa_direct'] ) && check_admin_referer( 'cead_acad_wa_direct' ) ) {
			$digits  = preg_replace( '/[^0-9]/', '', (string) ( $_POST['to'] ?? '' ) );
			$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
			if ( strlen( $digits ) < 7 ) {
				$notice = [ 'err', __( 'Número inválido (incluí el código de país).', 'cead-acad' ) ];
			} elseif ( trim( $message ) === '' ) {
				$notice = [ 'err', __( 'El mensaje no puede estar vacío.', 'cead-acad' ) ];
			} else {
				$res = $this->bridge->send_message( $digits, $message );
				if ( isset( $res['error'] ) || empty( $res['sent'] ) ) {
					$notice = [ 'err', $res['error'] ?? __( 'No se pudo enviar (¿el bridge está conectado?).', 'cead-acad' ) ];
				} else {
					$this->store->log( $digits, 'out', $message, 'direct' );
					/* translators: %s: número de teléfono destino */
					$notice = [ 'ok', sprintf( __( 'Mensaje enviado a +%s.', 'cead-acad' ), $digits ) ];
				}
			}
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Mensaje directo', 'cead-acad' ) . '</h1>';
		if ( $notice ) { $this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' ); }
		echo '<div class="card" style="max-width:720px"><form method="post">';
		wp_nonce_field( 'cead_acad_wa_direct' );
		echo '<p><label><strong>' . esc_html__( 'Número (con código de país, solo dígitos)', 'cead-acad' ) . '</strong></label><br/>';
		echo '<input type="text" name="to" class="regular-text" placeholder="595991123456" required /></p>';
		echo '<p><label><strong>' . esc_html__( 'Mensaje', 'cead-acad' ) . '</strong></label><br/>';
		echo '<textarea name="message" rows="5" class="large-text" required></textarea></p>';
		echo '<p><button type="submit" name="cead_acad_wa_direct" value="1" class="button button-primary">' . esc_html__( 'Enviar mensaje', 'cead-acad' ) . '</button></p>';
		echo '</form></div>';
		echo '<p><em>' . esc_html__( 'Envía un mensaje puntual a un número desde CEADI. Queda registrado en el historial.', 'cead-acad' ) . '</em></p>';
		echo '</div>';
	}

	// -------------------------------------------------- Perfil de CEADI
	public function page_profile() {
		$this->guard( 'manage_options' );
		$notice = null;
		if ( isset( $_POST['cead_acad_wa_profile'] ) && check_admin_referer( 'cead_acad_wa_profile' ) ) {
			$name   = sanitize_text_field( wp_unslash( $_POST['bot_name'] ?? '' ) );
			$about  = sanitize_textarea_field( wp_unslash( $_POST['bot_about'] ?? '' ) );
			$errors = [];
			$res = $this->bridge->set_profile( $name !== '' ? $name : null, $about );
			if ( isset( $res['error'] ) || empty( $res['ok'] ) ) {
				$errors[] = $res['error'] ?? __( 'No se pudo actualizar nombre/descripción.', 'cead-acad' );
			} else {
				update_option( 'cead_acad_wa_bot_name', $name, false );
				update_option( 'cead_acad_wa_bot_about', $about, false );
			}
			// Foto opcional (se envía directo al bridge, sin guardarla en la biblioteca).
			if ( ! empty( $_FILES['bot_picture']['tmp_name'] ) && is_uploaded_file( $_FILES['bot_picture']['tmp_name'] ) ) {
				$bytes = file_get_contents( $_FILES['bot_picture']['tmp_name'] );
				if ( $bytes !== false ) {
					$pres = $this->bridge->set_profile_picture( base64_encode( $bytes ) );
					if ( isset( $pres['error'] ) || empty( $pres['ok'] ) ) {
						$errors[] = $pres['error'] ?? __( 'No se pudo actualizar la foto.', 'cead-acad' );
					}
				}
			}
			$notice = $errors ? [ 'err', implode( ' · ', $errors ) ] : [ 'ok', __( 'Perfil de CEADI actualizado.', 'cead-acad' ) ];
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'CEADI — Perfil del bot', 'cead-acad' ) . '</h1>';
		if ( $notice ) { $this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' ); }
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Estos cambios se aplican a la cuenta de WhatsApp vinculada y requieren el bridge conectado.', 'cead-acad' ) . '</p></div>';
		echo '<div class="card" style="max-width:720px"><form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'cead_acad_wa_profile' );
		echo '<table class="form-table"><tbody>';
		$this->field( 'bot_name', __( 'Nombre visible', 'cead-acad' ), get_option( 'cead_acad_wa_bot_name', 'CEADI' ), 'CEADI' );
		$this->field_textarea( 'bot_about', __( 'Descripción (info)', 'cead-acad' ), get_option( 'cead_acad_wa_bot_about', '' ) );
		echo '<tr><th scope="row"><label for="bot_picture">' . esc_html__( 'Foto de perfil', 'cead-acad' ) . '</label></th><td>';
		echo '<input type="file" id="bot_picture" name="bot_picture" accept="image/*" />';
		echo '<p class="description">' . esc_html__( 'Imagen cuadrada (JPG/PNG). Dejalo vacío para no cambiarla.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" name="cead_acad_wa_profile" value="1" class="button button-primary">' . esc_html__( 'Guardar perfil', 'cead-acad' ) . '</button></p>';
		echo '</form></div></div>';
	}

	// -------------------------------------------------- Reportes
	public function page_reports() {
		$this->guard( 'cead_acad_manage_reports' );
		$notice = null;
		if ( isset( $_POST['cead_acad_wa_report_id'] ) && check_admin_referer( 'cead_acad_wa_reports' ) ) {
			$id  = (int) $_POST['cead_acad_wa_report_id'];
			$act = sanitize_key( wp_unslash( $_POST['report_action'] ?? '' ) );
			if ( $act === 'status' ) {
				$st = in_array( $_POST['status'] ?? '', [ 'new', 'in_review', 'resolved' ], true ) ? $_POST['status'] : 'new';
				$this->store->update_report_status( $id, $st );
				$notice = [ 'ok', __( 'Estado actualizado.', 'cead-acad' ) ];
			} elseif ( $act === 'note' ) {
				$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
				if ( $note !== '' ) {
					$this->store->append_report_note( $id, $note );
					$notice = [ 'ok', __( 'Nota agregada.', 'cead-acad' ) ];
				}
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Bandeja de reportes', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' );
		}
		$counts = $this->store->count_reports_by_status();
		echo '<p>🆕 ' . (int) $counts['new'] . ' · 🔎 ' . (int) $counts['in_review'] . ' · ✅ ' . (int) $counts['resolved'] . '</p>';

		$reports = $this->store->reports_by_status( '', 50 );
		if ( ! $reports ) {
			echo '<p>' . esc_html__( 'No hay reportes.', 'cead-acad' ) . '</p></div>';
			return;
		}
		foreach ( $reports as $r ) {
			$body = Cead_Acad_WA_Crypto::decrypt( (string) $r->body_enc );
			echo '<div class="card" style="max-width:760px;margin-bottom:14px">';
			echo '<h3 style="margin:0">' . esc_html( $r->ref_code ) . ' — ' . esc_html( $r->type === 'confidential' ? 'Confidencial' : 'Anónimo' ) . '</h3>';
			echo '<p style="color:#666;margin:.3em 0">' . esc_html( $r->category ?: '—' ) . ' · ' . esc_html( $r->created_at ) . ' · ' . esc_html( $r->status ) . '</p>';
			if ( $r->type === 'confidential' && ! empty( $r->phone ) ) {
				echo '<p><strong>' . esc_html__( 'Contacto', 'cead-acad' ) . ':</strong> +' . esc_html( $r->phone ) . '</p>';
			}
			echo '<blockquote style="border-left:3px solid #E93B3C;padding-left:12px">' . nl2br( esc_html( $body !== null ? $body : '⚠️ No se pudo descifrar.' ) ) . '</blockquote>';
			if ( trim( (string) $r->note ) !== '' ) {
				echo '<p><strong>' . esc_html__( 'Notas', 'cead-acad' ) . ':</strong><br/>' . nl2br( esc_html( $r->note ) ) . '</p>';
			}
			echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
			wp_nonce_field( 'cead_acad_wa_reports' );
			echo '<input type="hidden" name="cead_acad_wa_report_id" value="' . (int) $r->id . '" />';
			echo '<input type="hidden" name="report_action" value="status" />';
			echo '<select name="status">';
			foreach ( [ 'new' => 'Nuevo', 'in_review' => 'En revisión', 'resolved' => 'Resuelto' ] as $k => $lbl ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r->status, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
			}
			echo '</select> <button class="button">' . esc_html__( 'Actualizar estado', 'cead-acad' ) . '</button>';
			echo '</form>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( 'cead_acad_wa_reports' );
			echo '<input type="hidden" name="cead_acad_wa_report_id" value="' . (int) $r->id . '" />';
			echo '<input type="hidden" name="report_action" value="note" />';
			echo '<input type="text" name="note" class="regular-text" placeholder="' . esc_attr__( 'Agregar nota…', 'cead-acad' ) . '" /> <button class="button">' . esc_html__( 'Guardar nota', 'cead-acad' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}
		echo '</div>';
	}

	// -------------------------------------------------- Mensajes del bot
	public function page_messages() {
		$this->guard( 'manage_options' );
		$notice = null;
		if ( isset( $_POST['cead_acad_wa_save_messages'] ) && check_admin_referer( 'cead_acad_wa_messages' ) ) {
			$msgs = (array) ( $_POST['msg'] ?? [] );
			foreach ( $msgs as $key => $val ) {
				$this->store->update_message( sanitize_key( $key ), sanitize_textarea_field( wp_unslash( $val ) ) );
			}
			$notice = [ 'ok', __( 'Mensajes guardados.', 'cead-acad' ) ];
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Mensajes del bot', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1] );
		}
		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'Acá editás solo el TEXTO de los mensajes. Las opciones del menú (los números) se reconocen en el código: cambiar el texto no agrega ni quita funciones.', 'cead-acad' );
		echo '<br/>' . esc_html__( 'Las variables entre llaves como {name}, {count} o {events} se reemplazan solas — dejalas tal cual.', 'cead-acad' );
		echo '</p></div>';

		// Contenido actual indexado por clave.
		$current = [];
		foreach ( $this->store->all_messages() as $m ) {
			$current[ $m->msg_key ] = $m->content;
		}
		$catalog = Cead_Acad_WA_Tables::catalog();
		$groups  = Cead_Acad_WA_Tables::message_groups();

		echo '<form method="post">';
		wp_nonce_field( 'cead_acad_wa_messages' );
		foreach ( $groups as $gkey => $gtitle ) {
			$rows = array_filter( $catalog, static fn( $meta ) => $meta['group'] === $gkey );
			if ( ! $rows ) {
				continue;
			}
			echo '<h2 style="margin-top:1.5em">' . esc_html( $gtitle ) . '</h2>';
			echo '<table class="form-table"><tbody>';
			foreach ( $rows as $key => $meta ) {
				$value = $current[ $key ] ?? $meta['default'];
				echo '<tr><th scope="row" style="max-width:240px">';
				echo esc_html( $meta['label'] );
				echo '<br/><code style="font-size:11px;color:#888;word-break:break-all">' . esc_html( $key ) . '</code></th><td>';
				echo '<textarea name="msg[' . esc_attr( $key ) . ']" rows="2" class="large-text">' . esc_textarea( $value ) . '</textarea>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p><button type="submit" name="cead_acad_wa_save_messages" value="1" class="button button-primary">' . esc_html__( 'Guardar mensajes', 'cead-acad' ) . '</button></p>';
		echo '</form></div>';
	}

	// -------------------------------------------------- Métricas
	public function page_metrics() {
		$this->guard( 'cead_acad_view_metrics' );
		$in  = $this->store->count_messages( 'in', 30 );
		$out = $this->store->count_messages( 'out', 30 );
		$usr = $this->store->count_unique_users( 30 );
		$rep = $this->store->count_reports_by_status();
		$sug = $this->store->count_suggestions_by_status();
		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Métricas (30 días)', 'cead-acad' ) . '</h1>';
		echo '<div style="display:flex;gap:14px;flex-wrap:wrap">';
		$this->metric_card( __( 'Mensajes entrantes', 'cead-acad' ), $in );
		$this->metric_card( __( 'Mensajes salientes', 'cead-acad' ), $out );
		$this->metric_card( __( 'Usuarios activos', 'cead-acad' ), $usr );
		$this->metric_card( __( 'Reportes nuevos', 'cead-acad' ), $rep['new'] );
		$this->metric_card( __( 'Reportes en revisión', 'cead-acad' ), $rep['in_review'] );
		$this->metric_card( __( 'Sugerencias nuevas', 'cead-acad' ), $sug['new'] );
		echo '</div>';
		echo '<h2>' . esc_html__( 'Últimos mensajes', 'cead-acad' ) . '</h2><table class="widefat striped"><thead><tr><th>Fecha</th><th>Número</th><th>Dir.</th><th>Acción</th></tr></thead><tbody>';
		foreach ( $this->store->recent_logs( 20 ) as $l ) {
			echo '<tr><td>' . esc_html( $l->created_at ) . '</td><td>' . esc_html( $this->mask_phone( $l->phone ) ) . '</td><td>' . esc_html( $l->direction ) . '</td><td>' . esc_html( $l->processed_action ?: '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function metric_card( $label, $value ) {
		echo '<div class="card" style="min-width:160px;text-align:center"><div style="font-size:30px;font-weight:700;color:#E93B3C">' . (int) $value . '</div><div>' . esc_html( $label ) . '</div></div>';
	}

	private function mask_phone( $phone ) {
		$p = (string) $phone;
		return strlen( $p ) > 5 ? substr( $p, 0, 4 ) . '****' . substr( $p, -2 ) : $p;
	}
}
