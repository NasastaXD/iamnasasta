<?php
/**
 * Qué remitentes atiende el webhook.
 *
 * WhatsApp entrega por el mismo canal cosas que no son un mensaje para el
 * bot, y solo se distinguen por el dominio del JID. Publicar un estado
 * terminaba con CEADI saludando de la nada, porque el código tomaba los
 * dígitos del JID sin mirar nunca de qué tipo de chat venía.
 *
 * Se testea el criterio en sí, que es lo que hay que no romper: el endpoint
 * necesita WordPress y queda fuera del alcance de esta suite.
 */

use PHPUnit\Framework\TestCase;

class InboundJidTest extends TestCase {

	/** Mismo criterio que aplica el endpoint. */
	private function se_atiende( $from ) {
		$ok = [ 's.whatsapp.net', 'c.us' ];
		if ( false === strpos( $from, '@' ) ) {
			return true; // bridges que mandan solo el número
		}
		$dominio = strtolower( trim( substr( $from, strpos( $from, '@' ) + 1 ) ) );
		return in_array( $dominio, $ok, true );
	}

	public function test_atiende_chats_individuales() {
		$this->assertTrue( $this->se_atiende( '595991123456@s.whatsapp.net' ) );
		$this->assertTrue( $this->se_atiende( '595991123456@c.us' ) );
	}

	/** El caso que motivó esto: subir un estado no es escribirle al bot. */
	public function test_ignora_los_estados() {
		$this->assertFalse( $this->se_atiende( 'status@broadcast' ) );
		$this->assertFalse( $this->se_atiende( '595991123456@broadcast' ) );
	}

	/** Si alguien mete el número del bot a un grupo, no contesta ahí. */
	public function test_ignora_grupos_y_canales() {
		$this->assertFalse( $this->se_atiende( '120363000000000000@g.us' ) );
		$this->assertFalse( $this->se_atiende( '120363000000000000@newsletter' ) );
	}

	/** Sin dominio se acepta: hay bridges que mandan el número pelado. */
	public function test_acepta_un_numero_sin_dominio() {
		$this->assertTrue( $this->se_atiende( '595991123456' ) );
	}

	public function test_el_dominio_no_distingue_mayusculas() {
		$this->assertTrue( $this->se_atiende( '595991123456@S.WhatsApp.Net' ) );
		$this->assertFalse( $this->se_atiende( 'status@BROADCAST' ) );
	}
}
