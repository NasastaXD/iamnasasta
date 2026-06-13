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
			'notas'         => 'mostrar las notas/boletín del alumno (calificaciones reales por materia y periodo)',
			'tareas'        => 'mostrar las tareas pendientes del alumno y sus fechas de entrega (datos reales del curso)',
			'eventos'       => 'mostrar el calendario de eventos próximos',
			'comunicados'   => 'mostrar los comunicados/avisos recientes del colegio',
			'sitio'         => 'dar los enlaces oficiales del sitio web',
			'contacto'      => 'dar los teléfonos y contactos del colegio',
			'reportar'      => 'iniciar un reporte o denuncia (trámite guiado)',
			'escribir'      => 'escribir un mensaje a Dirección, Consejo o Administración (trámite guiado)',
			'constancia'    => 'iniciar una solicitud de constancia de alumno regular (trámite guiado)',
			'justificativo' => 'justificar una inasistencia, con foto opcional del certificado (trámite guiado)',
			'consejo'       => 'abrir el Consejo Estudiantil',
			'recordatorios' => 'activar o desactivar los recordatorios de eventos',
			'panel'         => 'dar el enlace al panel web del alumno',
			'carne'         => 'dar el enlace al carné digital del alumno',
			'faq'           => 'mostrar el listado completo de preguntas frecuentes',
			'ajustes'       => 'abrir los ajustes del usuario (ver sus datos, cambiar su nombre, pedir cambio de número, activar/desactivar el modo IA o los recordatorios)',
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

	/* ---------------- Transcripción de voz (speech-to-text) ---------------- */

	/** ¿La transcripción de notas de voz está activa y configurada? */
	public static function stt_enabled() {
		return (bool) get_option( 'cead_acad_wa_stt_enabled', 0 ) && self::stt_key() !== '';
	}
	/** Key del STT: constante, opción propia, o reusa la key de la IA. */
	public static function stt_key() {
		if ( defined( 'CEAD_ACAD_STT_KEY' ) && CEAD_ACAD_STT_KEY ) {
			return (string) CEAD_ACAD_STT_KEY;
		}
		$k = (string) get_option( 'cead_acad_wa_stt_key', '' );
		return $k !== '' ? $k : self::key();
	}
	public static function stt_endpoint() {
		return (string) ( get_option( 'cead_acad_wa_stt_endpoint', '' ) ?: 'https://api.openai.com/v1/audio/transcriptions' );
	}
	public static function stt_model() {
		return (string) ( get_option( 'cead_acad_wa_stt_model', '' ) ?: 'whisper-1' );
	}

	/**
	 * Transcribe un audio (base64) con un endpoint compatible OpenAI
	 * (/audio/transcriptions, multipart/form-data). Devuelve el texto o '' si la
	 * transcripción está apagada o falla. Pensado para notas de voz de WhatsApp
	 * (ogg/opus), pero acepta otros formatos comunes.
	 */
	public static function transcribe( $data_base64, $mime = 'audio/ogg', $lang = 'es' ) {
		if ( ! self::stt_enabled() ) { return ''; }
		$bytes = base64_decode( (string) $data_base64, true );
		if ( $bytes === false || $bytes === '' ) { return ''; }

		$boundary = wp_generate_password( 24, false );
		$ext      = self::audio_ext( $mime );
		$eol      = "\r\n";

		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="model"' . $eol . $eol . self::stt_model() . $eol;
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="language"' . $eol . $eol . $lang . $eol;
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="audio.' . $ext . '"' . $eol;
		$body .= 'Content-Type: ' . $mime . $eol . $eol;
		$body .= $bytes . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$res = wp_remote_post( self::stt_endpoint(), [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . self::stt_key(),
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
		] );
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			error_log( '[CeadAcadWA][STT] ' . $msg );
			self::store_stt_error( 'Error de red: ' . $msg );
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code !== 200 || ! is_array( $data ) ) {
			$err = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : mb_substr( wp_strip_all_tags( $raw ), 0, 200 );
			error_log( '[CeadAcadWA][STT] HTTP ' . $code . ' — ' . $err );
			self::store_stt_error( 'HTTP ' . $code . ' — ' . $err );
			return '';
		}
		delete_transient( 'cead_acad_wa_stt_last_error' );
		return trim( (string) ( $data['text'] ?? '' ) );
	}

	/** Almacena el último error de STT para mostrarlo en el panel admin. */
	protected static function store_stt_error( $msg ) {
		set_transient( 'cead_acad_wa_stt_last_error', [
			'error' => (string) $msg,
			'time'  => current_time( 'mysql' ),
		], DAY_IN_SECONDS );
	}

	/** Último error de STT registrado, o null. */
	public static function stt_last_error() {
		$e = get_transient( 'cead_acad_wa_stt_last_error' );
		return is_array( $e ) && ! empty( $e['error'] ) ? $e : null;
	}

	/**
	 * Prueba de conectividad y credenciales para la transcripción de voz.
	 * Envía un audio mínimo (WAV de silencio, 44 bytes) al endpoint configurado.
	 * 200 / «credenciales OK» (400/422) = configuración correcta.
	 * 401/403 = API key inválida. WP_Error = problema de red.
	 */
	public static function stt_test() {
		$key = self::stt_key();
		if ( $key === '' ) {
			return [ 'ok' => false, 'summary' => 'Falta la API key de transcripción (configurala abajo o usará la de la IA).' ];
		}
		// WAV mínimo válido: cabecera PCM 44100 Hz mono 16bit, 0 muestras (44 bytes).
		$wav_b64 = 'UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAAACABAAAABkYXRhAAAA';
		$boundary = 'cead_stt_test_boundary';
		$eol      = "\r\n";
		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="model"' . $eol . $eol . self::stt_model() . $eol;
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="test.wav"' . $eol;
		$body .= 'Content-Type: audio/wav' . $eol . $eol;
		$body .= base64_decode( $wav_b64 ) . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$res = wp_remote_post( self::stt_endpoint(), [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body' => $body,
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'summary' => 'Error de red: ' . $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		$err  = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : mb_substr( wp_strip_all_tags( $raw ), 0, 200 );

		if ( $code === 401 || $code === 403 ) {
			return [ 'ok' => false, 'summary' => 'Credenciales inválidas (HTTP ' . $code . '): ' . $err ];
		}
		if ( $code === 200 ) {
			delete_transient( 'cead_acad_wa_stt_last_error' );
			return [ 'ok' => true, 'summary' => 'STT OK — respuesta: «' . mb_substr( (string) ( $data['text'] ?? '(silencio)' ), 0, 80 ) . '»' ];
		}
		// 400/422 = audio inválido (esperado con 0 muestras) pero autenticación OK.
		if ( $code === 400 || $code === 422 ) {
			delete_transient( 'cead_acad_wa_stt_last_error' );
			return [ 'ok' => true, 'summary' => 'Credenciales OK — el endpoint respondió HTTP ' . $code . ' al audio de prueba (esperado): ' . mb_substr( $err, 0, 120 ) ];
		}
		return [ 'ok' => false, 'summary' => 'HTTP ' . $code . ' — ' . mb_substr( $err, 0, 200 ) ];
	}

	protected static function audio_ext( $mime ) {
		$mime = strtolower( (string) $mime );
		if ( strpos( $mime, 'mpeg' ) !== false || strpos( $mime, 'mp3' ) !== false ) { return 'mp3'; }
		if ( strpos( $mime, 'mp4' ) !== false || strpos( $mime, 'm4a' ) !== false ) { return 'm4a'; }
		if ( strpos( $mime, 'wav' ) !== false )  { return 'wav'; }
		if ( strpos( $mime, 'webm' ) !== false ) { return 'webm'; }
		return 'ogg'; // nota de voz de WhatsApp = ogg/opus
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
			// Fallo TÉCNICO (no «no entendí»): registrarlo para diagnóstico. El
			// motor lo usa para caer al menú, y el admin lo muestra en CEADI · IA.
			self::$last_error = (string) ( $r['error'] ?: 'Error desconocido.' );
			set_transient( 'cead_acad_wa_ai_last_error', [
				'error' => self::$last_error,
				'code'  => (int) ( $r['code'] ?? 0 ),
				'time'  => current_time( 'mysql' ),
			], DAY_IN_SECONDS );
			error_log( '[CeadAcadWA][AI] fallo: ' . self::$last_error );
			return null;
		}
		self::$last_error = '';
		delete_transient( 'cead_acad_wa_ai_last_error' );
		if ( $phone !== '' && self::memory_turns() > 0 ) {
			self::save_memory( $phone, $message, $r['content'] );
		}
		return [ 'intent' => $r['intent'], 'reply' => $r['reply'], 'args' => $r['args'] ?? [] ];
	}

	/** Error técnico de la última llamada del request actual ('' si salió bien). */
	protected static $last_error = '';
	public static function last_error() {
		return self::$last_error;
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
			delete_transient( 'cead_acad_wa_ai_last_error' );
			$accion = $r['intent'] !== '' ? sprintf( 'función: "%s"', $r['intent'] ) : 'respuesta directa (sin función)';
			$resp   = $r['reply'] !== '' ? ' · dice: ' . mb_substr( $r['reply'], 0, 160 ) : '';
			return [ 'ok' => true, 'summary' => 'OK · ' . $accion . $resp ];
		}
		set_transient( 'cead_acad_wa_ai_last_error', [
			'error' => (string) ( $r['error'] ?: 'Error desconocido.' ),
			'code'  => (int) ( $r['code'] ?? 0 ),
			'time'  => current_time( 'mysql' ),
		], DAY_IN_SECONDS );
		return [ 'ok' => false, 'summary' => $r['error'] ?: 'Error desconocido.' ];
	}
}
