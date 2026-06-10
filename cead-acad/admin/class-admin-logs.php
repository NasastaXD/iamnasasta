<?php
/**
 * Pantalla "CEAD Académico → Registros": audit log del plugin y logs del
 * bot de WhatsApp, con filtros y paginación. Solo lectura.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Admin_Logs {

	const PER_PAGE = 50;

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
	}

	public function register_menu() {
		if ( ! cead_acad_user_is_staff() ) {
			return;
		}
		add_submenu_page(
			'cead-acad',
			__( 'Registros', 'cead-acad' ),
			__( 'Registros', 'cead-acad' ),
			'read',
			'cead-acad-logs',
			[ $this, 'render' ]
		);
	}

	public function render() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}

		$tab = isset( $_GET['tab'] ) && 'bot' === $_GET['tab'] ? 'bot' : 'audit';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Registros', 'cead-acad' ) . '</h1>';

		$base = admin_url( 'admin.php?page=cead-acad-logs' );
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px">';
		echo '<a href="' . esc_url( $base ) . '" class="nav-tab' . ( 'audit' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Auditoría', 'cead-acad' ) . '</a>';
		echo '<a href="' . esc_url( add_query_arg( 'tab', 'bot', $base ) ) . '" class="nav-tab' . ( 'bot' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Bot de WhatsApp', 'cead-acad' ) . '</a>';
		echo '</nav>';

		if ( 'bot' === $tab ) {
			$this->render_bot_logs();
		} else {
			$this->render_audit_log();
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Auditoría
	// -------------------------------------------------------------------------

	protected function render_audit_log() {
		$filters = [
			'action'    => isset( $_GET['f_action'] ) ? sanitize_key( wp_unslash( $_GET['f_action'] ) ) : '',
			'user_id'   => isset( $_GET['f_user'] ) ? (int) $_GET['f_user'] : 0,
			'date_from' => $this->sanitize_date( $_GET['f_from'] ?? '' ),
			'date_to'   => $this->sanitize_date( $_GET['f_to'] ?? '' ),
			'page'      => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
			'per_page'  => self::PER_PAGE,
		];

		$result  = Cead_Acad_Audit::query( $filters );
		$actions = Cead_Acad_Audit::distinct_actions();

		// Filtros.
		echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
		echo '<input type="hidden" name="page" value="cead-acad-logs">';
		echo '<select name="f_action"><option value="">' . esc_html__( 'Todas las acciones', 'cead-acad' ) . '</option>';
		foreach ( $actions as $a ) {
			echo '<option value="' . esc_attr( $a ) . '"' . selected( $filters['action'], $a, false ) . '>' . esc_html( $a ) . '</option>';
		}
		echo '</select>';
		echo '<input type="number" name="f_user" min="0" placeholder="' . esc_attr__( 'ID de usuario', 'cead-acad' ) . '" value="' . esc_attr( $filters['user_id'] ?: '' ) . '" style="width:120px">';
		$this->render_date_inputs( $filters );
		submit_button( __( 'Filtrar', 'cead-acad' ), 'secondary', '', false );
		echo '</form>';

		$this->render_count_and_pagination( $result['total'], $filters['page'], 'audit', $filters );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:60px">ID</th>';
		echo '<th>' . esc_html__( 'Fecha', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Usuario', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Entidad', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Detalle', 'cead-acad' ) . '</th>';
		echo '<th>IP</th>';
		echo '</tr></thead><tbody>';

		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Sin registros todavía. Las acciones del plugin (logins, altas de usuarios, invitaciones) se van a ir registrando acá.', 'cead-acad' ) . '</td></tr>';
		}

		foreach ( $result['rows'] as $row ) {
			$user_label = '—';
			if ( $row['user_id'] ) {
				$u          = get_user_by( 'id', (int) $row['user_id'] );
				$user_label = $u ? $u->display_name . ' (#' . $row['user_id'] . ')' : '#' . $row['user_id'];
			}
			$entity = $row['entity_type'] ? $row['entity_type'] . ( $row['entity_id'] ? ' #' . $row['entity_id'] : '' ) : '—';

			echo '<tr>';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td><code>' . esc_html( $row['action'] ) . '</code></td>';
			echo '<td>' . esc_html( $entity ) . '</td>';
			echo '<td style="max-width:340px;word-break:break-word">' . esc_html( $this->payload_summary( $row['payload'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['ip'] ?: '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->render_count_and_pagination( $result['total'], $filters['page'], 'audit', $filters );
	}

	// -------------------------------------------------------------------------
	// Logs del bot
	// -------------------------------------------------------------------------

	protected function render_bot_logs() {
		$filters = [
			'phone'     => isset( $_GET['f_phone'] ) ? sanitize_text_field( wp_unslash( $_GET['f_phone'] ) ) : '',
			'direction' => isset( $_GET['f_dir'] ) && in_array( $_GET['f_dir'], [ 'in', 'out' ], true ) ? $_GET['f_dir'] : '',
			'date_from' => $this->sanitize_date( $_GET['f_from'] ?? '' ),
			'date_to'   => $this->sanitize_date( $_GET['f_to'] ?? '' ),
			'page'      => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
			'per_page'  => self::PER_PAGE,
		];

		$store  = new Cead_Acad_WA_Store();
		$result = $store->query_logs( $filters );

		echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
		echo '<input type="hidden" name="page" value="cead-acad-logs">';
		echo '<input type="hidden" name="tab" value="bot">';
		echo '<input type="text" name="f_phone" placeholder="' . esc_attr__( 'Teléfono', 'cead-acad' ) . '" value="' . esc_attr( $filters['phone'] ) . '" style="width:160px">';
		echo '<select name="f_dir">';
		echo '<option value="">' . esc_html__( 'Entrada y salida', 'cead-acad' ) . '</option>';
		echo '<option value="in"' . selected( $filters['direction'], 'in', false ) . '>' . esc_html__( 'Entrada (in)', 'cead-acad' ) . '</option>';
		echo '<option value="out"' . selected( $filters['direction'], 'out', false ) . '>' . esc_html__( 'Salida (out)', 'cead-acad' ) . '</option>';
		echo '</select>';
		$this->render_date_inputs( $filters );
		submit_button( __( 'Filtrar', 'cead-acad' ), 'secondary', '', false );
		echo '</form>';

		$this->render_count_and_pagination( $result['total'], $filters['page'], 'bot', $filters );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:60px">ID</th>';
		echo '<th>' . esc_html__( 'Fecha', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Teléfono', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Dirección', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Mensaje', 'cead-acad' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción procesada', 'cead-acad' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Sin mensajes registrados con esos filtros.', 'cead-acad' ) . '</td></tr>';
		}

		foreach ( $result['rows'] as $row ) {
			$dir_label = 'in' === $row['direction']
				? '⬇️ ' . __( 'in', 'cead-acad' )
				: '⬆️ ' . __( 'out', 'cead-acad' );

			echo '<tr>';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $row['phone'] ) . '</td>';
			echo '<td>' . esc_html( $dir_label ) . '</td>';
			echo '<td style="max-width:420px;word-break:break-word">' . esc_html( wp_trim_words( (string) $row['message_body'], 40, '…' ) ) . '</td>';
			echo '<td>' . ( $row['processed_action'] ? '<code>' . esc_html( $row['processed_action'] ) . '</code>' : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->render_count_and_pagination( $result['total'], $filters['page'], 'bot', $filters );
	}

	// -------------------------------------------------------------------------
	// Helpers de render
	// -------------------------------------------------------------------------

	protected function render_date_inputs( array $filters ) {
		echo '<label>' . esc_html__( 'Desde', 'cead-acad' ) . ' <input type="date" name="f_from" value="' . esc_attr( $filters['date_from'] ) . '"></label>';
		echo '<label>' . esc_html__( 'Hasta', 'cead-acad' ) . ' <input type="date" name="f_to" value="' . esc_attr( $filters['date_to'] ) . '"></label>';
	}

	protected function render_count_and_pagination( $total, $page, $tab, array $filters ) {
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		echo '<div class="tablenav"><div class="tablenav-pages" style="float:none;display:flex;gap:10px;align-items:center">';
		echo '<span class="displaying-num">' . esc_html( sprintf( _n( '%s registro', '%s registros', $total, 'cead-acad' ), number_format_i18n( $total ) ) ) . '</span>';

		if ( $pages > 1 ) {
			$args = [ 'page' => 'cead-acad-logs', 'paged' => '%#%' ];
			if ( 'bot' === $tab ) {
				$args['tab'] = 'bot';
				foreach ( [ 'phone' => 'f_phone', 'direction' => 'f_dir' ] as $k => $param ) {
					if ( $filters[ $k ] !== '' ) { $args[ $param ] = $filters[ $k ]; }
				}
			} else {
				if ( $filters['action'] ) { $args['f_action'] = $filters['action']; }
				if ( $filters['user_id'] ) { $args['f_user'] = $filters['user_id']; }
			}
			if ( $filters['date_from'] ) { $args['f_from'] = $filters['date_from']; }
			if ( $filters['date_to'] ) { $args['f_to'] = $filters['date_to']; }

			echo wp_kses_post( paginate_links( [
				'base'      => add_query_arg( $args, admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) ?: '' );
		}
		echo '</div></div>';
	}

	/** Resume el payload JSON en una línea legible. */
	protected function payload_summary( $payload ) {
		if ( ! $payload ) {
			return '—';
		}
		$data = json_decode( (string) $payload, true );
		if ( ! is_array( $data ) ) {
			return wp_trim_words( (string) $payload, 20, '…' );
		}
		$parts = [];
		foreach ( $data as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$parts[] = $k . ': ' . $v;
			}
		}
		return $parts ? implode( ' · ', array_slice( $parts, 0, 6 ) ) : '—';
	}

	/** Valida formato YYYY-MM-DD; devuelve '' si no es válido. */
	protected function sanitize_date( $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
