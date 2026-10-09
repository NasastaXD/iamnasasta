<?php
/**
 * El resto de la gestión del staff: invitaciones, notas, notas del sitio y
 * tareas del curso.
 *
 * Lo que se prueba es lo que abre una puerta si sale mal: que Secretaría no
 * pueda invitar a alguien como Dirección desde el teléfono, que pedir «redes»
 * no alcance para publicar en las redes del colegio, y que una tarea o una
 * nota no se cambie sin el permiso que corresponde.
 */

use PHPUnit\Framework\TestCase;

final class GestionStaffTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_caps();
		cead_test_reset_options();
	}

	/* -------------------------------------------------------- invitaciones */

	/** Secretaría no se da —ni le da a otro— el rol más alto del colegio. */
	public function test_solo_direccion_invita_a_direccion(): void {
		cead_test_set_caps( 2, [ 'cead_acad_manage_invitations' => true ] );
		$this->assertTrue( Cead_Acad_Invitations::puede_asignar( 2, 'cead_acad_student' ) );
		$this->assertTrue( Cead_Acad_Invitations::puede_asignar( 2, 'cead_acad_teacher' ) );
		$this->assertFalse( Cead_Acad_Invitations::puede_asignar( 2, 'cead_acad_direction' ) );

		cead_test_set_caps( 1, [ 'cead_acad_manage_invitations' => true, 'cead_acad_manage_roles' => true ] );
		$this->assertTrue( Cead_Acad_Invitations::puede_asignar( 1, 'cead_acad_direction' ) );
	}

	/** El selector de la app ya viene sin la opción que no se puede usar. */
	public function test_los_roles_invitables_no_ofrecen_direccion_a_secretaria(): void {
		cead_test_set_caps( 2, [ 'cead_acad_manage_invitations' => true ] );
		$roles = array_column( Cead_Acad_API_Gestion::roles_invitables( 2 ), 'valor' );

		$this->assertContains( 'cead_acad_student', $roles );
		$this->assertNotContains( 'cead_acad_direction', $roles );
	}

	public function test_una_invitacion_vigente_trae_su_link(): void {
		$fila = $this->invitacion( [ 'metadata' => wp_json_encode( [ 'token' => 'abc123' ] ) ] );
		$i    = Cead_Acad_API_Gestion::invitacion( $fila );

		$this->assertSame( 'valid', $i['estado'] );
		$this->assertSame( 'https://cead.test/i/abc123', $i['link'] );
		$this->assertSame( 3, $i['restantes'] );
		$this->assertSame( 'Alumno/a', $i['rol_label'] );
	}

	/** Un link revocado o vencido no se ofrece para compartir. */
	public function test_una_invitacion_revocada_o_vencida_no_trae_link(): void {
		$meta = wp_json_encode( [ 'token' => 'abc123' ] );

		$revocada = Cead_Acad_API_Gestion::invitacion( $this->invitacion( [ 'metadata' => $meta, 'revoked_at' => '2026-10-01 00:00:00' ] ) );
		$this->assertSame( 'revoked', $revocada['estado'] );
		$this->assertNull( $revocada['link'] );

		$vencida = Cead_Acad_API_Gestion::invitacion( $this->invitacion( [ 'metadata' => $meta, 'expires_at' => '2020-01-01 00:00:00' ] ) );
		$this->assertSame( 'expired', $vencida['estado'] );
		$this->assertNull( $vencida['link'] );
	}

	private function invitacion( array $cambios ): array {
		return array_merge( [
			'id'         => 7,
			'role'       => 'cead_acad_student',
			'course_id'  => null,
			'email'      => null,
			'max_uses'   => 3,
			'used_count' => 0,
			'used_at'    => null,
			'revoked_at' => null,
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'created_at' => '2026-10-09 12:00:00',
			'metadata'   => null,
		], $cambios );
	}

	/* ---------------------------------------------- notas del sitio: redes */

	public function test_el_telefono_del_director_se_reconoce_aunque_este_escrito_distinto(): void {
		$this->assertTrue( Cead_Acad_Articulos::mismo_telefono( '0981 123 456', '+595 981 123456' ) );
		$this->assertTrue( Cead_Acad_Articulos::mismo_telefono( '595981123456', '00595981123456' ) );
		$this->assertFalse( Cead_Acad_Articulos::mismo_telefono( '0981 123 456', '0981 123 457' ) );
		$this->assertFalse( Cead_Acad_Articulos::mismo_telefono( '', '' ) );
	}

	/** Las redes son del director/a: pedirlas no alcanza. */
	public function test_las_redes_solo_las_pide_el_director(): void {
		cead_test_set_caps( 4, [ 'cead_acad_manage_articles' => true ] );
		$this->assertFalse( Cead_Acad_Articulos::puede_redes( 4 ), 'sin director configurado' );

		cead_test_set_option( 'cead_acad_wa_director_phone', '0981123456' );
		update_user_meta( 4, Cead_Acad_Account::PHONE_META, '0981 999 999' );
		$this->assertFalse( Cead_Acad_Articulos::puede_redes( 4 ), 'otro número' );

		update_user_meta( 4, Cead_Acad_Account::PHONE_META, '+595 981 123 456' );
		$this->assertTrue( Cead_Acad_Articulos::puede_redes( 4 ) );

		cead_test_set_caps( 4, [] );
		$this->assertFalse( Cead_Acad_Articulos::puede_redes( 4 ), 'sin permiso de publicar' );
	}

	public function test_la_fecha_de_una_nota_evento_queda_como_la_lee_el_tema(): void {
		$this->assertSame( '2026-11-20 19:30:00', Cead_Acad_Articulos::fecha_mysql( '2026-11-20T19:30' ) );
		$this->assertSame( '2026-11-20 00:00:00', Cead_Acad_Articulos::fecha_mysql( '2026-11-20' ) );
		$this->assertSame( '', Cead_Acad_Articulos::fecha_mysql( 'el viernes' ) );
	}

	public function test_sin_permiso_no_se_publica_una_nota(): void {
		$r = Cead_Acad_Articulos::publicar( 4, [ 'titulo' => 'Hola', 'contenido' => 'Texto' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 403, $r->get_error_data()['status'] );
	}

	/* --------------------------------------------------------------- notas */

	public function test_sin_permiso_no_se_carga_una_nota(): void {
		$r = Cead_Acad_Notas::cargar( 4, [ 'curso_id' => 1, 'alumno_id' => 2, 'materia_id' => 3, 'periodo' => '1', 'nota' => 4 ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 403, $r->get_error_data()['status'] );
	}

	public function test_sin_permiso_no_hay_opciones_de_notas(): void {
		$r = Cead_Acad_Notas::opciones( 4 );
		$this->assertInstanceOf( WP_Error::class, $r );
	}

	public function test_una_fila_de_notas_se_entrega_con_numeros_de_verdad(): void {
		$f = Cead_Acad_Notas::fila( [
			'id' => '9', 'student_user_id' => '2', 'subject_term_id' => '0', 'period' => 'Segunda Etapa',
			'score' => '4.50', 'letter' => '', 'comments' => 'Bien',
		] );
		$this->assertSame( 9, $f['id'] );
		$this->assertSame( 4.5, $f['nota'] );
		$this->assertSame( 'Segunda Etapa', $f['periodo'] );

		$sin = Cead_Acad_Notas::fila( [ 'id' => 1, 'student_user_id' => 2, 'subject_term_id' => 0, 'period' => 'Final', 'score' => null, 'letter' => 'A', 'comments' => '' ] );
		$this->assertNull( $sin['nota'] );
		$this->assertSame( 'A', $sin['letra'] );
	}

	/* ---------------------------------------------------- tareas del curso */

	public function test_un_estado_que_no_existe_se_rechaza(): void {
		cead_test_set_caps( 1, [ 'cead_acad_assign_tasks' => true ] );
		$r = Cead_Acad_Tasks_CPT::fijar_estado( 1, 5, 'archivada' );
		$this->assertSame( 'estado_invalido', $r->get_error_code() );
	}

	public function test_una_tarea_que_no_existe_da_404(): void {
		cead_test_set_caps( 1, [ 'cead_acad_assign_tasks' => true ] );
		$r = Cead_Acad_Tasks_CPT::fijar_estado( 1, 5, 'hecha' );
		$this->assertSame( 404, $r->get_error_data()['status'] );
	}

	public function test_quien_asigna_tareas_cambia_cualquiera_y_sin_permiso_ninguna(): void {
		cead_test_set_caps( 1, [ 'cead_acad_assign_tasks' => true ] );
		$this->assertTrue( Cead_Acad_Tasks_CPT::puede_cambiar( 1, 5 ) );
		$this->assertFalse( Cead_Acad_Tasks_CPT::puede_cambiar( 9, 5 ) );
	}

	public function test_sin_permiso_no_se_asigna_una_tarea(): void {
		$r = Cead_Acad_Tasks_CPT::crear( [ 'titulo' => 'Juntar la plata del paseo', 'curso_id' => 3, 'autor' => 9 ] );
		$this->assertSame( 403, $r->get_error_data()['status'] );
	}

	public function test_una_tarea_sin_titulo_no_se_crea(): void {
		cead_test_set_caps( 1, [ 'cead_acad_assign_tasks' => true ] );
		$r = Cead_Acad_Tasks_CPT::crear( [ 'titulo' => '  ', 'curso_id' => 3, 'autor' => 1 ] );
		$this->assertSame( 'sin_titulo', $r->get_error_code() );
	}
}
