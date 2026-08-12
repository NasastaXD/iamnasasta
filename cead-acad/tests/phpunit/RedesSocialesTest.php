<?php
/**
 * Las redes del colegio: qué enlace termina publicándose.
 *
 * La URL vive por duplicado en el tema —una para el pie, otra para la tarjeta
 * de la portada— y encima el Customizer declara un valor por defecto que las
 * plantillas no estaban leyendo. Eso daba el peor de los estados posibles: el
 * Customizer mostraba la cuenta de Instagram cargada y en el sitio no había
 * ningún botón. Estos tests fijan cuál gana en cada caso.
 */

use PHPUnit\Framework\TestCase;

final class RedesSocialesTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_theme_mods();
	}

	/**
	 * El caso que estaba roto: recién instalado, sin que nadie haya entrado a
	 * guardar nada, las redes tienen que salir igual. Antes se leían con cadena
	 * vacía como respaldo, así que no salía ninguna.
	 */
	public function test_sin_nada_configurado_las_redes_igual_aparecen(): void {
		$r = cead_social_links();

		$this->assertArrayHasKey( 'ig', $r );
		$this->assertArrayHasKey( 'fb', $r );
		$this->assertStringContainsString( 'instagram.com', $r['ig']['url'] );
	}

	public function test_la_url_cargada_pisa_al_valor_por_defecto(): void {
		cead_test_set_theme_mod( 'cead_social_ig_url', 'https://www.instagram.com/otra_cuenta' );

		$this->assertSame( 'https://www.instagram.com/otra_cuenta', cead_social_links()['ig']['url'] );
	}

	/** La tarjeta de la portada puede traer su propia URL, y esa manda. */
	public function test_la_url_de_la_tarjeta_manda_sobre_la_del_pie(): void {
		cead_test_set_theme_mod( 'cead_social_ig_url', 'https://www.instagram.com/pie' );
		cead_test_set_theme_mod( 'cead_redes_1_url',   'https://www.instagram.com/tarjeta' );

		$this->assertSame( 'https://www.instagram.com/tarjeta', cead_social_links()['ig']['url'] );
	}

	/**
	 * Vaciar el enlace es la forma de sacar una red del sitio. Mejor nada que un
	 * botón que no lleva a ningún lado — el mismo criterio que ya usaba el pie.
	 */
	public function test_una_red_sin_enlace_no_se_publica(): void {
		cead_test_set_theme_mod( 'cead_social_fb_url', '' );

		$this->assertArrayNotHasKey( 'fb', cead_social_links() );
		$this->assertArrayHasKey( 'ig', cead_social_links(), 'Sacar una no puede sacar a las demás.' );
	}

	/** El `#` de relleno cuenta como vacío: es lo que deja un formulario a medio llenar. */
	public function test_el_numeral_de_relleno_cuenta_como_vacio(): void {
		cead_test_set_theme_mod( 'cead_social_fb_url', '#' );

		$this->assertArrayNotHasKey( 'fb', cead_social_links() );
	}

	/**
	 * `cead_sanitize_hex()` devuelve cadena vacía si el color no valida, y un
	 * custom property vacío NO dispara el respaldo de `var()`: la tarjeta
	 * quedaría sin color en vez de con el suyo.
	 */
	public function test_un_color_invalido_cae_en_el_de_la_red(): void {
		cead_test_set_theme_mod( 'cead_redes_1_color', '' );

		$this->assertSame( '#E1306C', cead_social_links()['ig']['color'] );
	}

	/** Cada red tiene su ícono dibujado; algo que no es una red no inventa uno. */
	public function test_solo_las_redes_conocidas_tienen_icono(): void {
		foreach ( array_keys( cead_social_defaults() ) as $slug ) {
			$this->assertStringContainsString( '<svg', cead_social_icon( $slug ) );
		}
		$this->assertSame( '', cead_social_icon( 'tiktok' ) );
	}
}
