<?php
/**
 * El panel no puede usar `$_GET['m']` ni `$_GET['p']` para sus propios query
 * vars: WordPress los reserva (fecha de archivo y "post ID" respectivamente),
 * y el `WP_Query` principal los resuelve ANTES de que el router propio del
 * plugin llegue a correr. `redirect_canonical()` termina mandando al
 * visitante a otro lado — la URL "canónica" de ese archivo de fecha o de ese
 * post — en vez de dejar que el router del panel atienda la request.
 *
 * Es justo el bug real: "el calendario al retroceder te redirige a los
 * artículos de noticias" venía de acá — la navegación de mes usaba `m`.
 *
 * Se lee el código fuente porque probar esto de verdad necesita WordPress
 * entero (WP_Query, redirect_canonical, rewrite rules).
 */

use PHPUnit\Framework\TestCase;

final class PanelReservedQueryVarsTest extends TestCase {

	/** @return string[] */
	private function templates_panel() {
		$dir = dirname( __DIR__, 2 ) . '/templates/panel';
		$out = [];
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[] = $file->getPathname();
			}
		}
		return $out;
	}

	public function test_ningun_template_del_panel_lee_o_escribe_m_o_p_como_query_var(): void {
		$reservados = [ "\$_GET['m']", '\$_GET["m"]', "\$_GET['p']", '\$_GET["p"]', "'m' =>", "'p' =>", "add_query_arg( 'm',", "add_query_arg( 'p'," ];
		foreach ( $this->templates_panel() as $file ) {
			$src = (string) file_get_contents( $file );
			foreach ( $reservados as $patron ) {
				$this->assertStringNotContainsString(
					$patron,
					$src,
					basename( $file ) . " usa \"{$patron}\" — m y p son query vars reservados de WordPress (fecha de archivo y post ID); eso dispara un redirect_canonical() a otra URL en vez de dejar correr el router del panel."
				);
			}
		}
	}
}
