<?php
/**
 * Las maquetas de nota: qué se ofrece, qué se guarda y qué se dibuja.
 *
 * Lo que se prueba acá es la frontera entre los dos proyectos del repositorio.
 * El catálogo lo publica el TEMA por el filtro `cead_nota_tipos` y lo consume el
 * PLUGIN cuando CEADI publica desde WhatsApp. Es exactamente la clase de unión
 * que se rompe en silencio: nadie tira un error, las notas simplemente salen
 * todas con la maqueta de siempre y parece que la función nunca se hizo.
 */

use PHPUnit\Framework\TestCase;

final class MaquetasNotaTest extends TestCase {

	/** @return string Carpeta de plantillas del tema. */
	private function dir_plantillas(): string {
		return dirname( __DIR__, 3 ) . '/cead/template-parts/nota/';
	}

	/**
	 * Cada maqueta ofrecida tiene su archivo.
	 *
	 * Es el fallo que más caro sale y el que no se ve: `get_template_part()` con
	 * un nombre que no existe no avisa nada — imprime la nada. Una maqueta
	 * agregada al catálogo sin su archivo (o renombrada de un lado solo) publica
	 * notas que se ven como una página cortada al medio.
	 *
	 * El despachador tiene una red (`locate_template`, cae a noticia), pero este
	 * test existe igual: la red convierte el bug en «esta nota salió con la
	 * maqueta equivocada», que también es invisible y encima parece un capricho
	 * de CEADI.
	 */
	public function test_cada_maqueta_del_catalogo_tiene_su_plantilla(): void {
		$tipos = cead_nota_tipos();

		$this->assertNotEmpty( $tipos, 'El tema no ofrece ninguna maqueta: CEADI no podría elegir nada.' );

		foreach ( array_keys( $tipos ) as $slug ) {
			$this->assertFileExists(
				$this->dir_plantillas() . $slug . '.php',
				"La maqueta «{$slug}» está en el catálogo pero no tiene plantilla: la nota saldría vacía."
			);
		}
	}

	/** Y el defecto tiene que existir, porque es a donde cae todo lo demás. */
	public function test_la_maqueta_por_defecto_existe(): void {
		$this->assertArrayHasKey( CEAD_NOTA_DEFECTO, cead_nota_tipos() );
		$this->assertSame( CEAD_NOTA_DEFECTO, Cead_Acad_Article_Kind::DEFECTO );
	}

	/**
	 * Las claves de meta son la otra mitad del contrato: el plugin escribe, el
	 * tema lee. Si divergen no falla nada — el tema busca un meta que nadie
	 * escribió y todo sale como noticia.
	 */
	public function test_el_plugin_y_el_tema_nombran_el_mismo_meta(): void {
		$this->assertSame( CEAD_NOTA_META,  Cead_Acad_Article_Kind::META );
		$this->assertSame( CEAD_NOTA_FECHA, Cead_Acad_Article_Kind::META_FECHA );
		$this->assertSame( CEAD_NOTA_LUGAR, Cead_Acad_Article_Kind::META_LUGAR );
	}

	/** Cada maqueta se le explica al modelo; sin pista, elige a ciegas. */
	public function test_toda_maqueta_trae_su_pista_para_el_modelo(): void {
		foreach ( cead_nota_tipos() as $slug => $cfg ) {
			$this->assertNotEmpty( $cfg['label'] ?? '', "«{$slug}» no tiene nombre para wp-admin." );
			$this->assertNotEmpty( $cfg['pista'] ?? '', "«{$slug}» no le explica a CEADI cuándo usarla." );
		}
	}

	/* ------------------------------- resolver ------------------------------ */

	public function test_una_maqueta_valida_se_respeta(): void {
		$this->assertSame( 'aviso', Cead_Acad_Article_Kind::resolver( 'aviso' ) );
	}

	/** Lo que el modelo inventó no se guarda: cae a noticia. */
	public function test_una_maqueta_inventada_cae_a_noticia(): void {
		$this->assertSame( 'noticia', Cead_Acad_Article_Kind::resolver( 'infografia_animada' ) );
		$this->assertSame( 'noticia', Cead_Acad_Article_Kind::resolver( '' ) );
	}

	/**
	 * El caso real: el modelo acierta que es un evento y se olvida la fecha.
	 *
	 * Sin este veto la nota saldría con el bloque de fecha vacío — un cuadro
	 * rojo enorme con el día en blanco arriba del título.
	 */
	public function test_un_evento_sin_fecha_no_es_un_evento(): void {
		$this->assertSame( 'noticia', Cead_Acad_Article_Kind::resolver( 'evento' ) );
		$this->assertSame( 'noticia', Cead_Acad_Article_Kind::resolver( 'evento', [ 'fecha' => '   ' ] ) );
		$this->assertSame( 'noticia', Cead_Acad_Article_Kind::resolver( 'evento', [ 'lugar' => 'Patio central' ] ) );
	}

	public function test_un_evento_con_fecha_si_lo_es(): void {
		$this->assertSame( 'evento', Cead_Acad_Article_Kind::resolver( 'evento', [ 'fecha' => '2026-08-20 09:00:00' ] ) );
	}

	/**
	 * Sin tema que ofrezca maquetas no se guarda NADA.
	 *
	 * Escribir igual un `_cead_nota_tipo` sería dejar una bomba de tiempo: el
	 * meta queda en la base, nadie lo ve, y el día que el tema vuelva aparecen
	 * notas viejas con maquetas que nadie eligió.
	 */
	public function test_sin_catalogo_no_se_elige_nada(): void {
		$guardado = $GLOBALS['cead_test_filters']['cead_nota_tipos'] ?? [];
		$GLOBALS['cead_test_filters']['cead_nota_tipos'] = [];

		try {
			$this->assertSame( '', Cead_Acad_Article_Kind::resolver( 'evento', [ 'fecha' => '2026-08-20 09:00:00' ] ) );
			$this->assertFalse( Cead_Acad_Article_Kind::hay() );
		} finally {
			$GLOBALS['cead_test_filters']['cead_nota_tipos'] = $guardado;
		}
	}

	/* -------------------------------- índice ------------------------------- */

	public function test_el_indice_sale_de_los_subtitulos(): void {
		list( $html, $indice ) = cead_nota_indice( '<h2>Resultados</h2><p>x</p><h2>Conclusiones</h2>' );

		$this->assertCount( 2, $indice );
		$this->assertSame( 'Resultados', $indice[0]['texto'] );
		$this->assertStringContainsString( 'id="resultados"', $html );
		$this->assertStringContainsString( 'id="conclusiones"', $html );
	}

	/**
	 * Dos secciones con el mismo nombre existen de verdad («Resultados» por
	 * curso). Sin desambiguar, los dos enlaces del índice llevarían al primero.
	 */
	public function test_dos_secciones_iguales_no_comparten_ancla(): void {
		list( $html, $indice ) = cead_nota_indice( '<h2>Resultados</h2><h2>Resultados</h2>' );

		$this->assertCount( 2, $indice );
		$this->assertNotSame( $indice[0]['id'], $indice[1]['id'] );
		$this->assertStringContainsString( 'id="resultados-2"', $html );
	}

	/** Un `id` puesto a mano manda: reescribirlo rompe enlaces que ya existen. */
	public function test_un_id_escrito_a_mano_se_respeta(): void {
		list( $html, $indice ) = cead_nota_indice( '<h2 id="anexo-b">Anexo</h2>' );

		$this->assertSame( 'anexo-b', $indice[0]['id'] );
		$this->assertSame( 1, substr_count( $html, 'id=' ) );
	}

	public function test_sin_subtitulos_no_hay_indice_y_el_html_no_se_toca(): void {
		$original = '<p>Un informe de un solo bloque.</p>';
		list( $html, $indice ) = cead_nota_indice( $original );

		$this->assertSame( [], $indice );
		$this->assertSame( $original, $html );
	}

	/* -------------------------------- cuándo ------------------------------- */

	/**
	 * La cuenta va por días de calendario, no por múltiplos de 24 horas.
	 *
	 * A las 23:00 de un lunes, un acto del martes a las 8:00 está a nueve horas.
	 * Restando timestamps daría «es hoy», que es directamente falso y manda a
	 * alguien al colegio el día equivocado.
	 */
	public function test_manana_temprano_es_manana_aunque_falten_nueve_horas(): void {
		$lunes_23 = strtotime( '2026-08-17 23:00:00' );
		$martes_8 = strtotime( '2026-08-18 08:00:00' );

		$this->assertSame( 'Es mañana', cead_nota_cuando( $martes_8, $lunes_23 ) );
	}

	public function test_hoy_mas_tarde_sigue_siendo_hoy(): void {
		$hoy_8  = strtotime( '2026-08-17 08:00:00' );
		$hoy_19 = strtotime( '2026-08-17 19:00:00' );

		$this->assertSame( 'Es hoy', cead_nota_cuando( $hoy_19, $hoy_8 ) );
	}

	public function test_lo_que_ya_paso_lo_dice(): void {
		$ayer = strtotime( '2026-08-16 10:00:00' );
		$hoy  = strtotime( '2026-08-17 09:00:00' );

		$this->assertSame( 'Ya pasó', cead_nota_cuando( $ayer, $hoy ) );
	}

	public function test_cuenta_los_dias_que_faltan(): void {
		$hoy   = strtotime( '2026-08-17 09:00:00' );
		$en_5  = strtotime( '2026-08-22 09:00:00' );

		$this->assertSame( 'Faltan 5 días', cead_nota_cuando( $en_5, $hoy ) );
	}
}
