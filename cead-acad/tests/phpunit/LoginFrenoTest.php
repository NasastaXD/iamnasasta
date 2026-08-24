<?php
/**
 * El freno de fuerza bruta del login.
 *
 * El tope anterior era «ocho intentos por minuto y por IP», y era peor que no
 * tener nada: **todo el colegio sale por una sola IP pública**. Contando además
 * los ingresos exitosos, el noveno alumno de la fila en el acto de registro se
 * comía un «probá más tarde» sin haber hecho nada mal y sin forma de entender
 * por qué. Una medida que castiga a la gente correcta se termina desactivando
 * entera, y ahí no queda ninguna.
 *
 * Ahora hay dos contadores y solo cuentan los FALLOS: uno por cuenta —el que de
 * verdad frena a quien adivina, porque lo sigue aunque cambie de IP— y uno por
 * IP, bien alto, para el barrido desde un mismo lugar.
 */

use PHPUnit\Framework\TestCase;

final class LoginFrenoTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
		$_SERVER['REMOTE_ADDR'] = '190.0.0.1';
	}

	/* ------------------------- el caso del acto de registro ----------------- */

	/**
	 * Treinta alumnos entrando bien, uno detrás de otro, desde el wifi del
	 * colegio. Ninguno tiene que quedar afuera: entrar bien no gasta cupo.
	 */
	public function test_un_curso_entero_puede_entrar_desde_la_misma_ip(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			$usuario = "alumno{$i}";
			$this->assertTrue(
				cead_acad_login_permitido( $usuario ),
				"El alumno {$i} quedó bloqueado entrando desde la IP del colegio."
			);
			cead_acad_login_ok( $usuario );
		}
	}

	/**
	 * Y aunque varios se equivoquen una vez —que es lo normal— el resto sigue
	 * pudiendo entrar.
	 */
	public function test_errores_sueltos_de_varios_no_bloquean_a_los_demas(): void {
		for ( $i = 1; $i <= 20; $i++ ) {
			cead_acad_login_fallo( "alumno{$i}" );
		}

		$this->assertTrue( cead_acad_login_permitido( 'alumno21' ) );
	}

	/* ----------------------------- frenar de verdad ------------------------- */

	/** Cinco fallos sobre la MISMA cuenta y se corta. */
	public function test_frena_a_quien_insiste_con_una_cuenta(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( cead_acad_login_permitido( 'ana' ), "Cortó antes de tiempo, en el intento {$i}." );
			cead_acad_login_fallo( 'ana' );
		}

		$this->assertFalse( cead_acad_login_permitido( 'ana' ) );
	}

	/**
	 * El bloqueo es de la CUENTA, no de la IP: cambiar de red no lo evita. Es
	 * lo que hace que el contador por usuario valga la pena.
	 */
	public function test_el_bloqueo_de_una_cuenta_sigue_desde_otra_ip(): void {
		for ( $i = 0; $i < 5; $i++ ) { cead_acad_login_fallo( 'ana' ); }

		$_SERVER['REMOTE_ADDR'] = '201.99.99.99';

		$this->assertFalse( cead_acad_login_permitido( 'ana' ) );
	}

	/** Y no salpica a las demás cuentas. */
	public function test_bloquear_una_cuenta_no_bloquea_a_las_otras(): void {
		for ( $i = 0; $i < 5; $i++ ) { cead_acad_login_fallo( 'ana' ); }

		$this->assertFalse( cead_acad_login_permitido( 'ana' ) );
		$this->assertTrue( cead_acad_login_permitido( 'bruno' ) );
	}

    /**
     * El barrido desde una IP —muchas cuentas distintas, pocos intentos en cada
     * una— sí se frena, pero con un tope bien por encima de lo que produce un
     * colegio entrando junto.
     */
	public function test_frena_el_barrido_de_muchas_cuentas_desde_una_ip(): void {
		for ( $i = 1; $i <= 60; $i++ ) {
			cead_acad_login_fallo( "victima{$i}" );
		}

		$this->assertFalse( cead_acad_login_permitido( 'victima61' ) );
	}

	/* -------------------------------- el reset ------------------------------ */

	/**
	 * Acertar limpia el contador de esa cuenta. Sin esto, quien se equivoca
	 * cuatro veces y acierta a la quinta quedaría con el contador casi lleno, y
	 * el próximo error lo dejaría afuera quince minutos habiendo demostrado que
	 * sabe su contraseña.
	 */
	public function test_entrar_bien_limpia_el_contador(): void {
		for ( $i = 0; $i < 4; $i++ ) { cead_acad_login_fallo( 'ana' ); }

		cead_acad_login_ok( 'ana' );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertTrue( cead_acad_login_permitido( 'ana' ) );
			cead_acad_login_fallo( 'ana' );
		}
	}

	/* -------------------------------- bordes -------------------------------- */

	/** El usuario se compara sin distinguir mayúsculas ni espacios sobrantes. */
	public function test_el_usuario_se_normaliza(): void {
		for ( $i = 0; $i < 5; $i++ ) { cead_acad_login_fallo( 'Ana' ); }

		$this->assertFalse( cead_acad_login_permitido( '  ana  ' ) );
	}

	/** Sin usuario no hay contador de cuenta, pero el de IP sigue valiendo. */
	public function test_sin_usuario_no_rompe(): void {
		$this->assertTrue( cead_acad_login_permitido( '' ) );
		cead_acad_login_fallo( '' );
		$this->assertTrue( cead_acad_login_permitido( '' ) );
	}
}
