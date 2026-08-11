<?php
/**
 * "Borrar lo que trajo este import": qué tipos lo admiten.
 *
 * El resto de `delete_job_data()` orquesta WP core (get_users, get_posts,
 * wp_delete_post, wp_delete_user) para de verdad borrar cuentas y cursos, y
 * eso queda fuera de esta suite por la misma razón que el resto del código
 * que necesita WordPress real — igual que `do_edit_user()` o el `save()` del
 * metabox de curso no tienen test propio, solo sus sub-lógicas puras.
 */

use PHPUnit\Framework\TestCase;

final class ImporterAdminDeleteDataTest extends TestCase {

	public function test_los_cinco_importadores_admiten_borrado_en_masa(): void {
		$this->assertSame(
			[ 'students', 'courses', 'events', 'horarios', 'grades' ],
			Cead_Acad_Importer_Admin::tipos_borrables()
		);
	}

	/**
	 * `_cead_acad_horario_imported_via_job` tiene que ser un meta APARTE de
	 * `_cead_acad_imported_via_job`: si compartieran el nombre, importar el
	 * horario de un curso ya existente le robaría al curso el rastro de qué
	 * job lo CREÓ, y "borrar lo que trajo el import de Cursos #5" dejaría de
	 * encontrar cursos que un import de Horario posterior tocó.
	 */
	public function test_el_meta_de_horario_es_distinto_del_meta_general_de_import(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/modules/importers/class-importer-horarios.php' );
		$this->assertStringContainsString( "'_cead_acad_horario_imported_via_job'", $src );
	}
}
