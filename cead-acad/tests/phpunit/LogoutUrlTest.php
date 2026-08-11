<?php
/**
 * El enlace de cerrar sesión lleva nonce, y nadie lo arma a mano.
 *
 * `/salir` cerraba la sesión con un GET pelado. El problema real no es el CSRF
 * teórico: es que CUALQUIER cosa que precargue enlaces desloguea al usuario —el
 * prefetch del navegador, un escáner de links de antivirus, la previsualización
 * de WhatsApp cuando alguien comparte una URL del panel—. Con el menú de
 * usuario nuevo ese enlace está en todas las pantallas, así que la exposición
 * es total.
 *
 * Estos tests fijan las dos mitades: que el helper ponga el nonce, y que las
 * plantillas usen el helper en vez de escribir la URL sueltas.
 */

use PHPUnit\Framework\TestCase;

final class LogoutUrlTest extends TestCase {

	private function plugin_dir() {
		return dirname( __DIR__, 2 );
	}

	public function test_la_url_apunta_a_salir(): void {
		$this->assertStringStartsWith( home_url( '/salir' ), cead_acad_logout_url() );
	}

	public function test_la_url_lleva_el_nonce(): void {
		$url = cead_acad_logout_url();

		$partes = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $partes );

		$this->assertArrayHasKey( 'cead_nonce', $partes, 'El enlace de logout salió sin nonce.' );
		$this->assertNotSame( '', $partes['cead_nonce'] );
	}

	public function test_el_nonce_valida_contra_la_accion_de_logout(): void {
		$partes = [];
		parse_str( (string) parse_url( cead_acad_logout_url(), PHP_URL_QUERY ), $partes );

		$nonce = $partes['cead_nonce'] ?? '';

		$this->assertSame( 1, wp_verify_nonce( $nonce, CEAD_ACAD_LOGOUT_ACTION ) );
		$this->assertFalse( wp_verify_nonce( $nonce, 'otra_accion' ) );
		$this->assertFalse( wp_verify_nonce( 'inventado', CEAD_ACAD_LOGOUT_ACTION ) );
	}

	/**
	 * La ruta tiene que exigir el nonce. Se lee el código porque el router
	 * necesita WordPress entero y no entra en esta suite.
	 */
	public function test_la_ruta_verifica_el_nonce_antes_de_cerrar_sesion(): void {
		$src = (string) file_get_contents( $this->plugin_dir() . '/includes/class-cead-acad-rewrites.php' );

		$pos_check  = strpos( $src, 'CEAD_ACAD_LOGOUT_ACTION' );
		$pos_logout = strpos( $src, 'wp_logout()' );

		$this->assertNotFalse( $pos_check, 'La ruta /salir no verifica el nonce de logout.' );
		$this->assertNotFalse( $pos_logout );
		$this->assertLessThan( $pos_logout, $pos_check, 'El nonce se verifica después de cerrar la sesión.' );
	}

	/**
	 * Ninguna plantilla arma el enlace a mano: si lo hiciera, ese enlace
	 * quedaría sin nonce y volvería el problema por la puerta de atrás.
	 */
	public function test_ninguna_plantilla_arma_la_url_de_salir_a_mano(): void {
		$sospechosos = [];

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->plugin_dir() . '/templates', RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) { continue; }
			$src = (string) file_get_contents( $file->getPathname() );
			// cead_acad_url( 'salir' ) sin pasar por el helper del nonce.
			if ( preg_match( "/cead_acad_url\(\s*['\"]\/?salir/", $src ) ) {
				$sospechosos[] = str_replace( $this->plugin_dir() . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame(
			[],
			$sospechosos,
			"Estas plantillas arman /salir a mano en vez de usar cead_acad_logout_url():\n  - " . implode( "\n  - ", $sospechosos )
		);
	}
}
