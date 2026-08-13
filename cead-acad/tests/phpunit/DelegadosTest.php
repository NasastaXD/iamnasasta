<?php
/**
 * El directorio de delegados y su enlace de contacto.
 *
 * Lo que se prueba acá es lo que decide QUÉ se publica y a quién se le escribe:
 * un teléfono mal armado manda el mensaje a un desconocido, y un curso apuntando
 * a un usuario borrado rompe la pantalla entera.
 *
 * El listado en sí toca la base (get_posts), así que la parte de `delegates()`
 * queda para la suite con WordPress; acá va el enlace, que es puro y es donde
 * está el riesgo real.
 */

use PHPUnit\Framework\TestCase;

final class DelegadosTest extends TestCase {

	protected function setUp(): void {
		cead_test_reset_options();
	}

	/**
	 * El caso que importa: los teléfonos de la gente se cargan en formato local
	 * («0991 123 456»). `wa.me/0991123456` no existe — sin el código de país el
	 * botón no abre nada, o peor, abre un chat con otro número.
	 */
	public function test_un_telefono_local_se_convierte_a_internacional(): void {
		$this->assertSame( 'https://wa.me/595991123456', cead_acad_wa_link( '0991 123 456' ) );
	}

	public function test_un_telefono_que_ya_trae_el_pais_no_se_duplica(): void {
		$this->assertSame( 'https://wa.me/595991123456', cead_acad_wa_link( '+595 991 123 456' ) );
	}

	/** Un local sin el cero inicial también tiene que llegar bien. */
	public function test_un_local_sin_cero_toma_el_codigo_igual(): void {
		$this->assertSame( 'https://wa.me/595991123456', cead_acad_wa_link( '991123456' ) );
	}

	/**
	 * El código de país es configurable: un colegio de otro país no queda atado.
	 *
	 * Se reemplaza el cero inicial del formato local por el código, sin agregar
	 * nada más. (En Argentina WhatsApp además quiere un 9 para los móviles; eso
	 * es una particularidad de ese país y no la resuelve esta función — el
	 * número se carga ya con el 9 si hace falta.)
	 */
	public function test_respeta_el_codigo_de_pais_configurado(): void {
		cead_test_set_option( 'cead_acad_wa_country_code', '54' );

		$this->assertSame( 'https://wa.me/541112345678', cead_acad_wa_link( '011 1234 5678' ) );
	}

	/**
	 * Sin teléfono no hay enlace, y la pantalla usa eso para NO dibujar el botón.
	 * Si devolviera «https://wa.me/» a secas, el botón aparecería igual y quien
	 * lo tocara creería que mandó el mensaje.
	 */
	public function test_sin_telefono_no_hay_enlace(): void {
		$this->assertSame( '', cead_acad_wa_link( '' ) );
		$this->assertSame( '', cead_acad_wa_link( 'no tiene' ) );
	}

	/**
	 * Sin argumento sigue siendo el de CEADI, que es como lo usan el registro y
	 * el chat del panel: agregar el parámetro no podía cambiarles el significado.
	 */
	public function test_sin_argumento_sigue_devolviendo_el_de_ceadi(): void {
		cead_test_set_option( 'cead_acad_wa_bot_number', '595991999888' );

		$this->assertSame( 'https://wa.me/595991999888', cead_acad_wa_link() );
	}

	/** Y sin número de CEADI cargado, tampoco inventa uno. */
	public function test_sin_numero_de_ceadi_no_hay_enlace(): void {
		$this->assertSame( '', cead_acad_wa_link() );
	}

	/**
	 * La capacidad es lo único que abre la sección, y tiene que estar en los
	 * cuatro roles de «delegado para arriba» — ni uno más. Si se le escapara al
	 * alumnado, los teléfonos de los delegados quedarían a la vista de todo el
	 * colegio.
	 */
	public function test_solo_de_delegado_para_arriba(): void {
		$roles = Cead_Acad_Capabilities::roles();

		foreach ( [ 'cead_acad_direction', 'cead_acad_secretary', 'cead_acad_teacher', 'cead_acad_delegate' ] as $slug ) {
			$this->assertArrayHasKey( 'cead_acad_view_delegates', $roles[ $slug ]['caps'], "{$slug} tendría que poder ver el directorio." );
		}

		foreach ( [ 'cead_acad_student', 'cead_acad_guardian', 'cead_acad_student_council' ] as $slug ) {
			$this->assertArrayNotHasKey( 'cead_acad_view_delegates', $roles[ $slug ]['caps'], "{$slug} NO tendría que ver teléfonos ajenos." );
		}
	}
}
