<?php
/**
 * Metabox del CPT cead_acad_resource: attachment, URL externa, targeting.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Resources_Admin {

	public function boot() {
		add_action( 'add_meta_boxes',                              [ $this, 'metaboxes' ] );
		add_action( 'save_post_' . Cead_Acad_Resources_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts',                       [ $this, 'enqueue_media' ] );
	}

	public function enqueue_media( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->post_type === Cead_Acad_Resources_CPT::POST_TYPE ) {
			wp_enqueue_media();
		}
	}

	public function metaboxes() {
		add_meta_box(
			'cead_acad_resource_file',
			__( 'Archivo o enlace', 'cead-acad' ),
			[ $this, 'render_file' ],
			Cead_Acad_Resources_CPT::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'cead_acad_resource_targeting',
			__( 'Acceso', 'cead-acad' ),
			[ $this, 'render_targeting' ],
			Cead_Acad_Resources_CPT::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_file( $post ) {
		wp_nonce_field( 'cead_acad_resource_save', '_cead_acad_resource_nonce' );
		$url      = (string) get_post_meta( $post->ID, '_cead_acad_resource_url', true );
		$attach_id = (int) get_post_meta( $post->ID, '_cead_acad_resource_attachment_id', true );
		$attach_url = $attach_id ? wp_get_attachment_url( $attach_id ) : '';
		?>
		<p>
			<label><strong><?php esc_html_e( 'Archivo adjunto', 'cead-acad' ); ?></strong></label><br>
			<input type="hidden" name="_cead_acad_resource_attachment_id" id="cead-acad-attach-id" value="<?php echo (int) $attach_id; ?>">
			<span id="cead-acad-attach-current"><?php echo $attach_url ? '<a href="' . esc_url( $attach_url ) . '" target="_blank">' . esc_html( basename( $attach_url ) ) . '</a>' : '<em>' . esc_html__( 'Ninguno', 'cead-acad' ) . '</em>'; ?></span>
			<button type="button" class="button" id="cead-acad-attach-pick"><?php esc_html_e( 'Seleccionar archivo', 'cead-acad' ); ?></button>
			<button type="button" class="button" id="cead-acad-attach-clear"><?php esc_html_e( 'Quitar', 'cead-acad' ); ?></button>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'O enlace externo', 'cead-acad' ); ?></strong></label><br>
			<input type="url" name="_cead_acad_resource_url" value="<?php echo esc_attr( $url ); ?>" class="large-text" placeholder="https://...">
			<span class="description"><?php esc_html_e( 'Útil para videos de YouTube, documentos compartidos o mapas online.', 'cead-acad' ); ?></span>
		</p>
		<script>
		(function () {
			var btn = document.getElementById('cead-acad-attach-pick');
			var clearBtn = document.getElementById('cead-acad-attach-clear');
			var hidden = document.getElementById('cead-acad-attach-id');
			var current = document.getElementById('cead-acad-attach-current');
			if (!btn || !window.wp || !window.wp.media) return;
			var frame;
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({ title: 'Seleccionar archivo', button: { text: 'Usar este archivo' }, multiple: false });
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					hidden.value = att.id;
					current.innerHTML = '<a href="' + att.url + '" target="_blank">' + (att.filename || att.url) + '</a>';
				});
				frame.open();
			});
			clearBtn.addEventListener('click', function () {
				hidden.value = '';
				current.innerHTML = '<em>Ninguno</em>';
			});
		})();
		</script>
		<?php
	}

	public function render_targeting( $post ) {
		$current = Cead_Acad_Audiences::get( 'resource', $post->ID );
		$roles   = Cead_Acad_Capabilities::roles();
		$courses = Cead_Acad_Courses_CPT::options();
		?>
		<p class="description"><?php esc_html_e( 'Quién puede ver/descargar este recurso.', 'cead-acad' ); ?></p>
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

		<label><input type="checkbox" name="add_all" value="1"> <?php esc_html_e( 'Todos los usuarios', 'cead-acad' ); ?></label>

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
		if ( ! isset( $_POST['_cead_acad_resource_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_acad_resource_nonce'], 'cead_acad_resource_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		update_post_meta( $post_id, '_cead_acad_resource_attachment_id', absint( $_POST['_cead_acad_resource_attachment_id'] ?? 0 ) );
		update_post_meta( $post_id, '_cead_acad_resource_url',           esc_url_raw( wp_unslash( $_POST['_cead_acad_resource_url'] ?? '' ) ) );

		// Audiencias.
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
		Cead_Acad_Audiences::set( 'resource', $post_id, $audiences );
	}
}
