<?php
/**
 * CPT cead_acad_task: tareas pendientes asignadas a un curso, gestionadas
 * por el delegado/a del curso.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Tasks_CPT {

	const POST_TYPE = 'cead_acad_task';

	const STATUSES  = [ 'pendiente', 'en_curso', 'hecha', 'cancelada' ];
	const PRIORITIES = [ 'baja', 'normal', 'alta' ];

	public function boot() {
		add_action( 'init',                                       [ $this, 'register' ], 10 );
		add_action( 'add_meta_boxes',                             [ $this, 'metaboxes' ] );
		add_action( 'save_post_' . self::POST_TYPE,               [ $this, 'save' ], 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',       [ $this, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

		add_action( 'admin_post_cead_acad_task_set_status', [ $this, 'handle_set_status' ] );
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Tareas', 'cead-acad' ),
				'singular_name' => __( 'Tarea', 'cead-acad' ),
				'add_new'       => __( 'Nueva tarea', 'cead-acad' ),
				'add_new_item'  => __( 'Nueva tarea', 'cead-acad' ),
				'edit_item'     => __( 'Editar tarea', 'cead-acad' ),
				'menu_name'     => __( 'Tareas (delegado)', 'cead-acad' ),
				'not_found'     => __( 'Sin tareas.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-clipboard',
			'supports'            => [ 'title', 'editor', 'author' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		] );

		foreach ( [
			'_cead_acad_task_course'    => 'integer',
			'_cead_acad_task_status'    => 'string',
			'_cead_acad_task_priority'  => 'string',
			'_cead_acad_task_due_date'  => 'string',
		] as $key => $type ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function () { return current_user_can( 'cead_acad_assign_tasks' ) || current_user_can( 'cead_acad_complete_delegate_task' ); },
			] );
		}
	}

	public function metaboxes() {
		add_meta_box(
			'cead_acad_task_meta',
			__( 'Detalles', 'cead-acad' ),
			[ $this, 'render_metabox' ],
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'cead_acad_task_save', '_cead_acad_task_nonce' );
		$course   = (int)    get_post_meta( $post->ID, '_cead_acad_task_course',   true );
		$status   = (string) get_post_meta( $post->ID, '_cead_acad_task_status',   true ) ?: 'pendiente';
		$priority = (string) get_post_meta( $post->ID, '_cead_acad_task_priority', true ) ?: 'normal';
		$due      = (string) get_post_meta( $post->ID, '_cead_acad_task_due_date', true );
		$courses  = Cead_Acad_Courses_CPT::options();
		?>
		<p>
			<label><strong><?php esc_html_e( 'Curso', 'cead-acad' ); ?></strong></label><br>
			<select name="_cead_acad_task_course" style="width:100%">
				<option value=""><?php esc_html_e( '— elegir —', 'cead-acad' ); ?></option>
				<?php foreach ( $courses as $id => $title ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( $course, $id ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Estado', 'cead-acad' ); ?></strong></label><br>
			<select name="_cead_acad_task_status" style="width:100%">
				<?php foreach ( self::STATUSES as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( self::status_label( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Prioridad', 'cead-acad' ); ?></strong></label><br>
			<select name="_cead_acad_task_priority" style="width:100%">
				<?php foreach ( self::PRIORITIES as $p ) : ?>
					<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $priority, $p ); ?>><?php echo esc_html( self::priority_label( $p ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Vence el', 'cead-acad' ); ?></strong></label><br>
			<input type="date" name="_cead_acad_task_due_date" value="<?php echo esc_attr( $due ); ?>">
		</p>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_cead_acad_task_nonce'] ) || ! wp_verify_nonce( $_POST['_cead_acad_task_nonce'], 'cead_acad_task_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( [ '_cead_acad_task_course', '_cead_acad_task_status', '_cead_acad_task_priority', '_cead_acad_task_due_date' ] as $key ) {
			$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
			update_post_meta( $post_id, $key, $val );
		}
	}

	public function columns( $cols ) {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['cead_acad_task_course']   = __( 'Curso', 'cead-acad' );
				$new['cead_acad_task_status']   = __( 'Estado', 'cead-acad' );
				$new['cead_acad_task_priority'] = __( 'Prioridad', 'cead-acad' );
				$new['cead_acad_task_due']      = __( 'Vence', 'cead-acad' );
			}
		}
		return $new;
	}

	public function render_column( $col, $post_id ) {
		if ( 'cead_acad_task_course' === $col ) {
			$cid = (int) get_post_meta( $post_id, '_cead_acad_task_course', true );
			echo $cid ? esc_html( get_the_title( $cid ) ?: '#' . $cid ) : '—';
		}
		if ( 'cead_acad_task_status' === $col ) {
			echo esc_html( self::status_label( get_post_meta( $post_id, '_cead_acad_task_status', true ) ?: 'pendiente' ) );
		}
		if ( 'cead_acad_task_priority' === $col ) {
			echo esc_html( self::priority_label( get_post_meta( $post_id, '_cead_acad_task_priority', true ) ?: 'normal' ) );
		}
		if ( 'cead_acad_task_due' === $col ) {
			$due = get_post_meta( $post_id, '_cead_acad_task_due_date', true );
			echo $due ? esc_html( $due ) : '—';
		}
	}

	public function handle_set_status() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( cead_acad_url( 'login' ) );
			exit;
		}
		check_admin_referer( 'cead_acad_task_set_status' );

		$task_id = (int) ( $_POST['task_id'] ?? 0 );
		$status  = sanitize_key( $_POST['status'] ?? '' );
		if ( ! $task_id || ! in_array( $status, self::STATUSES, true ) ) {
			wp_safe_redirect( cead_acad_url( 'panel/delegado' ) );
			exit;
		}

		$task = get_post( $task_id );
		if ( ! $task || $task->post_type !== self::POST_TYPE ) {
			wp_safe_redirect( cead_acad_url( 'panel/delegado' ) );
			exit;
		}

		// Permisos: dirección/secretaría siempre; delegado solo del curso asignado.
		$user = wp_get_current_user();
		$can  = current_user_can( 'cead_acad_assign_tasks' );
		if ( ! $can ) {
			if ( current_user_can( 'cead_acad_complete_delegate_task' ) ) {
				$task_course = (int) get_post_meta( $task_id, '_cead_acad_task_course', true );
				$user_courses = Cead_Acad_Courses_Roster::courses_for_user( $user->ID );
				$can = in_array( $task_course, $user_courses, true );
			}
		}
		if ( ! $can ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ), 403 );
		}

		update_post_meta( $task_id, '_cead_acad_task_status', $status );

		wp_safe_redirect( cead_acad_url( 'panel/delegado' ) );
		exit;
	}

	public static function status_label( $s ) {
		return [
			'pendiente' => __( 'Pendiente', 'cead-acad' ),
			'en_curso'  => __( 'En curso', 'cead-acad' ),
			'hecha'     => __( 'Hecha', 'cead-acad' ),
			'cancelada' => __( 'Cancelada', 'cead-acad' ),
		][ $s ] ?? $s;
	}

	public static function priority_label( $p ) {
		return [
			'baja'   => __( 'Baja', 'cead-acad' ),
			'normal' => __( 'Normal', 'cead-acad' ),
			'alta'   => __( 'Alta', 'cead-acad' ),
		][ $p ] ?? $p;
	}

	public static function for_user( $user_id, $statuses = null ) {
		$courses = Cead_Acad_Courses_Roster::courses_for_user( $user_id );
		if ( ! $courses ) {
			return [];
		}
		$meta_query = [
			[ 'key' => '_cead_acad_task_course', 'value' => array_map( 'intval', $courses ), 'compare' => 'IN' ],
		];
		if ( $statuses ) {
			$meta_query[] = [
				'key'     => '_cead_acad_task_status',
				'value'   => (array) $statuses,
				'compare' => 'IN',
			];
		}
		return get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query,
			'no_found_rows'  => true,
		] );
	}
}
