<?php
/**
 * Capa de IA configurable para CEADI. Interpreta lenguaje natural y decide qué
 * función del menú disparar, o responde dudas con el conocimiento/FAQ.
 *
 * Proveedor LIBRE: cualquier API compatible con OpenAI (DeepSeek, OpenRouter,
 * tokenlb, OpenAI, etc.). Se configura endpoint + modelo + API key. Todo
 * editable desde CEAD Académico → WhatsApp:
 *   - Endpoint (ej. https://tokenlb.net/v1/chat/completions)
 *   - Modelo, API key, temperatura, máx. tokens
 *   - System prompt (la "personalidad"/instrucciones)
 *   - Conocimiento (base de datos de texto que la IA usa para responder)
 *
 * 100% opcional: sin key o desactivada, el bot usa su menú numérico de siempre.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_AI {

	const ENDPOINT_DEFAULT = 'https://api.deepseek.com/chat/completions';
	const MODEL_DEFAULT    = 'deepseek-chat';

	/** Intenciones válidas (mapean a handlers del motor). */
	const INTENTS = [ 'horario', 'eventos', 'comunicados', 'sitio', 'contacto', 'escribir', 'faq', 'consejo', 'recordatorios', 'panel', 'chat', 'menu' ];

	/* ---------------- Config ---------------- */

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
	public static function temperature() {
		$t = get_option( 'cead_acad_wa_ai_temp', '' );
		return ( $t === '' ) ? 0.2 : (float) $t;
	}
	public static function max_tokens() {
		return max( 50, (int) ( get_option( 'cead_acad_wa_ai_maxtokens', 0 ) ?: 500 ) );
	}
	public static function knowledge() {
		return trim( (string) get_option( 'cead_acad_wa_ai_knowledge', '' ) );
	}
	/** Turnos de conversación a recordar (0 = sin memoria). */
	public static function memory_turns() {
		return max( 0, min( 20, (int) get_option( 'cead_acad_wa_ai_memory', 0 ) ) );
	}
	/** Minutos que dura la memoria de una charla. */
	public static function memory_ttl() {
		return 30 * MINUTE_IN_SECONDS;
	}

	/* ---------------- Prompts ---------------- */

	/** Persona/instrucciones editables (default si está vacío). */
	public static function persona() {
		$p = trim( (string) get_option( 'cead_acad_wa_ai_prompt', '' ) );
		return $p !== '' ? $p : self::default_persona();
	}

	public static function default_persona() {
		return "Sos CEADI, el asistente de WhatsApp del CEAD «Félix de Guarania», un colegio secundario de alto desempeño de Caaguazú, Paraguay. "
			. "Hablás en español de Paraguay, en tono breve, claro y amable. Ayudás a los alumnos con todo lo del colegio. "
			. "No inventes datos que no tengas; si no sabés algo, sugerí escribir a un encargado o ver el panel.";
	}

	/** Contrato de salida (SIEMPRE se agrega: garantiza el ruteo en JSON). */
	protected static function routing_instructions() {
		$intents = implode( ', ', self::INTENTS );
		return "Tu tarea operativa: leé el mensaje del alumno y elegí UNA intención de esta lista: {$intents}.\n"
			. "Significados: horario=su horario de clases; eventos=calendario de eventos; comunicados=avisos del colegio; "
			. "sitio=enlaces/sitio web; contacto=teléfonos y contactos del colegio; "
			. "escribir=mandar un mensaje a Dirección, Consejo o Administración; faq=preguntas frecuentes; consejo=Consejo Estudiantil; "
			. "recordatorios=activar o desactivar recordatorios de eventos; panel=su panel web; menu=mostrar el menú; "
			. "chat=responder vos mismo una duda general usando el CONOCIMIENTO/FAQ de abajo.\n"
			. "Si la duda se puede contestar con el conocimiento o las FAQ, usá intent \"chat\" y poné la respuesta en \"reply\" (máx 3 frases). "
			. "Si pide una función concreta, devolvé esa intención con \"reply\" vacío. Si no entendés, usá intent \"menu\".\n"
			. "Respondé EXCLUSIVAMENTE un JSON válido: {\"intent\":\"...\",\"reply\":\"...\"}. Nada de texto fuera del JSON.";
	}

	protected static function build_system( $faq_context = '' ) {
		$p = self::persona() . "\n\n" . self::routing_instructions();
		$kn = self::knowledge();
		if ( $kn !== '' ) {
			$p .= "\n\n[CONOCIMIENTO DEL COLEGIO]\n" . mb_substr( $kn, 0, 5000 );
		}
		if ( trim( (string) $faq_context ) !== '' ) {
			$p .= "\n\n[FAQ]\n" . mb_substr( (string) $faq_context, 0, 2500 );
		}
		return $p;
	}

	/* ---------------- Llamada ---------------- */

	/**
	 * Llamada cruda. Devuelve un array de depuración:
	 * [ ok, code, error, intent, reply, content ].
	 */
	public static function call( $message, $faq_context = '', $key = null, $endpoint = null, $model = null, $history = [] ) {
		$key      = $key !== null ? $key : self::key();
		$endpoint = $endpoint !== null && $endpoint !== '' ? $endpoint : self::endpoint();
		$model    = $model !== null && $model !== '' ? $model : self::model();
		$message  = trim( (string) $message );

		$out = [ 'ok' => false, 'code' => 0, 'error' => '', 'intent' => '', 'reply' => '', 'content' => '' ];
		if ( $key === '' ) { $out['error'] = 'Falta la API key.'; return $out; }
		if ( $message === '' ) { $out['error'] = 'Mensaje vacío.'; return $out; }

		$messages = [ [ 'role' => 'system', 'content' => self::build_system( $faq_context ) ] ];
		foreach ( (array) $history as $h ) {
			if ( isset( $h['role'], $h['content'] ) && in_array( $h['role'], [ 'user', 'assistant' ], true ) ) {
				$messages[] = [ 'role' => $h['role'], 'content' => (string) $h['content'] ];
			}
		}
		$messages[] = [ 'role' => 'user', 'content' => mb_substr( $message, 0, 1200 ) ];

		$payload = [
			'model'           => $model,
			'temperature'     => self::temperature(),
			'max_tokens'      => self::max_tokens(),
			'response_format' => [ 'type' => 'json_object' ],
			'messages'        => $messages,
		];

		$res = wp_remote_post( $endpoint, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $res ) ) {
			$out['error'] = $res->get_error_message();
			error_log( '[CeadAcadWA][AI] ' . $out['error'] );
			return $out;
		}
		$out['code'] = (int) wp_remote_retrieve_response_code( $res );
		$bodyraw     = wp_remote_retrieve_body( $res );
		if ( $out['code'] !== 200 ) {
			$out['error'] = 'HTTP ' . $out['code'] . ' — ' . mb_substr( wp_strip_all_tags( (string) $bodyraw ), 0, 300 );
			return $out;
		}

		$data    = json_decode( $bodyraw, true );
		$content = $data['choices'][0]['message']['content'] ?? '';
		$out['content'] = (string) $content;
		if ( ! $content ) { $out['error'] = 'Respuesta vacía del modelo.'; return $out; }

		$parsed = json_decode( (string) $content, true );
		if ( ! is_array( $parsed ) ) { $out['error'] = 'El modelo no devolvió JSON válido.'; return $out; }

		$intent = isset( $parsed['intent'] ) ? sanitize_key( $parsed['intent'] ) : '';
		$reply  = isset( $parsed['reply'] ) ? sanitize_textarea_field( $parsed['reply'] ) : '';
		if ( ! in_array( $intent, self::INTENTS, true ) ) { $out['error'] = 'Intención no reconocida: ' . $intent; return $out; }

		$out['ok']     = true;
		$out['intent'] = $intent;
		$out['reply']  = $reply;
		return $out;
	}

	/** Clasificación para el motor. Devuelve [intent, reply] o null. Usa memoria si está activa y hay $phone. */
	public static function route( $message, $faq_context = '', $phone = '' ) {
		$history = ( $phone !== '' ) ? self::load_memory( $phone ) : [];
		$r = self::call( $message, $faq_context, null, null, null, $history );
		if ( ! $r['ok'] ) {
			return null;
		}
		if ( $phone !== '' && self::memory_turns() > 0 ) {
			self::save_memory( $phone, $message, $r['content'] );
		}
		return [ 'intent' => $r['intent'], 'reply' => $r['reply'] ];
	}

	/* ---------------- Memoria conversacional (por número, con expiración) ---------------- */

	protected static function memory_key( $phone ) {
		return 'cead_acad_wa_aimem_' . md5( (string) $phone );
	}

	protected static function load_memory( $phone ) {
		if ( self::memory_turns() <= 0 ) { return []; }
		$m = get_transient( self::memory_key( $phone ) );
		return is_array( $m ) ? $m : [];
	}

	protected static function save_memory( $phone, $user_message, $assistant_content ) {
		$turns = self::memory_turns();
		if ( $turns <= 0 ) { return; }
		$m   = self::load_memory( $phone );
		$m[] = [ 'role' => 'user',      'content' => mb_substr( (string) $user_message, 0, 1000 ) ];
		$m[] = [ 'role' => 'assistant', 'content' => mb_substr( (string) $assistant_content, 0, 1000 ) ];
		$max = $turns * 2;
		if ( count( $m ) > $max ) {
			$m = array_slice( $m, -$max );
		}
		set_transient( self::memory_key( $phone ), $m, self::memory_ttl() );
	}

	/** Borra la memoria de un número. */
	public static function clear_memory( $phone ) {
		delete_transient( self::memory_key( $phone ) );
	}

	/** Prueba desde el admin: resumen legible. */
	public static function test( $message = '¿qué clases tengo hoy?' ) {
		$r = self::call( $message );
		if ( $r['ok'] ) {
			return [ 'ok' => true, 'summary' => sprintf( 'OK · intención: "%s"%s', $r['intent'], $r['reply'] !== '' ? ' · respuesta: ' . mb_substr( $r['reply'], 0, 160 ) : '' ) ];
		}
		return [ 'ok' => false, 'summary' => $r['error'] ?: 'Error desconocido.' ];
	}
}
