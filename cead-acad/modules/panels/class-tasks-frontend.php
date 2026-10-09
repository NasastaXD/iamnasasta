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
			'meta_query'     => [
				[ 'key' => '_cead_acad_task_course', 'value' => $courses, 'compare' => 'IN' ],
			],
		] );
		$posts = $q->posts;
		// Orden por vencimiento; las tareas sin fecha van al final (no al
		// principio, que es como MySQL ordena los NULL en ASC por defecto).
		usort( $posts, static function ( $a, $b ) {
			$da = (string) get_post_meta( $a->ID, '_cead_acad_task_due_date', true );
			$db = (string) get_post_meta( $b->ID, '_cead_acad_task_due_date', true );
			if ( $da === '' && $db === '' ) { return 0; }
			if ( $da === '' ) { return 1; }
			if ( $db === '' ) { return -1; }
			return strcmp( $da, $db );
		} );
		return $posts;
	}

	/** ¿La tarea pertenece a un curso del usuario? */
	public static function user_can_task( $user_id, $task_id ) {
		$course = (int) get_post_meta( $task_id, '_cead_acad_task_course', true );
		return $course && in_array( $course, self::user_courses( $user_id ), true );
	}

	/**
	 * Deja la tarea hecha o pendiente según `$hecha`.
	 *
	 * Fija un estado en vez de invertirlo, por la misma razón que los
	 * favoritos: la app reenvía lo que se tocó sin señal, y un interruptor
	 * reenviado dos veces vuelve a donde estaba.
	 *
	 * @return bool|WP_Error El estado que quedó, o `forbidden`.
	 */
	public static function fijar_hecha( $user_id, $task_id, $hecha ) {
		$task_id = (int) $task_id;
		if ( ! $task_id || ! self::user_can_task( $user_id, $task_id ) ) {
			return new WP_Error( 'forbidden', __( 'Esa tarea no es de tu curso.', 'cead-acad' ) );
		}
		$ids = array_values( array_diff( self::done_ids( $user_id ), [ $task_id ] ) );
		if ( $hecha ) {
			$ids[] = $task_id;
		}
		update_user_meta( (int) $user_id, self::DONE_META, $ids );
		return (bool) $hecha;
	}

	/**
	 * Guarda la entrega que vino en `$_FILES[ $clave ]`, reemplazando la anterior.
	 *
	 * @return int|WP_Error Id del adjunto, o `forbidden` / `archivo` / `subida`.
	 */
	public static function guardar_entrega( $user_id, $task_id, $clave = 'entrega' ) {
		$task_id = (int) $task_id;
		if ( ! $task_id || ! self::user_can_task( $user_id, $task_id ) ) {
			return new WP_Error( 'forbidden', __( 'Esa tarea no es de tu curso.', 'cead-acad' ) );
		}
		if ( empty( $_FILES[ $clave ]['name'] ) || ! empty( $_FILES[ $clave ]['error'] ) ) {
			return new WP_Error( 'archivo', __( 'Falta el archivo de la entrega.', 'cead-acad' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attach_id = media_handle_upload( $clave, 0, [], [ 'test_form' => false ] );
		if ( is_wp_error( $attach_id ) ) {
			return new WP_Error( 'subida', __( 'No se pudo subir el archivo.', 'cead-acad' ) );
		}
		$old = self::submission_id( $user_id, $task_id );
		update_user_meta( (int) $user_id, self::SUB_META . $task_id, (int) $attach_id );
		if ( $old && $old !== (int) $attach_id ) {
			wp_delete_attachment( $old, true );
		}
		return (int) $attach_id;
	}

	/* ----- Handlers ----- */

	public function handle_done() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_task_done' );

		$uid     = get_current_user_id();
		$task_id = (int) ( $_POST['task_id'] ?? 0 );

		// El botón de la web sigue siendo un interruptor: acá se decide hacia
		// dónde, y lo que se guarda es el estado.
		self::fijar_hecha( $uid, $task_id, ! self::is_done( $uid, $task_id ) );

		wp_safe_redirect( cead_acad_url( 'panel/tareas' ) );
		exit;
	}

	public function handle_submit() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( cead_acad_url( 'login' ) ); exit; }
		check_admin_referer( 'cead_acad_task_submit' );

		$r = self::guardar_entrega( get_current_user_id(), (int) ( $_POST['task_id'] ?? 0 ), 'entrega' );
		$dest = cead_acad_url( 'panel/tareas' );
		if ( is_wp_error( $r ) ) {
			wp_safe_redirect( add_query_arg( 'err', $r->get_error_code(), $dest ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'done', 1, $dest ) );
		exit;
	}
}
