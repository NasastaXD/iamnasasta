<?php
/**
 * `save_user_course()` — mover a alguien de curso desde la pantalla de
 * Usuarios de wp-admin.
 *
 * Sigue el mismo contrato que ya usan el alta por invitación y el importador
 * de alumnado: primero `Roster::add()` al curso nuevo, y solo si esa
 * escritura anduvo se toca `_cead_acad_current_course_id` y se da de baja el
 * curso anterior. Si el insert falla, la persona queda como estaba —en el
 * curso viejo, no en ningún curso a medias.
 */

use PHPUnit\Framework\TestCase;

final class AdminMenuUserCourseTest extends TestCase {

	/** @var Cead_Test_WPDB */
	private $wpdb;

	private $menu;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new Cead_Test_WPDB();
		$wpdb       = $this->wpdb;
		cead_test_reset_usermeta();
		$this->menu = new Cead_Acad_Admin_Menu();
	}

	private function call( $user_id, $new_course_id, $role ) {
		$m = new ReflectionMethod( 'Cead_Acad_Admin_Menu', 'save_user_course' );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->menu, [ $user_id, $new_course_id, $role ] );
	}

	public function test_asignar_curso_a_quien_no_tenia_actualiza_la_meta(): void {
		$this->wpdb->row = null; // sin inscripción previa

		$err = $this->call( 7, 33, 'cead_acad_student' );

		$this->assertNull( $err );
		$this->assertSame( 33, (int) get_user_meta( 7, '_cead_acad_current_course_id', true ) );
	}

	public function test_asignar_curso_usa_role_in_course_segun_el_rol_del_plugin(): void {
		$this->wpdb->row = null;

		$this->call( 7, 33, 'cead_acad_teacher' );

		$insert = $this->wpdb->writes[0];
		$this->assertSame( 'insert', $insert[0] );
		$this->assertSame( 'teacher', $insert[2]['role_in_course'] );
	}

	public function test_cambiar_de_curso_da_de_baja_el_anterior_y_actualiza_la_meta(): void {
		update_user_meta( 7, '_cead_acad_current_course_id', 10 );
		$this->wpdb->row = null; // el nuevo curso: sin inscripción previa

		$err = $this->call( 7, 20, 'cead_acad_student' );

		$this->assertNull( $err );
		$this->assertSame( 20, (int) get_user_meta( 7, '_cead_acad_current_course_id', true ) );

		// Se dio de baja (soft-delete) al curso viejo: un update con status inactive.
		$updates = array_filter( $this->wpdb->writes, static fn( $w ) => 'update' === $w[0] );
		$this->assertNotEmpty( $updates );
		$last = end( $updates );
		$this->assertSame( 'inactive', $last[2]['status'] );
		$this->assertSame( [ 'user_id' => 7, 'course_id' => 10 ], $last[3] );
	}

	public function test_quitar_el_curso_borra_la_meta_y_da_de_baja_el_roster(): void {
		update_user_meta( 7, '_cead_acad_current_course_id', 10 );

		$err = $this->call( 7, 0, 'cead_acad_student' );

		$this->assertNull( $err );
		$this->assertSame( '', get_user_meta( 7, '_cead_acad_current_course_id', true ) );
		$this->assertSame( 'update', $this->wpdb->writes[0][0] );
		$this->assertSame( 'inactive', $this->wpdb->writes[0][2]['status'] );
	}

	public function test_mismo_curso_no_escribe_nada(): void {
		update_user_meta( 7, '_cead_acad_current_course_id', 10 );

		$err = $this->call( 7, 10, 'cead_acad_student' );

		$this->assertNull( $err );
		$this->assertSame( [], $this->wpdb->writes );
	}

	/**
	 * El caso que ya rompió en otro lado (ver RosterAddTest): si el insert
	 * falla, no hay que confundir eso con éxito. Acá el efecto es que la
	 * persona se queda en el curso que ya tenía, no huérfana.
	 */
	public function test_si_falla_la_inscripcion_no_toca_la_meta_y_avisa(): void {
		update_user_meta( 7, '_cead_acad_current_course_id', 10 );
		$this->wpdb->row           = null;
		$this->wpdb->insert_result = false;

		$err = $this->call( 7, 20, 'cead_acad_student' );

		$this->assertNotNull( $err );
		$this->assertSame( 10, (int) get_user_meta( 7, '_cead_acad_current_course_id', true ) );
	}
}
