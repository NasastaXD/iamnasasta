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
		echo '</tbody></table>';
		submit_button( __( 'Guardar configuración', 'cead-acad' ) );
		echo '</form></div>';

		echo '<p><em>' . esc_html__( 'El bot reutiliza los datos del plugin: horarios/eventos, comunicados y cursos. Los alumnos se reconocen por el teléfono cargado en su ficha (meta _cead_acad_phone).', 'cead-acad' ) . '</em></p>';
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
				$res = $this->broadcaster->enqueue_for( $message, $target );
				if ( ! empty( $res['busy'] ) ) {
					$notice = [ 'err', __( 'Ya hay un envío en curso.', 'cead-acad' ) ];
				} elseif ( empty( $res['queued'] ) ) {
					$notice = [ 'err', __( 'No hay destinatarios para esa audiencia.', 'cead-acad' ) ];
				} else {
					$notice = [ 'ok', sprintf( __( 'Encolado para %d destinatario(s).', 'cead-acad' ), (int) $res['total'] ) ];
				}
			}
		}

		$progress = $this->broadcaster->progress();
		echo '<div class="wrap"><h1>' . esc_html__( 'WhatsApp — Comunicados', 'cead-acad' ) . '</h1>';
		if ( $notice ) {
			$this->notice( $notice[1], $notice[0] === 'ok' ? 'success' : 'error' );
		}
		if ( ( $progress['status'] ?? '' ) === 'running' ) {
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
