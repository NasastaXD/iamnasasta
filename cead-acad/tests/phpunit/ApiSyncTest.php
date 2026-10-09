<?php
/**
 * La sincronización para uso sin señal, y el rango del calendario.
 *
 * Lo que se prueba es lo que cuesta plata o deja a alguien a oscuras si sale
 * mal: que una sincronización en la que no cambió nada no vuelva a bajar todo
 * —los datos móviles se pagan—, y que el calendario no se coma el último día
 * del rango que se le pide.
 */

use PHPUnit\Framework\TestCase;

final class ApiSyncTest extends TestCase {

	/* -------------------------------------------------------------- versión */

	public function test_los_mismos_datos_dan_la_misma_version(): void {
		$a = [ 'horario' => [ 'dias' => [ 1, 2 ] ], 'faq' => [] ];
		$b = [ 'horario' => [ 'dias' => [ 1, 2 ] ], 'faq' => [] ];

		$this->assertSame( Cead_Acad_API_Sync::version_de( $a ), Cead_Acad_API_Sync::version_de( $b ) );
	}

	/** Un comunicado nuevo, una nota cargada: cualquier cambio cambia la versión. */
	public function test_un_cambio_cambia_la_version(): void {
		$antes   = [ 'comunicados' => [ 'sin_leer' => 0 ] ];
		$despues = [ 'comunicados' => [ 'sin_leer' => 1 ] ];

		$this->assertNotSame( Cead_Acad_API_Sync::version_de( $antes ), Cead_Acad_API_Sync::version_de( $despues ) );
	}

	/* ----------------------------------------------------------------- etag */

	/** @dataProvider etags */
	public function test_reconoce_la_version_en_las_formas_en_que_llega( $header, $esperado ): void {
		$this->assertSame( $esperado, Cead_Acad_API_Sync::coincide_etag( $header, 'abc123' ) );
	}

	public static function etags(): array {
		return [
			'con comillas'          => [ '"abc123"', true ],
			'sin comillas'          => [ 'abc123', true ],
			// Algunos proxies le agregan W/ al comprimir. Si eso hiciera fallar
			// la comparación, toda sincronización bajaría todo de nuevo sin que
			// nadie entienda por qué gasta tantos datos.
			'débil, de un proxy'    => [ 'W/"abc123"', true ],
			'en una lista'          => [ '"otra", "abc123"', true ],
			'otra versión'          => [ '"xyz789"', false ],
			'vacío'                 => [ '', false ],
			'prefijo de la versión' => [ '"abc12"', false ],
		];
	}

	/** Sin versión del servidor nunca hay coincidencia: mejor bajar de más que quedarse con datos viejos. */
	public function test_sin_version_no_coincide_nada(): void {
		$this->assertFalse( Cead_Acad_API_Sync::coincide_etag( '""', '' ) );
	}

	/* ------------------------------------------------------------ calendario */

	/**
	 * El error que tenía el endpoint: `hasta = 2026-10-09` se leía como la
	 * medianoche de ese día y dejaba afuera todo lo que pasaba el 9.
	 */
	public function test_el_rango_incluye_el_ultimo_dia_entero(): void {
		[ $desde, $hasta ] = Cead_Acad_API_Panel::rango( '2026-10-01', '2026-10-09' );

		$this->assertSame( '2026-10-01 00:00:00', $desde );
		$this->assertSame( '2026-10-09 23:59:59', $hasta );
	}

	public function test_un_lado_vacio_queda_sin_limite(): void {
		$this->assertSame( [ null, '2026-10-09 23:59:59' ], Cead_Acad_API_Panel::rango( '', '2026-10-09' ) );
		$this->assertSame( [ '2026-10-01 00:00:00', null ], Cead_Acad_API_Panel::rango( '2026-10-01', null ) );
	}

	/** Basura no inventa un rango: devolvería una lista que parece una respuesta. */
	public function test_una_fecha_con_otra_forma_no_inventa_rango(): void {
		$this->assertSame( [ null, null ], Cead_Acad_API_Panel::rango( '09/10/2026', 'mañana' ) );
	}
}
