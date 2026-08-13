<?php
/**
 * Roles y capabilities del plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Capabilities {

	/**
	 * Lista de roles que el plugin instala. Idempotente. Memoizada por request.
	 */
	public static function roles() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		return $cache = [
			'cead_acad_direction' => [
				'display' => 'Dirección',
				'caps'    => array_merge(
					self::base_caps(),
					[
						'cead_acad_manage_invitations'   => true,
						'cead_acad_manage_courses'       => true,
						'cead_acad_assign_delegate'      => true,
						'cead_acad_manage_articles'      => true,
						'cead_acad_manage_roles'         => true,
						'cead_acad_publish_broadcast'    => true,
						'cead_acad_publish_broadcast_all'=> true,
						'cead_acad_create_survey'        => true,
						'cead_acad_view_survey_results'  => true,
						'cead_acad_manage_schedule'      => true,
						'cead_acad_upload_resource'      => true,
						'cead_acad_import_data'          => true,
						'cead_acad_view_metrics'         => true,
						'cead_acad_assign_tasks'         => true,
						'cead_acad_record_grade'         => true,
						'cead_acad_view_course_grades'   => true,
						'cead_acad_manage_reports'       => true,
						'cead_acad_manage_suggestions'   => true,
						'cead_acad_view_other_schedules' => true,
						'cead_acad_view_delegates'       => true,
					]
				),
			],
			'cead_acad_secretary' => [
				'display' => 'Secretaría',
				'caps'    => array_merge(
					self::base_caps(),
					[
						'cead_acad_manage_invitations'   => true,
						'cead_acad_manage_courses'       => true,
						'cead_acad_publish_broadcast'    => true,
						'cead_acad_publish_broadcast_all'=> true,
						'cead_acad_create_survey'        => true,
						'cead_acad_manage_schedule'      => true,
						'cead_acad_upload_resource'      => true,
						'cead_acad_import_data'          => true,
						'cead_acad_assign_tasks'         => true,
						'cead_acad_record_grade'         => true,
						'cead_acad_view_course_grades'   => true,
						'cead_acad_manage_reports'       => true,
						'cead_acad_manage_suggestions'   => true,
						'cead_acad_manage_articles'      => true,
						'cead_acad_view_other_schedules' => true,
						'cead_acad_view_delegates'       => true,
					]
				),
			],
			'cead_acad_teacher' => [
				'display' => 'Docente',
				'caps'    => array_merge(
					self::base_caps(),
					[
						'cead_acad_publish_broadcast'    => true,
						'cead_acad_create_survey'        => true,
						'cead_acad_manage_schedule'      => true,
						'cead_acad_upload_resource'      => true,
						'cead_acad_record_grade'         => true,
						'cead_acad_view_course_grades'   => true,
						'cead_acad_view_other_schedules' => true,
						'cead_acad_view_delegates'       => true,
					]
				),
			],
			'cead_acad_delegate' => [
				'display' => 'Delegado',
				'caps'    => array_merge(
					self::base_caps(),
					[
						'cead_acad_upload_resource'         => true,
						'cead_acad_complete_delegate_task'  => true,
						'cead_acad_view_own_grades'         => true,
						'cead_acad_view_other_schedules'    => true,
						'cead_acad_view_delegates'          => true,
					]
				),
			],
			'cead_acad_student' => [
				'display' => 'Alumno/a',
				'caps'    => array_merge(
					self::base_caps(),
					[
						'cead_acad_view_own_grades' => true,
					]
				),
			],
			'cead_acad_guardian' => [
				'display' => 'Familia',
				// Las familias ven calendario, eventos, comunicados y tareas a nivel
				// colegio, pero NO calificaciones (para evitar conflictos).
				'caps'    => self::base_caps(),
			],
			'cead_acad_student_council' => [
				'display' => 'Consejo Estudiantil',
				'caps'    => array_merge(
					self::base_caps(),
					[
						'cead_acad_publish_broadcast'  => true,
						'cead_acad_manage_suggestions' => true,
					]
				),
			],
		];
	}

	/**
	 * Caps base que todos los roles del plugin comparten.
	 */
	protected static function base_caps() {
		return [
			'read'                  => true,
			'cead_acad_view_panel'  => true,
		];
	}

	public static function install() {
		foreach ( self::roles() as $slug => $cfg ) {
			$existing = get_role( $slug );
			if ( $existing ) {
				// Asegurar caps actualizadas en upgrade.
				foreach ( $cfg['caps'] as $cap => $grant ) {
					$existing->add_cap( $cap, (bool) $grant );
				}
				continue;
			}
			add_role( $slug, $cfg['display'], $cfg['caps'] );
		}

		// El Consejo dejó de gestionar reportes (ahora solo sugerencias de su categoría):
		// quitar la cap vieja en instalaciones que ya la tenían (add_cap no la remueve).
		$council = get_role( 'cead_acad_student_council' );
		if ( $council && $council->has_cap( 'cead_acad_manage_reports' ) ) {
			$council->remove_cap( 'cead_acad_manage_reports' );
		}

		// Las familias ya no ven calificaciones: quitar la cap en instalaciones
		// que ya la tenían (add_cap no la remueve).
		$guardian = get_role( 'cead_acad_guardian' );
		if ( $guardian && $guardian->has_cap( 'cead_acad_view_own_grades' ) ) {
			$guardian->remove_cap( 'cead_acad_view_own_grades' );
		}

		// Permitir a admin manejar todo lo del plugin.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::roles() as $cfg ) {
				foreach ( $cfg['caps'] as $cap => $grant ) {
					$admin->add_cap( $cap, (bool) $grant );
				}
			}
		}
	}

	public static function uninstall() {
		foreach ( array_keys( self::roles() ) as $slug ) {
			remove_role( $slug );
		}
	}

	/**
	 * Filtro: cualquier user con `manage_options` (administradores típicos) recibe
	 * todas las capabilities `cead_acad_*` automáticamente, aunque install() no
	 * se haya re-ejecutado tras un upgrade que agregó caps nuevas.
	 *
	 * Engancha en `user_has_cap` con prioridad temprana para que actúe antes
	 * que filtros restrictivos.
	 */
	public static function grant_plugin_caps_to_admins( $allcaps, $caps, $args, $user ) {
		if ( empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}
		foreach ( (array) $caps as $cap ) {
			if ( is_string( $cap ) && str_starts_with( $cap, 'cead_acad_' ) ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Comprueba si el usuario actual pertenece al ecosistema del plugin.
	 */
	public static function user_in_plugin( $user = null ) {
		$user = $user ?: wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( str_starts_with( $role, 'cead_acad_' ) ) {
				return true;
			}
		}
		return false;
	}
}
