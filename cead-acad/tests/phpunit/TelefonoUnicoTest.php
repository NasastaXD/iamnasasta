<?php
/**
 * El teléfono es la llave con la que CEADI reconoce a quien le escribe.
 *
 * Que dos cuentas compartan número no produce ningún error: produce que el bot
 * conteste, con total seguridad, los datos de la persona equivocada. No hay
 * excepción, ni log, ni nada raro en pantalla — la única señal es un alumno
 * diciendo «me muestra las notas de otro».
 *
 * Estos tests fijan las dos mitades de eso:
 *   1. Que `phone_taken_by()` detecte el choque ANTES de guardarlo.
 *   2. Que lo detecte con EXACTAMENTE el mismo criterio con el que el bot
 *      después busca — si el alta acepta un número que el bot resuelve a otra
 *      ficha, la validación no sirvió de nada.
 */

use PHPUnit\Framework\TestCase;

final class TelefonoUnicoTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
		cead_test_reset_usermeta();
	}

	/** Deja a $uid con ese teléfono en la ficha, por la puerta real. */
	private function conTelefono( int $uid, string $tel ): void {
		Cead_Acad_WA_Identity::store_phone( $uid, $tel );
	}

	/** Ficha vieja: solo el número visible, sin la clave canónica. */
	private function fichaVieja( int $uid, string $tel ): void {
		update_user_meta( $uid, '_cead_acad_phone', $tel );
	}

	/* ------------- el número escrito con separadores (el bug grave) --------- */

	/**
	 * El bug que dejaba invisible a un alumno registrado.
	 *
	 * La búsqueda anterior hacía un LIKE por los últimos OCHO dígitos sobre el
	 * número tal como estaba escrito. En «0981 111 111» esos ocho dígitos no
	 * están seguidos, así que el LIKE no encontraba nada: la persona se
	 * registraba bien, su número se veía perfecto en la ficha, y CEADI le
	 * contestaba «este número no está registrado» para siempre. Nadie podía
	 * diagnosticarlo mirando la pantalla.
	 *
	 * @dataProvider formatosQueLaGenteEscribe
	 */
	public function test_lo_encuentra_sin_importar_como_lo_haya_escrito( string $guardado ): void {
		$this->conTelefono( 7, $guardado );

		$this->assertSame(
			7,
			Cead_Acad_WA_Identity::phone_taken_by( '595981111111' ),
			"Guardado como «{$guardado}», CEADI no reconoce a quien escribe desde ese número."
		);
	}

	public static function formatosQueLaGenteEscribe(): array {
		return [
			'seguido'          => [ '0981111111' ],
			'con espacios'     => [ '0981 111 111' ],
			'con guiones'      => [ '0981-111-111' ],
			'internacional'    => [ '+595 981 111111' ],
			'con paréntesis'   => [ '(0981) 111-111' ],
			'ya canónico'      => [ '595981111111' ],
			'con cero doble'   => [ '00595981111111' ],
		];
	}

	/**
	 * Una ficha cargada ANTES de que existiera la clave canónica se sigue
	 * encontrando, y al encontrarla queda migrada sola. Sin esta red, actualizar
	 * el plugin dejaría a todo el padrón sin bot hasta que corra la migración.
	 */
	public function test_una_ficha_vieja_se_encuentra_y_queda_migrada(): void {
		$this->fichaVieja( 7, '0981 111 111' );
		$this->assertSame( '', (string) get_user_meta( 7, '_cead_acad_phone_e164', true ) );

		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '0981111111' ) );
		$this->assertSame( '595981111111', (string) get_user_meta( 7, '_cead_acad_phone_e164', true ) );
	}

	/** La migración masiva deja todo el padrón consultable de una. */
	public function test_la_migracion_completa_las_fichas_viejas(): void {
		$this->fichaVieja( 7, '0981 111 111' );
		$this->fichaVieja( 9, '+595 982 222222' );
		$this->fichaVieja( 11, 'no tiene' );

		$this->assertSame( 2, Cead_Acad_WA_Identity::backfill_phone_keys() );
		$this->assertSame( '595981111111', (string) get_user_meta( 7, '_cead_acad_phone_e164', true ) );
		$this->assertSame( '595982222222', (string) get_user_meta( 9, '_cead_acad_phone_e164', true ) );
		$this->assertSame( '', (string) get_user_meta( 11, '_cead_acad_phone_e164', true ) );
	}

	/** Y es idempotente: correrla dos veces no rehace lo ya hecho. */
	public function test_la_migracion_no_repite_trabajo(): void {
		$this->fichaVieja( 7, '0981111111' );

		$this->assertSame( 1, Cead_Acad_WA_Identity::backfill_phone_keys() );
		$this->assertSame( 0, Cead_Acad_WA_Identity::backfill_phone_keys() );
	}

	/** Borrar el teléfono también borra la clave: si no, seguiría encontrándolo. */
	public function test_vaciar_el_telefono_borra_la_clave(): void {
		$this->conTelefono( 7, '0981111111' );
		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '0981111111' ) );

		$this->conTelefono( 7, '' );

		$this->assertSame( '', (string) get_user_meta( 7, '_cead_acad_phone_e164', true ) );
		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '0981111111' ) );
	}

	/* ----------------------------- lo básico ----------------------------- */

	public function test_un_numero_libre_no_esta_ocupado(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '0981222222' ) );
	}

	public function test_un_numero_ya_cargado_devuelve_a_su_duenio(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '0981111111' ) );
	}

	/**
	 * Al EDITAR la propia ficha, el número de uno mismo no cuenta como ocupado.
	 * Sin esta exclusión, guardar el perfil sin tocar el teléfono se rechazaría
	 * a sí mismo y nadie podría cambiarse la foto.
	 */
	public function test_el_propio_numero_no_se_bloquea_al_editarse(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '0981111111', 7 ) );
	}

	/** Pero el de OTRO sigue ocupado aunque uno esté editando lo suyo. */
	public function test_el_numero_ajeno_sigue_ocupado_al_editarse(): void {
		$this->conTelefono( 7, '0981111111' );
		$this->conTelefono( 9, '0982222222' );

		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '0981111111', 9 ) );
	}

	/* ------------------- el mismo número, escrito distinto ------------------ */

	/**
	 * El caso que hace inútil una validación ingenua.
	 *
	 * Una comparación de texto plano diría que «0981111111» y «+595 981 111111»
	 * son distintos y dejaría entrar el duplicado. El bot, en cambio, normaliza
	 * los dos y los resuelve a la MISMA ficha. Ahí el alta habría aceptado
	 * exactamente el choque que después rompe.
	 */
	public function test_el_mismo_numero_en_otro_formato_tambien_esta_ocupado(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '+595 981 111111' ) );
		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '595981111111' ) );
		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '981111111' ) );
		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '0981-111-111' ) );
	}

	/** Y al revés: guardado con formato internacional, se detecta el local. */
	public function test_guardado_internacional_choca_con_el_local(): void {
		$this->conTelefono( 7, '+595 981 111111' );

		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '0981111111' ) );
	}

	/**
	 * Dos números que TERMINAN parecido no son el mismo número.
	 *
	 * La búsqueda estrecha con un LIKE sobre los últimos dígitos y recién
	 * después confirma por igualdad normalizada. Sin esa segunda vuelta, el
	 * LIKE daría falsos positivos y el alta rechazaría números legítimos —
	 * bloquear a un alumno real es tan malo como dejar pasar el duplicado.
	 */
	public function test_un_numero_parecido_no_cuenta_como_ocupado(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '0971111111' ) );
	}

	/* ------------------------------ los bordes ----------------------------- */

	/** Sin número no hay choque posible: el campo vacío lo rechaza otra regla. */
	public function test_vacio_no_esta_ocupado(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '' ) );
		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( 'no tiene' ) );
	}

	/** Un número demasiado corto no se considera: no identifica a nadie. */
	public function test_un_numero_corto_no_matchea(): void {
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '1111' ) );
	}

	/**
	 * El código de país es configurable y la detección lo tiene que seguir.
	 * Con otro país, «0981111111» normaliza distinto y el choque se evalúa
	 * sobre ese otro número — si `phone_taken_by()` tuviera su propia idea del
	 * país, empezaría a discrepar con el bot en cuanto alguien cambie el ajuste.
	 */
	public function test_sigue_el_codigo_de_pais_configurado(): void {
		cead_test_set_option( 'cead_acad_wa_country_code', '54' );
		$this->conTelefono( 7, '0981111111' );

		$this->assertSame( 7, Cead_Acad_WA_Identity::phone_taken_by( '+54 981 111111' ) );
		$this->assertSame( 0, Cead_Acad_WA_Identity::phone_taken_by( '+595 981 111111' ) );
	}
}
