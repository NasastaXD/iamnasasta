<?php
/**
 * Capa de IA opcional para CEADI. Interpreta el lenguaje natural del alumno y
 * decide qué función del menú disparar, o responde dudas generales con el
 * contexto de las FAQ. Usa una API compatible con OpenAI (por defecto DeepSeek).
 *
 * Es 100% opcional: si no hay API key o está desactivada, el bot funciona con su
 * menú numérico de siempre.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_AI {

	const ENDPOINT_DEFAULT = 'https://api.deepseek.com/chat/completions';
	const MODEL_DEFAULT    = 'deepseek-chat';

	/** Intenciones válidas que la IA puede devolver (mapean a handlers del motor). */
	const INTENTS = [ 'horario', 'eventos', 'comunicados', 'sitio', 'contacto', 'reportar', 'escribir', 'faq', 'consejo', 'recordatorios', 'panel', 'chat', 'menu' ];

	public static function enabled() {
		return (bool) get_option( 'cead_acad_wa_ai_enabled', 0 ) && self::key() !== '';
	}

	public static function key() {
		if ( defined( 'CEAD_ACAD_AI_KEY' ) && CEAD_ACAD_AI_KEY ) {
			return (string) CEAD_ACAD_AI_KEY;
		}
		return (string) get_option( 'cead_acad_wa_ai_key', '' );
	}

	public static function model() {
		return (string) ( get_option( 'cead_acad_wa_ai_model', '' ) ?: self::MODEL_DEFAULT );
	}

	public static function endpoint() {
		return (string) ( get_option( 'cead_acad_wa_ai_endpoint', '' ) ?: self::ENDPOINT_DEFAULT );
	}

	/**
	 * Clasifica el mensaje. Devuelve [ 'intent' => string, 'reply' => string ] o null.
	 */
	public static function route( $message, $faq_context = '' ) {
		$key = self::key();
		$message = trim( (string) $message );
		if ( $key === '' || $message === '' ) {
			return null;
		}

		$payload = [
			'model'           => self::model(),
			'temperature'     => 0.2,
			'max_tokens'      => 400,
			'response_format' => [ 'type' => 'json_object' ],
			'messages'        => [
				[ 'role' => 'system', 'content' => self::system_prompt( $faq_context ) ],
				[ 'role' => 'user',   'content' => mb_substr( $message, 0, 1000 ) ],
			],
		];

		$res = wp_remote_post( self::endpoint(), [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $res ) ) {
			error_log( '[CeadAcadWA][AI] ' . $res->get_error_message() );
			return null;
		}
		if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			error_log( '[CeadAcadWA][AI] HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $res ), true );
		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( ! $content ) {
			return null;
		}
		$parsed = json_decode( (string) $content, true );
		if ( ! is_array( $parsed ) ) {
			return null;
		}

		$intent = isset( $parsed['intent'] ) ? sanitize_key( $parsed['intent'] ) : '';
		$reply  = isset( $parsed['reply'] ) ? sanitize_textarea_field( $parsed['reply'] ) : '';
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			return null;
		}
		return [ 'intent' => $intent, 'reply' => $reply ];
	}

	protected static function system_prompt( $faq_context ) {
		$intents = implode( ', ', self::INTENTS );
		$p  = "Sos CEADI, el asistente de WhatsApp del CEAD «Félix de Guarania», un colegio secundario de alto desempeño de Caaguazú, Paraguay. ";
		$p .= "Hablás en español de Paraguay, en tono breve, claro y amable. ";
		$p .= "Tarea: leé el mensaje del alumno y elegí UNA intención de esta lista: {$intents}. ";
		$p .= "Significados: horario=su horario de clases; eventos=calendario de eventos; comunicados=avisos del colegio; sitio=enlaces/sitio web; contacto=teléfonos y contactos del colegio; reportar=hacer un reporte/denuncia; escribir=mandar un mensaje a Dirección, Consejo o Administración; faq=preguntas frecuentes; consejo=Consejo Estudiantil; recordatorios=activar o desactivar recordatorios de eventos; panel=su panel web; menu=mostrar el menú; chat=responder vos mismo una duda general. ";
		$p .= "Si la pregunta se puede contestar con las FAQ de abajo o con conocimiento general del colegio, usá intent \"chat\" y escribí la respuesta en \"reply\" (máximo 3 frases, sin inventar datos que no tengas). ";
		$p .= "Si pide una función concreta, devolvé esa intención con \"reply\" vacío. Si no entendés, usá intent \"menu\". ";
		$p .= "Respondé EXCLUSIVAMENTE un JSON válido con esta forma: {\"intent\":\"...\",\"reply\":\"...\"}. Nada de texto fuera del JSON.";
		if ( trim( (string) $faq_context ) !== '' ) {
			$p .= "\n\nFAQ del colegio (para respuestas tipo \"chat\"):\n" . mb_substr( (string) $faq_context, 0, 2500 );
		}
		return $p;
	}
}
