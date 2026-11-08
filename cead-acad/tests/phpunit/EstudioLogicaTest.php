<?php
use PHPUnit\Framework\TestCase;

/**
 * Lógica pura del estudio. Los valores esperados son los mismos que prueba el
 * backend de la app (repo Colegios, tests/unit/*.test.ts): las dos
 * implementaciones tienen que dar lo mismo.
 */
class EstudioLogicaTest extends TestCase {

	const SAL = 'una-sal-de-prueba-larga-de-mas-de-32-caracteres';

	public function test_seudonimo_es_hmac_estable_y_exige_sal_larga() {
		$this->assertSame( hash_hmac( 'sha256', '42', self::SAL ), Cead_Acad_Estudio_Logica::seudonimo( self::SAL, 42 ) );
		$this->assertNotSame( Cead_Acad_Estudio_Logica::seudonimo( self::SAL, 42 ), Cead_Acad_Estudio_Logica::seudonimo( self::SAL, 43 ) );
		$this->expectException( InvalidArgumentException::class );
		Cead_Acad_Estudio_Logica::seudonimo( 'corta', 42 );
	}

	public function test_meta_sin_datos_personales() {
		$this->assertSame(
			[ 'destinatarios' => 30, 'ts_cliente_dudoso' => true, 'tipo' => 'comunicado' ],
			Cead_Acad_Estudio_Logica::meta_segura( [
				'destinatarios' => 30, 'ts_cliente_dudoso' => true, 'tipo' => 'comunicado',
				'nombre' => 'Ana', 'email' => 'a@b.c', 'telefono' => '0981', 'user_agent' => 'x', 'ip' => '1.2.3.4',
				'texto' => 'hola', 'largo' => str_repeat( 'x', 61 ), 'objeto' => [ 1 ], 'Clave Rara' => 1,
			] )
		);
		$this->assertNull( Cead_Acad_Estudio_Logica::meta_segura( [] ) );
	}

	public function test_ts_cliente_dudoso() {
		$pub = strtotime( '2027-04-01T13:00:00Z' );
		$srv = strtotime( '2027-04-01T20:00:00Z' );
		$this->assertFalse( Cead_Acad_Estudio_Logica::ts_cliente_dudoso( strtotime( '2027-04-01T14:00:00Z' ), $srv, $pub ), 'leído sin conexión: creíble' );
		$this->assertTrue( Cead_Acad_Estudio_Logica::ts_cliente_dudoso( strtotime( '2027-04-01T21:00:00Z' ), $srv, $pub ), 'en el futuro' );
		$this->assertTrue( Cead_Acad_Estudio_Logica::ts_cliente_dudoso( strtotime( '2027-04-01T12:00:00Z' ), $srv, $pub ), 'antes de publicar' );
		$this->assertFalse( Cead_Acad_Estudio_Logica::ts_cliente_dudoso( null, $srv, $pub ) );
	}

	public function test_parsear_ts_acepta_iso_y_epoch_en_ms() {
		$this->assertSame( strtotime( '2027-04-01T13:00:00Z' ), Cead_Acad_Estudio_Logica::parsear_ts( '2027-04-01T13:00:00Z' ) );
		$this->assertSame( 1806584400, Cead_Acad_Estudio_Logica::parsear_ts( 1806584400000 ) );
		$this->assertNull( Cead_Acad_Estudio_Logica::parsear_ts( 'basura' ) );
		$this->assertNull( Cead_Acad_Estudio_Logica::parsear_ts( null ) );
	}

	private function filas( $inst, $rol, $curso, $personas, $eventos = 1 ) {
		$out = [];
		for ( $i = 0; $i < $personas * $eventos; $i++ ) {
			$out[] = [ 'seudonimo' => "$inst-$rol-$curso-" . ( $i % $personas ), 'institucion' => $inst, 'rol' => $rol, 'curso_grupo' => $curso ];
		}
		return $out;
	}

	public function test_k_anonimato_generaliza_y_suprime_contando_personas() {
		$r = Cead_Acad_Estudio_Logica::k_anonimato( $this->filas( 'I1', 'alumno', '1° año', 5 ) );
		$this->assertCount( 5, $r['filas'] );

		$r = Cead_Acad_Estudio_Logica::k_anonimato( $this->filas( 'I1', 'docente', '2° año', 3, 10 ) );
		$this->assertCount( 0, $r['filas'] );
		$this->assertSame( 30, $r['suprimidas'] );

		$r = Cead_Acad_Estudio_Logica::k_anonimato( array_merge(
			$this->filas( 'I1', 'docente', '1° año', 3 ), $this->filas( 'I1', 'docente', '2° año', 2 ), $this->filas( 'I1', 'docente', '3° año', 6 )
		) );
		$this->assertSame( 5, $r['generalizadas'] );
		$this->assertSame( 0, $r['suprimidas'] );
		$this->assertCount( 5, array_filter( $r['filas'], static function ( $f ) { return Cead_Acad_Estudio_Logica::AGRUPADO === $f['curso_grupo']; } ) );
	}

	public function test_sus() {
		$this->assertSame( 100.0, Cead_Acad_Estudio_Logica::sus( [ 5, 1, 5, 1, 5, 1, 5, 1, 5, 1 ] ) );
		$this->assertSame( 0.0, Cead_Acad_Estudio_Logica::sus( [ 1, 5, 1, 5, 1, 5, 1, 5, 1, 5 ] ) );
		$this->assertSame( 80.0, Cead_Acad_Estudio_Logica::sus( [ 4, 2, 4, 1, 4, 2, 5, 2, 4, 2 ] ) );
		$this->expectException( InvalidArgumentException::class );
		Cead_Acad_Estudio_Logica::sus( [ 3, 3, 3 ] );
	}

	public function test_calendario_paraguay() {
		$this->assertSame( '2026-04-05', Cead_Acad_Estudio_Logica::pascua( 2026 ) );
		$this->assertSame( '2027-03-28', Cead_Acad_Estudio_Logica::pascua( 2027 ) );
		$f = Cead_Acad_Estudio_Logica::feriados( 2026 );
		$this->assertArrayHasKey( '2026-04-02', $f );
		$this->assertArrayHasKey( '2026-04-03', $f );
		$this->assertArrayHasKey( '2026-05-15', $f );

		$v = Cead_Acad_Estudio_Logica::ventana( '2027-03-01', 1 );
		$this->assertSame( '2027-04-01T13:00:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $v['envio'] ), '10:00 PY = 13:00 UTC' );
		$this->assertSame( '2027-04-08T13:00:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $v['cierre'] ) );
		$this->assertSame( [ '2027-04-04T13:00:00Z', '2027-04-07T13:00:00Z' ], array_map( static function ( $t ) { return gmdate( 'Y-m-d\TH:i:s\Z', $t ); }, $v['recordatorios'] ) );
		$this->assertSame( '2027-03-08', gmdate( 'Y-m-d', Cead_Acad_Estudio_Logica::ventana( '2027-03-06', 0 )['envio'] ), 'mes 0: sábado → lunes' );
		$this->assertSame( '2028-01-03', gmdate( 'Y-m-d', Cead_Acad_Estudio_Logica::ventana( '2027-03-01', 10 )['envio'] ) );
		$this->assertSame( '2028-03-09', gmdate( 'Y-m-d', Cead_Acad_Estudio_Logica::fin_recoleccion( '2027-03-01' ) ), '1 mar 2028 feriado → 2, cierre 9' );
	}

	public function test_instrumentos_del_archivo_versionado() {
		$cfg = json_decode( file_get_contents( CEAD_ACAD_DIR . 'config/estudio-encuestas.json' ), true );
		$this->assertSame( 'mensual@1+sus@1+final_abiertas@1', Cead_Acad_Estudio_Logica::instrumento( $cfg, 12, 'alumno' )['version'] );
		$a = Cead_Acad_Estudio_Logica::instrumento( $cfg, 1, 'alumno' );
		$d = Cead_Acad_Estudio_Logica::instrumento( $cfg, 1, 'docente' );
		$this->assertCount( 6, $a['preguntas'] );
		$this->assertCount( 7, $d['preguntas'] );
		$this->assertNotSame( array_column( $a['preguntas'], 'texto' ), array_column( $d['preguntas'], 'texto' ), 'cada rol su instrumento' );
		for ( $m = 0; $m <= 12; $m++ ) {
			foreach ( [ 'alumno', 'docente' ] as $rol ) {
				$this->assertNotEmpty( Cead_Acad_Estudio_Logica::instrumento( $cfg, $m, $rol )['preguntas'] );
			}
		}
	}

	public function test_validar_respuestas_separa_abiertas_y_arma_sus() {
		$cfg  = json_decode( file_get_contents( CEAD_ACAD_DIR . 'config/estudio-encuestas.json' ), true );
		$inst = Cead_Acad_Estudio_Logica::instrumento( $cfg, 4, 'alumno' );
		$resp = [];
		foreach ( $inst['preguntas'] as $p ) {
			if ( 'likert5' === $p['tipo'] ) { $resp[ $p['id'] ] = 4; }
			if ( 'opcion' === $p['tipo'] ) { $resp[ $p['id'] ] = $p['opciones'][0]; }
		}
		$resp['mensual.mejorar'] = '  que cargue más rápido ';
		$v = Cead_Acad_Estudio_Logica::validar_respuestas( $inst['preguntas'], $resp );
		$this->assertSame( [], $v['errores'] );
		$this->assertSame( [ 'mensual.mejorar' => 'que cargue más rápido' ], $v['abiertas'] );
		$this->assertArrayNotHasKey( 'mensual.mejorar', $v['cerradas'] );
		$this->assertSame( 50.0, Cead_Acad_Estudio_Logica::sus( $v['sus'] ) );

		$v = Cead_Acad_Estudio_Logica::validar_respuestas( Cead_Acad_Estudio_Logica::instrumento( $cfg, 0, 'docente' )['preguntas'],
			[ 'inicial.minutos_asistencia' => 500, 'inicial.a_tiempo' => 9, 'otra' => 1 ] );
		$this->assertNotEmpty( preg_grep( '/desconocida/', $v['errores'] ) );
		$this->assertNotEmpty( preg_grep( '/fuera de rango/', $v['errores'] ) );
		$this->assertNotEmpty( preg_grep( '/falta responder/', $v['errores'] ) );
	}
}
