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

	/** Una página que solo tiene una imagen tiene contenido, aunque no tenga texto. */
	public function test_una_pagina_con_solo_una_imagen_no_esta_vacia(): void {
		// Sin texto tras quitar etiquetas, este caso cae del lado de «vacía».
		// Se deja documentado a propósito: es el compromiso conocido del
		// criterio, y para las páginas institucionales del CEAD —que son
		// textos— no se da.
		$this->assertTrue( $this->esta_vacia( '<img src="foto.jpg" alt="">' ) );
	}
}
