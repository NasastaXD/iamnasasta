<?php
/**
 * Las denuncias no pueden depender de que el modelo acierte.
 *
 * Reconocer un pedido de ayuda es fácil para una IA. El problema no es la
 * dificultad: es que si igual falla, no hay segunda oportunidad. Un alumno que
 * junta coraje para escribir «me están molestando» y recibe una respuesta de
 * preguntas frecuentes no reintenta — cierra el chat y aprende que acá no lo
 * escuchan. Es el peor fallo posible del sistema y el único que no se arregla
 * después.
 *
 * Por eso esto no se resolvió poniéndole un modelo más caro (un modelo mejor
 * falla menos, pero falla) sino sacándolo del modelo: estas palabras abren el
 * trámite guiado siempre, al instante, sin gastar una llamada.
 *
 * El test cuida las dos mitades, y la segunda importa tanto como la primera:
 * que reconozca lo que tiene que reconocer, y que NO se dispare con cualquier
 * cosa — meter a alguien en una denuncia que no pidió también hace daño.
 */

use PHPUnit\Framework\TestCase;

final class PedidoDeAyudaTest extends TestCase {

	/** @dataProvider pedidosDeAyuda */
	public function test_reconoce_un_pedido_de_ayuda( string $mensaje ): void {
		$this->assertTrue(
			Cead_Acad_WA_Engine::pide_ayuda( $mensaje ),
			"«{$mensaje}» tiene que abrir el canal de reportes."
		);
	}

	public static function pedidosDeAyuda(): array {
		return [
			'directo'            => [ 'me están molestando en el colegio' ],
			'sin tildes'         => [ 'me estan molestando' ],
			'en mayúsculas'      => [ 'ME HACEN BULLYING' ],
			'la palabra sola'    => [ 'bullying' ],
			'acoso'              => [ 'sufro acoso de unos compañeros' ],
			'me acosan'          => [ 'me acosan todos los días' ],
			'violencia física'   => [ 'me pegan en el recreo' ],
			'golpes'             => [ 'me golpean cuando salgo' ],
			'amenazas'           => [ 'me amenazan por WhatsApp' ],
			'una amenaza'        => [ 'recibí una amenaza' ],
			'abuso'              => [ 'quiero contar un abuso' ],
			'quiere denunciar'   => [ 'quiero denunciar algo grave' ],
			'hacer una denuncia' => [ 'necesito hacer una denuncia' ],
			'con espacios raros' => [ '   Me Estan Molestando   ' ],
		];
	}

	/**
	 * La otra mitad. Un falso positivo mete a alguien en un trámite guiado que
	 * no pidió, lo trata de víctima y le hace perder el hilo de lo que estaba
	 * consultando. Por eso la lista es corta y sin sinónimos rebuscados.
	 *
	 * @dataProvider mensajesNormales
	 */
	public function test_no_se_dispara_con_cualquier_cosa( string $mensaje ): void {
		$this->assertFalse(
			Cead_Acad_WA_Engine::pide_ayuda( $mensaje ),
			"«{$mensaje}» NO debería abrir una denuncia."
		);
	}

	public static function mensajesNormales(): array {
		return [
			'horario'              => [ '¿qué clases tengo hoy?' ],
			'notas'                => [ 'quiero ver mis notas' ],
			'saludo'               => [ 'hola CEADI' ],
			'queja por el horario' => [ 'me molesta que el horario esté mal cargado' ],
			'queja por la app'     => [ 'me molesta tener que entrar de nuevo' ],
			'tarea'                => [ '¿para cuándo es la tarea de mate?' ],
			'vacío'                => [ '' ],
			'espacios'             => [ '   ' ],
			'pega en otro sentido' => [ '¿dónde pego el comprobante?' ],
		];
	}

	/**
	 * «me molesta» sola NO alcanza, pero «me molestan» (a mí, ellos) sí.
	 * Es la distinción más fina de la lista y la que más falsos positivos
	 * evitaba: media escuela escribe «me molesta que…» por cualquier cosa.
	 */
	public function test_distingue_me_molesta_de_me_molestan(): void {
		$this->assertFalse( Cead_Acad_WA_Engine::pide_ayuda( 'me molesta la nota que me puso' ) );
		$this->assertTrue( Cead_Acad_WA_Engine::pide_ayuda( 'me molestan en clase' ) );
	}
}
