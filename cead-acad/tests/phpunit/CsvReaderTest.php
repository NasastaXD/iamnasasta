<?php
/**
 * Lector CSV de los importadores (Cead_Acad_Importer_Csv_Reader): sniff de
 * delimitador, BOM, headers y saneo anti CSV-injection.
 */

use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase {

	/** @var string[] Archivos temporales a limpiar. */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ( $this->tmp as $f ) {
			@unlink( $f );
		}
		$this->tmp = [];
	}

	private function file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'ceadcsv' );
		file_put_contents( $path, $content );
		$this->tmp[] = $path;
		return $path;
	}

	public function test_sniff_detecta_coma_punto_y_coma_y_tab(): void {
		$this->assertSame( ',', Cead_Acad_Importer_Csv_Reader::sniff_delimiter( $this->file( "a,b,c\n1,2,3\n" ) ) );
		$this->assertSame( ';', Cead_Acad_Importer_Csv_Reader::sniff_delimiter( $this->file( "a;b;c\n1;2;3\n" ) ) );
		$this->assertSame( "\t", Cead_Acad_Importer_Csv_Reader::sniff_delimiter( $this->file( "a\tb\tc\n" ) ) );
	}

	public function test_sniff_ignora_lineas_vacias_iniciales(): void {
		$path = $this->file( "\n\na;b;c\n" );
		$this->assertSame( ';', Cead_Acad_Importer_Csv_Reader::sniff_delimiter( $path ) );
	}

	public function test_read_all_separa_headers_y_filas(): void {
		$path = $this->file( "nombre,email\nAna,ana@x.com\nLuis,luis@x.com\n" );
		[ $headers, $rows ] = Cead_Acad_Importer_Csv_Reader::read_all( $path );
		$this->assertSame( [ 'nombre', 'email' ], $headers );
		$this->assertSame( [ [ 'Ana', 'ana@x.com' ], [ 'Luis', 'luis@x.com' ] ], $rows );
	}

	public function test_read_all_quita_bom(): void {
		$path = $this->file( "\xEF\xBB\xBFnombre,email\nAna,a@x.com\n" );
		[ $headers ] = Cead_Acad_Importer_Csv_Reader::read_all( $path );
		$this->assertSame( 'nombre', $headers[0], 'el BOM no debe quedar pegado al primer header' );
	}

	public function test_limpia_prefijos_de_csv_injection(): void {
		$path = $this->file( "nombre,formula\nAna,=SUM(A1:A9)\nLuis,+1234\nEva,@cmd\n" );
		[ , $rows ] = Cead_Acad_Importer_Csv_Reader::read_all( $path );
		$this->assertSame( 'SUM(A1:A9)', $rows[0][1] );
		$this->assertSame( '1234', $rows[1][1] );
		$this->assertSame( 'cmd', $rows[2][1] );
	}

	public function test_celdas_se_trimean(): void {
		$path = $this->file( "a,b\n  hola  , mundo \n" );
		[ , $rows ] = Cead_Acad_Importer_Csv_Reader::read_all( $path );
		$this->assertSame( [ 'hola', 'mundo' ], $rows[0] );
	}

	public function test_archivo_inexistente_devuelve_vacio(): void {
		[ $headers, $rows ] = @Cead_Acad_Importer_Csv_Reader::read_all( '/ruta/que/no/existe.csv' );
		$this->assertSame( [], $headers );
		$this->assertSame( [], $rows );
	}
}
