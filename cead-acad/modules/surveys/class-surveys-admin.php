<?php
/**
 * Builder de preguntas + targeting + ventana de fechas + flag anónimo.
 * Resultados con export CSV.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Surveys_Admin {

	public function boot() {
		add_action( 'add_meta_boxes',                              [ $this, 'metaboxes' ] );
		add_action( 'save_post_' . Cead_Acad_Surveys_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );

		// Pantalla extra "Resultados" como sub-page (acción ad-hoc).
		add_action( 'admin_post_cead_acad_survey_export', [ $this, 'handle_export' ] );
		add_filter( 'post_row_actions',                   [ $this, 'row_actions' ], 10, 2 );
	}

	public function metaboxes() {
		add_meta_box(
			'cead_acad_survey_questions',
			__( 'Preguntas', 'cead-acad' ),
			[ $this, 'render_questions' ],
			Cead_Acad_Surveys_CPT::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'cead_acad_survey_targeting',
			__( 'Destinatarios', 'cead-acad' ),
			[ $this, 'render_targeting' ],
			Cead_Acad_Surveys_CPT::POST_TYPE,
			'side',
			'high'
		);
		add_meta_box(
			'cead_acad_survey_settings',
			__( 'Configuración', 'cead-acad' ),
			[ $this, 'render_settings' ],
			Cead_Acad_Surveys_CPT::POST_TYPE,
			'side',
			'default'
		);
		add_meta_box(
			'cead_acad_survey_results_box',
			__( 'Resultados', 'cead-acad' ),
			[ $this, 'render_results_box' ],
			Cead_Acad_Surveys_CPT::POST_TYPE,
			'side',
			'low'
		);
	}

	public function render_questions( $post ) {
		wp_nonce_field( 'cead_acad_survey_save', '_cead_acad_survey_nonce' );
		$questions = Cead_Acad_Surveys_Questions::for_survey( $post->ID );
		$types = Cead_Acad_Surveys_CPT::TYPES;
		?>
		<p class="description"><?php esc_html_e( 'Definí las preguntas. Para opción única/múltiple, ingresá cada opción en una línea distinta.', 'cead-acad' ); ?></p>

		<div id="cead-acad-questions" style="display:flex;flex-direction:column;gap:12px">
			<?php
			if ( ! $questions ) {
				$questions = [ [ 'id' => 0, 'type' => 'text', 'text' => '', 'required' => 0, 'config' => [] ] ];
			}
			foreach ( $questions as $i => $q ) {
				$this->render_question_row( $i, $q, $types );
			}
			?>
		</div>

		<p style="margin-top:12px">
			<button type="button" class="button" id="cead-acad-add-question">+ <?php esc_html_e( 'Agregar pregunta', 'cead-acad' ); ?></button>
		</p>

		<template id="cead-acad-question-template">
			<?php $this->render_question_row( '__I__', [ 'id' => 0, 'type' => 'text', 'text' => '', 'required' => 0, 'config' => [] ], $types ); ?>
		</template>

		<script>
		(function () {
			var wrap = document.getElementById('cead-acad-questions');
			var tpl  = document.getElementById('cead-acad-question-template').innerHTML;
			var addBtn = document.getElementById('cead-acad-add-question');
			addBtn.addEventListener('click', function () {
				var idx = wrap.querySelectorAll('.cead-acad-q-row').length;
				var html = tpl.split('__I__').join(idx);
				var div = document.createElement('div');
				div.innerHTML = html;
				wrap.appendChild(div.firstElementChild);
			});
			wrap.addEventListener('click', function (e) {
				if (e.target.matches('.cead-acad-q-remove')) {
					var row = e.target.closest('.cead-acad-q-row');
					if (row) row.remove();
				}
				if (e.target.matches('.cead-acad-q-up') || e.target.matches('.cead-acad-q-down')) {
					var row = e.target.closest('.cead-acad-q-row');
					if (!row) return;
					if (e.target.matches('.cead-acad-q-up') && row.previousElementSibling) {
						row.parentNode.insertBefore(row, row.previousElementSibling);
					}
					if (e.target.matches('.cead-acad-q-down') && row.nextElementSibling) {
						row.parentNode.insertBefore(row.nextElementSibling, row);
					}
				}
			});
			// Toggle de campo "opciones" según tipo.
			wrap.addEventListener('change', function (e) {
				if (e.target.matches('.cead-acad-q-type')) {
					var row = e.target.closest('.cead-acad-q-row');
					var optsBox = row.querySelector('.cead-acad-q-options');
					var scaleBox = row.querySelector('.cead-acad-q-scale');
					if (optsBox) optsBox.style.display = (e.target.value === 'radio' || e.target.value === 'checkbox') ? 'block' : 'none';
					if (scaleBox) scaleBox.style.display = (e.target.value === 'scale') ? 'block' : 'none';
				}
			});
		})();
		</script>
		<?php
	}

	protected function render_question_row( $i, $q, $types ) {
		$opts_str = isset( $q['config']['options'] ) ? implode( "\n", (array) $q['config']['options'] ) : '';
		$scale_min = $q['config']['min'] ?? 1;
		$scale_max = $q['config']['max'] ?? 5;
		$show_opts = in_array( $q['type'], [ 'radio', 'checkbox' ], true ) ? 'block' : 'none';
		$show_scale= 'scale' === $q['type'] ? 'block' : 'none';
		?>
		<div class="cead-acad-q-row" style="border:1px solid #dcdcde;padding:12px;background:#fff">
			<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
				<button type="button" class="button cead-acad-q-up" title="<?php esc_attr_e( 'Subir', 'cead-acad' ); ?>">↑</button>
				<button type="button" class="button cead-acad-q-down" title="<?php esc_attr_e( 'Bajar', 'cead-acad' ); ?>">↓</button>
				<select name="questions[<?php echo esc_attr( $i ); ?>][type]" class="cead-acad-q-type">
					<?php foreach ( $types as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $q['type'], $t ); ?>>
							<?php echo esc_html( Cead_Acad_Surveys_Questions::type_label( $t ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<label style="margin-left:auto">
					<input type="checkbox" name="questions[<?php echo esc_attr( $i ); ?>][required]" value="1" <?php checked( 1, (int) $q['required'] ); ?>>
					<?php esc_html_e( 'Obligatoria', 'cead-acad' ); ?>
				</label>
				<button type="button" class="button button-link-delete cead-acad-q-remove">×</button>
			</div>
			<input type="text" name="questions[<?php echo esc_attr( $i ); ?>][text]" value="<?php echo esc_attr( $q['text'] ); ?>" placeholder="<?php esc_attr_e( 'Texto de la pregunta', 'cead-acad' ); ?>" style="width:100%">

			<div class="cead-acad-q-options" style="display:<?php echo esc_attr( $show_opts ); ?>;margin-top:8px">
				<label style="display:block;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Opciones (una por línea):', 'cead-acad' ); ?></label>
				<textarea name="questions[<?php echo esc_attr( $i ); ?>][options]" rows="4" style="width:100%"><?php echo esc_textarea( $opts_str ); ?></textarea>
			</div>

			<div class="cead-acad-q-scale" style="display:<?php echo esc_attr( $show_scale ); ?>;margin-top:8px;display:flex;gap:8px;align-items:center">
				<label><?php esc_html_e( 'Mín:', 'cead-acad' ); ?> <input type="number" name="questions[<?php echo esc_attr( $i ); ?>][scale_min]" value="<?php echo (int) $scale_min; ?>" min="1" max="10" style="width:60px"></label>
				<label><?php esc_html_e( 'Máx:', 'cead-acad' ); ?> <input type="number" name="questions[<?php echo esc_attr( $i ); ?>][scale_max]" value="<?php echo (int) $scale_max; ?>" min="2" max="10" style="width:60px"></label>
			</div>
		</div>
		<?php
	}

	public function render_targeting( $post ) {
		$current = Cead_Acad_Audiences::get( 'survey', $post->ID );
		$roles   = Cead_Acad_Capabilities::roles();
		$courses = Cead_Acad_Courses_CPT::options();
		$cohorts = get_terms( [ 'taxonomy' => Cead_Acad_Courses_CPT::TAX_COHORT, 'hide_empty' => false ] );
		?>
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

		<?php if ( ! empty( $cohorts ) && ! is_wp_error( $cohorts ) ) : ?>
			<p style="margin:8px 0 4px"><strong><?php esc_html_e( 'Por cohorte', 'cead-acad' ); ?></strong></p>
			<select name="add_cohort[]" multiple style="width:100%;height:60px">
				<?php foreach ( $cohorts as $c ) : ?>
					<option value="<?php echo (int) $c->term_id; ?>"><?php echo esc_html( $c->name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<?php
	}

	public function render_settings( $post ) {
		$opens  = (string) get_post_meta( $post->ID, '_cead_acad_survey_opens_at',  true );
		$closes = (string) get_post_meta( $post->ID, '_cead_acad_survey_closes_at', true );
		$anon   = (bool)   get_post_meta( $post->ID, '_cead_acad_survey_anonymous', true );
		?>
		<p>
			<label><?php esc_html_e( 'Abre el:', 'cead-acad' ); ?><br>
				<input type="datetime-local" name="_cead_acad_survey_opens_at" value="<?php echo esc_attr( $opens ); ?>">
			</label>
		</p>
		<p>
			<label><?php esc_html_e( 'Cierra el:', 'cead-acad' ); ?><br>
				<input type="datetime-local" name="_cead_acad_survey_closes_at" value="<?php echo esc_attr( $closes ); ?>">
			</label>
		</p>
		<p>
			<label><input type="checkbox" name="_cead_acad_survey_anonymous" value="1" <?php checked( true, $anon ); ?>>
				<?php esc_html_e( 'Encuesta anónima', 'cead-acad' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Si es anónima, no guardamos el user_id en las respuestas y un usuario puede responder múltiples veces.', 'cead-acad' ); ?></p>
		<?php
	}

	public function render_results_box( $post ) {
		$count = Cead_Acad_Surveys_Responses::count_for_survey( $post->ID );
		?>
		<p><strong><?php echo (int) $count; ?></strong> <?php esc_html_e( 'respuestas recibidas', 'cead-acad' ); ?></p>
		<?php if ( $count > 0 ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_survey_export&survey_id=' . $post->ID ), 'cead_acad_survey_export' ) ); ?>">
					<?php esc_html_e( 'Descargar CSV', 'cead-acad' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_cead_acad_survey_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_acad_survey_nonce'], 'cead_acad_survey_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		// Settings.
		foreach ( [ '_cead_acad_survey_opens_at', '_cead_acad_survey_closes_at' ] as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		update_post_meta( $post_id, '_cead_acad_survey_anonymous', ! empty( $_POST['_cead_acad_survey_anonymous'] ) ? 1 : 0 );

		// Preguntas.
		$raw_qs = $_POST['questions'] ?? [];
		if ( is_array( $raw_qs ) ) {
			// Re-numerar (puede haber huecos si el usuario borró).
			$qs = array_values( $raw_qs );
			Cead_Acad_Surveys_Questions::replace_all( $post_id, $qs );
		}

		// Audiencias (mismo patrón que broadcasts).
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
		foreach ( (array) ( $_POST['add_cohort'] ?? [] ) as $c ) {
			$audiences[] = [ 'type' => 'cohort', 'value' => (string) (int) $c ];
		}
		$audiences = array_map( 'unserialize', array_unique( array_map( 'serialize', $audiences ) ) );
		Cead_Acad_Audiences::set( 'survey', $post_id, $audiences );
	}

	public function handle_export() {
		check_admin_referer( 'cead_acad_survey_export' );
		if ( ! current_user_can( 'cead_acad_view_survey_results' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$survey_id = (int) ( $_GET['survey_id'] ?? 0 );
		if ( ! $survey_id ) {
			wp_die( esc_html__( 'Encuesta no especificada.', 'cead-acad' ) );
		}

		$csv = Cead_Acad_Surveys_Responses::export_csv( $survey_id );
		$slug = sanitize_title( get_the_title( $survey_id ) ?: ( 'encuesta-' . $survey_id ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '-respuestas.csv"' );
		echo "\xEF\xBB\xBF"; // BOM utf8 para Excel.
		echo $csv;
		exit;
	}

	public function row_actions( $actions, $post ) {
		if ( $post->post_type === Cead_Acad_Surveys_CPT::POST_TYPE && current_user_can( 'cead_acad_view_survey_results' ) ) {
			$count = Cead_Acad_Surveys_Responses::count_for_survey( $post->ID );
			if ( $count > 0 ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_survey_export&survey_id=' . $post->ID ), 'cead_acad_survey_export' );
				$actions['cead_acad_export'] = '<a href="' . esc_url( $url ) . '">' . sprintf( esc_html__( 'Exportar (%d)', 'cead-acad' ), (int) $count ) . '</a>';
			}
		}
		return $actions;
	}
}
