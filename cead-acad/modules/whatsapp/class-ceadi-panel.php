<?php
/**
 * CEADI dentro del panel web.
 *
 * Es el mismo cerebro que atiende por WhatsApp, pero con una diferencia
 * deliberada: acá SOLO puede consultar y responder. Las herramientas de
 * gestión —mandar comunicados, publicar artículos, crear eventos— no se le
 * pasan.
 *
 * No es una limitación por miedo: es que el flujo de aprobación (el resumen
 * con Aceptar / Editar / Cancelar antes de escribir nada) vive en el motor de
 * WhatsApp. Ofrecer acciones acá sin ese paso sería darle a la IA una vía para
 * escribir en el sistema sin que nadie confirme, que es exactamente lo que el
 * diseño evita en el otro canal. Cuando haga falta ejecutar algo, se hace por
 * WhatsApp, donde el trámite está completo.
 *
 * Lo que sí se respeta es el rol: las consultas que ve el modelo salen de
 * `Cead_Acad_WA_Tools::specs()`, que filtra por permisos reales. Un alumno
 * pregunta y le responde con lo suyo; dirección pregunta y llega hasta las
 * métricas del colegio. La misma pregunta, distinto alcance.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Ceadi_Panel {

	const NS    = 'cead-acad/v1';
	const RUTA  = '/ceadi';
	/** Tope de mensajes por usuario y por minuto. Cada uno cuesta una llamada paga. */
	const TOPE_MINUTO = 8;

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register() {
		register_rest_route( self::NS, self::RUTA, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'responder' ],
			'permission_callback' => [ $this, 'puede' ],
			'args'                => [
				'mensaje' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				],
			],
		] );
	}

	/** Solo gente logueada con acceso al panel. El nonce lo valida la REST API. */
	public function puede() {
		return is_user_logged_in() && current_user_can( 'cead_acad_view_panel' );
	}

	/** ¿Está disponible el chat? Lo usa la plantilla para no dibujar una barra muerta. */
	public static function disponible() {
		return class_exists( 'Cead_Acad_WA_AI' ) && Cead_Acad_WA_AI::enabled();
	}

	public function responder( $req ) {
		if ( ! self::disponible() ) {
			return new WP_Error( 'cead_acad_ia_off', __( 'CEADI no está disponible en este momento.', 'cead-acad' ), [ 'status' => 503 ] );
		}

		$uid     = get_current_user_id();
		$mensaje = trim( (string) $req->get_param( 'mensaje' ) );
		if ( '' === $mensaje ) {
			return new WP_Error( 'cead_acad_vacio', __( 'Escribí una pregunta.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		$mensaje = mb_substr( $mensaje, 0, 1000 );

		if ( ! $this->dentro_del_tope( $uid ) ) {
			return new WP_Error(
				'cead_acad_muchos',
				__( 'Esperá unos segundos antes de seguir preguntando.', 'cead-acad' ),
				[ 'status' => 429 ]
			);
		}

		$respuesta = Cead_Acad_WA_AI::route(
			$mensaje,
			$this->faq(),
			// Clave de memoria propia del panel: la conversación de la web no se
			// mezcla con la de WhatsApp aunque sea la misma persona.
			'panel:' . $uid,
			class_exists( 'Cead_Acad_WA_Tools' ) ? Cead_Acad_WA_Tools::specs( $uid ) : [],
			$this->contexto( $uid ),
			null,
			$uid
		);

		if ( ! is_array( $respuesta ) ) {
			return new WP_Error(
				'cead_acad_sin_respuesta',
				__( 'No pude responder ahora mismo. Probá de nuevo en un momento.', 'cead-acad' ),
				[ 'status' => 502 ]
			);
		}

		$texto = trim( (string) ( $respuesta['reply'] ?? '' ) );

		/*
		 * Si el modelo intentó una acción de GESTIÓN, acá no se ejecuta: no hay
		 * paso de aprobación en la web. Se le dice a la persona dónde sí puede
		 * hacerlo, en vez de fallar en silencio o —peor— hacerlo sin confirmar.
		 *
		 * Se pregunta por gestión y no «¿no es consulta?». Las consultas nunca
		 * llegan hasta acá —`parse_tools_mode()` ya las vació, porque las
		 * resuelve el bucle—, así que preguntar por ellas era preguntar por algo
		 * imposible: cualquier intención caía en el mensaje de WhatsApp. Y las
		 * intenciones informativas del menú (horario, notas, comunicados, faq…)
		 * siguen viniendo del catálogo base: mandar a alguien a WhatsApp «para
		 * confirmar antes de ejecutarlo» porque preguntó su horario no tiene
		 * ningún sentido. Esas caen abajo, en el pedido de reformular.
		 */
		$intent = (string) ( $respuesta['intent'] ?? '' );
		if ( '' !== $intent && class_exists( 'Cead_Acad_WA_Tools' ) && Cead_Acad_WA_Tools::es_gestion( $intent ) ) {
			$texto = trim( $texto . "\n\n" . __( 'Eso se confirma por WhatsApp: escribime por ahí y te muestro el resumen antes de ejecutarlo.', 'cead-acad' ) );
		}

		if ( '' === $texto ) {
			$texto = __( 'No te entendí bien. ¿Me lo decís de otra forma?', 'cead-acad' );
		}

		return rest_ensure_response( [ 'respuesta' => $texto ] );
	}

	/**
	 * Ventana simple por usuario: cuenta mensajes del último minuto.
	 *
	 * La clave lleva el minuto adentro en vez de apoyarse en el vencimiento del
	 * transient. Con `set_transient(..., MINUTE_IN_SECONDS)` en cada mensaje, la
	 * cuenta se refrescaba el reloj a sí misma y la ventana no cerraba nunca:
	 * quien preguntaba una cosa cada cuarenta segundos quedaba bloqueado al
	 * noveno mensaje, cinco minutos después de haber empezado, con un cartel que
	 * le decía «esperá unos segundos». Con el minuto en la clave, cada minuto
	 * calendario arranca de cero, que es lo que el tope dice hacer.
	 */
	protected function dentro_del_tope( $uid ) {
		$clave = 'cead_acad_ceadi_panel_' . (int) $uid . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::TOPE_MINUTO ) { return false; }
		set_transient( $clave, $n + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/** Las mismas preguntas frecuentes que ve por WhatsApp, en el mismo formato. */
	protected function faq() {
		if ( ! class_exists( 'Cead_Acad_FAQ' ) ) { return ''; }
		$out = [];
		foreach ( array_slice( Cead_Acad_FAQ::all(), 0, 20 ) as $f ) {
			$out[] = '- ' . get_the_title( $f ) . ': ' . wp_trim_words( wp_strip_all_tags( $f->post_content ), 60 );
		}
		return implode( "\n", $out );
	}

	/**
	 * Quién es quien pregunta. Sin esto el modelo contesta a ciegas y no puede
	 * decir «tu curso» ni saber qué día es hoy.
	 */
	protected function contexto( $uid ) {
		$u = get_userdata( $uid );
		if ( ! $u ) { return ''; }

		$lineas = [ 'Nombre: ' . $u->display_name ];

		if ( class_exists( 'Cead_Acad_Capabilities' ) ) {
			$roles = Cead_Acad_Capabilities::roles();
			foreach ( (array) $u->roles as $r ) {
				if ( isset( $roles[ $r ] ) ) { $lineas[] = 'Rol: ' . $roles[ $r ]['display']; break; }
			}
		}

		$curso_id = (int) get_user_meta( $uid, '_cead_acad_current_course_id', true );
		if ( $curso_id ) { $lineas[] = 'Curso: ' . get_the_title( $curso_id ); }

		/*
		 * Con la HORA, igual que por WhatsApp. Sin ella, desde el panel CEADI
		 * sabía el día pero no la hora, así que no podía razonar nada del tipo
		 * «tiene clase hasta las 14:50, te da el tiempo si salís ahora» — y esa
		 * es justo la clase de pregunta que se le hace. Las dos superficies
		 * tienen que saber lo mismo o la misma pregunta se contesta distinto
		 * según por dónde entró.
		 */
		$lineas[] = 'Hoy es ' . date_i18n( 'l j \d\e F \d\e Y, H:i', current_time( 'timestamp' ) ) . '.';
		$lineas[] = 'Te está escribiendo desde el panel web, no por WhatsApp.';

		return implode( "\n", $lineas );
	}
}
