<?php
/**
 * La firma del canje: lo único que impide que un tercero use un código robado.
 *
 * Por la URL del alumno viaja un código opaco. Si ese código se filtra —queda en
 * el historial, en un `Referer`, en el log de un proxy—, lo único que separa a
 * quien lo tenga de los datos de la persona es que no puede firmar el canje.
 *
 * Por eso los tests de acá son sobre la firma y nada más: es la pieza donde un
 * error no se nota (todo sigue funcionando) hasta que alguien la aprovecha.
 */

use PHPUnit\Framework\TestCase;

final class TurismoFirmaTest extends TestCase {

	private const SECRETO = 'a3f1c9e07b5d2846a3f1c9e07b5d2846a3f1c9e07b5d2846a3f1c9e07b5d2846';

	private function firmar( string $code, int $ts, string $secreto = self::SECRETO ): string {
		return hash_hmac( 'sha256', $code . '|' . $ts, $secreto );
	}

	private function code(): string {
		return str_repeat( 'ab12', 16 ); // 64 hex, como los de verdad
	}

	/* ------------------------------ lo que pasa ----------------------------- */

	public function test_una_firma_correcta_pasa(): void {
		$code = $this->code();
		$ts   = time();

		$this->assertTrue( Cead_Acad_Turismo::firma_valida( $code, $ts, $this->firmar( $code, $ts ), self::SECRETO ) );
	}

	/* ---------------------------- lo que NO pasa ---------------------------- */

	/**
	 * El caso que justifica todo: alguien tiene el código —lo vio en el
	 * historial de una compu compartida— pero no el secreto.
	 */
	public function test_con_el_codigo_pero_sin_el_secreto_no_se_puede_canjear(): void {
		$code = $this->code();
		$ts   = time();
		$suya = $this->firmar( $code, $ts, 'el-secreto-que-se-imagino' );

		$this->assertFalse( Cead_Acad_Turismo::firma_valida( $code, $ts, $suya, self::SECRETO ) );
	}

	/**
	 * La firma ata el código a SU marca de tiempo. Sin eso, una firma vieja
	 * capturada una vez serviría para siempre: se reusa cambiando el `ts` hasta
	 * caer dentro de la ventana de tolerancia.
	 */
	public function test_la_firma_no_sirve_con_otro_ts(): void {
		$code = $this->code();
		$ts   = time();
		$sig  = $this->firmar( $code, $ts );

		$this->assertFalse( Cead_Acad_Turismo::firma_valida( $code, $ts + 1, $sig, self::SECRETO ) );
	}

	/** Ni con otro código: firmar uno no habilita a canjear el del vecino. */
	public function test_la_firma_no_sirve_con_otro_code(): void {
		$ts  = time();
		$sig = $this->firmar( $this->code(), $ts );

		$this->assertFalse( Cead_Acad_Turismo::firma_valida( str_repeat( 'cd34', 16 ), $ts, $sig, self::SECRETO ) );
	}

	/** Una firma vacía o basura no puede colarse por un chequeo flojo. */
	public function test_una_firma_vacia_o_basura_no_pasa(): void {
		$code = $this->code();
		$ts   = time();

		foreach ( [ '', '0', 'false', 'null', 'x' ] as $basura ) {
			$this->assertFalse(
				Cead_Acad_Turismo::firma_valida( $code, $ts, $basura, self::SECRETO ),
				"«{$basura}» no puede pasar como firma."
			);
		}
	}

	/**
	 * Sin secreto configurado no se valida NADA: se rechaza todo.
	 *
	 * Es el borde peligroso. Con el secreto vacío, `hash_hmac` igual devuelve un
	 * hash —el de la cadena vacía como clave—, así que un atacante que sepa que
	 * no está configurado podría calcularlo y firmar. Rechazar de entrada
	 * convierte «mal configurado» en «cerrado» y no en «abierto para quien
	 * sepa».
	 */
	public function test_sin_secreto_no_se_valida_nada(): void {
		$code = $this->code();
		$ts   = time();

		// Ni siquiera la firma que un atacante calcularía con el secreto vacío.
		$this->assertFalse(
			Cead_Acad_Turismo::firma_valida( $code, $ts, hash_hmac( 'sha256', $code . '|' . $ts, '' ), '' )
		);
	}

	/* ------------------------------ el puente ------------------------------- */

	/**
	 * Sin URL y sin secreto, el puente está apagado. De eso depende que el botón
	 * no se muestre: es preferible que no exista a que lleve a un error.
	 */
	public function test_sin_configurar_el_puente_esta_apagado(): void {
		cead_test_reset_options();

		$this->assertFalse( Cead_Acad_Turismo::activo() );
	}

	/** Con la URL cargada pero sin el secreto, sigue apagado. */
	public function test_con_url_pero_sin_secreto_sigue_apagado(): void {
		cead_test_reset_options();
		cead_test_set_option( 'cead_acad_turismo_url', 'https://caaguazu.net' );

		$this->assertFalse( Cead_Acad_Turismo::activo() );
	}

	/** La barra final se recorta, para no armar URLs con doble barra. */
	public function test_la_url_no_queda_con_barra_doble(): void {
		cead_test_reset_options();
		cead_test_set_option( 'cead_acad_turismo_url', 'https://caaguazu.net/' );

		$this->assertSame( 'https://caaguazu.net', Cead_Acad_Turismo::portal_url() );
	}

	/* -------------------------- la ruta de acceso --------------------------- */

	/** Sin configurar, la ruta de fábrica. */
	public function test_la_ruta_por_defecto(): void {
		cead_test_reset_options();

		$this->assertSame( '/acceso-cead', Cead_Acad_Turismo::ruta_acceso() );
	}

	/**
	 * La ruta es configurable porque es del OTRO sitio: ya se movió una vez, de
	 * la raíz a `/turismo-panel`. Que sea un campo evita publicar una versión del
	 * plugin cada vez que ellos reacomodan su panel.
	 *
	 * @dataProvider rutasEscritas
	 */
	public function test_la_ruta_se_normaliza( string $escrita, string $esperada ): void {
		cead_test_reset_options();
		cead_test_set_option( 'cead_acad_turismo_ruta', $escrita );

		$this->assertSame( $esperada, Cead_Acad_Turismo::ruta_acceso() );
	}

	public static function rutasEscritas(): array {
		return [
			'con barra inicial'  => [ '/turismo-panel/acceso', '/turismo-panel/acceso' ],
			'sin barra inicial'  => [ 'turismo-panel/acceso',  '/turismo-panel/acceso' ],
			'con barra final'    => [ '/turismo-panel/acceso/', '/turismo-panel/acceso' ],
			'con espacios'       => [ '  /turismo-panel/acceso  ', '/turismo-panel/acceso' ],
			'vacía → la de fábrica' => [ '', '/acceso-cead' ],
		];
	}

	/** Sin puente configurado nadie es elegible, tenga el curso que tenga. */
	public function test_sin_puente_nadie_es_elegible(): void {
		cead_test_reset_options();

		$this->assertSame( '', Cead_Acad_Turismo::rol_de( 7 ) );
	}
}
