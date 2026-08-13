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
		add_action( 'wp_ajax_cead_acad_wa_qr', [ $this, 'ajax_qr' ] );
	}

	/**
	 * Estado del bridge para el refresco automático del QR.
	 *
	 * El QR de WhatsApp vence cada ~20 segundos: pedirlo con un submit de
	 * formulario garantizaba encontrarlo vencido. Esto lo trae en vivo.
	 */
	public function ajax_qr() {
		if ( ! cead_acad_user_is_staff() || ! current_user_can( 'read' ) ) {
			wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'cead-acad' ) ], 403 );
		}
		check_ajax_referer( 'cead_acad_wa_qr' );

		$res = $this->bridge->status( 8 );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( [ 'message' => (string) $res['error'] ] );
		}

		$connected = ! empty( $res['connected'] );
		$qr        = isset( $res['qr'] ) ? (string) $res['qr'] : '';

		$this->store->update_session( [
			'connection_status' => $connected ? 'connected' : 'disconnected',
			'linked_number'     => isset( $res['number'] ) ? (string) $res['number'] : null,
			'qr_data'           => $qr !== '' ? $qr : null,
			'last_heartbeat'    => current_time( 'mysql' ),
		] );

		wp_send_json_success( [
			'connected'    => $connected,
			'number'       => isset( $res['number'] ) ? (string) $res['number'] : '',
			// Solo devolvemos el QR si es un data URI de imagen: evita inyectar
			// cualquier otra cosa en el src del <img>.
			'qr'           => str_starts_with( $qr, 'data:image/' ) ? $qr : '',
			'pairing_code' => isset( $res['pairing_code'] ) ? (string) $res['pairing_code'] : '',
		] );
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
		add_submenu_page( 'cead-acad', __( 'CEADI · Memoria', 'cead-acad' ), __( 'CEADI · Memoria', 'cead-acad' ), 'manage_options', 'cead-acad-wa-memory', [ $this, 'page_memory' ] );
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
		$notice       = null;
		$pairing_code = null;

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
				update_option( 'cead_acad_wa_director_phone', preg_replace( '/[^0-9]/', '', (string) ( $_POST['director_phone'] ?? '' ) ), false );

				$ig_modo = sanitize_key( wp_unslash( $_POST['ig_modo'] ?? '' ) );
				update_option( 'cead_acad_wa_ig_modo', in_array( $ig_modo, [ 'graph', 'proveedor' ], true ) ? $ig_modo : 'off', false );
				update_option( 'cead_acad_wa_ig_usuario', sanitize_text_field( wp_unslash( $_POST['ig_usuario'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_ig_cuenta_id', sanitize_text_field( wp_unslash( $_POST['ig_cuenta_id'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_ig_token', sanitize_text_field( wp_unslash( $_POST['ig_token'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_ig_proveedor_url', esc_url_raw( wp_unslash( $_POST['ig_proveedor_url'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_ig_proveedor_key', sanitize_text_field( wp_unslash( $_POST['ig_proveedor_key'] ?? '' ) ), false );
				update_option( 'cead_acad_wa_social_category', sanitize_title( wp_unslash( $_POST['social_category'] ?? '' ) ) ?: 'redes-sociales', false );
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
			} elseif ( $action === 'temp_access' ) {
				Cead_Acad_WA_Temp_Access::configure(
					(string) ( $_POST['temp_pass'] ?? '' ),
					sanitize_text_field( wp_unslash( $_POST['temp_until'] ?? '' ) ),
					(int) ( $_POST['temp_max'] ?? 5 ),
					(int) ( $_POST['temp_user'] ?? 0 ),
					(int) ( $_POST['temp_minutes'] ?? 15 )
				);
				$notice = [ 'ok', __( 'Acceso temporal actualizado.', 'cead-acad' ) ];
			} elseif ( $action === 'temp_revoke' ) {
				Cead_Acad_WA_Temp_Access::revoke();
				$notice = [ 'ok', __( 'Acceso temporal desactivado.', 'cead-acad' ) ];
			} elseif ( $action === 'pair' ) {
				$phone = preg_replace( '/\D/', '', (string) ( $_POST['pair_phone'] ?? '' ) );
				if ( strlen( $phone ) < 8 ) {
					$notice = [ 'err', __( 'Escribí el número completo con código de país, por ejemplo 595981123456.', 'cead-acad' ) ];
				} else {
					$res = $this->bridge->pair( $phone );
					if ( isset( $res['error'] ) ) {
						$notice = [ 'err', $res['error'] ];
					} elseif ( ! empty( $res['pairing_code'] ) ) {
						$pairing_code = (string) $res['pairing_code'];
						$notice = [ 'ok', __( 'Código generado. Ingresalo en el celular.', 'cead-acad' ) ];
					} else {
						$notice = [ 'err', __( 'WhatsApp todavía no devolvió el código. Esperá unos segundos y tocá «Refrescar estado».', 'cead-acad' ) ];
					}
				}
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
					// Si hay una vinculación por número en curso, el código sigue vivo.
					if ( ! empty( $res['pairing_code'] ) ) {
						$pairing_code = (string) $res['pairing_code'];
					}
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
		// QR en vivo: mientras el bot esté desconectado, el navegador le pide el
		// QR al bridge cada pocos segundos. El de WhatsApp vence a los ~20s, así
		// que sin esto casi siempre se veía uno ya vencido.
		if ( ! $connected ) {
			$initial_qr = ( ! empty( $s->qr_data ) && str_starts_with( (string) $s->qr_data, 'data:image/' ) )
				? (string) $s->qr_data
				: '';
			echo '<div id="cead-wa-qr-box" style="margin-top:12px">';
			echo '<p style="margin:0 0 8px">' . esc_html__( 'Escaneá este QR desde WhatsApp → ⋮ → Dispositivos vinculados → Vincular dispositivo:', 'cead-acad' ) . '</p>';
			echo '<img id="cead-wa-qr-img" src="' . esc_attr( $initial_qr ) . '" alt="' . esc_attr__( 'Código QR de vinculación', 'cead-acad' ) . '"';
			echo ' style="width:260px;height:260px;border:1px solid #ddd;background:#fff;' . ( $initial_qr === '' ? 'display:none' : '' ) . '" />';
			echo '<p id="cead-wa-qr-msg" class="description" style="margin:8px 0 0">'
				. esc_html__( 'Buscando el código…', 'cead-acad' ) . '</p>';
			echo '</div>';

			$cfg = wp_json_encode( [
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cead_acad_wa_qr' ),
				'i18n'  => [
					'waiting'   => __( 'Buscando el código…', 'cead-acad' ),
					'scan'      => __( 'Se renueva solo cada pocos segundos. Escanealo y listo.', 'cead-acad' ),
					'connected' => __( '✅ ¡Conectado! Actualizando la página…', 'cead-acad' ),
					'error'     => __( 'No pude hablar con el bridge. Revisá la URL y el token.', 'cead-acad' ),
				],
			] );
			?>
			<script>
			// Sin jQuery a propósito: wp_enqueue_script('jquery') llamado acá (en medio
			// del render de la página) queda para el footer, después de este bloque —
			// depender de $ acá rompía en silencio (jQuery is not defined) y el QR
			// nunca se llegaba a pedir.
			( function () {
				var cfg  = <?php echo $cfg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode. ?>;
				var img  = document.getElementById( 'cead-wa-qr-img' );
				var msg  = document.getElementById( 'cead-wa-qr-msg' );
				var busy = false;

				function poll() {
					if ( busy || document.hidden ) { return; }
					busy = true;
					var body = new URLSearchParams( { action: 'cead_acad_wa_qr', _ajax_nonce: cfg.nonce } );
					fetch( cfg.url, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) {
								msg.textContent = ( res && res.data && res.data.message ) || cfg.i18n.error;
								return;
							}
							if ( res.data.connected ) {
								msg.textContent = cfg.i18n.connected;
								clearInterval( timer );
								setTimeout( function () { window.location.reload(); }, 1200 );
								return;
							}
							if ( res.data.qr ) {
								img.src = res.data.qr;
								img.style.display = '';
								msg.textContent = cfg.i18n.scan;
							} else {
								msg.textContent = cfg.i18n.waiting;
							}
						} )
						.catch( function () { msg.textContent = cfg.i18n.error; } )
						.finally( function () { busy = false; } );
				}

				var timer = setInterval( poll, 4000 );
				poll();
			} )();
			</script>
			<?php
		}
		echo '<p style="margin-top:12px">';
		$this->inline_button( 'refresh', __( 'Refrescar estado', 'cead-acad' ) );
		$this->inline_button( 'restart', __( 'Reiniciar bridge', 'cead-acad' ) );
		$this->inline_button( 'logout', __( 'Cerrar sesión', 'cead-acad' ), 'delete' );
		echo '</p>';

		// Vinculación por número: la salida cuando el QR no funciona.
		if ( ! $connected ) {
			echo '<hr><h3 style="margin-bottom:4px">' . esc_html__( '¿El QR no funciona? Vinculá con el número', 'cead-acad' ) . '</h3>';
			if ( $pairing_code ) {
				echo '<div class="notice notice-success inline" style="padding:10px 14px">';
				echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'Código de vinculación', 'cead-acad' ) . '</strong></p>';
				echo '<p style="margin:0 0 8px;font-size:30px;letter-spacing:5px;font-family:monospace"><strong>' . esc_html( $pairing_code ) . '</strong></p>';
				echo '<p style="margin:0">' . esc_html__( 'En el celular del bot: WhatsApp → ⋮ → Dispositivos vinculados → Vincular con número de teléfono. Vence en pocos minutos.', 'cead-acad' ) . '</p>';
				echo '</div>';
			}
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( 'cead_acad_wa_status' );
			echo '<input type="hidden" name="cead_acad_wa_action" value="pair" />';
			echo '<input type="text" name="pair_phone" class="regular-text" inputmode="numeric" placeholder="595981123456" value="' . esc_attr( (string) get_option( 'cead_acad_wa_bot_number', '' ) ) . '" /> ';
			echo '<button class="button">' . esc_html__( 'Pedir código', 'cead-acad' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Número del celular donde corre el bot, con código de país y sin +. Puede tardar unos segundos.', 'cead-acad' ) . '</p>';
			echo '</form>';
		}
		echo '</div>';

		// ---- Acceso temporal (clave de un día) ----
		$ta_active = Cead_Acad_WA_Temp_Access::active();
		echo '<div class="card" style="max-width:720px"><h2>' . esc_html__( 'Acceso temporal al bot', 'cead-acad' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Clave para que alguien opere CEADI durante una jornada puntual sin tener su número cargado. Se guarda cifrada, vence sola y se puede cortar en cualquier momento.', 'cead-acad' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Estado', 'cead-acad' ) . ':</strong> '
			. ( $ta_active ? '🟢 ' : '⚪ ' ) . esc_html( Cead_Acad_WA_Temp_Access::status_label() ) . '</p>';

		$dir_users = get_users( [
			'role__in' => [ 'cead_acad_direction', 'cead_acad_secretary', 'administrator' ],
			'orderby'  => 'display_name',
			'number'   => 100,
		] );

		echo '<form method="post">';
		wp_nonce_field( 'cead_acad_wa_status' );
		echo '<input type="hidden" name="cead_acad_wa_action" value="temp_access" />';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="temp_pass">' . esc_html__( 'Clave', 'cead-acad' ) . '</label></th><td>';
		echo '<input type="text" name="temp_pass" id="temp_pass" class="regular-text" autocomplete="off" placeholder="' . esc_attr__( '(dejar vacío para no cambiarla)', 'cead-acad' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Se guarda cifrada: no queda escrita en ningún lado ni se puede volver a ver. Al cambiarla se reinicia el contador de usos.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th><label for="temp_until">' . esc_html__( 'Sirve hasta', 'cead-acad' ) . '</label></th><td>';
		echo '<input type="datetime-local" name="temp_until" id="temp_until" value="' . esc_attr( str_replace( ' ', 'T', substr( Cead_Acad_WA_Temp_Access::until(), 0, 16 ) ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Pasada esa fecha y hora deja de funcionar sola.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th><label for="temp_max">' . esc_html__( 'Usos totales', 'cead-acad' ) . '</label></th><td>';
		echo '<input type="number" name="temp_max" id="temp_max" min="1" max="100" class="small-text" value="' . esc_attr( (string) Cead_Acad_WA_Temp_Access::max_uses() ) . '" /> ';
		echo '<span class="description">' . esc_html( sprintf( /* translators: %d: usos ya consumidos */ __( 'usados hasta ahora: %d', 'cead-acad' ), Cead_Acad_WA_Temp_Access::used() ) ) . '</span>';
		echo '</td></tr>';
		echo '<tr><th><label for="temp_minutes">' . esc_html__( 'Duración de cada sesión', 'cead-acad' ) . '</label></th><td>';
		echo '<input type="number" name="temp_minutes" id="temp_minutes" min="1" max="240" class="small-text" value="' . esc_attr( (string) Cead_Acad_WA_Temp_Access::minutes() ) . '" /> ' . esc_html__( 'minutos', 'cead-acad' );
		echo '</td></tr>';
		echo '<tr><th><label for="temp_user">' . esc_html__( 'Opera como', 'cead-acad' ) . '</label></th><td>';
		echo '<select name="temp_user" id="temp_user"><option value="0">' . esc_html__( '— elegir —', 'cead-acad' ) . '</option>';
		foreach ( $dir_users as $u ) {
			echo '<option value="' . (int) $u->ID . '" ' . selected( Cead_Acad_WA_Temp_Access::user_id(), $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Quien use la clave actúa con los permisos de esta persona, y todo lo que haga queda registrado a su nombre. No se crean permisos nuevos.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';
		submit_button( __( 'Guardar acceso temporal', 'cead-acad' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'' . esc_js( __( '¿Desactivar la clave y cortar las sesiones abiertas?', 'cead-acad' ) ) . '\');">';
		wp_nonce_field( 'cead_acad_wa_status' );
		echo '<input type="hidden" name="cead_acad_wa_action" value="temp_revoke" />';
		echo '<button class="button button-link-delete">' . esc_html__( 'Desactivar ahora', 'cead-acad' ) . '</button>';
		echo '</form>';
		echo '</div>';

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

			// Publicación en redes sociales: gateada por número, no por rol.
			echo '<tr><th scope="row">' . esc_html__( 'Publicar en redes', 'cead-acad' ) . '</th><td>';
			echo '<p><label>' . esc_html__( 'Número autorizado (Dirección)', 'cead-acad' ) . '<br>';
			echo '<input type="text" name="director_phone" class="regular-text" inputmode="numeric" placeholder="595981123456" value="'
				. esc_attr( (string) get_option( 'cead_acad_wa_director_phone', '' ) ) . '"></label></p>';
			echo '<p class="description">' . esc_html__( 'Solo este número puede pedirle a CEADI que un artículo se replique en las redes sociales del colegio. Dejalo vacío para desactivar la función por completo. Tener permisos de artículos NO alcanza: se chequea el número.', 'cead-acad' ) . '</p>';
			echo '<p><label>' . esc_html__( 'Categoría que replica a redes', 'cead-acad' ) . '<br>';
			echo '<input type="text" name="social_category" class="regular-text" placeholder="redes-sociales" value="'
				. esc_attr( (string) get_option( 'cead_acad_wa_social_category', 'redes-sociales' ) ) . '"></label></p>';
			echo '<p class="description">' . esc_html__( 'Slug de la categoría que el plugin Bit Social tiene configurada para auto-publicar. Al aprobar un artículo «con redes», se le asigna esta categoría y Bit Social se encarga del resto. Si la categoría no existe, se crea sola.', 'cead-acad' ) . '</p>';
			echo '</td></tr>';

			// Instagram → borrador de nota.
			$ig_modo = Cead_Acad_WA_Instagram::modo();
			echo '<tr><th scope="row">' . esc_html__( 'Instagram → borrador', 'cead-acad' ) . '</th><td>';
			echo '<p><label>' . esc_html__( 'Origen de las publicaciones', 'cead-acad' ) . '<br><select name="ig_modo">';
			foreach ( [
				'off'       => __( 'Desactivado', 'cead-acad' ),
				'graph'     => __( 'API oficial de Instagram (necesita acceso a la cuenta)', 'cead-acad' ),
				'proveedor' => __( 'Servicio externo (JSON)', 'cead-acad' ),
			] as $val => $label ) {
				echo '<option value="' . esc_attr( $val ) . '" ' . selected( $ig_modo, $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p class="description">' . esc_html__(
				'Cada media hora revisa la cuenta del colegio; cuando hay algo nuevo, CEADI redacta un borrador de nota a partir del pie de foto y te lo manda al número de Dirección de arriba. El borrador NO se publica solo: lo leés, lo corregís si hace falta y lo subís vos.',
				'cead-acad'
			) . '</p>';
			echo '<p class="description"><strong>' . esc_html__( 'Sobre de dónde salen los datos:', 'cead-acad' ) . '</strong> ' . esc_html__(
				'Instagram no deja leer una cuenta sin autenticación, así que no hay forma de rastrearla "gratis y sin permisos" que se mantenga andando: los endpoints públicos se cerraron y raspar la web termina en bloqueos. La opción confiable es la API oficial, que pide acceso a la cuenta del colegio. Mientras tanto se puede usar un servicio externo que haga esa lectura (varios son pagos). Con "Desactivado" no se consulta nada.',
				'cead-acad'
			) . '</p>';
			$this->field( 'ig_usuario', __( 'Cuenta de Instagram', 'cead-acad' ), Cead_Acad_WA_Instagram::usuario(), 'cead_felix_de_guarania' );
			if ( 'graph' === $ig_modo ) {
				$this->field( 'ig_cuenta_id', __( 'ID de la cuenta (Graph)', 'cead-acad' ), get_option( 'cead_acad_wa_ig_cuenta_id', '' ), '1784...' );
				$this->field( 'ig_token', __( 'Token de acceso', 'cead-acad' ), get_option( 'cead_acad_wa_ig_token', '' ), '', 'password' );
			} elseif ( 'proveedor' === $ig_modo ) {
				$this->field( 'ig_proveedor_url', __( 'URL del servicio', 'cead-acad' ), get_option( 'cead_acad_wa_ig_proveedor_url', '' ), 'https://…/posts?user={usuario}' );
				echo '<tr><th></th><td><p class="description">' . esc_html__( 'Se reemplaza {usuario} por la cuenta de arriba. Tiene que devolver JSON con una lista de publicaciones (id, caption, url).', 'cead-acad' ) . '</p></td></tr>';
				$this->field( 'ig_proveedor_key', __( 'Clave del servicio', 'cead-acad' ), get_option( 'cead_acad_wa_ig_proveedor_key', '' ), '', 'password' );
			}
			$ig_err = Cead_Acad_WA_Instagram::ultimo_error();
			if ( '' !== $ig_err ) {
				echo '<p class="description" style="color:#b32d2e"><strong>' . esc_html__( 'Último error:', 'cead-acad' ) . '</strong> ' . esc_html( $ig_err ) . '</p>';
			}
			echo '</td></tr>';

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
			update_option( 'cead_acad_wa_ai_endpoint_is_base', ! empty( $_POST['ai_endpoint_is_base'] ) ? 1 : 0, false );
			update_option( 'cead_acad_wa_ai_temp', is_numeric( $_POST['ai_temp'] ?? '' ) ? (float) $_POST['ai_temp'] : 0.2, false );
			$razona_in = sanitize_key( wp_unslash( $_POST['ai_reasoning'] ?? '' ) );
			update_option( 'cead_acad_wa_ai_reasoning', in_array( $razona_in, [ 'low', 'medium', 'high' ], true ) ? $razona_in : '', false );
			update_option( 'cead_acad_wa_ai_maxtokens', max( 50, (int) ( $_POST['ai_maxtokens'] ?? 500 ) ), false );
			update_option( 'cead_acad_wa_ai_prompt', sanitize_textarea_field( wp_unslash( $_POST['ai_prompt'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_ai_knowledge', sanitize_textarea_field( wp_unslash( $_POST['ai_knowledge'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_ai_memory', max( 0, min( 20, (int) ( $_POST['ai_memory'] ?? 4 ) ) ), false );
			update_option( 'cead_acad_wa_ai_context_budget', max( 4000, min( 120000, (int) ( $_POST['ai_context_budget'] ?? 22000 ) ) ), false );
			$dm = sanitize_key( wp_unslash( $_POST['ai_default_mode'] ?? 'auto' ) );
			update_option( 'cead_acad_wa_default_mode', in_array( $dm, [ 'auto', 'ia', 'menu' ], true ) ? $dm : 'auto', false );

			// Transcripción de notas de voz (speech-to-text), endpoint aparte.
			update_option( 'cead_acad_wa_stt_enabled', ! empty( $_POST['stt_enabled'] ) ? 1 : 0, false );
			update_option( 'cead_acad_wa_stt_endpoint', esc_url_raw( wp_unslash( $_POST['stt_endpoint'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_stt_model', sanitize_text_field( wp_unslash( $_POST['stt_model'] ?? '' ) ), false );
			update_option( 'cead_acad_wa_stt_key', sanitize_text_field( wp_unslash( $_POST['stt_key'] ?? '' ) ), false );

			// Lectura de imágenes (visión): usa la misma key y endpoint de la IA;
			// solo puede necesitar otro modelo si el de texto no mira imágenes.
			update_option( 'cead_acad_wa_vision_enabled', ! empty( $_POST['vision_enabled'] ) ? 1 : 0, false );
			update_option( 'cead_acad_wa_vision_model', sanitize_text_field( wp_unslash( $_POST['vision_model'] ?? '' ) ), false );

			// Lectura de documentos: el texto se extrae en WordPress, así que no
			// necesita ni key ni modelo aparte.
			update_option( 'cead_acad_wa_docs_enabled', ! empty( $_POST['docs_enabled'] ) ? 1 : 0, false );

			if ( $action === 'ai_test' ) {
				$msg = sanitize_text_field( wp_unslash( $_POST['ai_test_message'] ?? '' ) ) ?: '¿qué clases tengo hoy?';
				$t   = Cead_Acad_WA_AI::test( $msg );
				$notice = [ $t['ok'] ? 'ok' : 'err', __( 'Prueba IA', 'cead-acad' ) . ': ' . $t['summary'] ];
			} elseif ( $action === 'stt_test' ) {
				$t      = Cead_Acad_WA_AI::stt_test();
				$notice = [ $t['ok'] ? 'ok' : 'err', __( 'Prueba STT', 'cead-acad' ) . ': ' . $t['summary'] ];
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

		// Consumo real de la última llamada. Es la única forma de saber si este
		// proveedor cachea el prefijo del prompt: con caché, el bloque grande de
		// contexto sale mucho más barato de lo que sugiere su tamaño.
		$usage = Cead_Acad_WA_AI::last_usage();
		if ( $usage ) {
			echo '<div class="card" style="max-width:760px"><h2>' . esc_html__( '📊 Última llamada', 'cead-acad' ) . '</h2>';
			echo '<p>' . sprintf(
				/* translators: 1: tokens de entrada, 2: tokens de salida, 3: fecha y hora */
				esc_html__( 'Entrada: %1$s tokens · Salida: %2$s tokens · %3$s', 'cead-acad' ),
				'<strong>' . esc_html( number_format_i18n( $usage['prompt'] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $usage['completion'] ) ) . '</strong>',
				esc_html( $usage['time'] )
			) . '</p>';

			if ( null === $usage['cached'] ) {
				echo '<p>⚪ ' . esc_html__( 'Este proveedor no informa nada sobre caché de contexto. Puede que no la tenga, o que no la reporte: en cualquier caso, conviene asumir que cada mensaje paga el contexto entero y mantener el presupuesto ajustado.', 'cead-acad' ) . '</p>';
			} elseif ( $usage['cached'] > 0 ) {
				$pct = $usage['prompt'] > 0 ? round( $usage['cached'] * 100 / $usage['prompt'] ) : 0;
				echo '<p>🟢 ' . sprintf(
					/* translators: 1: tokens desde caché, 2: porcentaje */
					esc_html__( 'Caché activa: %1$s tokens (%2$d%%) de la entrada vinieron cacheados y se cobran mucho más barato. Podés permitirte un presupuesto de contexto más alto.', 'cead-acad' ),
					'<strong>' . esc_html( number_format_i18n( $usage['cached'] ) ) . '</strong>',
					(int) $pct
				) . '</p>';
			} else {
				echo '<p>🟡 ' . esc_html__( 'El proveedor informa caché, pero esta llamada no aprovechó nada. Es normal en la primera de una tanda: el prefijo recién queda cacheado. Si se mantiene en cero después de varios mensajes seguidos, no está funcionando y conviene bajar el presupuesto.', 'cead-acad' ) . '</p>';
			}
			echo '</div>';
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
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Ej.: DeepSeek https://api.deepseek.com/chat/completions · OpenRouter https://openrouter.ai/api/v1/chat/completions · tokenlb https://tokenlb.net/v1/chat/completions · OpenAI https://api.openai.com/v1/chat/completions', 'cead-acad' ) . '</p>';
		echo '<label><input type="checkbox" name="ai_endpoint_is_base" value="1" ' . checked( Cead_Acad_WA_AI::endpoint_is_base(), true, false ) . '> '
			. esc_html__( 'Lo de arriba es una base, no el endpoint completo', 'cead-acad' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Algunos proveedores no aceptan que se les pegue directo a /chat/completions y devuelven 405 si se manda la ruta completa: quieren solo la base (ej.: https://mi-servicio.com o https://mi-servicio.com/v1). Activá esto y cargá arriba la base sola — CEADI le agrega /chat/completions antes de cada pedido.', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_model', __( 'Modelo', 'cead-acad' ), get_option( 'cead_acad_wa_ai_model', 'deepseek-chat' ), 'deepseek-chat' );
		$this->field( 'ai_key', __( 'API key', 'cead-acad' ), get_option( 'cead_acad_wa_ai_key', '' ), 'sk-... (o definir CEAD_ACAD_AI_KEY en wp-config)', 'password' );
		if ( Cead_Acad_WA_AI::key() !== '' && defined( 'CEAD_ACAD_AI_KEY' ) && CEAD_ACAD_AI_KEY ) {
			echo '<tr><th></th><td><p class="description">' . esc_html__( 'La API key está fijada por constante en wp-config.php (CEAD_ACAD_AI_KEY); este campo se ignora.', 'cead-acad' ) . '</p></td></tr>';
		}
		$this->field( 'ai_temp', __( 'Temperatura (0–1)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_temp', '0.5' ), '0.5', 'text' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( '0.5 (recomendado) = respuestas naturales y con criterio. Más bajo = más rígido; más alto = más creativo.', 'cead-acad' ) . '</p></td></tr>';
		$razona = Cead_Acad_WA_AI::reasoning();
		echo '<tr><th scope="row"><label for="ai_reasoning">' . esc_html__( 'Razonamiento', 'cead-acad' ) . '</label></th><td>';
		echo '<select name="ai_reasoning" id="ai_reasoning">';
		foreach ( [
			''       => __( 'Desactivado', 'cead-acad' ),
			'low'    => __( 'Bajo', 'cead-acad' ),
			'medium' => __( 'Medio', 'cead-acad' ),
			'high'   => __( 'Alto', 'cead-acad' ),
		] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $razona, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Hace que el modelo piense antes de contestar: mejora bastante cuando tiene que encadenar consultas o decidir entre varias opciones. Dejalo desactivado si no estás seguro — es un parámetro que varios proveedores todavía no aceptan, y ahí devuelven error en TODOS los mensajes. Si al prenderlo CEADI deja de responder, volvé a "Desactivado". También hace que tarde más.', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_maxtokens', __( 'Máx. tokens de respuesta', 'cead-acad' ), get_option( 'cead_acad_wa_ai_maxtokens', 800 ), '800', 'number' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Cuánto puede escribir CEADI de una. 800 alcanza para responder consultas; para que escriba artículos largos conviene 4000–8000. Ojo: cada modelo tiene su techo (DeepSeek corta en 8192) — si ponés más del que acepta, se reintenta solo con 8192 en vez de fallar. Valores altos también hacen que tarde más en contestar.', 'cead-acad' ) . '</p></td></tr>';
		/*
		 * El campo muestra SOLO lo que hay guardado, y la personalidad por
		 * defecto va de placeholder.
		 *
		 * Antes se pre-cargaba el texto por defecto adentro del textarea. Se veía
		 * bien, pero tenía una consecuencia que no se veía: bastaba entrar a esta
		 * pantalla y tocar «Guardar» —aunque fuera para cambiar otra cosa— para
		 * que esas dos mil palabras quedaran COPIADAS en la opción. Desde ese
		 * momento la personalidad quedaba congelada en la versión de ese día, y
		 * cualquier mejora que trajera una actualización del plugin no llegaba
		 * nunca. Vacío significa «usá la que trae el plugin, y que se actualice
		 * con él».
		 */
		$prompt_guardado = (string) get_option( 'cead_acad_wa_ai_prompt', '' );
		$this->field_textarea( 'ai_prompt', __( 'System prompt (personalidad / instrucciones)', 'cead-acad' ), $prompt_guardado, 6 );
		echo '<tr><th></th><td><p class="description">'
			. esc_html__( 'Definí el tono y rol de CEADI. El formato de salida (ruteo en JSON) se agrega automáticamente, no hace falta escribirlo.', 'cead-acad' )
			. ' <strong>' . esc_html__( 'Vacío = se usa la personalidad que trae el plugin, y mejora con cada actualización.', 'cead-acad' ) . '</strong>'
			. '</p>';
		if ( '' !== trim( $prompt_guardado ) ) {
			echo '<p class="description" style="color:#8a6d00">'
				. esc_html__( 'Ahora mismo hay una personalidad propia guardada, así que la del plugin NO se usa. Si no la escribiste a propósito, lo más probable es que se haya copiado sola al guardar esta pantalla: vaciá el campo y guardá para volver a la del plugin.', 'cead-acad' )
				. '</p>';
		}
		echo '<details><summary>' . esc_html__( 'Ver la personalidad por defecto', 'cead-acad' ) . '</summary>'
			. '<textarea readonly rows="10" class="large-text code">' . esc_textarea( Cead_Acad_WA_AI::default_persona() ) . '</textarea></details>';
		echo '</td></tr>';
		$this->field_textarea( 'ai_knowledge', __( 'Conocimiento (base de datos para responder)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_knowledge', '' ), 8 );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Texto libre con info del colegio (horarios generales, reglas, contactos, fechas, etc.). La IA lo usa para responder dudas. Las FAQ del panel se suman a esto.', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_context_budget', __( 'Presupuesto de contexto (caracteres)', 'cead-acad' ), Cead_Acad_WA_AI::context_budget(), '22000', 'number' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Techo de todo el prompt de sistema (personalidad + conocimiento + memoria + noticias + FAQ + identidad). Se paga en CADA mensaje, no una vez. Si no entra todo, se recorta por orden de importancia: primero las noticias (el modelo las puede buscar igual), después la FAQ, después el conocimiento; la memoria y la identidad no se recortan nunca. Cada recorte queda en el log de errores.', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'ai_memory', __( 'Memoria (turnos a recordar)', 'cead-acad' ), get_option( 'cead_acad_wa_ai_memory', 4 ), '4', 'number' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( '0 = sin memoria (cada mensaje es independiente). 4 (recomendado) = recuerda los últimos 4 intercambios de esa persona, por 30 minutos, para que las charlas fluyan. Más memoria usa más tokens.', 'cead-acad' ) . '</p></td></tr>';

		// --- Transcripción de notas de voz (speech-to-text) ---
		$stt_on      = (bool) get_option( 'cead_acad_wa_stt_enabled', 0 );
		$stt_has_key = Cead_Acad_WA_AI::stt_key() !== '';
		$stt_err     = Cead_Acad_WA_AI::stt_last_error();
		echo '<tr><th scope="row" colspan="2"><hr><h2 style="margin:0">' . esc_html__( '🎤 Notas de voz (transcripción)', 'cead-acad' ) . '</h2></th></tr>';
		// Banner de estado de STT.
		echo '<tr><td colspan="2">';
		if ( $stt_on && $stt_has_key ) {
			echo '<div class="notice notice-success inline"><p>🟢 ' . esc_html__( 'STT activo: las notas de voz se transcriben automáticamente y el bot las procesa como texto.', 'cead-acad' ) . '</p></div>';
			if ( $stt_err ) {
				echo '<div class="notice notice-warning inline"><p>⚠️ ' . sprintf(
					/* translators: 1: fecha/hora, 2: error */
					esc_html__( 'Último error de transcripción (%1$s): %2$s — Usá «Probar STT» para diagnosticar.', 'cead-acad' ),
					esc_html( (string) $stt_err['time'] ),
					esc_html( (string) $stt_err['error'] )
				) . '</p></div>';
			}
		} elseif ( $stt_on && ! $stt_has_key ) {
			echo '<div class="notice notice-warning inline"><p>⚠️ ' . esc_html__( 'STT activado pero falta la API key (configurala abajo o usará la de la IA si está cargada).', 'cead-acad' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info inline"><p>🔴 ' . esc_html__( 'STT desactivado: si la gente manda notas de voz, CEADI les pide que escriban el mensaje.', 'cead-acad' ) . '</p></div>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Activar', 'cead-acad' ) . '</th><td>';
		echo '<label><input type="checkbox" name="stt_enabled" value="1" ' . checked( $stt_on, true, false ) . '> ' . esc_html__( 'Transcribir las notas de voz que mande la gente y procesarlas como texto', 'cead-acad' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Necesita un endpoint compatible con OpenAI /audio/transcriptions (ej.: Whisper). Si está apagado o falla, CEADI pide que escriban el mensaje.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		$this->field( 'stt_endpoint', __( 'Endpoint de transcripción', 'cead-acad' ), get_option( 'cead_acad_wa_stt_endpoint', '' ), 'https://api.openai.com/v1/audio/transcriptions', 'url' );
		echo '<tr><th></th><td><p class="description">⚠️ ' . esc_html__( 'OJO: este endpoint NO es el mismo que el de la IA (chat). Tiene que ser uno de transcripción de audio, terminado en /audio/transcriptions (Whisper-compatible). Ej.: OpenAI https://api.openai.com/v1/audio/transcriptions o Groq https://api.groq.com/openai/v1/audio/transcriptions. Si ponés acá el endpoint de chat, vas a ver un error tipo «failed to unmarshal JSON».', 'cead-acad' ) . '</p></td></tr>';
		$this->field( 'stt_model', __( 'Modelo de transcripción', 'cead-acad' ), get_option( 'cead_acad_wa_stt_model', '' ), 'whisper-1' );
		$this->field( 'stt_key', __( 'API key de transcripción', 'cead-acad' ), get_option( 'cead_acad_wa_stt_key', '' ), __( 'vacío = reusar la API key de la IA (o CEAD_ACAD_STT_KEY en wp-config)', 'cead-acad' ), 'password' );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Si lo dejás vacío, usa la misma API key de la IA. Ponelo solo si el proveedor de transcripción es distinto.', 'cead-acad' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Probar STT', 'cead-acad' ) . '</th><td>';
		echo '<button type="submit" class="button" name="cead_acad_wa_ai_action" value="stt_test">' . esc_html__( 'Guardar y probar transcripción', 'cead-acad' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Guarda la configuración y prueba si la API key y el endpoint de transcripción responden correctamente (sin necesitar una nota de voz real).', 'cead-acad' ) . '</p></td></tr>';

		// --- Lectura de imágenes (visión) ---
		$vis_on  = (bool) get_option( 'cead_acad_wa_vision_enabled', 0 );
		$vis_key = Cead_Acad_WA_AI::key() !== '';
		echo '<tr><th scope="row" colspan="2"><hr><h2 style="margin:0">' . esc_html__( '🖼️ Imágenes (lectura)', 'cead-acad' ) . '</h2></th></tr>';
		echo '<tr><td colspan="2">';
		if ( $vis_on && $vis_key ) {
			echo '<div class="notice notice-success inline"><p>🟢 ' . esc_html__( 'Lectura de imágenes activa: si mandan una foto, CEADI la mira y responde sobre lo que ve.', 'cead-acad' ) . '</p></div>';
		} elseif ( $vis_on && ! $vis_key ) {
			echo '<div class="notice notice-warning inline"><p>⚠️ ' . esc_html__( 'Lectura de imágenes activada pero falta la API key de la IA (cargala más arriba).', 'cead-acad' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info inline"><p>🔴 ' . esc_html__( 'Lectura de imágenes desactivada: las fotos solo sirven como adjunto (comunicado, artículo); CEADI no las mira.', 'cead-acad' ) . '</p></div>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Activar', 'cead-acad' ) . '</th><td>';
		echo '<label><input type="checkbox" name="vision_enabled" value="1" ' . checked( $vis_on, true, false ) . '> ' . esc_html__( 'Que CEADI pueda mirar las fotos que le manden y responder sobre ellas', 'cead-acad' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Usa la misma API key y el mismo endpoint de la IA. El modelo tiene que aceptar imágenes (por ejemplo gpt-4o); si el de texto no las acepta, poné uno que sí abajo. Las fotos de más de 4 MB no se mandan.', 'cead-acad' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Adjuntar la foto a un comunicado o artículo sigue funcionando igual, esté esto encendido o apagado.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';
		$this->field( 'vision_model', __( 'Modelo con visión', 'cead-acad' ), get_option( 'cead_acad_wa_vision_model', '' ), __( 'vacío = usar el mismo modelo de la IA', 'cead-acad' ) );
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'Solo hace falta si el modelo de la IA no mira imágenes. Ej.: gpt-4o, gpt-4o-mini.', 'cead-acad' ) . '</p></td></tr>';

		// --- Lectura de documentos (PDF, Word) ---
		$docs_on  = (bool) get_option( 'cead_acad_wa_docs_enabled', 0 );
		$docs_zip = class_exists( 'ZipArchive' );
		echo '<tr><th scope="row" colspan="2"><hr><h2 style="margin:0">' . esc_html__( '📄 Documentos (lectura)', 'cead-acad' ) . '</h2></th></tr>';
		echo '<tr><td colspan="2">';
		if ( $docs_on ) {
			echo '<div class="notice notice-success inline"><p>🟢 ' . esc_html__( 'Lectura de documentos activa: si mandan un PDF o un Word, CEADI lee el texto y responde sobre el contenido.', 'cead-acad' ) . '</p></div>';
			if ( ! $docs_zip ) {
				echo '<div class="notice notice-warning inline"><p>⚠️ ' . esc_html__( 'Falta la extensión ZipArchive de PHP: los PDF se leen igual, pero los .docx y .odt no. Pedile al hosting que active php-zip.', 'cead-acad' ) . '</p></div>';
			}
		} else {
			echo '<div class="notice notice-info inline"><p>🔴 ' . esc_html__( 'Lectura de documentos desactivada: los PDF y Word que manden se ignoran.', 'cead-acad' ) . '</p></div>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Activar', 'cead-acad' ) . '</th><td>';
		echo '<label><input type="checkbox" name="docs_enabled" value="1" ' . checked( $docs_on, true, false ) . '> ' . esc_html__( 'Que CEADI lea los PDF y Word que le manden (circulares, resoluciones, notas)', 'cead-acad' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'El texto se extrae acá, en el servidor: no se manda el archivo a la IA ni queda guardado. No consume API key aparte.', 'cead-acad' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Formatos: PDF, .docx, .odt, .txt. Un PDF ESCANEADO (una foto adentro de un PDF) no tiene texto que leer: CEADI avisa y pide que manden la foto directo, que sí puede mirar si la lectura de imágenes está activa.', 'cead-acad' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Las planillas de notas (.xlsx) tienen su propio camino y no dependen de esta opción.', 'cead-acad' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Probar IA', 'cead-acad' ) . '</th><td>';
		echo '<input type="text" name="ai_test_message" class="regular-text" placeholder="' . esc_attr__( '¿qué clases tengo hoy?', 'cead-acad' ) . '">';
		echo ' <button type="submit" class="button" name="cead_acad_wa_ai_action" value="ai_test">' . esc_html__( 'Guardar y probar IA', 'cead-acad' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Guarda la configuración y hace una consulta de prueba a la API de IA; el resultado aparece arriba.', 'cead-acad' ) . '</p></td></tr>';

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

	// -------------------------------------------------- CEADI · Memoria
	/**
	 * Lo que CEADI aprendió por chat, acá para verlo de una y corregirlo sin
	 * tener que pedírselo por WhatsApp. Es la misma memoria: se guarda en la
	 * misma option y se audita igual que cuando la dicta la dirección.
	 */
	public function page_memory() {
		$this->guard( 'manage_options' );
		$notice = null;

		if ( isset( $_POST['cead_acad_wa_mem_action'] ) && check_admin_referer( 'cead_acad_wa_mem' ) ) {
			$action = sanitize_key( wp_unslash( $_POST['cead_acad_wa_mem_action'] ) );
			$uid    = get_current_user_id();

			if ( 'add' === $action ) {
				$texto = sanitize_textarea_field( wp_unslash( $_POST['mem_text'] ?? '' ) );
				$r     = Cead_Acad_WA_Memory::add( $texto, $uid );
				if ( is_wp_error( $r ) ) {
					$notice = [ 'error', $r->get_error_message() ];
				} else {
					Cead_Acad_Audit::log( 'wa_ai_memory_added', [
						'user_id' => $uid ?: null,
						'payload' => [ 'texto' => $texto, 'via' => 'admin' ],
					] );
					$notice = [ 'success', __( 'Memoria agregada.', 'cead-acad' ) ];
				}
			} elseif ( 'edit' === $action ) {
				$id    = sanitize_text_field( wp_unslash( $_POST['mem_id'] ?? '' ) );
				$texto = sanitize_textarea_field( wp_unslash( $_POST['mem_text'] ?? '' ) );
				$r     = Cead_Acad_WA_Memory::update( $id, $texto );
				if ( is_wp_error( $r ) ) {
					$notice = [ 'error', $r->get_error_message() ];
				} else {
					Cead_Acad_Audit::log( 'wa_ai_memory_edited', [
						'user_id' => $uid ?: null,
						'payload' => [ 'texto' => $r, 'via' => 'admin' ],
					] );
					$notice = [ 'success', __( 'Memoria actualizada.', 'cead-acad' ) ];
				}
			} elseif ( 'delete' === $action ) {
				$id = sanitize_text_field( wp_unslash( $_POST['mem_id'] ?? '' ) );
				$r  = Cead_Acad_WA_Memory::remove( $id );
				if ( is_wp_error( $r ) ) {
					$notice = [ 'error', $r->get_error_message() ];
				} else {
					Cead_Acad_Audit::log( 'wa_ai_memory_removed', [
						'user_id' => $uid ?: null,
						'payload' => [ 'texto' => $r, 'via' => 'admin' ],
					] );
					$notice = [ 'success', __( 'Memoria borrada.', 'cead-acad' ) ];
				}
			} elseif ( 'clear' === $action ) {
				$n = Cead_Acad_WA_Memory::clear();
				Cead_Acad_Audit::log( 'wa_ai_memory_cleared', [
					'user_id' => $uid ?: null,
					'payload' => [ 'total' => $n, 'via' => 'admin' ],
				] );
				/* translators: %d: cuántas memorias se borraron */
				$notice = [ 'success', sprintf( __( 'Se borraron %d memorias.', 'cead-acad' ), $n ) ];
			}
		}

		$list = Cead_Acad_WA_Memory::all();
		$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';

		echo '<div class="wrap"><h1>' . esc_html__( 'CEADI — Memoria', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1], $notice[0] );
		}

		echo '<p class="description" style="max-width:720px">'
			. esc_html__( 'Datos que CEADI tiene en cuenta en todas las conversaciones. Se los podés dictar por WhatsApp o cargarlos acá: es la misma memoria. Pisan al texto de «Conocimiento del colegio» si se contradicen, así que sirven para corregir algo que cambió.', 'cead-acad' )
			. '</p>';

		echo '<div class="card" style="max-width:720px"><h2>'
			. ( $edit ? esc_html__( 'Editar memoria', 'cead-acad' ) : esc_html__( 'Agregar memoria', 'cead-acad' ) )
			. '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cead_acad_wa_mem' );
		$edit_text = '';
		if ( $edit ) {
			foreach ( $list as $m ) {
				if ( $m['id'] === $edit ) { $edit_text = $m['text']; break; }
			}
			echo '<input type="hidden" name="mem_id" value="' . esc_attr( $edit ) . '">';
		}
		echo '<textarea name="mem_text" rows="3" class="large-text" maxlength="' . (int) Cead_Acad_WA_Memory::MAX_LEN . '" required '
			. 'placeholder="' . esc_attr__( 'Ej.: Las clases del turno mañana empiezan 7:10', 'cead-acad' ) . '">'
			. esc_textarea( $edit_text ) . '</textarea>';
		echo '<p class="description">' . sprintf(
			/* translators: %d: máximo de caracteres */
			esc_html__( 'Escribilo como una frase corta y entendible sin contexto. Máximo %d caracteres.', 'cead-acad' ),
			(int) Cead_Acad_WA_Memory::MAX_LEN
		) . '</p>';
		echo '<p><button class="button button-primary" name="cead_acad_wa_mem_action" value="' . ( $edit ? 'edit' : 'add' ) . '">'
			. ( $edit ? esc_html__( 'Guardar cambios', 'cead-acad' ) : esc_html__( 'Agregar', 'cead-acad' ) ) . '</button>';
		if ( $edit ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=cead-acad-wa-memory' ) ) . '">' . esc_html__( 'Cancelar', 'cead-acad' ) . '</a>';
		}
		echo '</p></form></div>';

		echo '<h2>' . sprintf(
			/* translators: 1: cuántas memorias hay, 2: techo de memorias */
			esc_html__( 'Guardadas (%1$d de %2$d)', 'cead-acad' ),
			count( $list ),
			(int) Cead_Acad_WA_Memory::MAX
		) . '</h2>';

		if ( ! $list ) {
			echo '<p>' . esc_html__( 'Todavía no hay nada guardado.', 'cead-acad' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Dato', 'cead-acad' ) . '</th>'
			. '<th style="width:140px">' . esc_html__( 'Cargada', 'cead-acad' ) . '</th>'
			. '<th style="width:160px">' . esc_html__( 'Por', 'cead-acad' ) . '</th>'
			. '<th style="width:150px"></th>'
			. '</tr></thead><tbody>';
		foreach ( $list as $m ) {
			$autor = $m['author'] ? get_userdata( $m['author'] ) : null;
			echo '<tr><td>' . esc_html( $m['text'] ) . '</td>';
			echo '<td>' . esc_html( $m['created'] ? wp_date( 'j/n/Y H:i', $m['created'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $autor ? $autor->display_name : __( 'CEADI (por chat)', 'cead-acad' ) ) . '</td>';
			echo '<td><a class="button button-small" href="'
				. esc_url( admin_url( 'admin.php?page=cead-acad-wa-memory&edit=' . rawurlencode( $m['id'] ) ) ) . '">'
				. esc_html__( 'Editar', 'cead-acad' ) . '</a> ';
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'cead_acad_wa_mem' );
			echo '<input type="hidden" name="mem_id" value="' . esc_attr( $m['id'] ) . '">';
			echo '<button class="button button-small" name="cead_acad_wa_mem_action" value="delete" '
				. 'onclick="return confirm(' . esc_attr( wp_json_encode( __( '¿Borrar esta memoria?', 'cead-acad' ) ) ) . ')">'
				. esc_html__( 'Borrar', 'cead-acad' ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:1.5rem">';
		wp_nonce_field( 'cead_acad_wa_mem' );
		echo '<button class="button" name="cead_acad_wa_mem_action" value="clear" '
			. 'onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Esto borra TODA la memoria de CEADI. ¿Seguir?', 'cead-acad' ) ) ) . ')">'
			. esc_html__( 'Vaciar la memoria entera', 'cead-acad' ) . '</button>';
		echo '</form>';

		echo '</div>';
	}
}
