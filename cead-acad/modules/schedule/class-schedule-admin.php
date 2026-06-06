<?php
/**
 * Metaboxes del CPT cead_acad_event: detalles + targeting (reutiliza audiencias).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Schedule_Admin {

	public function boot() {
		add_action( 'add_meta_boxes',                            [ $this, 'metaboxes' ] );
		add_action( 'save_post_' . Cead_Acad_Schedule_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );

		// Columnas custom en la lista.
		add_filter( 'manage_' . Cead_Acad_Schedule_CPT::POST_TYPE . '_posts_columns',       [ $this, 'columns' ] );
		add_action( 'manage_' . Cead_Acad_Schedule_CPT::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function metaboxes() {
		add_meta_box(
			'cead_acad_event_meta',
			__( 'Detalles del evento', 'cead-acad' ),
			[ $this, 'render_meta' ],
			Cead_Acad_Schedule_CPT::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'cead_acad_event_targeting',
			__( 'Destinatarios', 'cead-acad' ),
			[ $this, 'render_targeting' ],
			Cead_Acad_Schedule_CPT::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_meta( $post ) {
		wp_nonce_field( 'cead_acad_event_save', '_cead_acad_event_nonce' );
		$start = (string) get_post_meta( $post->ID, '_cead_acad_event_start',    true );
		$end   = (string) get_post_meta( $post->ID, '_cead_acad_event_end',      true );
		$all   = (bool)   get_post_meta( $post->ID, '_cead_acad_event_all_day',  true );
		$loc   = (string) get_post_meta( $post->ID, '_cead_acad_event_location', true );
		$type  = (string) get_post_meta( $post->ID, '_cead_acad_event_type',     true ) ?: 'evento';
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Tipo', 'cead-acad' ); ?></label></th>
				<td>
					<select name="_cead_acad_event_type">
						<?php foreach ( Cead_Acad_Schedule_CPT::TYPES as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>>
								<?php echo esc_html( Cead_Acad_Schedule_CPT::type_label( $t ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Comienzo', 'cead-acad' ); ?></label></th>
				<td><input type="datetime-local" name="_cead_acad_event_start" value="<?php echo esc_attr( $start ); ?>" required></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Fin', 'cead-acad' ); ?></label></th>
				<td><input type="datetime-local" name="_cead_acad_event_end" value="<?php echo esc_attr( $end ); ?>"></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Todo el día', 'cead-acad' ); ?></label></th>
				<td><label><input type="checkbox" name="_cead_acad_event_all_day" value="1" <?php checked( true, $all ); ?>> <?php esc_html_e( 'Sí', 'cead-acad' ); ?></label></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Lugar', 'cead-acad' ); ?></label></th>
				<td><input type="text" name="_cead_acad_event_location" value="<?php echo esc_attr( $loc ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Aula 12 / Auditorio / Online', 'cead-acad' ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	public function render_targeting( $post ) {
		$current = Cead_Acad_Audiences::get( 'event', $post->ID );
		$roles   = Cead_Acad_Capabilities::roles();
		$courses = Cead_Acad_Courses_CPT::options();
		?>
		<p class="description"><?php esc_html_e( 'Quién verá este evento en su calendario.', 'cead-acad' ); ?></p>
		<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px">
			<?php foreach ( $current as $row ) : ?>
				<label style="display:flex;align-items:center;gap:6px;background:#f6f7f7;padding:4px 8px;border:1px solid #dcdcde">
					<input type="hidden" name="audiences[type][]"  value="<?php echo esc_attr( $row['audience_type'] ); ?>">
					<input type="hidden" name="audiences[value][]" value="<?php echo esc_attr( $row['audience_value'] ); ?>">
					<input type="checkbox" name="audiences_keep[]" value="1" checked>
					<span><?php echo esc_html( Cead_Acad_Audiences::describe( [ $row ] ) ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<label><input type="checkbox" name="add_all" value="1"> <?php esc_html_e( 'Todos', 'cead-acad' ); ?></label>

		<p style="margin:8px 0 4px"><strong><?php esc_html_e( 'Por rol', 'cead-acad' ); ?></strong></p>
		<?php foreach ( $roles as $slug => $cfg ) : ?>
			<label style="display:block"><input type="checkbox" name="add_role[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( $cfg['display'] ); ?></label>
		<?php endforeach; ?>

		<?php if ( $courses ) : ?>
			<p style="margin:8px 0 4px"><strong><?php esc_html_e( 'Por curso', 'cead-acad' ); ?></strong></p>
			<select name="add_course[]" multiple style="width:100%;height:100px">
				<?php foreach ( $courses as $id => $title ) : ?>
					<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_cead_acad_event_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_acad_event_nonce'], 'cead_acad_event_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( [ '_cead_acad_event_start', '_cead_acad_event_end', '_cead_acad_event_location', '_cead_acad_event_type' ] as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		update_post_meta( $post_id, '_cead_acad_event_all_day', ! empty( $_POST['_cead_acad_event_all_day'] ) ? 1 : 0 );

		// Audiencias (mismo patrón que broadcasts/surveys).
		$keep_types  = (array) ( $_POST['audiences']['type']  ?? [] );
		$keep_values = (array) ( $_POST['audiences']['value'] ?? [] );
		$keep_marks  = (array) ( $_POST['audiences_keep']     ?? [] );

		$audiences = [];
		for ( $i = 0; $i < count( $keep_types ); $i++ ) {
			if ( empty( $keep_marks[ $i ] ) ) { continue; }
			$audiences[] = [
				'type'  => sanitize_text_field( wp_unslash( $keep_types[ $i ] ) ),
				'value' => sanitize_text_field( wp_unslash( $keep_values[ $i ] ) ),
			];
		}
		if ( ! empty( $_POST['add_all'] ) ) {
			$audiences[] = [ 'type' => 'all', 'value' => '*' ];
		}
		foreach ( (array) ( $_POST['add_role'] ?? [] ) as $r ) {
			$audiences[] = [ 'type' => 'role', 'value' => sanitize_text_field( wp_unslash( $r ) ) ];
		}
		foreach ( (array) ( $_POST['add_course'] ?? [] ) as $c ) {
			$audiences[] = [ 'type' => 'course', 'value' => (string) (int) $c ];
		}
		$audiences = array_map( 'unserialize', array_unique( array_map( 'serialize', $audiences ) ) );
		Cead_Acad_Audiences::set( 'event', $post_id, $audiences );
	}

	public function columns( $cols ) {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['cead_acad_when']  = __( 'Cuándo', 'cead-acad' );
				$new['cead_acad_etype'] = __( 'Tipo', 'cead-acad' );
			}
		}
		return $new;
	}

	public function render_column( $col, $post_id ) {
		if ( 'cead_acad_when' === $col ) {
			$start = get_post_meta( $post_id, '_cead_acad_event_start', true );
			echo $start ? esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $start ) ) : '—';
		}
		if ( 'cead_acad_etype' === $col ) {
			echo esc_html( Cead_Acad_Schedule_CPT::type_label( get_post_meta( $post_id, '_cead_acad_event_type', true ) ) );
		}
	}
}
