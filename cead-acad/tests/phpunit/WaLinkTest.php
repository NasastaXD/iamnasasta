<?php
/**
 * El enlace para agendar a CEADI por WhatsApp.
 *
 * Parece una concatenación tonta, pero tiene dos reglas que importan: que un
 * número mal tipeado igual produzca un enlace que abra, y que SIN número no
 * devuelva nada — porque quien llama decide qué hacer con eso (el registro
 * esconde el botón; el panel abre el chat directo en vez del menú). Si esto
 * devolviera «https://wa.me/» a secas, las dos pantallas mostrarían un botón
 * que no lleva a ningún lado.
 */

use PHPUnit\Framework\TestCase;

final class WaLinkTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	public function test_con_numero_limpio_arma_el_enlace(): void {
		cead_test_set_option( 'cead_acad_wa_bot_number', '595991123456' );

		$this->assertSame( 'https://wa.me/595991123456', cead_acad_wa_link() );
	}

	/**
	 * El caso real: alguien copia el número de un contacto y lo pega con el más,
	 * los espacios y los guiones. `wa.me` con espacios no abre nada.
	 */
	public function test_un_numero_pegado_con_formato_se_limpia(): void {
		cead_test_set_option( 'cead_acad_wa_bot_number', '+595 991 123-456' );

		$this->assertSame( 'https://wa.me/595991123456', cead_acad_wa_link() );
	}

	public function test_sin_numero_no_devuelve_enlace(): void {
		$this->assertSame( '', cead_acad_wa_link() );
	}

	/** Un valor que no tiene ni un dígito es lo mismo que no tener número. */
	public function test_un_valor_sin_digitos_cuenta_como_vacio(): void {
		cead_test_set_option( 'cead_acad_wa_bot_number', 'todavía no lo tenemos' );

		$this->assertSame( '', cead_acad_wa_link() );
	}
}
