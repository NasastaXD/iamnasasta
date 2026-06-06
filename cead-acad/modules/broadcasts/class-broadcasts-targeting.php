<?php
/**
 * Metabox de targeting para comunicados.
 * UI: chips de audiencia + select de tipo + select dependiente de valor.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Broadcasts_Targeting {

	public function boot() {
		add_action( 'add_meta_boxes',                              [ $this, 'metabox' ] );
		add_action( 'save_post_' . Cead_Acad_Broadcasts_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );

		// Notificación por email opcional al publicar.
		add_action( 'transition_post_status', [ $this, 'maybe_notify_email' ], 10, 3 );
	}

	public function metabox() {
		add_meta_box(
			'cead_acad_broadcast_targeting',
			__( 'Destinatarios', 'cead-acad' ),
			[ $this, 'render' ],
			Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'side',
			'high'
		);
		add_meta_box(
			'cead_acad_broadcast_notify',
			__( 'Notificación', 'cead-acad' ),
			[ $this, 'render_notify' ],
			Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'side',
			'default'
		);
	}

	public function render( $post ) {
		wp_nonce_field( 'cead_acad_broadcast_save', '_cead_acad_broadcast_nonce' );

		$current = Cead_Acad_Audiences::get( 'broadcast', $post->ID );
		$roles   = Cead_Acad_Capabilities::roles();
		$courses = Cead_Acad_Courses_CPT::options();
		$cohorts = get_terms( [ 'taxonomy' => Cead_Acad_Courses_CPT::TAX_COHORT, 'hide_empty' => false ] );
		?>
		<p class="description"><?php esc_html_e( 'Definí quién verá este comunicado.', 'cead-acad' ); ?></p>

		<div id="cead-acad-audiences" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px">
			<?php foreach ( $current as $row ) :
				$label = Cead_Acad_Audiences::describe( [ $row ] ); ?>
				<label style="display:flex;align-items:center;gap:6px;background:#f6f7f7;padding:4px 8px;border:1px solid #dcdcde">
					<input type="hidden" name="audiences[type][]"  value="<?php echo esc_attr( $row['audience_type'] ); ?>">
					<input type="hidden" name="audiences[value][]" value="<?php echo esc_attr( $row['audience_value'] ); ?>">
					<input type="checkbox" name="audiences_keep[]" value="1" checked>
					<span><?php echo esc_html( $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<hr>
		<p style="font-weight:600;margin:8px 0 4px"><?php esc_html_e( 'Agregar destinatario', 'cead-acad' ); ?></p>

		<label style="display:block;margin-top:6px">
			<input type="checkbox" name="add_all" value="1">
			<?php esc_html_e( 'Todos los usuarios', 'cead-acad' ); ?>
		</label>

		<?php if ( $roles ) : ?>
			<p style="margin:8px 0 4px"><strong><?php esc_html_e( 'Por rol', 'cead-acad' ); ?></strong></p>
			<?php foreach ( $roles as $slug => $cfg ) : ?>
				<label style="display:block">
					<input type="checkbox" name="add_role[]" value="<?php echo esc_attr( $slug ); ?>">
					<?php echo esc_html( $cfg['display'] ); ?>
				</label>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $courses ) : ?>
			<p style="margin:8px 0 4px"><strong><?php esc_html_e( 'Por curso', 'cead-acad' ); ?></strong></p>
			<select name="add_course[]" multiple style="width:100%;height:120px">
				<?php foreach ( $courses as $id => $title ) : ?>
					<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>

		<?php if ( ! empty( $cohorts ) && ! is_wp_error( $cohorts ) ) : ?>
			<p style="margin:8px 0 4px"><strong><?php esc_html_e( 'Por cohorte', 'cead-acad' ); ?></strong></p>
			<select name="add_cohort[]" multiple style="width:100%;height:80px">
				<?php foreach ( $cohorts as $c ) : ?>
					<option value="<?php echo (int) $c->term_id; ?>"><?php echo esc_html( $c->name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<?php
	}

	public function render_notify( $post ) {
		$notify = (int) get_post_meta( $post->ID, '_cead_acad_notify_email', true );
		?>
		<label style="display:block">
			<input type="checkbox" name="_cead_acad_notify_email" value="1" <?php checked( 1, $notify ); ?>>
			<?php esc_html_e( 'Enviar también por email al publicar', 'cead-acad' ); ?>
		</label>
		<p class="description" style="margin-top:6px"><?php esc_html_e( 'Si está activado, al pasar a publicado se envía un email a cada destinatario.', 'cead-acad' ); ?></p>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_cead_acad_broadcast_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_acad_broadcast_nonce'], 'cead_acad_broadcast_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		$keep_types  = (array) ( $_POST['audiences']['type']  ?? [] );
		$keep_values = (array) ( $_POST['audiences']['value'] ?? [] );
		$keep_marks  = (array) ( $_POST['audiences_keep']     ?? [] );

		$audiences = [];

		// Mantener los preexistentes marcados.
		for ( $i = 0; $i < count( $keep_types ); $i++ ) {
			if ( empty( $keep_marks[ $i ] ) ) {
				continue;
			}
			$audiences[] = [
				'type'  => sanitize_text_field( wp_unslash( $keep_types[ $i ] ) ),
				'value' => sanitize_text_field( wp_unslash( $keep_values[ $i ] ) ),
			];
		}

		// Agregar nuevos.
		if ( ! empty( $_POST['add_all'] ) ) {
			$audiences[] = [ 'type' => 'all', 'value' => '*' ];
		}
		foreach ( (array) ( $_POST['add_role'] ?? [] ) as $r ) {
			$audiences[] = [ 'type' => 'role', 'value' => sanitize_text_field( wp_unslash( $r ) ) ];
		}
		foreach ( (array) ( $_POST['add_course'] ?? [] ) as $c ) {
			$audiences[] = [ 'type' => 'course', 'value' => (string) (int) $c ];
		}
		foreach ( (array) ( $_POST['add_cohort'] ?? [] ) as $c ) {
			$audiences[] = [ 'type' => 'cohort', 'value' => (string) (int) $c ];
		}

		// Dedup.
		$audiences = array_map( 'unserialize', array_unique( array_map( 'serialize', $audiences ) ) );

		Cead_Acad_Audiences::set( 'broadcast', $post_id, $audiences );

		update_post_meta( $post_id, '_cead_acad_notify_email', ! empty( $_POST['_cead_acad_notify_email'] ) ? 1 : 0 );
	}

	public function maybe_notify_email( $new_status, $old_status, $post ) {
		if ( $post->post_type !== Cead_Acad_Broadcasts_CPT::POST_TYPE ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! get_post_meta( $post->ID, '_cead_acad_notify_email', true ) ) {
			return;
		}
		// Evitar duplicados.
		if ( get_post_meta( $post->ID, '_cead_acad_notify_sent', true ) ) {
			return;
		}

		$user_ids = Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $post->ID );
		if ( ! $user_ids ) {
			update_post_meta( $post->ID, '_cead_acad_notify_sent', current_time( 'mysql', 1 ) );
			return;
		}

		$emails = [];
		foreach ( array_chunk( $user_ids, 50 ) as $chunk ) {
			$users = get_users( [ 'include' => $chunk, 'fields' => [ 'user_email' ] ] );
			foreach ( $users as $u ) {
				if ( $u->user_email ) {
					$emails[] = $u->user_email;
				}
			}
		}
		$emails = array_unique( $emails );
		if ( ! $emails ) {
			update_post_meta( $post->ID, '_cead_acad_notify_sent', current_time( 'mysql', 1 ) );
			return;
		}

		$subject = sprintf( /* translators: %s: título del comunicado. */
			__( '[Comunicado CEAD] %s', 'cead-acad' ),
			wp_strip_all_tags( $post->post_title )
		);
		$link    = cead_acad_url( 'panel/comunicados/' . $post->ID );
		$body    = sprintf(
			__( "Tenés un nuevo comunicado: %s\n\nLeerlo en el panel: %s\n\n— %s", 'cead-acad' ),
			wp_strip_all_tags( $post->post_title ),
			$link,
			get_bloginfo( 'name' )
		);

		// BCC para no exponer destinatarios.
		wp_mail(
			get_bloginfo( 'admin_email' ),
			$subject,
			$body,
			[ 'Bcc: ' . implode( ',', $emails ) ]
		);
		update_post_meta( $post->ID, '_cead_acad_notify_sent', current_time( 'mysql', 1 ) );
	}
}
