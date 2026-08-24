<?php
/**
 * El nombre interno de una ruta y su dirección pública son cosas distintas.
 *
 * `/login` chocaba con el login de WordPress: entre `/login`, `/wp-login.php` y
 * el redirector que manda de uno al otro, no quedaba claro cuál era cuál. La
 * ruta se mudó a `/ingresar`.
 *
 * La mudanza se hizo con un mapa y no cambiando las treinta y pico de llamadas
 * que piden 'login'. Estos tests fijan esa separación, porque es lo que hace que
 * la próxima mudanza sea una línea en vez de una búsqueda global donde alcanza
 * con saltearse un archivo para dejar un enlace roto.
 */

use PHPUnit\Framework\TestCase;

final class RutasPublicasTest extends TestCase {

	public function test_login_apunta_a_la_direccion_nueva(): void {
		$this->assertSame( 'https://cead.test/ingresar', cead_acad_url( 'login' ) );
	}

	/** Las demás rutas no se tocan. */
	public function test_el_resto_de_las_rutas_queda_igual(): void {
		$this->assertSame( 'https://cead.test/panel', cead_acad_url( 'panel' ) );
		$this->assertSame( 'https://cead.test/registro', cead_acad_url( 'registro' ) );
		$this->assertSame( 'https://cead.test/salir', cead_acad_url( 'salir' ) );
	}

	/**
	 * El mapeo es solo del PRIMER tramo. Si tomara la ruta entera, una
	 * subruta que empezara igual se rompería; y si hiciera un reemplazo de
	 * texto suelto, «panel/login-algo» quedaría mutilado.
	 */
	public function test_solo_se_mapea_el_primer_tramo(): void {
		$this->assertSame( 'https://cead.test/panel/perfil', cead_acad_url( 'panel/perfil' ) );
		$this->assertSame( 'https://cead.test/panel/comunicados', cead_acad_url( 'panel/comunicados' ) );
	}

	/** La barra inicial es opcional, como antes. */
	public function test_tolera_la_barra_inicial(): void {
		$this->assertSame( cead_acad_url( 'login' ), cead_acad_url( '/login' ) );
		$this->assertSame( cead_acad_url( 'panel' ), cead_acad_url( '/panel' ) );
	}

	/**
	 * El logout sigue llevando su nonce y apuntando a la ruta correcta. Se
	 * comprueba junto con esto porque arma su URL con el mismo helper: si el
	 * mapeo rompiera el armado, cerrar sesión dejaría de funcionar.
	 */
	public function test_el_logout_conserva_su_nonce(): void {
		$url = cead_acad_logout_url();

		$this->assertStringStartsWith( 'https://cead.test/salir?', $url );
		$this->assertStringContainsString( 'cead_nonce=', $url );
	}

	/**
	 * El mapa tiene que ser un array de slugs válidos para una URL. Un valor con
	 * espacios o mayúsculas produciría una dirección que no matchea la regla de
	 * rewrite, y el enlace llevaría a un 404 sin que nada falle antes.
	 */
	public function test_los_slugs_del_mapa_sirven_como_url(): void {
		foreach ( cead_acad_route_slugs() as $interno => $publico ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9-]+$/',
				$publico,
				"El slug de «{$interno}» no sirve como dirección."
			);
		}
	}
}
