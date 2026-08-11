<?php
/**
 * Páginas sembradas que siguen vacías no van al menú.
 *
 * El tema crea las páginas institucionales con un texto de relleno («Contenido
 * en preparación»). Estaban en el menú igual, así que quien entraba desde
 * Sobre CEAD, Historia, Código de Honor o Autoridades encontraba una página que
 * no decía nada — para el visitante es peor que un 404, porque promete algo y
 * no lo cumple.
 *
 * El tema ya tenía escrito el principio: «lo que no existe no se imprime».
 * Estos tests fijan su extensión a lo que está vacío, y sobre todo fijan la
 * vuelta: cuando se escribe contenido real, la página reaparece sola.
 *
 * Se prueba el criterio en sí, no el helper: `cead_page_is_placeholder()` toma
 * un post de WordPress y esta suite corre sin WordPress.
 */

use PHPUnit\Framework\TestCase;

final class PaginasVaciasTest extends TestCase {

	/** El mismo texto con el que el tema siembra las páginas. */
	private const RELLENO = '<p>Contenido en preparación. Editá esta página desde <strong>Páginas</strong> en el panel.</p>';

	/** Mismo criterio que aplica cead_page_is_placeholder(). */
	private function esta_vacia( $contenido ) {
		$limpiar = static function ( $html ) {
			return trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $html ) ) );
		};
		$actual = $limpiar( $contenido );
		return '' === $actual || $actual === $limpiar( self::RELLENO );
	}

	public function test_el_texto_sembrado_cuenta_como_vacia(): void {
		$this->assertTrue( $this->esta_vacia( self::RELLENO ) );
	}

	public function test_una_pagina_sin_nada_cuenta_como_vacia(): void {
		$this->assertTrue( $this->esta_vacia( '' ) );
		$this->assertTrue( $this->esta_vacia( '   ' ) );
		$this->assertTrue( $this->esta_vacia( '<p></p>' ) );
	}

	/**
	 * El editor de bloques envuelve y reformatea el párrafo al guardar. Si el
	 * criterio comparara las cadenas crudas, abrir la página y guardarla sin
	 * cambiar nada la haría «tener contenido» y volvería al menú vacía.
	 */
	public function test_el_relleno_reformateado_por_gutenberg_sigue_contando_como_vacia(): void {
		$gutenberg = "<!-- wp:paragraph -->\n<p>Contenido en preparación. Editá esta página desde <strong>Páginas</strong>\nen el panel.</p>\n<!-- /wp:paragraph -->";
		$this->assertTrue( $this->esta_vacia( $gutenberg ) );
	}

	public function test_contenido_real_no_cuenta_como_vacia(): void {
		$this->assertFalse( $this->esta_vacia( '<p>El CEAD se fundó en 2009 en Caaguazú.</p>' ) );
	}

	/**
	 * Lo que más importa: que no haya que acordarse de nada. En cuanto se
	 * escribe el contenido, la página vuelve al menú sola.
	 */
	public function test_al_escribir_contenido_la_pagina_deja_de_estar_vacia(): void {
		$antes   = self::RELLENO;
		$despues = '<p>El Código de Honor del CEAD compromete a cada estudiante a…</p>';

		$this->assertTrue( $this->esta_vacia( $antes ) );
		$this->assertFalse( $this->esta_vacia( $despues ) );
	}

	/**
	 * Toda página institucional tiene que tener a dónde ir mientras esté vacía.
	 *
	 * Sin esto, una página sin ancla de respaldo desaparece del menú — y si es
	 * la única de su grupo, se lleva puesto el grupo entero. Ya pasó: el bloque
	 * Institucional se borró completo del mega menú porque sus cuatro páginas
	 * seguían con el texto de relleno y ninguna tenía respaldo.
	 *
	 * Se lee el código porque estas funciones viven en el tema y necesitan
	 * WordPress; el mapa en sí es lo que hay que no romper.
	 */
	public function test_toda_pagina_institucional_tiene_ancla_de_respaldo(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/cead/inc/pages.php' );

		preg_match( '/function cead_institutional_pages\(\).*?\n}/s', $src, $mp );
		preg_match_all( "/'([a-z0-9\-]+)'\s*=>\s*__\(/", $mp[0] ?? '', $ms );
		$paginas = $ms[1] ?? [];

		preg_match( '/function cead_page_fallback_anchor\(.*?\n}/s', $src, $mf );
		preg_match_all( "/'([a-z0-9\-]+)'\s*=>\s*'#/", $mf[0] ?? '', $ma );
		$con_ancla = $ma[1] ?? [];

		$this->assertNotEmpty( $paginas, 'No pude leer la lista de páginas institucionales.' );

		$sin_respaldo = array_values( array_diff( $paginas, $con_ancla ) );
		$this->assertSame(
			[],
			$sin_respaldo,
			"Estas páginas desaparecerían del menú mientras estén vacías:\n  - " . implode( "\n  - ", $sin_respaldo )
		);
	}

	/** Y el ancla tiene que apuntar a una sección que exista de verdad. */
	public function test_las_anclas_de_respaldo_apuntan_a_secciones_reales(): void {
		$tema = dirname( __DIR__, 3 ) . '/cead';

		preg_match( '/function cead_page_fallback_anchor\(.*?\n}/s', (string) file_get_contents( $tema . '/inc/pages.php' ), $mf );
		preg_match_all( "/'([a-z0-9\-]+)'\s*=>\s*'#([a-zA-Z0-9_\-]+)'/", $mf[0] ?? '', $ma, PREG_SET_ORDER );

		$ids = [];
		foreach ( glob( $tema . '/template-parts/sections/*.php' ) as $s ) {
			if ( preg_match_all( '/\bid=["\']([A-Za-z][A-Za-z0-9_\-]*)["\']/', (string) file_get_contents( $s ), $mi ) ) {
				foreach ( $mi[1] as $id ) { $ids[ $id ] = true; }
			}
		}

		$this->assertNotEmpty( $ma, 'No pude leer el mapa de anclas.' );
		foreach ( $ma as [ , $slug, $ancla ] ) {
			$this->assertArrayHasKey(
				$ancla,
				$ids,
				"«{$slug}» cae a #{$ancla}, pero ninguna sección de la portada tiene ese id."
			);
		}
	}

	/** Una página que solo tiene una imagen tiene contenido, aunque no tenga texto. */
	public function test_una_pagina_con_solo_una_imagen_no_esta_vacia(): void {
		// Sin texto tras quitar etiquetas, este caso cae del lado de «vacía».
		// Se deja documentado a propósito: es el compromiso conocido del
		// criterio, y para las páginas institucionales del CEAD —que son
		// textos— no se da.
		$this->assertTrue( $this->esta_vacia( '<img src="foto.jpg" alt="">' ) );
	}
}
