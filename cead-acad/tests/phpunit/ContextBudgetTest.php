<?php
/**
 * Presupuesto del prompt de sistema.
 *
 * Todo este bloque se manda en CADA turno de CADA conversación, así que su
 * tamaño es costo recurrente. Cuando no entra, importa QUÉ se cae: las
 * noticias tienen reemplazo (`buscar_noticias`), la memoria no — perderla
 * dejaría al modelo afirmando datos viejos con seguridad.
 */

use PHPUnit\Framework\TestCase;

class ContextBudgetTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
		// Persona corta para que el presupuesto lo dominen los bloques bajo test
		// y no el texto fijo.
		cead_test_set_option( 'cead_acad_wa_ai_prompt', 'PERSONA' );
		// Digest de noticias vacío por defecto: sin esto, News::digest() se va a
		// la base con get_posts(), que esta suite no cubre.
		set_transient( 'cead_acad_wa_news_digest', '' );
	}

	/** Llama al armador, que es protected. */
	protected function build( $faq = '', $user = '' ) {
		$m = new ReflectionMethod( 'Cead_Acad_WA_AI', 'build_system' );
		$m->setAccessible( true );
		return $m->invoke( null, $faq, 'tools', $user, [] );
	}

	protected function cargar( $chars = 500 ) {
		cead_test_set_option( 'cead_acad_wa_ai_knowledge', str_repeat( 'C', $chars ) );
		cead_test_set_option( 'cead_acad_wa_ai_memories', [
			[ 'id' => 'm1', 'text' => str_repeat( 'M', $chars ), 'created' => 1, 'author' => 1 ],
		] );
		set_transient( 'cead_acad_wa_news_digest', str_repeat( 'N', $chars ) );
	}

	public function test_con_presupuesto_amplio_entran_todos_los_bloques() {
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 120000 );
		$this->cargar();

		$p = $this->build( str_repeat( 'F', 500 ), 'Nombre: Alguien' );

		$this->assertStringContainsString( '[CONOCIMIENTO DEL COLEGIO]', $p );
		$this->assertStringContainsString( '[LO QUE TE FUERON ENSEÑANDO]', $p );
		$this->assertStringContainsString( '[PUBLICADO EN EL SITIO ÚLTIMAMENTE]', $p );
		$this->assertStringContainsString( '[FAQ]', $p );
		$this->assertStringContainsString( '[IDENTIDAD VERIFICADA POR EL SISTEMA]', $p );
		$this->assertSame( [], Cead_Acad_WA_AI::last_trimmed() );
	}

	/**
	 * Lo primero que se toca son las noticias: el modelo las puede buscar igual.
	 * Con un exceso chico se trunca ese bloque en vez de tirarlo, y los demás
	 * quedan intactos.
	 */
	public function test_lo_primero_que_se_recorta_son_las_noticias() {
		$this->cargar( 500 );
		/*
		 * El presupuesto se fija en relación al prompt base, no en un número
		 * suelto: lo que se prueba acá es el ORDEN del recorte con un exceso
		 * CHICO, y el tamaño del base cambia cada vez que se ajustan las
		 * instrucciones. Con un valor fijo, escribir dos párrafos más en el
		 * prompt hacía fallar un test que no tiene nada que ver con eso.
		 */
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 120000 );
		$base = mb_strlen( $this->build( '', '' ) );
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', $base + 1200 );

		$p     = $this->build( str_repeat( 'F', 500 ), 'Nombre: Alguien' );
		$fuera = Cead_Acad_WA_AI::last_trimmed();

		$this->assertNotEmpty( $fuera );
		$this->assertStringStartsWith( 'noticias', $fuera[0] );
		// Solo las noticias resultaron afectadas.
		$this->assertCount( 1, $fuera );
		$this->assertStringContainsString( '[CONOCIMIENTO DEL COLEGIO]', $p );
		$this->assertStringContainsString( '[LO QUE TE FUERON ENSEÑANDO]', $p );
		$this->assertStringContainsString( '[FAQ]', $p );
	}

	/** La memoria pisa al conocimiento: si algo tiene que sobrevivir, es ella. */
	public function test_la_memoria_sobrevive_al_conocimiento() {
		$this->cargar( 2000 );
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 4000 );

		$p = $this->build( str_repeat( 'F', 2000 ), 'Nombre: Alguien' );

		$this->assertStringContainsString( '[LO QUE TE FUERON ENSEÑANDO]', $p );
		$fuera = Cead_Acad_WA_AI::last_trimmed();
		$this->assertContains( 'noticias', $fuera );
		$this->assertContains( 'faq', $fuera );
		$this->assertNotContains( 'memoria', $fuera );
	}

	/** La identidad verificada es de seguridad: nunca se recorta ni se trunca. */
	public function test_la_identidad_nunca_se_recorta() {
		$this->cargar( 3000 );
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 4000 );

		$identidad = 'Nombre: Alguien · Rol: Administrador del sistema';
		$p = $this->build( str_repeat( 'F', 3000 ), $identidad );

		$this->assertStringContainsString( '[IDENTIDAD VERIFICADA POR EL SISTEMA]', $p );
		$this->assertStringContainsString( $identidad, $p );
	}

	/**
	 * El orden del prompt no puede cambiar con el recorte: lo estático va
	 * primero y lo variable al final, que es lo que permite que un proveedor
	 * con caché de prefijo reutilice el bloque de arriba entre conversaciones.
	 */
	public function test_el_orden_se_mantiene_para_no_romper_la_cache() {
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 120000 );
		$this->cargar();

		$p = $this->build( str_repeat( 'F', 500 ), 'Nombre: Alguien' );

		$posiciones = [
			mb_strpos( $p, '[CONOCIMIENTO DEL COLEGIO]' ),
			mb_strpos( $p, '[LO QUE TE FUERON ENSEÑANDO]' ),
			mb_strpos( $p, '[PUBLICADO EN EL SITIO ÚLTIMAMENTE]' ),
			mb_strpos( $p, '[FAQ]' ),
			mb_strpos( $p, '[IDENTIDAD VERIFICADA POR EL SISTEMA]' ),
		];
		$ordenadas = $posiciones;
		sort( $ordenadas );
		$this->assertSame( $ordenadas, $posiciones, 'la identidad tiene que quedar última' );
	}

	public function test_el_presupuesto_se_acota_a_un_rango_razonable() {
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 10 );
		$this->assertSame( 4000, Cead_Acad_WA_AI::context_budget() );

		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 999999 );
		$this->assertSame( 120000, Cead_Acad_WA_AI::context_budget() );
	}

	public function test_sin_bloques_opcionales_no_recorta_nada() {
		cead_test_set_option( 'cead_acad_wa_ai_context_budget', 4000 );
		$p = $this->build( '', 'Nombre: Alguien' );

		$this->assertSame( [], Cead_Acad_WA_AI::last_trimmed() );
		$this->assertStringContainsString( 'PERSONA', $p );
	}
}
