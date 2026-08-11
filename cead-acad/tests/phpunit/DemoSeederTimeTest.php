<?php
/**
 * Hora local vs. GMT en los datos de demo.
 *
 * El sistema guarda tiempo en dos husos según dónde:
 *
 *  - Metas que en el admin se cargan con `<input type="datetime-local">` o
 *    `type="date"` —inicio/fin de evento, apertura/cierre de encuesta,
 *    vencimiento de tarea— van en hora LOCAL y se comparan contra
 *    `current_time()`. `post_date` también es local.
 *  - Tablas propias del plugin: GMT, `current_time( 'mysql', 1 )`.
 *
 * El seeder sacaba casi todo de `gmdate()`, que es UTC. En Paraguay (UTC-3) eso
 * adelantaba los comunicados tres horas y corría un día los eventos cercanos a
 * medianoche — justo lo que se mira en una demo.
 */

use PHPUnit\Framework\TestCase;

final class DemoSeederTimeTest extends TestCase {

	protected function setUp(): void {
		cead_test_set_gmt_offset( -3 ); // Paraguay
	}

	protected function tearDown(): void {
		cead_test_set_gmt_offset( 0 );
	}

	/** Los helpers son protected: se los alcanza por reflexión. */
	private function call( $metodo, ...$args ) {
		$m = new ReflectionMethod( 'Cead_Acad_Demo_Seeder', $metodo );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	public function test_local_va_tres_horas_atras_de_utc_en_paraguay(): void {
		$local = strtotime( $this->call( 'local', 'Y-m-d H:i:s' ) );
		$utc   = strtotime( $this->call( 'utc', 'Y-m-d H:i:s' ) );

		// Margen de un segundo: las dos llamadas no son simultáneas.
		$this->assertEqualsWithDelta( 3 * HOUR_IN_SECONDS, $utc - $local, 1 );
	}

	public function test_local_coincide_con_current_time(): void {
		$this->assertSame(
			substr( current_time( 'mysql' ), 0, 16 ),
			substr( $this->call( 'local', 'Y-m-d H:i:s' ), 0, 16 )
		);
	}

	public function test_utc_coincide_con_current_time_gmt(): void {
		$this->assertSame(
			substr( current_time( 'mysql', 1 ), 0, 16 ),
			substr( $this->call( 'utc', 'Y-m-d H:i:s' ), 0, 16 )
		);
	}

	public function test_las_expresiones_relativas_se_aplican_sobre_la_hora_local(): void {
		$hoy      = $this->call( 'local', 'Y-m-d' );
		$en_tres  = $this->call( 'local', 'Y-m-d', '+3 days' );
		$esperado = gmdate( 'Y-m-d', strtotime( $hoy ) + 3 * DAY_IN_SECONDS );

		$this->assertSame( $esperado, $en_tres );
	}

	/**
	 * El caso que motivó el arreglo: a las 22:00 hora paraguaya ya son las 01:00
	 * del día siguiente en UTC, así que un evento "de hoy" fechado con gmdate()
	 * aparecía en el calendario un día corrido.
	 */
	public function test_cerca_de_medianoche_la_fecha_local_y_la_utc_no_son_el_mismo_dia(): void {
		$local_ts = time() + (int) round( -3 * HOUR_IN_SECONDS );
		$dia_local = gmdate( 'Y-m-d', $local_ts );
		$dia_utc   = gmdate( 'Y-m-d', time() );

		// No siempre difieren (solo en la franja de 21:00 a 24:00 local), pero
		// cuando difieren, el seeder tiene que quedarse con la local.
		if ( $dia_local !== $dia_utc ) {
			$this->assertSame( $dia_local, $this->call( 'local', 'Y-m-d' ) );
			$this->assertNotSame( $dia_utc, $this->call( 'local', 'Y-m-d' ) );
		} else {
			$this->assertSame( $dia_local, $this->call( 'local', 'Y-m-d' ) );
		}
	}

	/**
	 * Que no vuelva a colarse un `gmdate()` crudo donde va hora local. Se lee el
	 * código porque sembrar de verdad necesita WordPress entero.
	 */
	public function test_el_seeder_no_usa_gmdate_crudo_para_metas_locales(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/modules/demo/class-demo-seeder.php' );

		$locales = [
			'_cead_acad_event_start',
			'_cead_acad_task_due_date',
			'_cead_acad_survey_opens_at',
			'_cead_acad_survey_closes_at',
		];
		foreach ( $locales as $meta ) {
			foreach ( explode( "\n", $src ) as $linea ) {
				if ( false === strpos( $linea, $meta ) ) { continue; }
				$this->assertStringNotContainsString(
					'gmdate(',
					$linea,
					"El meta {$meta} va en hora local; usá self::local()."
				);
			}
		}
	}

	/** Y que las tablas propias sigan en GMT. */
	public function test_las_tablas_propias_se_escriben_en_gmt(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/modules/demo/class-demo-seeder.php' );

		foreach ( [ 'recorded_at', 'read_at', 'submitted_at', 'created_at' ] as $col ) {
			foreach ( explode( "\n", $src ) as $linea ) {
				if ( false === strpos( $linea, "'{$col}'" ) ) { continue; }
				$this->assertMatchesRegularExpression(
					"/current_time\(\s*'mysql',\s*1\s*\)|self::utc\(/",
					$linea,
					"La columna {$col} va en GMT: current_time( 'mysql', 1 ) o self::utc()."
				);
			}
		}
	}
}
