<?php
/**
 * Vista de tareas para el alumnado: ve las tareas de su curso, las marca como
 * hechas (estado personal) y opcionalmente sube una entrega. No toca notas.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Tasks_Frontend {

	const DONE_META = '_cead_acad_task_done';      // user meta: array de IDs hechos.
	const SUB_META  = '_cead_acad_task_sub_';      // user meta por tarea: attachment id.

	public function boot() {
		add_action( 'admin_post_cead_acad_task_done',   [ $this, 'handle_done' ] );
		add_action( 'admin_post_cead_acad_task_submit', [ $this, 'handle_submit' ] );
	}

	/* ----- Helpers ----- */

	public static function done_ids( $user_id ) {
		$ids = get_user_meta( (int) $user_id, self::DONE_META, true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	public static function is_done( $user_id, $task_id ) {
		return in_array( (int) $task_id, self::done_ids( $user_id ), true );
	}

	public static function submission_id( $user_id, $task_id ) {
		return (int) get_user_meta( (int) $user_id, self::SUB_META . (int) $task_id, true );
	}

	/** Cursos del usuario (para validar pertenencia). */
	protected static function user_courses( $user_id ) {
		return class_exists( 'Cead_Acad_Courses_Roster' ) ? array_map( 'intval', Cead_Acad_Courses_Roster::courses_for_user( $user_id ) ) : [];
	}

	/** Tareas de los cursos del usuario, ordenadas por vencimiento. */
	public static function tasks_for_user( $user_id ) {
		$courses = self::user_courses( $user_id );
		if ( ! $courses ) { return []; }

		$q = new WP_Query( [
			'post_type'      => Cead_Acad_Tasks_CPT::POST_TYPE,
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cead_acad_task_due_date',
			'order'          => 'ASC',
			'meta_query'     => [
				[ 'key' => '_cead_acad_task_course', 'value' => $courses, 'compare' => 'IN' ],
			],
		] );
		return $q->posts;
	}

	/** ¿La tarea pertenece a un curso del usuario? */
	protected static function user_can_task( $user_id, $task_id ) {
		$course = (int) get_post_meta( $task_id, '_cead_acad_task_course', true );
		return $course && in_array( $course, self::user_courses( $user_id ), true );
	}

	/* ----- Handlers ----- */

	public function handle_done() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_task_done' );

		$uid     = get_current_user_id();
		$task_id = (int) ( $_POST['task_id'] ?? 0 );
		$dest    = cead_acad_url( 'panel/tareas' );

		if ( $task_id && self::user_can_task( $uid, $task_id ) ) {
			$ids = self::done_ids( $uid );
			if ( in_array( $task_id, $ids, true ) ) {
				$ids = array_values( array_diff( $ids, [ $task_id ] ) );
			} else {
				$ids[] = $task_id;
			}
			update_user_meta( $uid, self::DONE_META, $ids );
		}
		wp_safe_redirect( $dest );
		exit;
	}

	public function handle_submit() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_task_submit' );

		$uid     = get_current_user_id();
		$task_id = (int) ( $_POST['task_id'] ?? 0 );
		$dest    = cead_acad_url( 'panel/tareas' );

		if ( ! $task_id || ! self::user_can_task( $uid, $task_id ) ) {
			wp_safe_redirect( add_query_arg( 'err', 'forbidden', $dest ) );
			exit;
		}
		if ( empty( $_FILES['entrega']['name'] ) || ! empty( $_FILES['entrega']['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'err', 'archivo', $dest ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attach_id = media_handle_upload( 'entrega', 0, [], [ 'test_form' => false ] );
		if ( is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'err', 'subida', $dest ) );
			exit;
		}
		$old = self::submission_id( $uid, $task_id );
		update_user_meta( $uid, self::SUB_META . $task_id, (int) $attach_id );
		if ( $old && $old !== (int) $attach_id ) {
			wp_delete_attachment( $old, true );
		}
		wp_safe_redirect( add_query_arg( 'done', 1, $dest ) );
		exit;
	}
}
