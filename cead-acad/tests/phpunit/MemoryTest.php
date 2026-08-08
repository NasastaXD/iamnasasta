<?php
/**
 * Memoria persistente de CEADI.
 *
 * Lo que importa acá es que no se pueda ensuciar sola: sin duplicados, con
 * techo de entradas, y que borrar por texto no borre la equivocada cuando hay
 * más de una candidata.
 */

use PHPUnit\Framework\TestCase;

class MemoryTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	public function test_guarda_y_lista() {
		$id = Cead_Acad_WA_Memory::add( 'Las clases empiezan 7:10' );
		$this->assertIsString( $id );

		$all = Cead_Acad_WA_Memory::all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'Las clases empiezan 7:10', $all[0]['text'] );
	}

	public function test_la_mas_nueva_va_primero() {
		Cead_Acad_WA_Memory::add( 'vieja' );
		Cead_Acad_WA_Memory::add( 'nueva' );

		$all = Cead_Acad_WA_Memory::all();
		$this->assertSame( 'nueva', $all[0]['text'] );
		$this->assertSame( 'vieja', $all[1]['text'] );
	}

	public function test_no_guarda_vacio() {
		$this->assertInstanceOf( WP_Error::class, Cead_Acad_WA_Memory::add( '   ' ) );
		$this->assertSame( [], Cead_Acad_WA_Memory::all() );
	}

	/** Repetir un dato no tiene sentido: solo gasta contexto en el prompt. */
	public function test_rechaza_duplicado_sin_importar_mayusculas() {
		Cead_Acad_WA_Memory::add( 'El recreo es 9:30' );
		$dup = Cead_Acad_WA_Memory::add( 'el recreo ES 9:30' );

		$this->assertInstanceOf( WP_Error::class, $dup );
		$this->assertSame( 'duplicate', $dup->get_error_code() );
		$this->assertCount( 1, Cead_Acad_WA_Memory::all() );
	}

	public function test_normaliza_espacios_y_recorta_largo() {
		Cead_Acad_WA_Memory::add( "  hola\n\n   mundo  " );
		$this->assertSame( 'hola mundo', Cead_Acad_WA_Memory::all()[0]['text'] );

		cead_test_reset_options();
		Cead_Acad_WA_Memory::add( str_repeat( 'a', 500 ) );
		$this->assertSame( Cead_Acad_WA_Memory::MAX_LEN, strlen( Cead_Acad_WA_Memory::all()[0]['text'] ) );
	}

	/** El techo existe para que la memoria no se coma el contexto del modelo. */
	public function test_respeta_el_techo_descartando_la_mas_vieja() {
		for ( $i = 1; $i <= Cead_Acad_WA_Memory::MAX + 5; $i++ ) {
			Cead_Acad_WA_Memory::add( "dato $i" );
		}
		$all = Cead_Acad_WA_Memory::all();
		$this->assertCount( Cead_Acad_WA_Memory::MAX, $all );
		$this->assertSame( 'dato ' . ( Cead_Acad_WA_Memory::MAX + 5 ), $all[0]['text'] );
		$this->assertNotContains( 'dato 1', wp_list_pluck_texts( $all ) );
	}

	public function test_borra_por_texto_parcial() {
		Cead_Acad_WA_Memory::add( 'La coordinadora de tercero es Ana' );
		$gone = Cead_Acad_WA_Memory::remove( 'coordinadora' );

		$this->assertSame( 'La coordinadora de tercero es Ana', $gone );
		$this->assertSame( [], Cead_Acad_WA_Memory::all() );
	}

	public function test_borra_por_id() {
		$id = Cead_Acad_WA_Memory::add( 'algo' );
		$this->assertSame( 'algo', Cead_Acad_WA_Memory::remove( $id ) );
		$this->assertSame( [], Cead_Acad_WA_Memory::all() );
	}

	/** Con dos candidatas no se adivina: se devuelven las dos y no se borra nada. */
	public function test_ambiguo_no_borra_nada() {
		Cead_Acad_WA_Memory::add( 'El horario de primero es 7:10' );
		Cead_Acad_WA_Memory::add( 'El horario de segundo es 13:00' );

		$r = Cead_Acad_WA_Memory::remove( 'horario' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ambiguous', $r->get_error_code() );
		$this->assertCount( 2, $r->get_error_data() );
		$this->assertCount( 2, Cead_Acad_WA_Memory::all(), 'no tiene que borrar nada' );
	}

	public function test_borrar_lo_que_no_existe_avisa() {
		Cead_Acad_WA_Memory::add( 'algo' );
		$r = Cead_Acad_WA_Memory::remove( 'otra cosa' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'not_found', $r->get_error_code() );
		$this->assertCount( 1, Cead_Acad_WA_Memory::all() );
	}

	/** La previsualización tiene que coincidir con lo que después se borra. */
	public function test_preview_no_borra_y_coincide_con_remove() {
		Cead_Acad_WA_Memory::add( 'La coordinadora de tercero es Ana' );

		$preview = Cead_Acad_WA_Memory::remove_preview( 'coordinadora' );
		$this->assertSame( 'La coordinadora de tercero es Ana', $preview );
		$this->assertCount( 1, Cead_Acad_WA_Memory::all(), 'preview no borra' );

		$this->assertSame( $preview, Cead_Acad_WA_Memory::remove( 'coordinadora' ) );
	}

	public function test_text_at_resuelve_la_posicion_del_listado() {
		Cead_Acad_WA_Memory::add( 'vieja' );
		Cead_Acad_WA_Memory::add( 'nueva' );

		$this->assertSame( 'nueva', Cead_Acad_WA_Memory::text_at( 1 ) );
		$this->assertSame( 'vieja', Cead_Acad_WA_Memory::text_at( 2 ) );
		$this->assertSame( '', Cead_Acad_WA_Memory::text_at( 9 ) );
	}

	/** Sin memorias el bloque va vacío, para no gastar contexto en un título solo. */
	public function test_contexto_vacio_cuando_no_hay_nada() {
		$this->assertSame( '', Cead_Acad_WA_Memory::context() );

		Cead_Acad_WA_Memory::add( 'un dato' );
		$this->assertSame( '- un dato', Cead_Acad_WA_Memory::context() );
	}

	public function test_update_reescribe_conservando_la_fecha() {
		$id    = Cead_Acad_WA_Memory::add( 'El recreo es 9:30' );
		$antes = Cead_Acad_WA_Memory::all()[0]['created'];

		$r = Cead_Acad_WA_Memory::update( $id, 'El recreo es 9:45' );
		$this->assertSame( 'El recreo es 9:45', $r );

		$m = Cead_Acad_WA_Memory::all()[0];
		$this->assertSame( 'El recreo es 9:45', $m['text'] );
		$this->assertSame( $antes, $m['created'], 'corregir la redacción no la vuelve un dato nuevo' );
		$this->assertCount( 1, Cead_Acad_WA_Memory::all() );
	}

	public function test_update_rechaza_vacio_y_id_inexistente() {
		$id = Cead_Acad_WA_Memory::add( 'algo' );

		$this->assertSame( 'empty', Cead_Acad_WA_Memory::update( $id, '  ' )->get_error_code() );
		$this->assertSame( 'not_found', Cead_Acad_WA_Memory::update( 'noexiste', 'texto' )->get_error_code() );
		$this->assertSame( 'algo', Cead_Acad_WA_Memory::all()[0]['text'], 'no tiene que tocar nada' );
	}

	/** Editar una para dejarla igual a otra crearía el duplicado por la ventana. */
	public function test_update_no_permite_chocar_con_otra() {
		Cead_Acad_WA_Memory::add( 'primera' );
		$id = Cead_Acad_WA_Memory::add( 'segunda' );

		$r = Cead_Acad_WA_Memory::update( $id, 'PRIMERA' );
		$this->assertSame( 'duplicate', $r->get_error_code() );
		$this->assertSame( 'segunda', Cead_Acad_WA_Memory::all()[0]['text'] );
	}

	/** Reescribirla con su mismo texto no es un choque consigo misma. */
	public function test_update_con_el_mismo_texto_no_es_duplicado() {
		$id = Cead_Acad_WA_Memory::add( 'sin cambios' );
		$this->assertSame( 'sin cambios', Cead_Acad_WA_Memory::update( $id, 'sin cambios' ) );
	}

	public function test_clear_vacia_todo() {
		Cead_Acad_WA_Memory::add( 'a' );
		Cead_Acad_WA_Memory::add( 'b' );

		$this->assertSame( 2, Cead_Acad_WA_Memory::clear() );
		$this->assertSame( [], Cead_Acad_WA_Memory::all() );
	}
}

/** Helper local: los textos de una lista de memorias. */
function wp_list_pluck_texts( array $list ) {
	return array_map( static function ( $m ) { return $m['text']; }, $list );
}
