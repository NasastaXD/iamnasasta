<?php
use PHPUnit\Framework\TestCase;

class AsistenciasConsentimientosTest extends TestCase {

	public function test_clave_de_fila_es_estable_y_limpia() {
		$this->assertSame( 'abc-123:7', Cead_Acad_Asistencias::clave_fila( 'abc-123', 7 ) );
		$this->assertSame( Cead_Acad_Asistencias::clave_fila( 'x', 7 ), Cead_Acad_Asistencias::clave_fila( 'x', 7 ), 'reintento = misma clave' );
		$this->assertNotSame( Cead_Acad_Asistencias::clave_fila( 'x', 7 ), Cead_Acad_Asistencias::clave_fila( 'y', 7 ), 'otro envío = corrección' );
		$this->assertSame( 'drop:7', Cead_Acad_Asistencias::clave_fila( "dr'op;", 7 ) );
	}

	public function test_importar_csv_de_consentimientos_en_papel() {
		$usuarios = [ 'ana@x.py' => 10, 'beto' => 11 ];
		$guardados = [];
		$r = Cead_Acad_Estudio_Consentimientos::importar_csv(
			"\xEF\xBB\xBFemail;tipo;version_documento;fecha\nana@x.py;consentimiento_familia;v1;2027-03-01\nbeto;asentimiento_alumno;v1;\nnadie@x.py;consentimiento_propio;v1;\nana@x.py;inventado;v1;\n",
			static function ( $id ) use ( $usuarios ) { return $usuarios[ $id ] ?? 0; },
			static function ( $uid, $tipo, $version, $fecha ) use ( &$guardados ) { $guardados[] = [ $uid, $tipo, $version, $fecha ]; }
		);
		$this->assertSame( 2, $r['ok'] );
		$this->assertSame( [ [ 10, 'consentimiento_familia', 'v1', '2027-03-01' ], [ 11, 'asentimiento_alumno', 'v1', null ] ], $guardados );
		$this->assertSame( [ [ 'fila' => 4, 'error' => 'usuario_no_encontrado' ], [ 'fila' => 5, 'error' => 'tipo' ] ], $r['errores'] );
		$this->assertStringNotContainsString( 'nadie@x.py', wp_json_encode( $r ), 'el informe no repite correos' );
	}

	public function test_importar_csv_sin_encabezados_requeridos() {
		$r = Cead_Acad_Estudio_Consentimientos::importar_csv( "nombre,apellido\nAna,B\n", static function () { return 0; }, static function () {} );
		$this->assertSame( 'encabezados', $r['errores'][0]['error'] );
	}
}
