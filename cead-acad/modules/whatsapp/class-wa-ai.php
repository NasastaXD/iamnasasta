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

	/**
	 * Funciones del sistema que la IA PUEDE disparar cuando lo cree necesario.
	 * No es una lista de clasificación obligatoria: la IA responde por su cuenta
	 * por defecto y solo usa una de estas cuando aporta datos reales o un trámite.
	 * clave => descripción (la descripción va en el prompt para que tenga criterio).
	 */
	public static function actions() {
		return [
			'horario'       => 'mostrar el horario de clases personal del alumno (datos reales del sistema)',
			'eventos'       => 'mostrar el calendario de eventos próximos',
			'comunicados'   => 'mostrar los comunicados/avisos recientes del colegio',
			'sitio'         => 'dar los enlaces oficiales del sitio web',
			'contacto'      => 'dar los teléfonos y contactos del colegio',
			'reportar'      => 'iniciar un reporte o denuncia (trámite guiado)',
			'escribir'      => 'escribir un mensaje a Dirección, Consejo o Administración (trámite guiado)',
			'consejo'       => 'abrir el Consejo Estudiantil',
			'recordatorios' => 'activar o desactivar los recordatorios de eventos',
			'panel'         => 'dar el enlace al panel web del alumno',
			'faq'           => 'mostrar el listado completo de preguntas frecuentes',
		];
	}

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
		return ( $t === '' ) ? 0.5 : (float) $t;
	}
	public static function max_tokens() {
		return max( 50, (int) ( get_option( 'cead_acad_wa_ai_maxtokens', 0 ) ?: 800 ) );
	}
	public static function knowledge() {
		return trim( (string) get_option( 'cead_acad_wa_ai_knowledge', '' ) );
	}
	/** Turnos de conversación a recordar (0 = sin memoria). Default 4 para charlas fluidas. */
	public static function memory_turns() {
		return max( 0, min( 20, (int) get_option( 'cead_acad_wa_ai_memory', 4 ) ) );
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
			. "Hablás en español de Paraguay, en tono cercano, claro y amable. Ayudás a los alumnos con todo lo del colegio y podés conversar con naturalidad. "
			. "No inventes datos que no tengas (horarios, fechas, notas, datos personales); si no sabés algo, decilo con honestidad y sugerí escribir a un encargado o ver el panel.";
	}

	/**
	 * Instrucciones para el modo herramientas (tool calling). Livianas a propósito:
	 * el modelo es capaz, así que se le da criterio y libertad, no una camisa de
	 * fuerza. Habla natural en el contenido y llama a una herramienta si hace falta.
	 */
	protected static function tool_instructions() {
		return "Conversá con naturalidad, con tu propio criterio y sin límites artificiales de formato. "
			. "Tenés herramientas para datos reales del sistema y trámites guiados (el horario personal del alumno, eventos, comunicados, contactos, reportar, escribir a un encargado, etc.). "
			. "Usalas SOLO cuando aportan algo que vos no podés dar por tu cuenta (datos personales o actualizados) o cuando inician un trámite; el resto —saludos, dudas, explicaciones, charla— resolvelo vos mismo. "
			. "Algunas herramientas son de gestión del personal (enviar un comunicado, crear un evento): proponelas con los datos completos que tengas; el sistema le mostrará a la persona un resumen para Aceptar, Editar o Cancelar antes de ejecutar, así que no hace falta que vos pidas confirmación. "
			. "Si llamás a una herramienta, podés acompañarla con una frase breve de transición. Nunca inventes horarios, fechas ni datos personales: para eso están las herramientas.";
	}

	/**
	 * Contrato JSON. Solo se usa como FALLBACK si el proveedor no soporta
	 * herramientas (tool calling). Equivalente al modo herramientas pero pidiendo
	 * la decisión en un JSON {reply, action}.
	 */
	protected static function routing_instructions() {
		$lines = [];
		foreach ( self::actions() as $key => $desc ) {
			$lines[] = "- {$key}: {$desc}";
		}
		$actions = implode( "\n", $lines );
		return "Conversá con naturalidad y criterio propio. Respondé con tus palabras en \"reply\".\n"
			. "El sistema tiene funciones con datos reales o trámites guiados; SOLO cuando la persona realmente necesita una, poné su nombre en \"action\". Si no, dejá \"action\" vacío y resolvé vos en \"reply\".\n"
			. "Funciones:\n{$actions}\n"
			. "Usá una función solo si aporta datos que vos NO tenés (horario, comunicados, eventos, contactos) o inicia un trámite (reportar, escribir). Si usás \"action\", poné en \"reply\" una transición corta. Nunca inventes horarios ni datos personales.\n"
			. "Respondé EXCLUSIVAMENTE un JSON válido: {\"reply\":\"...\",\"action\":\"\"}. \"action\" vacío = solo respondés vos. Nada de texto fuera del JSON.";
	}

	protected static function build_system( $faq_context = '', $mode = 'tools' ) {
		$instr = ( $mode === 'json' ) ? self::routing_instructions() : self::tool_instructions();
		$p     = self::persona() . "\n\n" . $instr;
		$kn    = self::knowledge();
		if ( $kn !== '' ) {
			$p .= "\n\n[CONOCIMIENTO DEL COLEGIO]\n" . mb_substr( $kn, 0, 8000 );
		}
		if ( trim( (string) $faq_context ) !== '' ) {
			$p .= "\n\n[FAQ]\n" . mb_substr( (string) $faq_context, 0, 4000 );
		}
		return $p;
	}

	/** Herramientas en formato OpenAI (una por cada función del sistema). */
	protected static function tools_spec() {
		$tools = [];
		foreach ( self::actions() as $key => $desc ) {
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => $key,
					'description' => $desc,
					'parameters'  => [ 'type' => 'object', 'properties' => (object) [] ],
				],
			];
		}
		return $tools;
	}

	/* ---------------- Llamada ---------------- */

	/**
	 * Llama al modelo. Camino principal: tool calling (el modelo habla libre y
	 * decide si invocar una herramienta). Si el proveedor no soporta herramientas
	 * (HTTP 400), cae automáticamente al modo JSON. Devuelve un array de depuración:
	 * [ ok, code, error, intent, reply, content ].
	 */
	public static function call( $message, $faq_context = '', $key = null, $endpoint = null, $model = null, $history = [], $extra_tools = [] ) {
		$key      = $key !== null ? $key : self::key();
		$endpoint = $endpoint !== null && $endpoint !== '' ? $endpoint : self::endpoint();
		$model    = $model !== null && $model !== '' ? $model : self::model();
		$message  = trim( (string) $message );

		$out = [ 'ok' => false, 'code' => 0, 'error' => '', 'intent' => '', 'reply' => '', 'content' => '', 'args' => [] ];
		if ( $key === '' ) { $out['error'] = 'Falta la API key.'; return $out; }
		if ( $message === '' ) { $out['error'] = 'Mensaje vacío.'; return $out; }

		// Herramientas: base (informativas) + las de staff que pase el motor (con permiso).
		$tools   = array_merge( self::tools_spec(), is_array( $extra_tools ) ? $extra_tools : [] );
		$allowed = array_keys( self::actions() );
		foreach ( $tools as $t ) {
			if ( ! empty( $t['function']['name'] ) ) { $allowed[] = (string) $t['function']['name']; }
		}
		$allowed = array_values( array_unique( $allowed ) );

		$messages = function ( $mode ) use ( $faq_context, $history, $message ) {
			$m = [ [ 'role' => 'system', 'content' => self::build_system( $faq_context, $mode ) ] ];
			foreach ( (array) $history as $h ) {
				if ( isset( $h['role'], $h['content'] ) && in_array( $h['role'], [ 'user', 'assistant' ], true ) ) {
					$m[] = [ 'role' => $h['role'], 'content' => (string) $h['content'] ];
				}
			}
			$m[] = [ 'role' => 'user', 'content' => mb_substr( $message, 0, 2000 ) ];
			return $m;
		};

		// 1) Tool calling — el modelo habla con libertad y llama función si quiere.
		$r = self::http( $endpoint, $key, [
			'model'       => $model,
			'temperature' => self::temperature(),
			'max_tokens'  => self::max_tokens(),
			'messages'    => $messages( 'tools' ),
			'tools'       => $tools,
			'tool_choice' => 'auto',
		] );

		// 2) Fallback: si el proveedor rechaza las herramientas (400), modo JSON.
		if ( $r['code'] === 400 ) {
			$rj = self::http( $endpoint, $key, [
				'model'           => $model,
				'temperature'     => self::temperature(),
				'max_tokens'      => self::max_tokens(),
				'messages'        => $messages( 'json' ),
				'response_format' => [ 'type' => 'json_object' ],
			] );
			if ( $rj['code'] === 200 ) {
				return self::parse_json_mode( $rj, $out );
			}
			$r = ( $rj['code'] !== 0 ) ? $rj : $r;
		}

		if ( $r['error'] !== '' ) { $out['error'] = $r['error']; $out['code'] = $r['code']; return $out; }
		$out['code'] = $r['code'];
		if ( $r['code'] !== 200 ) {
			$out['error'] = 'HTTP ' . $r['code'] . ' — ' . mb_substr( wp_strip_all_tags( (string) $r['bodyraw'] ), 0, 300 );
			return $out;
		}
		return self::parse_tools_mode( $r, $out, $allowed );
	}

	/** POST al endpoint compatible OpenAI. Devuelve [ code, error, bodyraw, data ]. */
	protected static function http( $endpoint, $key, array $payload ) {
		$res = wp_remote_post( $endpoint, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $res ) ) {
			error_log( '[CeadAcadWA][AI] ' . $res->get_error_message() );
			return [ 'code' => 0, 'error' => $res->get_error_message(), 'bodyraw' => '', 'data' => null ];
		}
		$bodyraw = (string) wp_remote_retrieve_body( $res );
		return [
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'error'   => '',
			'bodyraw' => $bodyraw,
			'data'    => json_decode( $bodyraw, true ),
		];
	}

	/** Lee la respuesta del modo herramientas: contenido libre + tool_call opcional. */
	protected static function parse_tools_mode( $r, $out, $allowed = null ) {
		$allowed = is_array( $allowed ) ? $allowed : array_keys( self::actions() );
		$msg     = $r['data']['choices'][0]['message'] ?? [];
		$content = (string) ( $msg['content'] ?? '' );
		$reply   = sanitize_textarea_field( $content );

		$action = '';
		$args   = [];
		if ( ! empty( $msg['tool_calls'][0]['function']['name'] ) ) {
			$fn     = $msg['tool_calls'][0]['function'];
			$action = sanitize_key( (string) $fn['name'] );
			if ( isset( $fn['arguments'] ) ) {
				$args = is_array( $fn['arguments'] ) ? $fn['arguments'] : (array) json_decode( (string) $fn['arguments'], true );
			}
		}
		if ( $action !== '' && ! in_array( $action, $allowed, true ) ) {
			$action = '';
			$args   = [];
		}

		if ( $reply === '' && $action === '' ) { $out['error'] = 'Respuesta vacía del modelo.'; return $out; }
		$out['ok']      = true;
		$out['intent']  = $action;
		$out['args']    = $args;
		$out['reply']   = $reply;
		$out['content'] = ( $content !== '' ) ? $content : $reply;
		return $out;
	}

	/** Lee la respuesta del modo JSON (fallback): {reply, action} dentro del contenido. */
	protected static function parse_json_mode( $r, $out, $allowed = null ) {
		$out['code'] = $r['code'];
		$content     = (string) ( $r['data']['choices'][0]['message']['content'] ?? '' );
		$out['content'] = $content;
		if ( $content === '' ) { $out['error'] = 'Respuesta vacía del modelo.'; return $out; }

		$parsed = json_decode( $content, true );
		if ( ! is_array( $parsed ) ) { $out['error'] = 'El modelo no devolvió JSON válido.'; return $out; }

		$reply = isset( $parsed['reply'] ) ? sanitize_textarea_field( $parsed['reply'] ) : '';
		$action = '';
		if ( isset( $parsed['action'] ) ) {
			$action = sanitize_key( $parsed['action'] );
		} elseif ( isset( $parsed['intent'] ) ) {
			$action = sanitize_key( $parsed['intent'] );
		}
		if ( in_array( $action, [ 'chat', 'menu', 'none', 'null', 'ninguna', 'ninguno', '' ], true ) ) {
			$action = '';
		}
		if ( $action !== '' && ! array_key_exists( $action, self::actions() ) ) {
			$action = '';
		}
		if ( $reply === '' && $action === '' ) { $out['error'] = 'Respuesta vacía del modelo.'; return $out; }

		$out['ok']     = true;
		$out['intent'] = $action;
		$out['reply']  = $reply;
		return $out;
	}

	/** Decisión para el motor. Devuelve [intent, reply, args] o null. Usa memoria si está activa y hay $phone. */
	public static function route( $message, $faq_context = '', $phone = '', $extra_tools = [] ) {
		$history = ( $phone !== '' ) ? self::load_memory( $phone ) : [];
		$r = self::call( $message, $faq_context, null, null, null, $history, $extra_tools );
		if ( ! $r['ok'] ) {
			return null;
		}
		if ( $phone !== '' && self::memory_turns() > 0 ) {
			self::save_memory( $phone, $message, $r['content'] );
		}
		return [ 'intent' => $r['intent'], 'reply' => $r['reply'], 'args' => $r['args'] ?? [] ];
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
		$m[] = [ 'role' => 'user',      'content' => mb_substr( (string) $user_message, 0, 1500 ) ];
		$m[] = [ 'role' => 'assistant', 'content' => mb_substr( (string) $assistant_content, 0, 1500 ) ];
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
			$accion = $r['intent'] !== '' ? sprintf( 'función: "%s"', $r['intent'] ) : 'respuesta directa (sin función)';
			$resp   = $r['reply'] !== '' ? ' · dice: ' . mb_substr( $r['reply'], 0, 160 ) : '';
			return [ 'ok' => true, 'summary' => 'OK · ' . $accion . $resp ];
		}
		return [ 'ok' => false, 'summary' => $r['error'] ?: 'Error desconocido.' ];
	}
}
