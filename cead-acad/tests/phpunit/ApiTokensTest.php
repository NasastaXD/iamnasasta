<?php
/**
 * Los tokens con los que entran las apps nativas.
 *
 * Son credenciales de larga vida que viven en un teléfono, así que lo que hay
 * que probar no es que funcionen —eso se nota al primer login— sino que se
 * MUERAN cuando tienen que morirse. Un token que sigue abriendo después de un
 * cambio de contraseña, de una suspensión o de dos meses en un cajón no rompe
 * nada visible: simplemente deja una puerta abierta que nadie sabe que existe.
 *
 * Esa es la razón de que las contraseñas de aplicación de WordPress estén
 * apagadas en este plugin. Si estos tokens tuvieran el mismo defecto, no
 * habríamos ganado nada.
 */

use PHPUnit\Framework\TestCase;

final class ApiTokensTest extends TestCase {

	private const PASS_VIEJA = '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx0';
	private const PASS_NUEVA = '$P$ByyyyyyyyyyyyyyyyyyyyyyyyyyyyY1';

	protected function setUp(): void {
		cead_test_reset_usermeta();
		cead_test_reset_users();
	}

	/* ------------------------------ lo que pasa ----------------------------- */

	public function test_un_token_recien_emitido_vale(): void {
		$user = cead_test_set_user( 7, self::PASS_VIEJA );

		$token = Cead_Acad_API_Tokens::emitir( $user, 'Moto de Ana' );

		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $token ) );
	}

	public function test_cada_dispositivo_tiene_su_token_y_conviven(): void {
		$user = cead_test_set_user( 7, self::PASS_VIEJA );

		$celular = Cead_Acad_API_Tokens::emitir( $user, 'Celular' );
		$tablet  = Cead_Acad_API_Tokens::emitir( $user, 'Tablet' );

		$this->assertNotSame( $celular, $tablet );
		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $celular ) );
		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $tablet ) );
		$this->assertCount( 2, Cead_Acad_API_Tokens::sesiones( 7 ) );
	}

	/**
	 * Usarlo lo renueva: un teléfono que se abre todos los días no tiene que
	 * pedir la contraseña nunca más.
	 */
	public function test_usar_el_token_corre_el_vencimiento(): void {
		$user  = cead_test_set_user( 7, self::PASS_VIEJA );
		$token = Cead_Acad_API_Tokens::emitir( $user );

		// Lo envejecemos un día: pasó el freno de una hora, falta para vencer.
		$this->envejecer( 7, DAY_IN_SECONDS );
		$antes = $this->entrada( 7 )['usado'];

		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $token ) );
		$this->assertGreaterThan( $antes, $this->entrada( 7 )['usado'] );
	}

	/* ---------------------------- lo que NO pasa ---------------------------- */

	/**
	 * El caso que justifica todo el diseño.
	 *
	 * Cambiar la contraseña tiene que ser lo que la gente cree que es: echar a
	 * todos los dispositivos. Si el token sobreviviera, a quien cambia la
	 * contraseña porque sospecha que alguien se la sabe no le habría servido de
	 * nada.
	 */
	public function test_cambiar_la_contrasena_mata_los_tokens(): void {
		$user  = cead_test_set_user( 7, self::PASS_VIEJA );
		$token = Cead_Acad_API_Tokens::emitir( $user );

		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $token ), 'precondición: el token servía' );

		$user->user_pass = self::PASS_NUEVA;

		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $token ) );
	}

	/** Y además se limpia solo: no queda una entrada muerta ocupando lugar. */
	public function test_el_token_muerto_se_borra_de_la_meta(): void {
		$user  = cead_test_set_user( 7, self::PASS_VIEJA );
		$token = Cead_Acad_API_Tokens::emitir( $user );

		$user->user_pass = self::PASS_NUEVA;
		Cead_Acad_API_Tokens::validar( $token );

		$this->assertSame( [], Cead_Acad_API_Tokens::sesiones( 7 ) );
	}

	public function test_un_token_vencido_no_vale(): void {
		$user  = cead_test_set_user( 7, self::PASS_VIEJA );
		$token = Cead_Acad_API_Tokens::emitir( $user );

		$this->envejecer( 7, Cead_Acad_API_Tokens::VIDA_SEG + 60 );

		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $token ) );
	}

	/**
	 * Cambiarle el id de usuario al token no sirve para entrar como otro: el
	 * secreto está atado a la entrada de SU dueño.
	 */
	public function test_no_se_puede_entrar_como_otro_cambiando_el_id(): void {
		$ana  = cead_test_set_user( 7, self::PASS_VIEJA );
		cead_test_set_user( 9, self::PASS_VIEJA );

		$token  = Cead_Acad_API_Tokens::emitir( $ana );
		$partes = explode( '.', $token );

		$robado = '9.' . $partes[1] . '.' . $partes[2];

		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $robado ) );
	}

	/** Adivinar el secreto tampoco: se compara contra su hash. */
	public function test_un_secreto_alterado_no_vale(): void {
		$user   = cead_test_set_user( 7, self::PASS_VIEJA );
		$token  = Cead_Acad_API_Tokens::emitir( $user );
		$partes = explode( '.', $token );

		$falso = $partes[0] . '.' . $partes[1] . '.' . str_repeat( 'a', 64 );

		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $falso ) );
	}

	/**
	 * En la base queda el hash, nunca el secreto. Si alguien se lleva una copia
	 * de `usermeta`, no se lleva llaves.
	 */
	public function test_el_secreto_no_queda_guardado_en_claro(): void {
		$user   = cead_test_set_user( 7, self::PASS_VIEJA );
		$token  = Cead_Acad_API_Tokens::emitir( $user );
		$secreto = explode( '.', $token )[2];

		$guardado = get_user_meta( 7, Cead_Acad_API_Tokens::META, true );

		$this->assertStringNotContainsString( $secreto, serialize( $guardado ) );
	}

	/** @dataProvider basura */
	public function test_un_token_con_forma_invalida_se_rechaza_sin_tocar_la_base( $token ): void {
		$this->assertNull( Cead_Acad_API_Tokens::partir( $token ) );
		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $token ) );
	}

	public static function basura(): array {
		return [
			'vacío'            => [ '' ],
			'no es string'     => [ null ],
			'sin puntos'       => [ str_repeat( 'a', 64 ) ],
			'de más'           => [ '7.aabbccddeeff.' . str_repeat( 'a', 64 ) . '.extra' ],
			'usuario 0'        => [ '0.aabbccddeeff.' . str_repeat( 'a', 64 ) ],
			'usuario no numerico' => [ 'siete.aabbccddeeff.' . str_repeat( 'a', 64 ) ],
			'jti corto'        => [ '7.aabbcc.' . str_repeat( 'a', 64 ) ],
			'jti no hex'       => [ '7.zzbbccddeeff.' . str_repeat( 'a', 64 ) ],
			'secreto corto'    => [ '7.aabbccddeeff.' . str_repeat( 'a', 32 ) ],
			'secreto no hex'   => [ '7.aabbccddeeff.' . str_repeat( 'z', 64 ) ],
		];
	}

	/* ------------------------------- revocación ----------------------------- */

	/**
	 * Perder el celular se arregla sacando ESE dispositivo. Si revocar echara a
	 * todos, nadie lo usaría por no volver a loguear la tablet y la compu.
	 */
	public function test_revocar_un_dispositivo_no_toca_a_los_otros(): void {
		$user    = cead_test_set_user( 7, self::PASS_VIEJA );
		$perdido = Cead_Acad_API_Tokens::emitir( $user, 'Celular perdido' );
		$queda   = Cead_Acad_API_Tokens::emitir( $user, 'Tablet' );

		$this->assertTrue( Cead_Acad_API_Tokens::revocar( $perdido ) );

		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $perdido ) );
		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $queda ) );
	}

	public function test_revocar_todo_echa_a_todos(): void {
		$user = cead_test_set_user( 7, self::PASS_VIEJA );
		$a    = Cead_Acad_API_Tokens::emitir( $user );
		$b    = Cead_Acad_API_Tokens::emitir( $user );

		Cead_Acad_API_Tokens::revocar_todo( 7 );

		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $a ) );
		$this->assertSame( 0, Cead_Acad_API_Tokens::validar( $b ) );
	}

	/* ---------------------------------- poda -------------------------------- */

	public function test_la_poda_saca_los_vencidos(): void {
		$ahora  = time();
		$tokens = [
			'vivo'    => [ 'usado' => $ahora - DAY_IN_SECONDS ],
			'vencido' => [ 'usado' => $ahora - Cead_Acad_API_Tokens::VIDA_SEG - 1 ],
		];

		$this->assertSame( [ 'vivo' ], array_keys( Cead_Acad_API_Tokens::podar( $tokens, $ahora ) ) );
	}

	/**
	 * Pasado el tope, cae el que hace más tiempo que nadie usa — que es casi
	 * siempre el teléfono que la persona ya no tiene.
	 */
	public function test_pasado_el_tope_cae_el_menos_usado(): void {
		$ahora  = time();
		$tokens = [];
		for ( $i = 0; $i <= Cead_Acad_API_Tokens::MAX_DISPOSITIVOS; $i++ ) {
			$tokens[ 'd' . $i ] = [ 'usado' => $ahora - $i * HOUR_IN_SECONDS ];
		}
		$viejo = 'd' . Cead_Acad_API_Tokens::MAX_DISPOSITIVOS;

		$podados = Cead_Acad_API_Tokens::podar( $tokens, $ahora );

		$this->assertCount( Cead_Acad_API_Tokens::MAX_DISPOSITIVOS, $podados );
		$this->assertArrayNotHasKey( $viejo, $podados );
		$this->assertArrayHasKey( 'd0', $podados );
	}

	public function test_emitir_de_mas_no_desloguea_al_recien_llegado(): void {
		$user = cead_test_set_user( 7, self::PASS_VIEJA );

		$ultimo = '';
		for ( $i = 0; $i <= Cead_Acad_API_Tokens::MAX_DISPOSITIVOS + 3; $i++ ) {
			$ultimo = Cead_Acad_API_Tokens::emitir( $user, 'Dispositivo ' . $i );
		}

		$this->assertSame( 7, Cead_Acad_API_Tokens::validar( $ultimo ) );
		$this->assertCount( Cead_Acad_API_Tokens::MAX_DISPOSITIVOS, Cead_Acad_API_Tokens::sesiones( 7 ) );
	}

	/* -------------------------------- auxiliares ---------------------------- */

	/** Corre hacia atrás el «último uso» de todos los tokens de alguien. */
	private function envejecer( $user_id, $segundos ): void {
		$tokens = get_user_meta( $user_id, Cead_Acad_API_Tokens::META, true );
		foreach ( $tokens as $jti => $e ) {
			$tokens[ $jti ]['usado']  = $e['usado'] - $segundos;
			$tokens[ $jti ]['creado'] = $e['creado'] - $segundos;
		}
		update_user_meta( $user_id, Cead_Acad_API_Tokens::META, $tokens );
	}

	/** La única entrada de tokens de alguien. */
	private function entrada( $user_id ): array {
		$tokens = get_user_meta( $user_id, Cead_Acad_API_Tokens::META, true );
		return reset( $tokens );
	}
}
