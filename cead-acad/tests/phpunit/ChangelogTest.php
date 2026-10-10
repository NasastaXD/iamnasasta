<?php
/**
 * Las novedades: lo que la gente lee para enterarse de qué cambió.
 *
 * Dos cosas se prueban acá. Una es la lógica (quién ve qué, qué ya vio cada
 * quien). La otra es el DATO: el archivo de novedades tiene que estar completo y
 * ordenado, y —lo que importa— tiene que traer la versión que se está por
 * publicar. Sin esa última prueba, publicar una versión sin contar qué trae es
 * cuestión de olvidarse, y las novedades dejan de ser confiables justo cuando
 * más se necesitan.
 */

use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_caps();
		cead_test_reset_users();
		$GLOBALS['cead_test_usermeta'] = [];
	}

	/** Datos de prueba, chicos y a propósito desordenados. */
	private function entradas(): array {
		return [
			[
				'version' => '1.2.0', 'fecha' => '2026-03-05', 'titulo' => 'Tres', 'resumen' => 'R3',
				'items'   => [
					[ 'tipo' => 'nuevo',   'titulo' => 'Para todos',  'texto' => 'T' ],
					[ 'tipo' => 'mejora',  'titulo' => 'Del equipo',  'texto' => 'T', 'para' => 'equipo' ],
					[ 'tipo' => 'arreglo', 'titulo' => 'De wp-admin', 'texto' => 'T', 'para' => 'admin', 'admin' => 'nota' ],
				],
			],
			[
				'version' => '1.10.0', 'fecha' => '2026-04-01', 'titulo' => 'Cuatro', 'resumen' => 'R4',
				'items'   => [ [ 'tipo' => 'nuevo', 'titulo' => 'Solo del equipo', 'texto' => 'T', 'para' => 'equipo' ] ],
			],
			[
				'version' => '1.1.0', 'fecha' => '2026-02-01', 'titulo' => 'Dos', 'resumen' => 'R2',
				'items'   => [ [ 'tipo' => 'nuevo', 'titulo' => 'Para todos', 'texto' => 'T' ] ],
			],
		];
	}

	private function versiones( array $entradas ): array {
		return array_column( $entradas, 'version' );
	}

	/* ------------------------------------------------- el dato de verdad */

	public function test_la_entrada_mas_nueva_es_la_version_del_plugin(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/cead-acad.php' );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $src, $m ) );

		$todas = Cead_Acad_Changelog::todas();
		$this->assertSame(
			$m[1],
			$todas[0]['version'],
			"El plugin va por {$m[1]} pero la entrada más nueva de includes/changelog-data.php es {$todas[0]['version']}. "
			. 'Cada versión trae su entrada en las novedades: sumala arriba de todo.'
		);
	}

	public function test_el_archivo_de_novedades_esta_completo(): void {
		$vistas = [];
		foreach ( Cead_Acad_Changelog::todas() as $e ) {
			$v = $e['version'] ?? '';
			$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $v, 'versión mal escrita' );
			$this->assertArrayNotHasKey( $v, $vistas, "la versión $v está repetida" );
			$vistas[ $v ] = true;

			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $e['fecha'] ?? '', "$v: fecha mal escrita" );
			$this->assertNotSame( Cead_Acad_Changelog::fecha_larga( $e['fecha'] ), $e['fecha'], "$v: la fecha no existe en el calendario" );
			$this->assertNotSame( '', trim( (string) ( $e['titulo'] ?? '' ) ), "$v: falta el título" );
			$this->assertNotSame( '', trim( (string) ( $e['resumen'] ?? '' ) ), "$v: falta el resumen" );
			$this->assertNotEmpty( $e['items'] ?? [], "$v: una versión sin novedades no se anota" );

			foreach ( $e['items'] as $i => $item ) {
				$donde = "$v, ítem " . ( $i + 1 );
				$this->assertArrayHasKey( $item['tipo'] ?? '', Cead_Acad_Changelog::TIPOS, "$donde: tipo desconocido" );
				$this->assertNotSame( '', trim( (string) ( $item['titulo'] ?? '' ) ), "$donde: falta el título" );
				$this->assertNotSame( '', trim( (string) ( $item['texto'] ?? '' ) ), "$donde: falta el texto" );
				$this->assertArrayHasKey( $item['para'] ?? 'todos', Cead_Acad_Changelog::NIVELES, "$donde: «para» desconocido" );
			}
		}
	}

	public function test_las_entradas_vienen_de_la_mas_nueva_a_la_mas_vieja(): void {
		$v = $this->versiones( Cead_Acad_Changelog::todas() );
		$ordenadas = $v;
		usort( $ordenadas, static function ( $a, $b ) { return version_compare( $b, $a ); } );
		$this->assertSame( $ordenadas, $v );
	}

	public function test_lo_que_ve_el_alumnado_no_trae_notas_tecnicas_ni_cosas_del_equipo(): void {
		foreach ( Cead_Acad_Changelog::para( 'todos' ) as $e ) {
			foreach ( $e['items'] as $item ) {
				$this->assertSame( 'todos', $item['para'] ?? 'todos', "«{$item['titulo']}» no es para todos" );
			}
		}
	}

	public function test_lo_que_se_lee_en_castellano_simple_no_lleva_jerga(): void {
		// Estas palabras son de quien programa. Si hace falta decirlas, van en la
		// nota «admin», que solo se ve en wp-admin.
		$jerga = [ '/\bendpoints?\b/i', '/\bREST\b/', '/\bJWT\b/', '/\bETag\b/i', '/\bidempoten/i', '/\btransients?\b/i', '/\bcron\b/i', '/\bCPT\b/', '/\bhooks?\b/i', '/\bAPI\b/', '/\bFCM\b/', '/\bFirebase\b/i' ];
		foreach ( Cead_Acad_Changelog::todas() as $e ) {
			foreach ( $e['items'] as $item ) {
				if ( 'admin' === ( $item['para'] ?? 'todos' ) ) { continue; } // esas ya son para quien administra
				foreach ( [ 'titulo', 'texto' ] as $campo ) {
					foreach ( $jerga as $patron ) {
						$this->assertDoesNotMatchRegularExpression(
							$patron,
							$item[ $campo ],
							"{$e['version']}: «{$item['titulo']}» usa jerga ({$patron}); eso va en la nota «admin»."
						);
					}
				}
			}
		}
	}

	/* ------------------------------------------------------ quién ve qué */

	public function test_cada_mirada_ve_lo_suyo_y_lo_de_las_anteriores(): void {
		$d = $this->entradas();

		$todos  = Cead_Acad_Changelog::para( 'todos', $d );
		$equipo = Cead_Acad_Changelog::para( 'equipo', $d );
		$admin  = Cead_Acad_Changelog::para( 'admin', $d );

		// La 1.10.0 solo tiene una cosa del equipo: para el alumnado no existe.
		$this->assertSame( [ '1.2.0', '1.1.0' ], $this->versiones( $todos ) );
		$this->assertSame( [ '1.10.0', '1.2.0', '1.1.0' ], $this->versiones( $equipo ) );
		$this->assertSame( [ '1.10.0', '1.2.0', '1.1.0' ], $this->versiones( $admin ) );

		$this->assertCount( 1, $todos[0]['items'] );
		$this->assertCount( 2, $equipo[1]['items'] );
		$this->assertCount( 3, $admin[1]['items'] );
	}

	public function test_las_entradas_se_ordenan_por_numero_y_no_por_texto(): void {
		// «1.10.0» es más nueva que «1.2.0», aunque como texto sea menor.
		$this->assertSame(
			[ '1.10.0', '1.2.0', '1.1.0' ],
			$this->versiones( Cead_Acad_Changelog::para( 'admin', $this->entradas() ) )
		);
	}

	public function test_un_item_se_ve_desde_su_nivel_o_uno_mayor(): void {
		$todos = [ 'tipo' => 'nuevo', 'titulo' => 'x', 'texto' => 'x' ];
		$equipo = $todos + [ 'para' => 'equipo' ];
		$admin  = $todos + [ 'para' => 'admin' ];

		$this->assertTrue( Cead_Acad_Changelog::ve( $todos, 'todos' ) );
		$this->assertFalse( Cead_Acad_Changelog::ve( $equipo, 'todos' ) );
		$this->assertTrue( Cead_Acad_Changelog::ve( $equipo, 'equipo' ) );
		$this->assertFalse( Cead_Acad_Changelog::ve( $admin, 'equipo' ) );
		$this->assertTrue( Cead_Acad_Changelog::ve( $admin, 'admin' ) );
		// Una mirada que no existe no ve más que lo de todos.
		$this->assertFalse( Cead_Acad_Changelog::ve( $equipo, 'inventada' ) );
		// Un «para» que no existe se trata como de todos.
		$this->assertTrue( Cead_Acad_Changelog::ve( $todos + [ 'para' => 'inventado' ], 'todos' ) );
	}

	public function test_la_mirada_de_cada_persona_sale_de_su_rol(): void {
		$roles = [
			1 => [ 'cead_acad_student', 'todos' ],
			2 => [ 'cead_acad_guardian', 'todos' ],
			3 => [ 'cead_acad_teacher', 'equipo' ],
			4 => [ 'cead_acad_delegate', 'equipo' ],
			5 => [ 'cead_acad_direction', 'equipo' ],
			6 => [ 'cead_acad_student_council', 'equipo' ],
		];
		foreach ( $roles as $id => [ $rol, $esperada ] ) {
			cead_test_set_user( $id )->roles = [ $rol ];
			$this->assertSame( $esperada, Cead_Acad_Changelog::mirada_de( $id ), $rol );
		}
		// Quien administra WordPress ve lo del equipo en el panel.
		cead_test_set_user( 7 )->roles = [ 'administrator' ];
		cead_test_set_caps( 7, [ 'manage_options' => true ] );
		$this->assertSame( 'equipo', Cead_Acad_Changelog::mirada_de( 7 ) );
		// Sin sesión, la mirada más cerrada.
		$this->assertSame( 'todos', Cead_Acad_Changelog::mirada_de( 0 ) );
	}

	/* --------------------------------------------------- qué ya se vio */

	public function test_hay_novedades_si_la_ultima_es_mas_nueva_que_lo_visto(): void {
		$e = Cead_Acad_Changelog::para( 'admin', $this->entradas() );

		$this->assertTrue( Cead_Acad_Changelog::hay_nuevas( '', $e ), 'quien nunca entró tiene novedades' );
		$this->assertTrue( Cead_Acad_Changelog::hay_nuevas( '1.1.0', $e ) );
		$this->assertTrue( Cead_Acad_Changelog::hay_nuevas( '1.2.0', $e ) );
		$this->assertFalse( Cead_Acad_Changelog::hay_nuevas( '1.10.0', $e ), '1.10.0 es la última' );
		$this->assertFalse( Cead_Acad_Changelog::hay_nuevas( '9.0.0', $e ) );
		$this->assertFalse( Cead_Acad_Changelog::hay_nuevas( '', [] ), 'sin novedades no hay qué avisar' );
	}

	public function test_a_quien_nunca_entro_solo_se_le_marca_la_ultima_como_nueva(): void {
		$e = Cead_Acad_Changelog::para( 'admin', $this->entradas() );

		$this->assertSame( [ '1.10.0' ], Cead_Acad_Changelog::frescas( $e, '' ) );
		// Quien vio hasta la 1.1.0 ve marcadas las que vinieron después (en el orden de la lista).
		$this->assertSame( [ '1.10.0', '1.2.0' ], Cead_Acad_Changelog::frescas( $e, '1.1.0' ) );
		$this->assertSame( [], Cead_Acad_Changelog::frescas( $e, '1.10.0' ) );
		$this->assertSame( [], Cead_Acad_Changelog::frescas( [], '' ) );
	}

	public function test_marcar_como_visto_se_recuerda_y_nunca_retrocede(): void {
		$this->assertSame( '', Cead_Acad_Changelog::visto( 5 ) );

		Cead_Acad_Changelog::marcar_visto( 5, '1.2.0' );
		$this->assertSame( '1.2.0', Cead_Acad_Changelog::visto( 5 ) );

		// Entrar por una mirada que ve menos (la última visible es más vieja) no borra lo visto.
		Cead_Acad_Changelog::marcar_visto( 5, '1.1.0' );
		$this->assertSame( '1.2.0', Cead_Acad_Changelog::visto( 5 ) );

		// 1.10.0 es mayor que 1.2.0: se compara como versión, no como texto.
		Cead_Acad_Changelog::marcar_visto( 5, '1.10.0' );
		$this->assertSame( '1.10.0', Cead_Acad_Changelog::visto( 5 ) );

		// Es por persona.
		$this->assertSame( '', Cead_Acad_Changelog::visto( 6 ) );
		// Sin persona o sin versión no se anota nada.
		Cead_Acad_Changelog::marcar_visto( 0, '2.0.0' );
		Cead_Acad_Changelog::marcar_visto( 6, '' );
		$this->assertSame( '', Cead_Acad_Changelog::visto( 6 ) );
	}

	/* ----------------------------------------------------- lo chico */

	public function test_las_fechas_se_leen_en_castellano(): void {
		$this->assertSame( '10 de octubre de 2026', Cead_Acad_Changelog::fecha_larga( '2026-10-10' ) );
		$this->assertSame( '1 de enero de 2027', Cead_Acad_Changelog::fecha_larga( '2027-01-01' ) );
		$this->assertSame( '29 de febrero de 2028', Cead_Acad_Changelog::fecha_larga( '2028-02-29' ) );
		// Lo que no es una fecha vuelve como vino, en vez de inventar una.
		$this->assertSame( '2026-02-30', Cead_Acad_Changelog::fecha_larga( '2026-02-30' ) );
		$this->assertSame( 'ayer', Cead_Acad_Changelog::fecha_larga( 'ayer' ) );
		$this->assertSame( '', Cead_Acad_Changelog::fecha_larga( '' ) );
	}

	public function test_se_cuentan_las_novedades_por_tipo(): void {
		$e = $this->entradas()[0];
		$this->assertSame( [ 'nuevo' => 1, 'mejora' => 1, 'arreglo' => 1 ], Cead_Acad_Changelog::contar( $e ) );
		$this->assertSame( [ 'nuevo' => 3, 'mejora' => 1, 'arreglo' => 1 ], Cead_Acad_Changelog::contar_todas( $this->entradas() ) );
		$this->assertSame( [], Cead_Acad_Changelog::contar( [ 'items' => [] ] ) );
	}

	public function test_el_ancla_de_una_version_sirve_en_una_url(): void {
		$this->assertSame( 'v0-97-0', Cead_Acad_Changelog::ancla( '0.97.0' ) );
	}

	public function test_las_notas_del_release_salen_del_mismo_archivo(): void {
		$md = Cead_Acad_Changelog::notas_markdown( '1.2.0', $this->entradas() );

		$this->assertStringStartsWith( "## Tres\n\nR3\n", $md );
		$this->assertStringContainsString( "### Nuevos\n\n- **Para todos** — T", $md );
		$this->assertStringContainsString( '### Mejoras', $md );
		$this->assertStringContainsString( '### Arreglos', $md );
		$this->assertStringContainsString( '_Para quien administra:_ nota', $md );

		$this->assertSame( '', Cead_Acad_Changelog::notas_markdown( '9.9.9', $this->entradas() ), 'sin entrada, sin notas' );
	}
}
