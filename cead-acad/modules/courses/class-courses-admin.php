<?php
/**
 * Metabox del CPT cead_acad_course + acciones de roster.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Courses_Admin {

	public function boot() {
		add_action( 'add_meta_boxes',                       [ $this, 'metabox' ] );
		add_action( 'save_post_' . Cead_Acad_Courses_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_filter( 'manage_' . Cead_Acad_Courses_CPT::POST_TYPE . '_posts_columns',       [ $this, 'columns' ] );
		add_action( 'manage_' . Cead_Acad_Courses_CPT::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

		add_action( 'admin_post_cead_acad_roster_add',    [ $this, 'handle_add_user' ] );
		add_action( 'admin_post_cead_acad_roster_remove', [ $this, 'handle_remove_user' ] );

		add_action( 'admin_notices', [ $this, 'roster_notice' ] );

		add_action( 'admin_menu', [ $this, 'register_review_menu' ] );
		add_action( 'admin_post_cead_acad_horario_review_save', [ $this, 'handle_review_save' ] );
	}

	/**
	 * Submenú "Horarios": repasar y corregir el horario de cualquier curso
	 * desde un solo lugar, sin entrar curso por curso. Nació de una planilla
	 * real que se importó mal (día u hora corridos en algunas filas) y no
	 * había dónde revisar eso de punta a punta salvo abriendo cada curso.
	 */
	public function register_review_menu() {
		if ( ! current_user_can( 'cead_acad_manage_courses' ) ) {
			return;
		}
		add_submenu_page(
			'cead-acad',
			__( 'Horarios', 'cead-acad' ),
			__( 'Horarios', 'cead-acad' ),
			'read',
			'cead-acad-horarios',
			[ $this, 'render_review' ]
		);
	}

	public function render_review() {
		if ( ! current_user_can( 'cead_acad_manage_courses' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$courses   = cead_acad_courses_for_select();
		$course_id = isset( $_GET['curso'] ) ? (int) $_GET['curso'] : 0;
		if ( $course_id && ! isset( $courses[ $course_id ] ) ) {
			$course_id = 0;
		}
		$slots_count = 0;
		if ( $course_id ) {
			$raw   = get_post_meta( $course_id, '_cead_acad_horario', true );
			$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [] );
			$slots_count = is_array( $slots ) ? count( $slots ) : 0;
		}
		include CEAD_ACAD_DIR . 'admin/views/horarios-review.php';
	}

	public function handle_review_save() {
		if ( ! current_user_can( 'cead_acad_manage_courses' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		check_admin_referer( 'cead_acad_horario_review' );

		$course_id = (int) ( $_POST['course_id'] ?? 0 );
		if ( ! $course_id || Cead_Acad_Courses_CPT::POST_TYPE !== get_post_type( $course_id ) ) {
			wp_die( esc_html__( 'Curso inválido.', 'cead-acad' ) );
		}

		$slots = $this->slots_from_post();
		// JSON_UNESCAPED_UNICODE: ver la nota en Cead_Acad_Importer_Horarios::commit_row().
		update_post_meta( $course_id, '_cead_acad_horario', wp_json_encode( $slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		/*
		 * Una vez que alguien revisó y corrigió esto a mano, deja de ser "lo que
		 * trajo el import tal": si quedara marcado, "Borrar lo importado" sobre
		 * ese job podría pisar una corrección que se acaba de hacer a propósito.
		 */
		delete_post_meta( $course_id, '_cead_acad_horario_imported_via_job' );

		Cead_Acad_Audit::log( 'course_horario_reviewed', [
			'entity_type' => 'course',
			'entity_id'   => $course_id,
			'payload'     => [ 'slots' => count( $slots ) ],
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=cead-acad-horarios&curso=' . $course_id . '&saved=1' ) );
		exit;
	}

	/**
	 * Aviso cuando una inscripción no se pudo escribir.
	 *
	 * Sin esto, agregar a alguien al curso y que el insert falle se ve igual que
	 * agregarlo bien: la pantalla vuelve al curso y la persona simplemente no
	 * está en la lista, sin ningún motivo a la vista.
	 */
	public function roster_notice() {
		$clave    = 'cead_acad_roster_error_' . get_current_user_id();
		$guardado = get_transient( $clave );
		if ( $guardado ) {
			delete_transient( $clave );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['cead_roster_error'] ) && ! $guardado ) {
			return;
		}
		$msg = is_string( $guardado ) && $guardado !== '1'
			? $guardado
			: __( 'No se pudo inscribir a esa persona en el curso. La información no se guardó correctamente. Intente de nuevo y, si el problema continúa, revise el registro de errores.', 'cead-acad' );
		echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/** Deja un aviso para el próximo pintado del admin (save_post no puede redirigir). */
	protected function flag_roster_error( $msg ) {
		set_transient( 'cead_acad_roster_error_' . get_current_user_id(), $msg, 60 );
	}

	public function metabox() {
		add_meta_box(
			'cead_acad_course_meta',
			__( 'Detalles del curso', 'cead-acad' ),
			[ $this, 'render_metabox' ],
			Cead_Acad_Courses_CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'cead_acad_course_roster',
			__( 'Alumnado del curso', 'cead-acad' ),
			[ $this, 'render_roster_metabox' ],
			Cead_Acad_Courses_CPT::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'cead_acad_course_horario',
			__( 'Horario de materias', 'cead-acad' ),
			[ $this, 'render_horario_metabox' ],
			Cead_Acad_Courses_CPT::POST_TYPE,
			'normal',
			'default'
		);
	}

	public function render_horario_metabox( $post ) {
		echo '<p class="description">' . esc_html__( 'El horario semanal de materias de este curso. Se muestra a sus alumnos en el panel y en el bot. Dejá la materia vacía para borrar una fila.', 'cead-acad' ) . '</p>';
		$this->render_horario_fields( $post->ID );
	}

	/**
	 * La tabla editable de horario, sola: la usan tanto el metabox del curso
	 * (que ya está dentro del `<form>` nativo de editar post) como la pantalla
	 * de revisión centralizada (`admin/views/horarios-review.php`, con su
	 * propio `<form>`) — mismo `name="cead_acad_horario[...]"`, mismo
	 * `slots_from_post()` del lado del guardado.
	 */
	public function render_horario_fields( $post_id ) {
		$raw   = get_post_meta( $post_id, '_cead_acad_horario', true );
		$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [] );
		$slots = is_array( $slots ) ? $slots : [];
		$days  = [ 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' ];
		// Filas existentes + 8 vacías para cargar nuevas (sin JS).
		$rows = $slots;
		for ( $i = 0; $i < 8; $i++ ) { $rows[] = []; }
		?>
		<table class="widefat striped">
			<thead><tr>
				<th style="width:130px"><?php esc_html_e( 'Día', 'cead-acad' ); ?></th>
				<th style="width:90px"><?php esc_html_e( 'Desde', 'cead-acad' ); ?></th>
				<th style="width:90px"><?php esc_html_e( 'Hasta', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Materia', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Docente (opcional)', 'cead-acad' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Aula (opcional)', 'cead-acad' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $i => $s ) :
				$dia     = (int) ( $s['dia'] ?? 0 );
				$inicio  = esc_attr( (string) ( $s['inicio'] ?? '' ) );
				$fin     = esc_attr( (string) ( $s['fin'] ?? '' ) );
				$materia = esc_attr( (string) ( $s['materia'] ?? '' ) );
				$docente = esc_attr( (string) ( $s['docente'] ?? '' ) );
				$aula    = esc_attr( (string) ( $s['aula'] ?? '' ) );
			?>
				<tr>
					<td>
						<select name="cead_acad_horario[<?php echo (int) $i; ?>][dia]">
							<option value="0"><?php esc_html_e( '—', 'cead-acad' ); ?></option>
							<?php foreach ( $days as $dn => $dl ) : ?>
								<option value="<?php echo (int) $dn; ?>" <?php selected( $dia, $dn ); ?>><?php echo esc_html( $dl ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="time" name="cead_acad_horario[<?php echo (int) $i; ?>][inicio]" value="<?php echo $inicio; ?>"></td>
					<td><input type="time" name="cead_acad_horario[<?php echo (int) $i; ?>][fin]" value="<?php echo $fin; ?>"></td>
					<td><input type="text" class="regular-text" name="cead_acad_horario[<?php echo (int) $i; ?>][materia]" value="<?php echo $materia; ?>"></td>
					<td><input type="text" class="regular-text" name="cead_acad_horario[<?php echo (int) $i; ?>][docente]" value="<?php echo $docente; ?>"></td>
					<td><input type="text" name="cead_acad_horario[<?php echo (int) $i; ?>][aula]" value="<?php echo $aula; ?>"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Filas de horario posteadas (por el metabox o por la pantalla de
	 * revisión), ya limpias: se descartan las que no tengan día o materia.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function slots_from_post() {
		$slots = [];
		if ( isset( $_POST['cead_acad_horario'] ) && is_array( $_POST['cead_acad_horario'] ) ) {
			foreach ( $_POST['cead_acad_horario'] as $row ) {
				$dia     = (int) ( $row['dia'] ?? 0 );
				$materia = sanitize_text_field( wp_unslash( $row['materia'] ?? '' ) );
				if ( $dia < 1 || $dia > 7 || $materia === '' ) { continue; }
				$aula = sanitize_text_field( wp_unslash( $row['aula'] ?? '' ) );
				$slot = [
					'dia'     => $dia,
					'inicio'  => $this->clean_time( wp_unslash( $row['inicio'] ?? '' ) ),
					'fin'     => $this->clean_time( wp_unslash( $row['fin'] ?? '' ) ),
					'materia' => $materia,
					'docente' => sanitize_text_field( wp_unslash( $row['docente'] ?? '' ) ),
				];
				if ( '' !== $aula ) { $slot['aula'] = $aula; }
				$slots[] = $slot;
			}
		}
		return $slots;
	}

	protected function clean_time( $t ) {
		$t = trim( (string) $t );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t ) ? $t : '';
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'cead_acad_course_save', '_cead_acad_course_nonce' );

		$turno    = get_post_meta( $post->ID, '_cead_acad_turno', true );
		$division = get_post_meta( $post->ID, '_cead_acad_division', true );
		$delegate = (int) get_post_meta( $post->ID, '_cead_acad_delegate', true );
		$tutor    = (int) get_post_meta( $post->ID, '_cead_acad_tutor', true );

		// Lista de divisiones del tema (cead_division CPT). Si no existe, fallback vacío.
		$divisions = post_type_exists( 'cead_division' )
			? get_posts( [ 'post_type' => 'cead_division', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] )
			: [];
		?>
		<table class="form-table">
			<tr>
				<th><label for="cead_acad_turno"><?php esc_html_e( 'Turno', 'cead-acad' ); ?></label></th>
				<td>
					<select name="_cead_acad_turno" id="cead_acad_turno">
						<?php
						$options = [
							''       => __( '— seleccionar —', 'cead-acad' ),
							'manana' => __( 'Mañana', 'cead-acad' ),
							'tarde'  => __( 'Tarde', 'cead-acad' ),
							'noche'  => __( 'Noche', 'cead-acad' ),
						];
						foreach ( $options as $val => $label ) :
						?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $turno, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cead_acad_division"><?php esc_html_e( 'División (tema)', 'cead-acad' ); ?></label></th>
				<td>
					<select name="_cead_acad_division" id="cead_acad_division">
						<option value=""><?php esc_html_e( '— ninguna —', 'cead-acad' ); ?></option>
						<?php foreach ( $divisions as $d ) : ?>
							<option value="<?php echo (int) $d->ID; ?>" <?php selected( (int) $division, (int) $d->ID ); ?>><?php echo esc_html( $d->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Bachillerato técnico relacionado (del tema CEAD).', 'cead-acad' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cead_acad_delegate"><?php esc_html_e( 'Delegado/a', 'cead-acad' ); ?></label></th>
				<td>
					<?php $this->user_select( '_cead_acad_delegate', $delegate, [ 'cead_acad_delegate', 'cead_acad_student' ] ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="cead_acad_tutor"><?php esc_html_e( 'Tutor/a (docente)', 'cead-acad' ); ?></label></th>
				<td>
					<?php $this->user_select( '_cead_acad_tutor', $tutor, [ 'cead_acad_teacher' ] ); ?>
				</td>
			</tr>

			<tr>
				<th><label for="cead_acad_turismo"><?php esc_html_e( 'Portal turístico', 'cead-acad' ); ?></label></th>
				<td>
					<label><input type="checkbox" id="cead_acad_turismo" name="_cead_acad_turismo" value="1" <?php checked( '1', (string) get_post_meta( $post->ID, '_cead_acad_turismo', true ) ); ?>>
						<?php esc_html_e( 'Este curso participa del portal de caaguazu.net', 'cead-acad' ); ?></label>
					<p class="description"><?php esc_html_e( 'Al alumnado y a los docentes de este curso les aparece el botón «Ir al portal turístico» en su panel, y entran sin registrarse de nuevo. Se marca acá, con una casilla, y no por el nombre del curso: así renombrarlo no deja a todos afuera sin que nadie entienda por qué.', 'cead-acad' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function user_select( $field, $current, $roles ) {
		$users = get_users( [
			'role__in' => $roles,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 500,
		] );
		echo '<select name="' . esc_attr( $field ) . '"><option value="">' . esc_html__( '— ninguno —', 'cead-acad' ) . '</option>';
		foreach ( $users as $u ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $u->ID,
				selected( (int) $current, (int) $u->ID, false ),
				esc_html( $u->display_name . ' (' . $u->user_login . ')' )
			);
		}
		echo '</select>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_cead_acad_course_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_acad_course_nonce'], 'cead_acad_course_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		// Delegado/a anterior (antes de que el loop de abajo pise el meta), para
		// poder revocarle el rol/roster si cambia.
		$old_delegate_id = (int) get_post_meta( $post_id, '_cead_acad_delegate', true );

		// Casilla: presente = 1, ausente = se borra el meta.
		if ( ! empty( $_POST['_cead_acad_turismo'] ) ) {
			update_post_meta( $post_id, '_cead_acad_turismo', '1' );
		} else {
			delete_post_meta( $post_id, '_cead_acad_turismo' );
		}

		foreach ( [ '_cead_acad_turno', '_cead_acad_division', '_cead_acad_delegate', '_cead_acad_tutor' ] as $key ) {
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $post_id, $key, $val );
		}

		// JSON_UNESCAPED_UNICODE: ver la nota en Cead_Acad_Importer_Horarios::commit_row().
		update_post_meta( $post_id, '_cead_acad_horario', wp_json_encode( $this->slots_from_post(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		// Si se asigna delegado/a, asegurar su rol.
		$delegate_id = (int) ( $_POST['_cead_acad_delegate'] ?? 0 );
		if ( $delegate_id ) {
			$u = get_user_by( 'id', $delegate_id );
			if ( $u ) {
				$u->add_role( 'cead_acad_delegate' );
				if ( ! Cead_Acad_Courses_Roster::add( $delegate_id, $post_id, 'delegate' ) ) {
					/* translators: %s: nombre del delegado/a */
					$this->flag_roster_error( sprintf( __( 'El curso se guardó, pero no fue posible inscribir a %s como delegado o delegada. Puede agregar a esa persona manualmente desde el listado de estudiantes.', 'cead-acad' ), $u->display_name ) );
				}
			}
		}
		// Delegado/a rotado: revocar el rol/roster de quien dejó de serlo en
		// este curso, salvo que siga siendo delegado/a de otro curso.
		if ( $old_delegate_id && $old_delegate_id !== $delegate_id ) {
			Cead_Acad_Courses_Roster::remove( $old_delegate_id, $post_id );
			if ( ! Cead_Acad_Courses_Roster::has_active_role_elsewhere( $old_delegate_id, 'delegate', $post_id ) ) {
				$old_u = get_user_by( 'id', $old_delegate_id );
				if ( $old_u ) {
					$old_u->remove_role( 'cead_acad_delegate' );
				}
			}
		}
		$tutor_id = (int) ( $_POST['_cead_acad_tutor'] ?? 0 );
		if ( $tutor_id && ! Cead_Acad_Courses_Roster::add( $tutor_id, $post_id, 'teacher' ) ) {
			$this->flag_roster_error( __( 'El curso se guardó, pero no fue posible inscribir al tutor o tutora. Puede agregarlo manualmente desde el listado de estudiantes.', 'cead-acad' ) );
		}
	}

	public function render_roster_metabox( $post ) {
		$user_ids = Cead_Acad_Courses_Roster::users_in_course( $post->ID );
		$users    = $user_ids ? get_users( [ 'include' => $user_ids, 'orderby' => 'display_name' ] ) : [];
		?>
		<p><strong><?php /* translators: %d: cantidad de inscriptos activos */ printf( esc_html__( 'Inscriptos activos: %d', 'cead-acad' ), count( $user_ids ) ); ?></strong></p>
		<?php if ( $users ) : ?>
			<ul style="max-height:200px;overflow:auto;margin:0;padding-left:18px">
				<?php foreach ( $users as $u ) : ?>
					<li>
						<?php echo esc_html( $u->display_name ); ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'cead_acad_roster_remove' ); ?>
							<input type="hidden" name="action" value="cead_acad_roster_remove">
							<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
							<input type="hidden" name="course_id" value="<?php echo (int) $post->ID; ?>">
							<button class="button-link-delete" style="font-size:11px">×</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<hr>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cead_acad_roster_add' ); ?>
			<input type="hidden" name="action" value="cead_acad_roster_add">
			<input type="hidden" name="course_id" value="<?php echo (int) $post->ID; ?>">
			<label><?php esc_html_e( 'Agregar usuario', 'cead-acad' ); ?></label>
			<?php
			$all_students = get_users( [
				'role__in' => [ 'cead_acad_student', 'cead_acad_delegate' ],
				'orderby'  => 'display_name',
				'number'   => 500,
				'exclude'  => $user_ids,
			] );
			?>
			<select name="user_id" style="width:100%">
				<option value=""><?php esc_html_e( '— elegir —', 'cead-acad' ); ?></option>
				<?php foreach ( $all_students as $u ) : ?>
					<option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="role_in_course" style="width:100%;margin-top:6px">
				<option value="student"><?php esc_html_e( 'Alumno/a', 'cead-acad' ); ?></option>
				<option value="delegate"><?php esc_html_e( 'Delegado/a', 'cead-acad' ); ?></option>
				<option value="teacher"><?php esc_html_e( 'Docente', 'cead-acad' ); ?></option>
			</select>
			<button class="button button-primary" style="margin-top:6px"><?php esc_html_e( 'Agregar', 'cead-acad' ); ?></button>
		</form>
		<?php
	}

	public function handle_add_user() {
		check_admin_referer( 'cead_acad_roster_add' );
		if ( ! current_user_can( 'cead_acad_manage_courses' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$user_id   = (int) ( $_POST['user_id'] ?? 0 );
		$course_id = (int) ( $_POST['course_id'] ?? 0 );
		$role      = sanitize_key( $_POST['role_in_course'] ?? 'student' );
		$destino   = get_edit_post_link( $course_id, '' );
		if ( $user_id && $course_id && ! Cead_Acad_Courses_Roster::add( $user_id, $course_id, $role ) ) {
			$destino = add_query_arg( 'cead_roster_error', 1, $destino );
		}
		wp_safe_redirect( $destino );
		exit;
	}

	public function handle_remove_user() {
		check_admin_referer( 'cead_acad_roster_remove' );
		if ( ! current_user_can( 'cead_acad_manage_courses' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$user_id   = (int) ( $_POST['user_id'] ?? 0 );
		$course_id = (int) ( $_POST['course_id'] ?? 0 );
		if ( $user_id && $course_id ) {
			Cead_Acad_Courses_Roster::remove( $user_id, $course_id );
		}
		wp_safe_redirect( get_edit_post_link( $course_id, '' ) );
		exit;
	}

	public function columns( $cols ) {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['cead_acad_count'] = __( 'Inscriptos', 'cead-acad' );
				$new['cead_acad_turno'] = __( 'Turno', 'cead-acad' );
			}
		}
		return $new;
	}

	public function render_column( $col, $post_id ) {
		if ( 'cead_acad_count' === $col ) {
			echo (int) Cead_Acad_Courses_Roster::count_active_in_course( $post_id );
		}
		if ( 'cead_acad_turno' === $col ) {
			$turno = get_post_meta( $post_id, '_cead_acad_turno', true );
			$labels = [ 'manana' => 'Mañana', 'tarde' => 'Tarde', 'noche' => 'Noche' ];
			echo esc_html( $labels[ $turno ] ?? '—' );
		}
	}
}
