<?php
/**
 * Lo que hace seguro mandar escrituras desde una cola offline.
 *
 * La app guarda lo que se toca sin señal y lo manda al volver la conexión. Si
 * el envío llega pero la respuesta se pierde, la app no sabe que entró y lo
 * reenvía. Estos tests fijan las dos piezas que hacen que ese reenvío sea
 * inofensivo:
 *
 *  - La clave de idempotencia: la misma clave no ejecuta dos veces.
 *  - Fijar estados en vez de invertirlos: repetir «queda marcado» no cambia
 *    nada, mientras que repetir «invertí» deshace lo que se hizo.
 */

use PHPUnit\Framework\TestCase;

final class ApiIdempotenciaTest extends TestCase {

	/** Un pedido de mentira: solo hace falta que devuelva headers. */
	private function pedido( $clave = null ) {
		return new class( $clave ) {
			private $clave;
			public function __construct( $clave ) { $this->clave = $clave; }
			public function get_header( $k ) { return 'idempotency_key' === $k ? $this->clave : null; }
		};
	}

	protected function setUp(): void {
		cead_test_reset_options(); // también limpia los transients
		cead_test_reset_usermeta();
		cead_test_set_current_user( 7 );
	}

	/* --------------------------------------------------------- idempotencia */

	/**
	 * El caso que justifica todo: la respuesta se perdió, la app reenvía.
	 * La segunda vez no se ejecuta nada y se devuelve lo mismo.
	 */
	public function test_la_misma_clave_no_ejecuta_dos_veces(): void {
		$veces = 0;
		$hacer = function () use ( &$veces ) { $veces++; return [ 'ok' => true, 'vez' => $veces ]; };

		$a = Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );
		$b = Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );

		$this->assertSame( 1, $veces );
		$this->assertSame( $a->get_data(), $b->get_data(), 'el reenvío recibe la misma respuesta' );
		$this->assertSame( 'true', $b->get_headers()['Idempotent-Replay'] ?? null );
	}

	public function test_claves_distintas_son_escrituras_distintas(): void {
		$veces = 0;
		$hacer = function () use ( &$veces ) { $veces++; return [ 'ok' => true ]; };

		Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );
		Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0002' ), $hacer );

		$this->assertSame( 2, $veces );
	}

	/** Sin clave se ejecuta siempre: es lo que espera un cliente que no sabe de esto. */
	public function test_sin_clave_se_ejecuta_siempre(): void {
		$veces = 0;
		$hacer = function () use ( &$veces ) { $veces++; return [ 'ok' => true ]; };

		Cead_Acad_API::una_vez( $this->pedido( null ), $hacer );
		Cead_Acad_API::una_vez( $this->pedido( null ), $hacer );

		$this->assertSame( 2, $veces );
	}

	/**
	 * Un error no se recuerda. Si falló porque se cayó la base, el reintento
	 * tiene que poder salir bien en vez de recibir el mismo error guardado.
	 */
	public function test_un_error_no_queda_recordado(): void {
		$veces = 0;
		$hacer = function () use ( &$veces ) {
			$veces++;
			return 1 === $veces ? new WP_Error( 'caida', 'se cayó' ) : [ 'ok' => true ];
		};

		$a = Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );
		$b = Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );

		$this->assertInstanceOf( WP_Error::class, $a );
		$this->assertSame( [ 'ok' => true ], $b->get_data() );
		$this->assertSame( 2, $veces );
	}

	/**
	 * La memoria es por persona. Si dos teléfonos generaran la misma clave,
	 * el segundo no puede recibir la respuesta del primero — ni quedarse sin
	 * que se ejecute lo suyo.
	 */
	public function test_la_misma_clave_de_dos_personas_no_se_pisa(): void {
		$veces = 0;
		$hacer = function () use ( &$veces ) { $veces++; return [ 'de' => get_current_user_id() ]; };

		cead_test_set_current_user( 7 );
		Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );
		cead_test_set_current_user( 9 );
		$b = Cead_Acad_API::una_vez( $this->pedido( 'clave-de-prueba-0001' ), $hacer );

		$this->assertSame( 2, $veces );
		$this->assertSame( [ 'de' => 9 ], $b->get_data() );
	}

	public function test_una_clave_con_forma_rara_se_rechaza_sin_ejecutar(): void {
		$veces = 0;
		$hacer = function () use ( &$veces ) { $veces++; return [ 'ok' => true ]; };

		$r = Cead_Acad_API::una_vez( $this->pedido( 'corta' ), $hacer );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 0, $veces );
	}

	/** @dataProvider claves */
	public function test_validacion_de_claves( $bruta, $esperado ): void {
		$this->assertSame( $esperado, Cead_Acad_API::clave_idempotencia( $bruta ) );
	}

	public static function claves(): array {
		return [
			'no vino'        => [ null, null ],
			'vacía'          => [ '   ', null ],
			'uuid'           => [ '3f2b8c1e-9d4a-4e7b-a1c2-5f6e7d8c9b0a', '3f2b8c1e-9d4a-4e7b-a1c2-5f6e7d8c9b0a' ],
			'con espacios'   => [ '  abcd1234  ', 'abcd1234' ],
			'muy corta'      => [ 'abc', false ],
			'muy larga'      => [ str_repeat( 'a', 81 ), false ],
			'caracteres raros' => [ 'abcd1234<script>', false ],
		];
	}

	/* ------------------------------------------------------- fijar estados */

	/**
	 * El problema que tenía el interruptor: la app toca la estrella sin señal,
	 * el envío entra, la respuesta se pierde, la app reenvía. Con «invertir»
	 * la segunda vez la desmarca. Con «fijar», queda marcada.
	 */
	public function test_reenviar_un_favorito_no_lo_deshace(): void {
		Cead_Acad_Account::fijar_favorito( 7, 42, true );
		Cead_Acad_Account::fijar_favorito( 7, 42, true );

		$this->assertSame( [ 42 ], Cead_Acad_Account::fav_ids( 7 ) );
	}

	public function test_fijar_favorito_en_falso_lo_saca(): void {
		Cead_Acad_Account::fijar_favorito( 7, 42, true );
		Cead_Acad_Account::fijar_favorito( 7, 43, true );

		Cead_Acad_Account::fijar_favorito( 7, 42, false );

		$this->assertSame( [ 43 ], Cead_Acad_Account::fav_ids( 7 ) );
	}

	public function test_sacar_un_favorito_que_no_estaba_no_rompe_nada(): void {
		Cead_Acad_Account::fijar_favorito( 7, 43, true );

		Cead_Acad_Account::fijar_favorito( 7, 99, false );

		$this->assertSame( [ 43 ], Cead_Acad_Account::fav_ids( 7 ) );
	}
}
