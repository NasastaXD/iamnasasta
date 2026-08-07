<?php
/**
 * Motor de estados del bot. Personalización mixta: usa datos reales de cead-acad
 * (eventos, comunicados, cursos) y, si el número coincide con un usuario
 * (meta _cead_acad_phone), responde personalizado; si no, modo general.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Engine {

	private $store;
	private $bridge;
	private $broadcaster;

	/** Buffer de mensajes del turno actual (se entregan juntos en flush_outbox). */
	private $outbox = [];

	/** Si true, el próximo flush manda un mensaje NUEVO en vez de editar el ancla. */
	private $force_new = false;

	/** Si true, estamos dentro de una acción disparada por la IA: las vistas no
	 * pegan el menú al final (la IA rearmará el estado de espera del modo). */
	private $in_ia = false;

	/** Si true, este turno es conversacional (IA): se entrega como mensaje NUEVO,
	 * no editando el ancla. Editar el ancla es útil para el menú, no para charlar. */
	private $ia_turn = false;

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Bridge_Client $bridge, Cead_Acad_WA_Broadcaster $broadcaster ) {
		$this->store       = $store;
		$this->bridge      = $bridge;
		$this->broadcaster = $broadcaster;
	}

	public function process_message( array $msg ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) ( $msg['from'] ?? '' ) );
		$body  = sanitize_textarea_field( (string) ( $msg['body'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $msg['pushName'] ?? '' ) );
		$media = ( isset( $msg['media'] ) && is_array( $msg['media'] ) ) ? $msg['media'] : null;
		// Procesar si hay texto O imagen (mensajes con solo imagen son válidos).
		if ( $phone === '' || ( $body === '' && ! $media ) ) {
			return;
		}

		// Anti-flood: límite por número y ventana (evita abuso / creación masiva).
		if ( ! $this->allow_rate( $phone ) ) {
			return;
		}

		$identity = $this->resolve_identity( $phone );

		$existing = $this->store->get_number( $phone );
		// Se guardan ANTES de tocar la fila: son los datos de la conversación
		// anterior, y con eso se decide si hace falta explicar cómo funciona.
		$this->prev_msg_count = $existing ? (int) ( $existing->msg_count ?? 0 ) : 0;
		$this->prev_seen      = $existing ? (string) ( $existing->last_seen ?? '' ) : '';
		if ( ! $existing ) {
			$this->store->upsert_number( $phone, [ 'name' => $name, 'user_id' => $identity['user_id'] ] );
		} elseif ( $identity['user_id'] && empty( $existing->user_id ) ) {
			$this->store->upsert_number( $phone, [ 'user_id' => $identity['user_id'] ] );
		}
		$this->store->update_last_seen( $phone );
		$this->store->bump_msg_count( $phone );

		$st      = $this->store->get_state( $phone );
		$state   = $st['state'];
		$context = $st['context'];

		if ( $this->store->is_opted_out( $phone ) ) {
			return;
		}

		// Nota de voz: si llega un audio, lo transcribimos y seguimos el flujo
		// normal con ese texto. Solo se transcribe a números que vamos a atender
		// (registrados, o modo abierto). Si la transcripción está apagada o falla,
		// avisamos y cortamos. Audio = WhatsApp PTT (ogg/opus) u otros formatos.
		if ( $media && $this->is_audio_media( $media ) ) {
			$attend = ! get_option( 'cead_acad_wa_registered_only', 1 ) || ! empty( $identity['user_id'] );
			if ( $attend ) {
				$text = class_exists( 'Cead_Acad_WA_AI' )
					? Cead_Acad_WA_AI::transcribe( (string) ( $media['data_base64'] ?? '' ), (string) ( $media['mime'] ?? 'audio/ogg' ) )
					: '';
				if ( $text !== '' ) {
					$body  = sanitize_textarea_field( $text );
					$media = null; // ya es texto: los handlers lo tratan normal.
					// La IA recibe la transcripción, no el audio. Sin esta marca no
					// tiene forma de saber que hubo una nota de voz, y termina
					// negando que pueda escucharlas.
					$this->from_voice = true;
				} else {
					$this->outbox = [];
					$this->send( $phone, $this->m( 'voice_unavailable' ), 'voice_unavailable' );
					$this->flush_outbox( $phone );
					return;
				}
			}
		}

		// Planilla de notas: el docente manda el Excel que ya usa y lo procesamos
		// acá mismo, sin archivarlo. No pasa por el dispatch normal.
		if ( $media && Cead_Acad_Grades_Sheet::looks_like_sheet( $media ) ) {
			$this->outbox = [];
			$this->sheet_received( $phone, $media, $body, $identity );
			$this->flush_outbox( $phone );
			return;
		}

		// Log redactado para reportes sensibles.
		$this->log_inbound( $phone, $state, $context, $body !== '' ? $body : ( $this->is_image_media( $media ) ? '[imagen]' : '[archivo]' ) );

		// Todas las respuestas de este turno se acumulan y se entregan juntas.
		$this->outbox = [];

		// El ancla solo se edita cuando la persona NAVEGA con números. Si escribe
		// (palabras, una foto), editar dejaría la respuesta ARRIBA de su mensaje,
		// como si el bot no hubiera contestado: el turno baja como mensaje nuevo.
		$lc = strtolower( trim( $body ) );
		if ( ! ctype_digit( $lc ) ) {
			$this->force_new = true;
		}
		if ( $lc === 'baja' ) {
			$this->store->set_opt_out( $phone, true );
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'opt_out_confirmed' ), 'opt_out' );
			$this->flush_outbox( $phone );
			return;
		}

		// Clave de acceso temporal: va antes del filtro de registrados, porque
		// justamente sirve para números que todavía no están cargados.
		if ( class_exists( 'Cead_Acad_WA_Temp_Access' ) && Cead_Acad_WA_Temp_Access::attempt( $phone, $body ) ) {
			$identity = $this->resolve_identity( $phone );
			$this->send( $phone, sprintf(
				/* translators: %d: minutos de la sesión */
				__( '🔓 Acceso temporal habilitado por *%d minutos*. Escribí *menú* para empezar.', 'cead-acad' ),
				Cead_Acad_WA_Temp_Access::minutes()
			), 'temp_access' );
			$this->store->reset_state( $phone );
			$this->flush_outbox( $phone );
			return;
		}

		// CEADI solo atiende a números registrados en el panel del CEAD. A los
		// desconocidos se les avisa una vez (cada 6h) y no se entra a los menús.
		if ( get_option( 'cead_acad_wa_registered_only', 1 ) && empty( $identity['user_id'] ) ) {
			$notice_key = 'cead_acad_wa_unreg_' . md5( $phone );
			if ( ! get_transient( $notice_key ) ) {
				set_transient( $notice_key, 1, 6 * HOUR_IN_SECONDS );
				$this->send( $phone, '👋 Este número no está registrado en el panel del CEAD, así que CEADI todavía no puede atenderte por acá. Pedile a la dirección o secretaría que registre tu número.', 'not_registered' );
				$this->flush_outbox( $phone );
			}
			return;
		}

		// Formatos viejos que no se pueden abrir (.xls, .doc). El bridge los marca
		// y no manda el contenido; acá se explica cómo seguir, que si no el
		// mensaje se queda sin respuesta.
		if ( $media && ! empty( $media['unsupported'] ) ) {
			$this->send( $phone, $this->unsupported_file_message( (string) $media['unsupported'] ), 'file_unsupported' );
			$this->flush_outbox( $phone );
			return;
		}

		// "bajar": reenvía el mensaje actual al final del chat (mensaje nuevo).
		if ( in_array( $lc, [ 'bajar', 'abajo' ], true ) ) {
			$this->bring_down( $phone );
			return;
		}

		// "volver": sube un nivel de menú desde donde esté.
		if ( in_array( $lc, [ 'volver', 'atras', 'atrás' ], true ) ) {
			$this->go_back( $phone, $state, $identity );
			$this->flush_outbox( $phone );
			return;
		}

		// "cancelar": corta el flujo actual y vuelve al menú (mensaje nuevo).
		if ( in_array( $lc, [ 'cancelar', 'cancel' ], true ) ) {
			$this->force_new = true;
			$this->show_root_menu( $phone, $identity );
			$this->flush_outbox( $phone );
			return;
		}

		// Cambio rápido de modo: asistente (IA) ↔ menú numérico.
		$want_ia   = in_array( $lc, [ 'modo ia', 'modo asistente', 'asistente', 'modo chat' ], true );
		$want_menu = in_array( $lc, [ 'modo menu', 'modo menú', 'modo clasico', 'modo clásico', 'menu clasico', 'menú clásico' ], true );
		if ( $want_ia || $want_menu ) {
			$this->force_new = true;
			if ( $want_ia && ! $this->ai_enabled() ) {
				$this->send( $phone, __( '⚠️ El modo asistente no está disponible ahora; seguimos con el menú.', 'cead-acad' ), 'mode' );
				$this->enter_mode_landing( $phone, $identity, 'menu' );
			} else {
				$this->set_mode( $phone, $want_ia ? 'ia' : 'menu' );
				$this->send( $phone, $want_ia
					? __( '✅ Modo asistente activado. Escribime lo que necesites 💬 (mandá *menú* para ver opciones).', 'cead-acad' )
					: __( '✅ Modo menú activado.', 'cead-acad' ), 'mode' );
				$this->enter_mode_landing( $phone, $identity, $want_ia ? 'ia' : 'menu' );
			}
			$this->flush_outbox( $phone );
			return;
		}

		// Atajos rápidos de staff (-AA / -AE), solo en estados de menú.
		if ( $body !== '' && $this->maybe_handle_shortcut( $phone, $body, $state, $identity ) ) {
			$this->flush_outbox( $phone );
			return;
		}

		$this->dispatch( $phone, $body, $lc, $state, $context, $name, $identity, $media );
		$this->flush_outbox( $phone );
	}

	private function log_inbound( $phone, $state, $context, $body ) {
		// Nunca dejar una clave escrita en el historial: si el mensaje coincide
		// con la clave temporal vigente, se registra sin el contenido.
		if ( class_exists( 'Cead_Acad_WA_Temp_Access' ) && Cead_Acad_WA_Temp_Access::looks_like_key( $body ) ) {
			$this->store->log( $phone, 'in', '[clave de acceso temporal]' );
			return;
		}
		if ( $state === 'stu_report_body' ) {
			$type = $context['report_type'] ?? 'anonymous';
			if ( $type === 'anonymous' ) {
				return; // no dejar rastro del contenido anónimo
			}
			$this->store->log( $phone, 'in', '[reporte confidencial]' );
			return;
		}
		$this->store->log( $phone, 'in', $body );
	}

	private function dispatch( $phone, $body, $lc, $state, $context, $name, $identity, $media = null ) {
		// Filtro de lenguaje en estados de texto libre (reportes, sugerencias, comunicados).
		if ( $body !== '' && $this->is_free_text_state( $state ) ) {
			$hits = $this->detect_banned_words( $body );
			if ( $hits ) {
				$this->send( $phone, $this->interp( $this->m( 'vulgar_detected' ), [ 'words' => implode( ', ', $hits ) ] ), 'vulgar_blocked' );
				return;
			}
		}
		switch ( $state ) {
			case 'idle':                 $this->idle( $phone, $name, $identity, $body, $media ); break;
			case 'menu_pending':         $this->menu_pending( $phone, $identity, $context ); break;
			case 'role_chooser':         $this->role_chooser( $phone, $lc, $context, $identity ); break;
			case 'ia_home':              $this->ia_home( $phone, $body, $lc, $identity, $media ); break;
			case 'ia_staff_confirm':     $this->ia_staff_confirm( $phone, $lc, $context, $identity, $body, $media ); break;
			// Alumnado
			case 'student_menu':         $this->student_menu( $phone, $lc, $identity, $body, $media ); break;
			case 'stu_report_type':      $this->report_type( $phone, $lc ); break;
			case 'stu_report_cat':       $this->report_cat( $phone, $lc, $context ); break;
			case 'stu_report_body':      $this->report_body( $phone, $body, $lc, $context ); break;
			case 'stu_msg_to':           $this->msg_to( $phone, $lc, $identity ); break;
			case 'stu_msg_body':         $this->msg_body( $phone, $body, $lc, $context, $identity ); break;
			case 'stu_council_menu':     $this->council_menu( $phone, $lc ); break;
			case 'stu_council_proposal': $this->council_proposal( $phone, $body, $lc, $identity ); break;
			case 'stu_settings_menu':    $this->settings_menu( $phone, $lc, $identity ); break;
			case 'stu_settings_name':    $this->settings_name( $phone, $body, $lc, $identity ); break;
			case 'stu_settings_phone':   $this->settings_phone( $phone, $body, $lc, $identity ); break;
			// Staff
			case 'staff_menu':           $this->staff_menu( $phone, $lc, $context, $identity ); break;
			case 'staff_comm_compose':   $this->comm_compose( $phone, $body, $lc, $media ); break;
			case 'staff_comm_template':  $this->comm_template( $phone, $lc ); break;
			case 'staff_comm_audience':  $this->comm_audience( $phone, $lc, $context ); break;
			case 'staff_comm_when':      $this->comm_when( $phone, $lc, $context ); break;
			case 'staff_comm_confirm':   $this->comm_confirm( $phone, $lc, $context, $identity ); break;
			case 'staff_comm_schedule':  $this->comm_schedule( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_event_title':    $this->event_title( $phone, $body, $lc ); break;
			case 'staff_event_date':     $this->event_date( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_article_menu':   $this->article_menu( $phone, $lc, $identity ); break;
			case 'staff_article_title':  $this->article_title( $phone, $body, $lc ); break;
			case 'staff_article_body':   $this->article_body( $phone, $body, $lc, $context, $identity, $media ); break;
			case 'staff_article_cat':    $this->article_cat( $phone, $lc, $context, $identity ); break;
			case 'staff_article_edit_pick': $this->article_edit_pick( $phone, $lc, $context ); break;
			case 'staff_article_edit_body': $this->article_edit_body( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_article_del_pick':  $this->article_del_pick( $phone, $lc, $context ); break;
			case 'staff_article_del_confirm': $this->article_del_confirm( $phone, $lc, $context, $identity ); break;
			case 'staff_role_phone':     $this->role_phone( $phone, $body, $lc ); break;
			case 'staff_role_choose':    $this->role_choose( $phone, $lc, $context, $identity ); break;
			default:                     $this->idle( $phone, $name, $identity );
		}
	}

	/**
	 * Atajos de staff: "-AA <texto>" anuncio, "-AE <texto>" evento. Solo se
	 * disparan en estados de menú (no en medio de una captura) y según capacidad.
	 */
	private function maybe_handle_shortcut( $phone, $body, $state, $identity ) {
		$safe = [ 'idle', 'role_chooser', 'student_menu', 'staff_menu' ];
		if ( ! in_array( $state, $safe, true ) ) {
			return false;
		}
		if ( ! preg_match( '/^-(aa|ae)\b\s*(.*)$/is', trim( $body ), $m ) ) {
			return false;
		}
		$cmd  = strtolower( $m[1] );
		$text = trim( $m[2] );
		if ( $cmd === 'aa' ) {
			$uid = (int) ( $identity['user_id'] ?? 0 );
			if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast' ) ) {
				return false;
			}
			if ( $text === '' ) { $this->send( $phone, $this->m( 'shortcut_aa_usage' ) ); return true; }
			$aud = Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast_all' ) ? 'all' : 'students';
			$this->create_broadcast_post( $text, $aud );
			$res = $this->broadcaster->enqueue_for( $text, $aud );
			Cead_Acad_Audit::log( 'wa_broadcast_sent', [
				'user_id' => $uid ?: null,
				'payload' => [ 'target' => $aud, 'total' => (int) ( $res['total'] ?? 0 ), 'via' => 'shortcut_aa' ],
			] );
			$this->send( $phone, $this->interp( $this->m( 'shortcut_announce_ok' ), [ 'total' => (int) ( $res['total'] ?? 0 ) ] ), 'quick_announce' );
			return true;
		}
		// -AE: pedir la fecha en un paso.
		if ( ! Cead_Acad_WA_Identity::can( $identity['user_id'], 'cead_acad_manage_schedule' ) ) {
			return false;
		}
		if ( $text === '' ) { $this->send( $phone, $this->m( 'shortcut_ae_usage' ) ); return true; }
		$this->store->set_state( $phone, 'staff_event_date', [ 'title' => $text ] );
		$this->send( $phone, $this->m( 'event_date_prompt' ) );
		return true;
	}

	// ---------------------------------------------------------------- idle
	private function idle( $phone, $name, $identity, $body = '', $media = null ) {
		$is_staff = (bool) $this->available_role_menus( $identity );
		$mode     = $this->mode_for( $phone );

		// Modo asistente (IA): mismo flujo para alumnado y personal. El texto libre
		// lo maneja la IA; el menú (lo que corresponda al rol) queda a un «menú».
		if ( $mode === 'ia' && $this->ai_enabled() ) {
			$this->store->set_state( $phone, 'ia_home' );
			// Una foto o un documento solos (sin texto) también son una consulta:
			// no pasan por looks_like_query() porque ahí no hay nada que medir.
			$trae_pedido = $this->looks_like_query( $body )
				|| $this->has_ai_image( $media )
				|| $this->has_ai_doc( $media );
			if ( $trae_pedido && $this->ai_try( $phone, $body, $identity, 'ia_home', $media ) ) {
				return;
			}
			// Traía un pedido concreto y la IA no pudo con él. Contestar acá el
			// saludo de bienvenida hacía que el pedido se perdiera sin más: la
			// persona tenía que volver a escribirlo y a adjuntar el archivo.
			if ( $trae_pedido ) {
				$this->ia_turn = true;
				$this->send(
					$phone,
					__( '⚠️ No pude procesar tu mensaje en este momento. Reenviámelo en un ratito, o mandá *menú* para ver las opciones.', 'cead-acad' ),
					'ai_error'
				);
				return;
			}
			$greeting = $is_staff
				? $this->interp( $this->m( 'greeting_staff' ),   [ 'name' => $name ?: 'Profe' ] )
				: $this->interp( $this->m( 'greeting_student' ), [ 'name' => $name ?: 'che' ] );
			// La explicación de cómo funciona solo las primeras veces, o cuando
			// pasó tanto que conviene recordarla. Repetirla en cada saludo a
			// quien ya lo usa todos los días es puro ruido.
			if ( $this->should_show_intro() ) {
				$greeting .= "\n\n" . __( '💬 Escribime lo que necesites y te ayudo. Mandá *menú* si preferís las opciones.', 'cead-acad' );
			}
			$this->ia_turn = true;
			$this->send( $phone, $greeting, 'ia_home' );
			return;
		}

		// Modo menú (clásico): personal → selector de roles; alumnado → menú numérico.
		$switch_hint = $this->ai_enabled() ? "\n\n" . __( '_💬 Escribí *modo asistente* para hablar con CEADI en lenguaje natural._', 'cead-acad' ) : '';
		if ( $is_staff ) {
			$greeting = $this->interp( $this->m( 'greeting_staff' ), [ 'name' => $name ?: 'Profe' ] ) . $switch_hint;
			$this->enter_role_chooser( $phone, $identity, $this->available_role_menus( $identity ), $greeting );
			return;
		}
		$this->store->set_state( $phone, 'student_menu' );
		$greeting = $this->interp( $this->m( 'greeting_student' ), [ 'name' => $name ?: 'che' ] );
		$menu     = $greeting . "\n\n" . $this->m( 'student_menu' ) . "\n\n" . $this->m( 'panel_promo' ) . $switch_hint;
		$this->send_menu( $phone, $menu, 'student_menu' );
	}

	/** ¿La capa de IA está disponible y configurada? */
	private function ai_enabled() {
		return class_exists( 'Cead_Acad_WA_AI' ) && Cead_Acad_WA_AI::enabled();
	}

	/* ------------------------------------------------- modo (IA / menú) por número */

	/** Modo efectivo del número: preferencia guardada o el default configurado. */
	private function mode_for( $phone ) {
		$m = get_transient( 'cead_acad_wa_mode_' . md5( (string) $phone ) );
		if ( $m === 'ia' || $m === 'menu' ) {
			return ( $m === 'ia' && ! $this->ai_enabled() ) ? 'menu' : $m;
		}
		return $this->default_mode();
	}

	/** Modo por defecto (admin): 'ia', 'menu' o 'auto' (= IA si está disponible). */
	private function default_mode() {
		$d = (string) get_option( 'cead_acad_wa_default_mode', 'auto' );
		if ( $d === 'menu' ) { return 'menu'; }
		if ( $d === 'ia' )   { return $this->ai_enabled() ? 'ia' : 'menu'; }
		return $this->ai_enabled() ? 'ia' : 'menu'; // auto
	}

	/** Guarda la preferencia de modo del número (persistente, 60 días). */
	private function set_mode( $phone, $mode ) {
		set_transient( 'cead_acad_wa_mode_' . md5( (string) $phone ), $mode === 'ia' ? 'ia' : 'menu', 60 * DAY_IN_SECONDS );
	}

	/** Deja al número en el punto de entrada del modo elegido (igual para todos). */
	private function enter_mode_landing( $phone, $identity, $mode ) {
		if ( $mode === 'ia' && $this->ai_enabled() ) {
			// La confirmación ya se envió; solo dejamos el estado de espera de la IA.
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$this->show_root_menu( $phone, $identity );
	}

	/** Heurística: ¿el texto parece una consulta y no un saludo suelto? */
	private function looks_like_query( $body ) {
		$body = trim( (string) $body );
		if ( mb_strlen( $body ) < 4 ) { return false; }
		$lc = strtolower( $body );
		foreach ( [ 'hola', 'buenas', 'buen dia', 'buen día', 'buenos dias', 'buenas tardes', 'buenas noches', 'que tal', 'qué tal', 'hello', 'hi', 'menu', 'menú' ] as $greet ) {
			if ( $lc === $greet ) { return false; }
		}
		return true;
	}

	// --------------------------------------------------- selector de menú por rol
	/**
	 * Definición declarativa de los menús de cada rol. Cada acción: [ key, label, cap ]
	 * (cap '' = siempre visible). Ver EXTENDING.md, receta 4.
	 */
	private function role_menus() {
		// La gestión de reportes y sugerencias vive en el panel web; el bot ya no
		// expone bandejas (para no llenar el WhatsApp de coordinación).
		$full = [
			[ 'comm',      'Enviar comunicado / anuncio',   'cead_acad_publish_broadcast' ],
			[ 'event',     'Agregar evento al calendario',  'cead_acad_manage_schedule' ],
			[ 'articles',  'Artículos del sitio',           'cead_acad_manage_articles' ],
			[ 'roles',     'Asignar roles a un número',     'cead_acad_manage_roles' ],
			[ 'metrics',   'Métricas',                      'cead_acad_view_metrics' ],
			[ 'shortcuts', 'Atajos rápidos',                '' ],
		];
		return [
			'cead_acad_direction' => [ 'label' => 'Dirección',  'actions' => $full ],
			'cead_acad_secretary' => [ 'label' => 'Secretaría', 'actions' => $full ],
			'cead_acad_teacher'   => [ 'label' => 'Docente', 'actions' => [
				[ 'comm',      'Enviar comunicado',  'cead_acad_publish_broadcast' ],
				[ 'event',     'Agregar evento',     'cead_acad_manage_schedule' ],
				[ 'shortcuts', 'Atajos rápidos',     '' ],
			] ],
			'cead_acad_student_council' => [ 'label' => 'Consejo Estudiantil', 'actions' => [
				[ 'comm',      'Enviar comunicado', 'cead_acad_publish_broadcast' ],
				[ 'shortcuts', 'Atajos rápidos',    '' ],
			] ],
		];
	}

	/** Acciones disponibles de un rol para un usuario (filtradas por capacidad). */
	private function role_actions( $role, $uid ) {
		$defs = $this->role_menus();
		if ( ! isset( $defs[ $role ] ) ) {
			return [];
		}
		$out = [];
		foreach ( $defs[ $role ]['actions'] as $a ) {
			[ $key, $label, $cap ] = $a;
			if ( $cap === '' || Cead_Acad_WA_Identity::can( $uid, $cap ) ) {
				$out[] = [ 'key' => $key, 'label' => $label ];
			}
		}
		return $out;
	}

	/** Menús de rol que el usuario realmente puede usar (rol => def). */
	private function available_role_menus( $identity ) {
		$uid = $identity['user_id'];
		if ( ! $uid ) {
			return [];
		}
		$user = get_user_by( 'id', $uid );
		if ( ! $user ) {
			return [];
		}
		$defs = $this->role_menus();
		$out  = [];
		foreach ( (array) $user->roles as $role ) {
			if ( isset( $defs[ $role ] ) && $this->role_actions( $role, $uid ) ) {
				$out[ $role ] = $defs[ $role ];
			}
		}
		// Administrador de WordPress sin rol de cead-acad: darle el menú de Dirección.
		if ( ! $out && user_can( $uid, 'manage_options' ) && $this->role_actions( 'cead_acad_direction', $uid ) ) {
			$out['cead_acad_direction'] = $defs['cead_acad_direction'];
		}
		return $out;
	}

	private function enter_role_chooser( $phone, $identity, $menus, $prefix = '' ) {
		$options = array_merge( [ 'students' ], array_keys( $menus ) );
		$this->store->set_state( $phone, 'role_chooser', [ 'options' => $options ] );
		$lines = [];
		if ( $prefix !== '' ) { $lines[] = $prefix; $lines[] = ''; }
		$lines[] = $this->m( 'role_chooser_header' );
		$lines[] = '1. Estudiantes';
		$i = 2;
		foreach ( $menus as $def ) { $lines[] = $i . '. ' . $def['label']; $i++; }
		$lines[] = '0. Salir';
		$this->send_menu( $phone, implode( "\n", $lines ), 'role_chooser' );
	}

	private function role_chooser( $phone, $lc, $context, $identity ) {
		if ( in_array( $lc, [ '0', 'salir' ], true ) ) {
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'goodbye' ) );
			return;
		}
		$options = $context['options'] ?? [];
		$idx     = (int) $lc - 1;
		if ( ! isset( $options[ $idx ] ) ) { $this->invalid( $phone ); return; }
		if ( $options[ $idx ] === 'students' ) {
			$this->store->set_state( $phone, 'student_menu' );
			$this->send_menu( $phone, $this->m( 'student_menu' ), 'student_menu' );
			return;
		}
		$this->enter_role_menu( $phone, $options[ $idx ], $identity );
	}

	private function enter_role_menu( $phone, $role, $identity ) {
		$actions = $this->role_actions( $role, $identity['user_id'] );
		if ( ! $actions ) { $this->idle( $phone, '', $identity ); return; }
		$this->store->set_state( $phone, 'staff_menu', [ 'options' => array_column( $actions, 'key' ), 'role' => $role ] );
		$defs  = $this->role_menus();
		$lines = [ '*' . ( $defs[ $role ]['label'] ?? 'Panel' ) . '* — ¿qué querés hacer?' ];
		foreach ( $actions as $i => $a ) { $lines[] = ( $i + 1 ) . '. ' . $a['label']; }
		$lines[] = '0. Salir';
		$this->send_menu( $phone, implode( "\n", $lines ), 'staff_menu' );
	}

	// ---------------------------------------------------------------- alumnado
	private function student_menu( $phone, $lc, $identity, $body = '', $media = null ) {
		switch ( $lc ) {
			case '1':  $this->show_horario( $phone, $identity ); break;
			case '2':  $this->show_links( $phone ); break;
			case '3':  $this->show_events( $phone, $identity ); break;
			case '4':  $this->show_contacts( $phone ); break;
			case '5':  $this->show_comunicados( $phone, $identity ); break;
			case '6':  $this->report_start( $phone ); break;
			case '7':  $this->suggestion_start( $phone ); break;
			case '8':  $this->show_faq( $phone ); break;
			case '9':  $this->council_open( $phone ); break;
			case '10': $this->reminders_toggle( $phone ); break;
			case '11': $this->show_panel( $phone ); break;
			case '12': $this->settings_open( $phone ); break;
			case '13': $this->show_notas( $phone, $identity ); break;
			case '14': $this->show_tareas( $phone, $identity ); break;
			case '15': $this->show_carne( $phone, $identity ); break;
			case '0': case 'salir': case 'adios': case 'adiós':
				$this->store->reset_state( $phone );
				if ( class_exists( 'Cead_Acad_WA_AI' ) ) { Cead_Acad_WA_AI::clear_memory( $phone ); }
				$this->send( $phone, $this->m( 'goodbye' ) );
				break;
			case 'menu': case 'menú': case 'hola': case 'inicio':
				// Con rol de staff, «menú» vuelve al selector; un alumno sin
				// roles cae igual a su menú (show_root_menu lo resuelve).
				$this->show_root_menu( $phone, $identity );
				break;
			default:
				// En modo asistente, el texto libre lo maneja la IA. En modo menú, no.
				if ( $this->mode_for( $phone ) === 'ia' ) {
					if ( $this->ai_try( $phone, ( $body !== '' ? $body : $lc ), $identity, 'ia_home', $media ) ) {
						return;
					}
					if ( $this->ai_failed() ) {
						$this->force_new = true;
						$this->send( $phone, __( '⚠️ Ahora mismo no puedo procesar tu mensaje. Te dejo el menú 👇', 'cead-acad' ), 'ai_error' );
						$this->back_to_student( $phone );
						return;
					}
					if ( $this->ai_enabled() ) {
						$this->ia_turn = true;
						$this->send( $phone, __( '🤔 No te entendí del todo. Probá con otras palabras, o escribí *menú* para ver las opciones.', 'cead-acad' ), 'ai_miss' );
						return;
					}
				}
				$this->invalid( $phone );
		}
	}

	/**
	 * Estado de conversación con la IA (modo asistente), IGUAL para alumnado y
	 * personal. El texto libre va a la IA; "menú" / un número abren el menú que
	 * corresponda al rol (alumnado → menú de alumno; personal → su panel).
	 */
	private function ia_home( $phone, $body, $lc, $identity, $media = null ) {
		if ( in_array( $lc, [ 'menu', 'menú', 'opciones', 'panel', 'inicio' ], true ) || ctype_digit( $lc ) ) {
			// El menú arranca como mensaje nuevo; de ahí en más se edita el ancla.
			$this->force_new = true;
			$this->show_root_menu( $phone, $identity );
			return;
		}
		if ( $this->ai_try( $phone, ( $body !== '' ? $body : $lc ), $identity, 'ia_home', $media ) ) {
			return;
		}
		// Fallo TÉCNICO de la IA (key/endpoint/red): no culpar al usuario con un
		// «no te entendí»; avisar y dejar el menú, que siempre funciona.
		if ( $this->ai_failed() ) {
			$this->force_new = true;
			$this->send( $phone, __( '⚠️ Ahora mismo no puedo procesar tu mensaje. Te dejo el menú 👇', 'cead-acad' ), 'ai_error' );
			$this->show_root_menu( $phone, $identity );
			return;
		}
		if ( $this->ai_enabled() ) {
			$this->ia_turn = true;
			$this->send( $phone, __( '🤔 No te entendí del todo. Escribime de nuevo, o mandá *menú* para ver las opciones.', 'cead-acad' ), 'ai_miss' );
			return;
		}
		// IA caída/desactivada: caemos al menú que corresponda.
		$this->show_root_menu( $phone, $identity );
	}

	/** ¿La última llamada a la IA falló por un error técnico (no «no entendí»)? */
	private function ai_failed() {
		return $this->ai_enabled() && class_exists( 'Cead_Acad_WA_AI' ) && Cead_Acad_WA_AI::last_error() !== '';
	}

	/* ---------------------------------------------------- IA (CEADI inteligente) */

	/**
	 * Deja que la IA entienda el mensaje y decida con criterio: por defecto
	 * responde ella misma (charla); solo dispara una función del sistema cuando
	 * de verdad hace falta. Devuelve true si lo manejó, false para caer al menú.
	 */
	private function ai_try( $phone, $text, $identity, $home_state = 'ia_home', $media = null ) {
		if ( ! $this->ai_enabled() ) {
			return false;
		}
		// Imagen: si la lectura de imágenes está activa, se la damos al modelo
		// para que la mire. Sigue disponible aparte como adjunto (comunicado,
		// artículo): mirarla no consume el archivo.
		$ai_image = $this->has_ai_image( $media ) ? $media : null;
		// Se marca antes de armar el contexto: ai_channel_note() lo lee de ahí.
		$this->from_image = (bool) $ai_image;

		// Documento (PDF, Word): se le saca el texto y va como contexto. Si no
		// se pudo leer, se dice por qué en vez de contestar cualquier cosa.
		$this->doc_ctx = '';
		if ( $this->has_ai_doc( $media ) ) {
			$doc = Cead_Acad_WA_Docs::extract( $media );
			if ( empty( $doc['ok'] ) ) {
				$this->ia_turn = true;
				$extra = ( 'pdf' === ( $doc['kind'] ?? '' ) )
					? ' ' . __( 'Si es un escaneo, sacale una foto a la hoja y mandámela como imagen.', 'cead-acad' )
					: '';
				$this->send(
					$phone,
					'📄 ' . sprintf(
						/* translators: %s: motivo por el que no se pudo leer */
						__( 'Recibí el archivo pero no pude leerlo. %s', 'cead-acad' ),
						(string) ( $doc['reason'] ?? '' )
					) . $extra,
					'doc_unreadable'
				);
				$this->leave_ia_state( $phone, $home_state );
				return true;
			}
			$this->doc_ctx = "\n\n[DOCUMENTO QUE ACABA DE ENVIAR]\n"
				. 'Tipo: ' . Cead_Acad_WA_Docs::kind_label( $doc['kind'] )
				. ( ! empty( $doc['filename'] ) ? ' · Archivo: ' . $doc['filename'] : '' ) . "\n"
				. "--- texto del documento ---\n"
				. $doc['text'] . "\n"
				. "--- fin del documento ---\n"
				. 'Respondé sobre este documento: resumilo, buscá un dato, explicá lo que pidan. '
				. 'Es un archivo de trabajo: no queda guardado en el sistema.';
			if ( $text === '' ) {
				$text = __( '(Te mandé este documento sin texto. Decime de qué se trata, en breve.)', 'cead-acad' );
			}
		}

		if ( $text === '' && $ai_image ) {
			// Foto sola, sin epígrafe: hay que decirle algo al modelo, si no la
			// llamada se rechaza por mensaje vacío.
			$text = __( '(Te mandé esta imagen sin texto. Miralá y decime qué es o ayudame con lo que muestra.)', 'cead-acad' );
		} elseif ( $text === '' ) {
			// Sin texto y sin imagen que mirar. Llamar a la IA con el mensaje
			// vacío da un error técnico y termina mostrando «no puedo procesar
			// tu mensaje», que confunde: acá el problema es que no hay nada que
			// leer. Se contesta según el caso y no se gasta una llamada.
			if ( $this->is_image_media( $media ) ) {
				$this->ia_turn = true;
				$this->send( $phone, __( '📷 Me llegó tu imagen, pero no puedo mirarla. Contame con palabras qué necesitás.', 'cead-acad' ), 'image_unsupported' );
				$this->leave_ia_state( $phone, $home_state );
				return true;
			}
			if ( class_exists( 'Cead_Acad_WA_Docs' ) && Cead_Acad_WA_Docs::is_document( $media ) ) {
				$this->ia_turn = true;
				$this->send( $phone, __( '📄 Me llegó tu archivo, pero no puedo leerlo. Contame con palabras qué necesitás.', 'cead-acad' ), 'doc_unsupported' );
				$this->leave_ia_state( $phone, $home_state );
				return true;
			}
			return false;
		}
		$res = Cead_Acad_WA_AI::route(
			$text,
			$this->faq_context(),
			$phone,
			$this->ai_staff_tools( $identity, $phone ),
			$this->ai_context_with_sheet( $identity, $phone ),
			$ai_image
		);
		if ( ! is_array( $res ) ) {
			return false;
		}
		$action = (string) ( $res['intent'] ?? '' ); // '' = la IA respondió por su cuenta
		$reply  = isset( $res['reply'] ) ? trim( (string) $res['reply'] ) : '';
		$args   = isset( $res['args'] ) && is_array( $res['args'] ) ? $res['args'] : [];

		// Acciones de gestión del staff: NO se ejecutan; se proponen y el menú aprueba.
		if ( in_array( $action, [ 'enviar_comunicado', 'crear_evento', 'crear_invitacion', 'cargar_nota', 'crear_articulo' ], true ) ) {
			return $this->propose_staff_action( $phone, $action, $args, $reply, $identity, $media );
		}

		// La IA decidió disparar una función: su "reply" es la transición y va primero.
		if ( $action !== '' ) {
			$this->ia_turn = true; // respuesta conversacional → mensaje nuevo
			if ( $reply !== '' ) { $this->send( $phone, $reply ); }
			// Las vistas no pegan su menú: la IA rearma el estado de espera al terminar.
			$this->in_ia = true;
			$handled     = true;
			switch ( $action ) {
				// Informativas: tras mostrarlas volvemos al estado de espera del modo.
				case 'horario':       $this->show_horario( $phone, $identity ); break;
				case 'notas':         $this->show_notas( $phone, $identity ); break;
				case 'tareas':        $this->show_tareas( $phone, $identity ); break;
				case 'eventos':       $this->show_events( $phone, $identity ); break;
				case 'comunicados':   $this->show_comunicados( $phone, $identity ); break;
				case 'sitio':         $this->show_links( $phone ); break;
				case 'contacto':      $this->show_contacts( $phone ); break;
				case 'faq':           $this->show_faq( $phone ); break;
				case 'panel':         $this->show_panel( $phone ); break;
				case 'carne':         $this->show_carne( $phone, $identity ); break;
				case 'ver_notas_curso': $this->show_notas_curso( $phone, $identity, $args ); break;
				case 'panorama':      $this->metrics_show( $phone, $identity ); break;
				case 'recordatorios': $this->reminders_toggle( $phone ); break;
				// Flujos guiados: mantienen el estado que fijan ellos mismos.
				case 'reportar':      $this->in_ia = false; $this->report_start( $phone ); return true;
				case 'escribir':      $this->in_ia = false; $this->suggestion_start( $phone ); return true;
				case 'consejo':       $this->in_ia = false; $this->council_open( $phone ); return true;
				case 'ajustes':       $this->in_ia = false; $this->settings_open( $phone ); return true;
				default:              $handled = false;
			}
			$this->in_ia = false;
			if ( $handled ) {
				$this->leave_ia_state( $phone, $home_state );
				return true;
			}
			// Función inexistente: si respondió algo, lo dejamos como charla.
			if ( $reply !== '' ) { $this->leave_ia_state( $phone, $home_state ); return true; }
			return false;
		}

		// Charla pura: la IA respondió con criterio propio.
		if ( $reply !== '' ) {
			$this->ia_turn = true; // mensaje nuevo, no editar el ancla
			$this->leave_ia_state( $phone, $home_state );
			$this->send( $phone, $reply, 'ai_chat' );
			return true;
		}
		return false;
	}

	/* ------------------------------------------ acciones de staff vía IA (con aprobación) */

	/** Cache por request de la ficha de contexto (una petición = un mensaje). */
	private $ai_ctx_cache = [];

	/** Mensajes que ya había mandado este número ANTES del actual. */
	private $prev_msg_count = 0;
	/** Cuándo se lo vio por última vez ANTES de este mensaje ('' si es nuevo). */
	private $prev_seen = '';

	/**
	 * Cuántos mensajes se acompañan con la explicación de cómo hablarle al bot.
	 * Con tres alcanza para agarrarle la mano.
	 */
	const INTRO_MESSAGES = 3;

	/** Silencio a partir del cual conviene recordar cómo funciona. */
	const INTRO_AGAIN_AFTER = 2 * DAY_IN_SECONDS;

	/**
	 * ¿Corresponde explicar cómo funciona el bot en este saludo?
	 *
	 * Sí las primeras veces, y también cuando la persona estuvo un par de días
	 * sin escribir (ahí ya no se acuerda). El resto del tiempo el saludo va
	 * solo: quien lo usa a diario no necesita que le repitan las instrucciones.
	 */
	private function should_show_intro() {
		return self::needs_intro( $this->prev_msg_count, $this->prev_seen, (int) current_time( 'timestamp' ) );
	}

	/**
	 * La decisión sola, sin tocar la base: cuántos mensajes mandó antes, cuándo
	 * fue el último y qué hora es ahora (todo en hora local del sitio, que es
	 * como se guarda last_seen).
	 */
	public static function needs_intro( $msg_count, $last_seen, $now_ts ) {
		if ( (int) $msg_count < self::INTRO_MESSAGES ) { return true; }
		$last_seen = trim( (string) $last_seen );
		if ( '' === $last_seen || '0000-00-00 00:00:00' === $last_seen ) { return true; }
		$ts = strtotime( $last_seen );
		if ( ! $ts ) { return true; }
		return ( (int) $now_ts - $ts ) >= self::INTRO_AGAIN_AFTER;
	}

	/** El mensaje de este turno llegó como nota de voz y fue transcripto. */
	private $from_voice = false;
	private $from_image = false;
	/** Texto del documento adjunto de este turno ('' si no hay). */
	private $doc_ctx = '';

	/**
	 * Cursos que la persona puede gestionar: null = todos (dirección/secretaría),
	 * o la lista de IDs donde está inscripta o figura como tutor/a (docente).
	 *
	 * @return int[]|null
	 */
	private function courses_scope_for( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid ) { return []; }
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_courses' ) ) { return null; }

		$ids = class_exists( 'Cead_Acad_Courses_Roster' )
			? Cead_Acad_Courses_Roster::courses_for_user( $uid )
			: [];
		// Cursos donde figura como tutor/a aunque no esté en el roster.
		$tutor = get_posts( [
			'post_type'      => 'cead_acad_course',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'meta_key'       => '_cead_acad_tutor',
			'meta_value'     => $uid,
		] );
		return array_values( array_unique( array_map( 'intval', array_merge( (array) $ids, (array) $tutor ) ) ) );
	}

	/** Títulos legibles de una lista de cursos. */
	private function course_titles( $ids, $limit = 8 ) {
		$out = [];
		foreach ( array_slice( (array) $ids, 0, $limit ) as $cid ) {
			$t = get_the_title( (int) $cid );
			if ( $t ) { $out[] = $t; }
		}
		return $out;
	}

	/**
	 * Ficha breve de quien escribe (nombre, rol, cursos) + la fecha de hoy, para
	 * que la IA responda con datos en vez de a ciegas: así sabe a qué se refiere
	 * con «mi curso» y qué día cae «el viernes».
	 */
	private function ai_user_context( $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( isset( $this->ai_ctx_cache[ $uid ] ) ) { return $this->ai_ctx_cache[ $uid ]; }

		$lines = [];
		$user  = $uid ? get_user_by( 'id', $uid ) : null;

		if ( $user ) {
			$lines[] = 'Nombre: ' . $user->display_name;
			$roles   = $this->role_labels_for( $user );
			if ( '' !== $roles ) { $lines[] = 'Rol: ' . $roles; }

			$scope = $this->courses_scope_for( $uid );
			if ( null === $scope ) {
				$lines[] = 'Alcance: todos los cursos del colegio.';
			} else {
				$titles  = $this->course_titles( $scope );
				$lines[] = $titles
					? 'Cursos: ' . implode( ' · ', $titles )
					: 'Todavía no tiene cursos asignados.';
			}
		} else {
			$lines[] = 'Es un número que no está registrado en el panel del CEAD.';
		}

		$lines[] = 'Hoy es ' . date_i18n( 'l j \d\e F \d\e Y, H:i', (int) current_time( 'timestamp' ) ) . '.';

		$ctx = implode( "\n", $lines );

		// Nota: la planilla recién enviada NO se cachea junto al resto, porque
		// cambia dentro de la misma conversación.
		$this->ai_ctx_cache[ $uid ] = $ctx;
		return $ctx;
	}

	/** Marca de este turno: si el mensaje llegó hablado, la IA tiene que saberlo. */
	private function ai_channel_note() {
		$note = '';
		if ( $this->from_voice ) {
			$note .= "\n\nEste mensaje llegó como NOTA DE VOZ y el sistema lo transcribió: lo que leés es la transcripción. "
				. "Si te preguntan si escuchás audios, la respuesta es sí. "
				. "La transcripción puede traer errores de palabras: si algo no cierra, pedí que lo aclaren.";
		}
		if ( '' !== $this->doc_ctx ) {
			$note .= "\n\nEn este mensaje viene un DOCUMENTO adjunto y su texto está más arriba, en el contexto. "
				. "Si te preguntan si leés PDF o Word, la respuesta es sí. "
				. "Trabajá con lo que dice el documento y no inventes lo que no está; si le falta una parte "
				. "(porque era muy largo y se cortó), decilo. "
				. "Un documento leído por acá NO queda cargado en el sistema: si hay que registrar algo, aclaralo.";
		}
		if ( $this->from_image ) {
			$note .= "\n\nEn este mensaje viene una IMAGEN adjunta y la estás viendo: describila o usala para responder, "
				. "según lo que te pidan. Si te preguntan si ves fotos, la respuesta es sí. "
				. "Si la foto está borrosa o no se entiende, decilo y pedí que la manden de nuevo, en vez de inventar. "
				. "Si es una foto de un documento (planilla, circular, boletín), leé lo que dice y trabajá con eso, "
				. "pero recordá que una foto NO reemplaza cargar los datos en el sistema.";
		}
		return $note;
	}

	/** Ficha del usuario + la planilla que mandó recién, si sigue en memoria. */
	private function ai_context_with_sheet( $identity, $phone ) {
		$ctx   = $this->ai_user_context( $identity ) . $this->doc_ctx;
		$sheet = Cead_Acad_Grades_Sheet::recall( $phone );
		if ( ! $sheet || empty( $sheet['rows'] ) ) { return $ctx . $this->ai_channel_note(); }

		$ctx .= "\n\n[PLANILLA QUE ACABA DE ENVIAR]\n"
			. ( ! empty( $sheet['course_id'] ) ? 'Curso: ' . get_the_title( (int) $sheet['course_id'] ) . "\n" : '' )
			. Cead_Acad_Grades_Sheet::to_text( (array) $sheet['rows'] ) . "\n"
			. 'Podés responder preguntas sobre esta planilla (promedios, cuántos aprobaron, quién bajó). '
			. 'La escala del colegio va de 0 a ' . Cead_Acad_Grades_Writer::fmt( Cead_Acad_Grades_Writer::score_max() )
			. ' y se aprueba desde ' . Cead_Acad_Grades_Writer::fmt( Cead_Acad_Grades_Writer::score_pass() ) . '. '
			. 'Es un archivo de trabajo: no está guardado en el sistema salvo que lo carguen.';
		return $ctx . $this->ai_channel_note();
	}

	/**
	 * Herramientas de gestión que la IA puede PROPONER, según los permisos reales
	 * del usuario. Si no tiene permiso, ni siquiera se le ofrecen al modelo.
	 */
	/**
	 * ¿Este número es el del director/a?
	 *
	 * La publicación en redes sociales del colegio se restringe por NÚMERO, no
	 * por rol: es una cuenta institucional pública y quien la usa responde por
	 * ella. Que alguien tenga permisos de artículos en el sistema no lo habilita
	 * a publicar en las redes del colegio.
	 */
	private function is_director_phone( $phone ) {
		$conf = (string) get_option( 'cead_acad_wa_director_phone', '' );
		if ( trim( $conf ) === '' ) { return false; }
		$a = Cead_Acad_WA_Identity::normalize_phone( $phone );
		$b = Cead_Acad_WA_Identity::normalize_phone( $conf );
		return $a !== '' && $a === $b;
	}

	/** Categoría que Bit Social vigila para auto-publicar en redes. */
	private function social_category_id() {
		$slug = (string) get_option( 'cead_acad_wa_social_category', 'redes-sociales' );
		$slug = sanitize_title( $slug ) ?: 'redes-sociales';
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term && ! is_wp_error( $term ) ) { return (int) $term->term_id; }
		$new = wp_insert_term( 'Redes sociales', 'category', [ 'slug' => $slug ] );
		return is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}

	/**
	 * Categorías que se pueden asignar a un artículo desde el bot.
	 *
	 * Son las categorías reales de WordPress, así que se administran donde
	 * corresponde (Entradas → Categorías) y no en una lista aparte. Se excluye
	 * la de redes sociales: esa no es un tema, es el disparador de Bit Social, y
	 * se asigna sola cuando el director/a pide replicar.
	 *
	 * @return array term_id => nombre
	 */
	private function article_categories() {
		$social = (string) get_option( 'cead_acad_wa_social_category', 'redes-sociales' );
		$social = sanitize_title( $social ) ?: 'redes-sociales';

		$terms = get_terms( [
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'number'     => 30,
			'orderby'    => 'name',
		] );
		if ( is_wp_error( $terms ) ) { return []; }

		$out = [];
		foreach ( $terms as $t ) {
			if ( $t->slug === $social || $t->slug === 'uncategorized' || $t->slug === 'sin-categoria' ) { continue; }
			$out[ (int) $t->term_id ] = $t->name;
		}
		return $out;
	}

	/**
	 * Resuelve un nombre de categoría escrito por la IA (o por la persona) al
	 * term_id real. Compara sin acentos ni mayúsculas y acepta coincidencia
	 * parcial ("deporte" → "Deportes"). Nunca crea categorías nuevas: si no
	 * existe, el artículo se publica sin categoría en vez de ensuciar la
	 * taxonomía con lo que se le haya ocurrido al modelo.
	 */
	private function resolve_category( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) { return 0; }

		$norm = static function ( $s ) {
			$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
			return trim( strtolower( $s ) );
		};
		$needle = $norm( $name );
		if ( '' === $needle ) { return 0; }

		$cats = $this->article_categories();
		foreach ( $cats as $id => $label ) {
			if ( $norm( $label ) === $needle ) { return (int) $id; }
		}
		foreach ( $cats as $id => $label ) {
			$hay = $norm( $label );
			if ( str_contains( $hay, $needle ) || str_contains( $needle, $hay ) ) { return (int) $id; }
		}
		return 0;
	}

	private function ai_staff_tools( $identity, $phone = '' ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! $uid ) { return []; }
		$tools = [];
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast' ) ) {
			$aud = Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast_all' )
				? 'students=alumnado, staff=personal, all=todos'
				: 'students=alumnado, staff=personal';
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'enviar_comunicado',
					'description' => 'Proponer el envío de un comunicado por WhatsApp. NO se envía hasta que la persona lo apruebe. Si el mensaje vino acompañado de una foto, se adjunta sola al comunicado: no hace falta (ni es posible) pasarla como argumento.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'mensaje'   => [ 'type' => 'string', 'description' => 'Texto del comunicado, ya redactado y listo para enviar.' ],
							'audiencia' => [ 'type' => 'string', 'description' => "A quién enviarlo ($aud)." ],
						],
						'required'   => [ 'mensaje', 'audiencia' ],
					],
				],
			];
		}
		// Artículos del sitio. La opción de replicar en redes sociales solo se le
		// ofrece al número del director/a: si no es él, el modelo ni siquiera ve
		// que exista el parámetro, así que no puede proponerlo.
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_articles' ) ) {
			$es_dir = $this->is_director_phone( $phone );
			$cats   = $this->article_categories();
			$props  = [
				'titulo'    => [
					'type'        => 'string',
					'description' => 'Título del artículo, en una línea y sin markdown.',
				],
				'contenido' => [
					'type'        => 'string',
					'description' => 'Cuerpo del artículo, ya redactado y en MARKDOWN, que se maqueta solo al publicar. '
						. 'Escribilo como una nota de un diario escolar. El PRIMER párrafo es la bajada: se muestra más grande, '
						. 'así que tiene que resumir lo importante solo. '
						. 'Usá ## para los subtítulos, listas con - o 1., **negrita** para destacar, --- para separar bloques, '
						. 'y tablas con | cuando haya datos que se leen mejor en columnas (partidos, horarios, cursos). '
						. 'No repitas el título adentro del cuerpo. No inventes fechas, horarios ni lugares que no te hayan dado: '
						. 'si un dato falta, escribí que está a confirmar.'
						. ( class_exists( 'Cead_Acad_Article_Format' )
							? Cead_Acad_Article_Format::templates_hint( array_values( $cats ) )
							: '' ),
				],
			];
			if ( $cats ) {
				$props['categoria'] = [
					'type'        => 'string',
					'enum'        => array_values( $cats ),
					'description' => 'Categoría del artículo. Elegí la que mejor corresponda al tema entre: '
						. implode( ', ', $cats ) . '. Si ninguna encaja, omitilo.',
				];
			}
			if ( $es_dir ) {
				$props['redes'] = [
					'type'        => 'boolean',
					'description' => 'true solo si la persona pidió explícitamente publicarlo también en las redes sociales del colegio.',
				];
			}
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'crear_articulo',
					'description' => 'Proponer la publicación de un artículo en el sitio web. NO se publica hasta que la persona lo apruebe.'
						. ( $es_dir ? ' Esta persona además puede pedir que se replique en las redes sociales del colegio.' : '' )
						. ' Si el mensaje vino con una foto, se adjunta sola: no hace falta pasarla como argumento.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => $props,
						'required'   => [ 'titulo', 'contenido' ],
					],
				],
			];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_schedule' ) ) {
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'crear_evento',
					'description' => 'Proponer la creación de un evento en el calendario. NO se crea hasta que la persona lo apruebe.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'titulo' => [ 'type' => 'string', 'description' => 'Título del evento.' ],
							'fecha'  => [ 'type' => 'string', 'description' => 'Fecha y hora (formato dd/mm/aaaa hh:mm o lenguaje natural).' ],
						],
						'required'   => [ 'titulo', 'fecha' ],
					],
				],
			];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_invitations' ) ) {
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'crear_invitacion',
					'description' => 'Proponer la creación de una invitación para sumar usuarios nuevos. SOLO admite los roles alumno, delegado o profe (nunca dirección ni secretaría). Genera UN SOLO link reutilizable la cantidad de veces indicada. NO se crea hasta que la persona lo apruebe; al confirmar devuelve el link para compartir.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'rol'      => [ 'type' => 'string', 'description' => "Rol del nuevo usuario: solo 'alumno', 'delegado' o 'profe'." ],
							'cantidad' => [ 'type' => 'integer', 'description' => 'Cuántas veces se puede usar el link, es decir cuántas personas podrán registrarse con él (opcional, por defecto 1). Es UN solo link reutilizable, no varios links.' ],
						],
						'required'   => [ 'rol' ],
					],
				],
			];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_record_grade' ) ) {
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'cargar_nota',
					'description' => sprintf(
						'Proponer la carga de una calificación a un alumno. NO se guarda hasta que la persona lo apruebe. Si ya hay nota para ese alumno, materia y periodo, se actualiza. La escala del colegio va de 0 a %s. Si la persona da un PORCENTAJE ("sacó 75%%") o un PUNTAJE ("45 de 60"), NO lo conviertas vos: mandalo en "porcentaje" o en "puntaje" y "puntaje_total", que el sistema calcula la nota.',
						Cead_Acad_Grades_Writer::fmt( Cead_Acad_Grades_Writer::score_max() )
					),
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'alumno'        => [ 'type' => 'string', 'description' => 'Nombre, apellido o documento del alumno.' ],
							'materia'       => [ 'type' => 'string', 'description' => 'Nombre de la materia.' ],
							'periodo'       => [ 'type' => 'string', 'description' => 'Periodo: 1, 2, 3, 4 o Final.' ],
							'nota'          => [ 'type' => 'number', 'description' => 'Calificación ya en la escala del colegio.' ],
							'porcentaje'    => [ 'type' => 'number', 'description' => 'Porcentaje de logro (0 a 100), si lo dieron así en vez de la nota.' ],
							'puntaje'       => [ 'type' => 'number', 'description' => 'Puntos obtenidos, si lo dieron como "X de Y".' ],
							'puntaje_total' => [ 'type' => 'number', 'description' => 'Puntos totales de la evaluación.' ],
							'curso'         => [ 'type' => 'string', 'description' => 'Curso. Solo hace falta si la persona tiene más de un curso a cargo.' ],
							'comentario'    => [ 'type' => 'string', 'description' => 'Observación opcional.' ],
						],
						'required'   => [ 'alumno', 'materia', 'periodo' ],
					],
				],
			];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_view_metrics' ) ) {
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'panorama',
					'description' => 'Mostrar el panorama del colegio: uso del bot, reportes y sugerencias pendientes. Solo lectura.',
					'parameters'  => [ 'type' => 'object', 'properties' => (object) [] ],
				],
			];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_view_course_grades' ) ) {
			$tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => 'ver_notas_curso',
					'description' => 'Mostrar las calificaciones ya cargadas de un curso (opcionalmente de una materia o periodo). Solo lectura.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'curso'   => [ 'type' => 'string', 'description' => 'Curso. Solo si tiene más de uno a cargo.' ],
							'materia' => [ 'type' => 'string', 'description' => 'Filtrar por materia (opcional).' ],
							'periodo' => [ 'type' => 'string', 'description' => 'Filtrar por periodo (opcional).' ],
						],
					],
				],
			];
		}
		return $tools;
	}

	/**
	 * Resuelve a qué curso se refiere la persona, dentro de los que puede tocar.
	 * Si tiene uno solo, no hace falta que lo nombre.
	 *
	 * @return array{status:string,id:int,options:array} status: ok|need|ambiguous|none
	 */
	private function resolve_course_arg( $uid, $hint ) {
		$scope = $this->courses_scope_for( $uid );
		if ( null === $scope ) {
			$scope = array_map( 'intval', array_keys( cead_acad_courses_for_select() ) );
		}
		$scope = array_values( array_filter( array_map( 'intval', (array) $scope ) ) );
		if ( ! $scope ) { return [ 'status' => 'none', 'id' => 0, 'options' => [] ]; }

		$hint = trim( (string) $hint );
		if ( '' === $hint ) {
			return ( 1 === count( $scope ) )
				? [ 'status' => 'ok', 'id' => (int) $scope[0], 'options' => $scope ]
				: [ 'status' => 'need', 'id' => 0, 'options' => $scope ];
		}

		$people = [];
		foreach ( $scope as $cid ) {
			$people[] = [ 'id' => (int) $cid, 'name' => (string) get_the_title( $cid ), 'doc' => '' ];
		}
		$hit = Cead_Acad_Grades_Writer::pick_by_name( $people, $hint );
		if ( 'exact' === $hit['status'] ) {
			return [ 'status' => 'ok', 'id' => (int) $hit['matches'][0]['id'], 'options' => $scope ];
		}
		if ( 'ambiguous' === $hit['status'] ) {
			return [ 'status' => 'ambiguous', 'id' => 0, 'options' => array_column( $hit['matches'], 'id' ) ];
		}
		return [ 'status' => 'none', 'id' => 0, 'options' => $scope ];
	}

	/** Lista corta de cursos para repreguntar. */
	private function course_options_text( $ids ) {
		$t = $this->course_titles( $ids, 8 );
		return $t ? ( '*' . implode( '*, *', $t ) . '*' ) : '';
	}

	/** Arma la propuesta de una acción de staff y la deja a la espera de aprobación. */
	private function propose_staff_action( $phone, $action, $args, $reply, $identity, $media = null ) {
		$this->ia_turn = true; // propuesta conversacional → mensaje nuevo
		$uid = (int) ( $identity['user_id'] ?? 0 );

		if ( $action === 'enviar_comunicado' ) {
			if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast' ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$mensaje = trim( (string) ( $args['mensaje'] ?? '' ) );
			$aud     = (string) ( $args['audiencia'] ?? '' );
			$aud     = in_array( $aud, [ 'students', 'staff', 'all' ], true ) ? $aud : 'students';
			if ( $aud === 'all' && ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast_all' ) ) {
				$aud = 'students';
			}
			if ( $reply !== '' ) { $this->send( $phone, $reply ); }
			if ( $mensaje === '' ) {
				$this->send( $phone, __( '¿Qué querés que diga el comunicado?', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			// Si mandó una foto junto con el pedido, se guarda ahora para poder
			// reenviarla al aceptar (igual que en el flujo del menú numérico).
			$image        = $media ? $this->store_image( $media ) : null;
			$image_failed = $media && ! $image;

			$labels = [ 'students' => __( 'Alumnado', 'cead-acad' ), 'staff' => __( 'Personal', 'cead-acad' ), 'all' => __( 'Todos', 'cead-acad' ) ];
			$count  = (int) $this->broadcaster->count_for( $aud );
			$this->store->set_state( $phone, 'ia_staff_confirm', [ 'kind' => 'comunicado', 'mensaje' => $mensaje, 'audiencia' => $aud, 'image' => $image ] );
			$image_note = $image ? "\n📎 " . __( 'Con imagen adjunta.', 'cead-acad' ) : ( $image_failed ? "\n" . $this->m( 'image_attach_failed' ) : '' );
			$this->send(
				$phone,
				sprintf(
					/* translators: 1: audiencia, 2: cantidad de destinatarios, 3: texto del comunicado, 4: nota de imagen adjunta */
					__( "📢 *Comunicado* — propuesta de CEADI\nPara: *%1\$s* (%2\$d)\n────────\n%3\$s\n────────%4\$s\n\n*1.* ✅ Aceptar y enviar\n*2.* ✏️ Editar (decime el cambio)\n*3.* ❌ Cancelar", 'cead-acad' ),
					$labels[ $aud ],
					$count,
					$mensaje,
					$image_note
				),
				'ia_staff_propose'
			);
			return true;
		}

		if ( $action === 'crear_articulo' ) {
			if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_articles' ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$titulo    = trim( (string) ( $args['titulo'] ?? '' ) );
			$contenido = trim( (string) ( $args['contenido'] ?? '' ) );
			if ( $titulo === '' || $contenido === '' ) {
				$this->send( $phone, __( '¿Qué título y qué contenido querés para el artículo?', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			// El pedido de redes solo vale si viene del número del director/a.
			// Se revalida acá aunque la herramienta no se le haya ofrecido: el
			// modelo podría inventar el argumento igual.
			$redes = ! empty( $args['redes'] ) && $this->is_director_phone( $phone );
			$image = $media ? $this->store_image( $media ) : null;
			$cat   = $this->resolve_category( $args['categoria'] ?? '' );

			if ( $reply !== '' ) { $this->send( $phone, $reply ); }
			$this->store->set_state( $phone, 'ia_staff_confirm', [
				'kind'      => 'articulo',
				'titulo'    => $titulo,
				'contenido' => $contenido,
				'redes'     => $redes,
				'image'     => $image,
				'categoria' => $cat,
			] );
			$extra = $redes
				? "\n📣 " . __( 'Además se va a publicar en las redes del colegio.', 'cead-acad' )
				: '';
			if ( $cat ) {
				$cats   = $this->article_categories();
				$extra .= "\n🏷️ " . sprintf( __( 'Categoría: %s', 'cead-acad' ), $cats[ $cat ] ?? '' );
			}
			if ( $image )                    { $extra .= "\n📎 " . __( 'Con imagen destacada.', 'cead-acad' ); }
			elseif ( $media && ! $image )    { $extra .= "\n" . $this->m( 'image_attach_failed' ); }
			$this->send(
				$phone,
				sprintf(
					/* translators: 1: título, 2: extracto del contenido, 3: notas extra */
					__( "📝 *Artículo* — propuesta de CEADI\n*%1\$s*\n────────\n%2\$s\n────────%3\$s\n\n*1.* ✅ Publicar\n*2.* ✏️ Editar (decime el cambio)\n*3.* ❌ Cancelar", 'cead-acad' ),
					$titulo,
					self::preview_text( $contenido ),
					$extra
				),
				'ia_staff_propose'
			);
			return true;
		}

		if ( $action === 'crear_invitacion' ) {
			if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_invitations' ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$role = $this->invite_role_from_text( (string) ( $args['rol'] ?? '' ) );
			$uses = max( 1, min( 500, (int) ( $args['cantidad'] ?? 1 ) ) );
			if ( $reply !== '' ) { $this->send( $phone, $reply ); }
			if ( $role === '' ) {
				$this->send( $phone, __( '¿Para qué rol creo la invitación? Puede ser *alumno*, *delegado* o *profe*.', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$label = $this->invite_role_label( $role );
			$usos_txt = ( $uses > 1 )
				? sprintf( /* translators: %d: usos */ __( 'un solo link para *%d* usos', 'cead-acad' ), $uses )
				: __( 'un link de un solo uso', 'cead-acad' );
			$this->store->set_state( $phone, 'ia_staff_confirm', [ 'kind' => 'invitacion', 'role' => $role, 'uses' => $uses ] );
			$this->send(
				$phone,
				sprintf(
					/* translators: 1: rol, 2: descripción de usos del link */
					__( "👤 *Invitación* — propuesta de CEADI\nRol: *%1\$s*\n%2\$s\n\n*1.* ✅ Aceptar y crear\n*2.* ✏️ Editar (decime el cambio)\n*3.* ❌ Cancelar", 'cead-acad' ),
					$label,
					ucfirst( $usos_txt )
				),
				'ia_staff_propose'
			);
			return true;
		}

		if ( $action === 'cargar_nota' ) {
			if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_record_grade' ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			if ( $reply !== '' ) { $this->send( $phone, $reply ); }

			// 1) Curso (si tiene uno solo, no hace falta que lo nombre).
			$c = $this->resolve_course_arg( $uid, $args['curso'] ?? '' );
			if ( 'ok' !== $c['status'] ) {
				$opts = $this->course_options_text( $c['options'] );
				$this->send( $phone, $opts
					? sprintf( /* translators: %s: lista de cursos */ __( '¿En qué curso? Puede ser: %s', 'cead-acad' ), $opts )
					: __( 'No tenés cursos asignados, así que no puedo cargar notas.', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$course_id = (int) $c['id'];
			if ( ! Cead_Acad_Grades_Writer::user_can_grade_course( $uid, $course_id ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}

			// 2) Alumno, dentro de ese curso.
			$stu = Cead_Acad_Grades_Writer::match_student_in_course( $course_id, $args['alumno'] ?? '' );
			if ( 'ambiguous' === $stu['status'] ) {
				$names = implode( ', ', array_map( static fn( $m ) => '*' . $m['name'] . '*', $stu['matches'] ) );
				$this->send( $phone, sprintf( /* translators: %s: nombres */ __( '¿A cuál de estos? %s', 'cead-acad' ), $names ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			if ( 'exact' !== $stu['status'] ) {
				$this->send( $phone, sprintf(
					/* translators: 1: nombre buscado, 2: curso */
					__( 'No encontré a *%1$s* en %2$s. ¿Me pasás el nombre como figura en el sistema?', 'cead-acad' ),
					trim( (string) ( $args['alumno'] ?? '' ) ),
					get_the_title( $course_id )
				) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$student = $stu['matches'][0];

			// 3) Materia (si no existe, se avisa en la tarjeta antes de crearla).
			$materia_raw = trim( (string) ( $args['materia'] ?? '' ) );
			$sub         = Cead_Acad_Grades_Writer::match_subject( $materia_raw, $course_id, false );
			if ( 'ambiguous' === $sub['status'] ) {
				$names = implode( ', ', array_map( static fn( $m ) => '*' . $m['name'] . '*', $sub['matches'] ) );
				$this->send( $phone, sprintf( /* translators: %s: materias */ __( '¿Qué materia exactamente? %s', 'cead-acad' ), $names ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			if ( '' === $materia_raw ) {
				$this->send( $phone, __( '¿De qué materia es la nota?', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$subject_id   = (int) $sub['term_id'];
			$subject_new  = ( 0 === $subject_id );
			$subject_name = $subject_new ? $materia_raw : (string) $sub['matches'][0]['name'];

			// 4) Periodo y nota.
			$period = Cead_Acad_Grades_Writer::norm_period( $args['periodo'] ?? '' );
			if ( '' === $period ) {
				$this->send( $phone, __( '¿De qué periodo? (1, 2, 3, 4 o Final)', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			// La nota puede venir directa, como porcentaje o como «X de Y».
			$score  = $args['nota'] ?? null;
			$origen = '';
			$W      = 'Cead_Acad_Grades_Writer';

			$pts   = $args['puntaje'] ?? null;
			$total = $args['puntaje_total'] ?? null;
			$pct   = $args['porcentaje'] ?? null;

			if ( ( null === $score || '' === $score ) && null !== $pts && null !== $total ) {
				$calc = $W::points_to_percent( $pts, $total );
				if ( null === $calc ) {
					$this->send( $phone, __( 'Ese puntaje no cierra (revisá que los puntos obtenidos no superen el total).', 'cead-acad' ) );
					$this->store->set_state( $phone, 'ia_home' );
					return true;
				}
				$pct = $calc;
				/* translators: 1: puntos obtenidos, 2: puntos totales, 3: porcentaje */
				$origen = sprintf( __( 'de %1$s/%2$s = %3$s%%', 'cead-acad' ), $W::fmt( $pts ), $W::fmt( $total ), $W::fmt( $calc ) );
			}
			if ( ( null === $score || '' === $score ) && null !== $pct && is_numeric( $pct ) ) {
				$score = $W::percent_to_score( $pct );
				if ( null === $score ) {
					$this->send( $phone, __( 'Ese porcentaje no es válido (tiene que ir de 0 a 100).', 'cead-acad' ) );
					$this->store->set_state( $phone, 'ia_home' );
					return true;
				}
				if ( '' === $origen ) {
					/* translators: %s: porcentaje */
					$origen = sprintf( __( 'de %s%%', 'cead-acad' ), $W::fmt( $pct ) );
				}
			}

			if ( null === $score || '' === $score || ! is_numeric( $score ) ) {
				$this->send( $phone, __( '¿Qué nota le pongo? Podés decirme la nota, el porcentaje o el puntaje («45 de 60»).', 'cead-acad' ) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}
			$score = round( (float) $score, 2 );
			$max   = $W::score_max();
			if ( $score < 0 || $score > $max ) {
				$this->send( $phone, sprintf(
					/* translators: %s: nota máxima de la escala */
					__( 'Esa nota está fuera de la escala del colegio (0 a %s). Si me estás pasando un porcentaje, decímelo y lo convierto.', 'cead-acad' ),
					$W::fmt( $max )
				) );
				$this->store->set_state( $phone, 'ia_home' );
				return true;
			}

			$prev  = $subject_id ? $W::find( (int) $student['id'], $course_id, $subject_id, $period ) : null;
			$fmt   = static fn( $n ) => $W::fmt( $n );
			$extra = '';
			if ( '' !== $origen ) {
				$extra .= ' _' . $origen . '_';
			}
			if ( $prev && null !== $prev['score'] ) {
				/* translators: %s: nota anterior */
				$extra .= ' ' . sprintf( __( '(antes: %s)', 'cead-acad' ), $fmt( $prev['score'] ) );
			}
			// Avisos para que un número mal dictado no pase de largo.
			if ( $score < $W::score_pass() ) {
				$extra .= "\n" . __( '⚠️ Con esa nota queda *aplazado/a*.', 'cead-acad' );
			}
			if ( $subject_new ) {
				$extra .= "\n" . __( '⚠️ La materia es nueva, se va a crear.', 'cead-acad' );
			}

			$this->store->set_state( $phone, 'ia_staff_confirm', [
				'kind'         => 'nota',
				'student_id'   => (int) $student['id'],
				'student_name' => (string) $student['name'],
				'course_id'    => $course_id,
				'subject_id'   => $subject_id,
				'subject_name' => $subject_name,
				'subject_new'  => $subject_new ? 1 : 0,
				'period'       => $period,
				'score'        => $score,
				'comments'     => trim( (string) ( $args['comentario'] ?? '' ) ),
			] );
			$this->send(
				$phone,
				sprintf(
					/* translators: 1: alumno, 2: curso, 3: materia, 4: periodo, 5: nota, 6: aclaraciones */
					__( "📝 *Cargar nota* — propuesta de CEADI\nAlumno/a: *%1\$s*\nCurso: *%2\$s*\nMateria: *%3\$s*\nPeriodo: *%4\$s*\nNota: *%5\$s*%6\$s\n\n*1.* ✅ Aceptar y guardar\n*2.* ✏️ Editar (decime el cambio)\n*3.* ❌ Cancelar", 'cead-acad' ),
					$student['name'],
					get_the_title( $course_id ),
					$subject_name,
					$period,
					$fmt( $score ),
					$extra
				),
				'ia_staff_propose'
			);
			return true;
		}

		// crear_evento
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_schedule' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return true;
		}
		$titulo = trim( (string) ( $args['titulo'] ?? '' ) );
		$start  = $this->parse_datetime( trim( (string) ( $args['fecha'] ?? '' ) ) );
		if ( $reply !== '' ) { $this->send( $phone, $reply ); }
		if ( $titulo === '' || $start === null ) {
			$this->send( $phone, __( 'Para el evento necesito *título* y *fecha*. ¿Me los pasás?', 'cead-acad' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return true;
		}
		$human = $this->human_date( substr( $start, 0, 10 ) ) . ' ' . substr( $start, 11, 5 );
		$this->store->set_state( $phone, 'ia_staff_confirm', [ 'kind' => 'evento', 'titulo' => $titulo, 'start' => $start ] );
		$this->send(
			$phone,
			sprintf(
				/* translators: 1: título del evento, 2: fecha y hora */
				__( "📅 *Evento* — propuesta de CEADI\n*%1\$s*\n🗓️ %2\$s\n\n*1.* ✅ Aceptar y crear\n*2.* ✏️ Editar (decime el cambio)\n*3.* ❌ Cancelar", 'cead-acad' ),
				$titulo,
				$human
			),
			'ia_staff_propose'
		);
		return true;
	}

	/** Menú de aprobación de una acción propuesta por la IA. */
	private function ia_staff_confirm( $phone, $lc, $context, $identity, $body = '', $media = null ) {
		$this->ia_turn = true; // resultado conversacional → mensaje nuevo
		$kind = (string) ( $context['kind'] ?? '' );

		list( $opcion, $resto ) = self::parse_confirm_choice( $body !== '' ? $body : $lc );

		// Elegir y explicar el cambio en el mismo mensaje («2 agregale la fecha»)
		// es lo natural, así que el texto que viene después del 2 se toma como la
		// instrucción y se ahorra una vuelta.
		if ( 2 === $opcion ) {
			$this->store->set_state( $phone, 'ia_home' );
			if ( '' !== $resto && $this->ai_try( $phone, $resto, $identity, 'ia_home', $media ) ) {
				return;
			}
			$this->send( $phone, __( '✏️ Dale, decime cómo lo ajusto y te lo propongo de nuevo.', 'cead-acad' ), 'ia_edit' );
			return;
		}

		// En cambio, publicar o descartar con texto pegado es ambiguo («1, pero
		// cambiale el título» no es un sí). Ahí se pregunta, sin perder la
		// propuesta, en vez de adivinar y publicar algo que no era.
		if ( ( 1 === $opcion || 3 === $opcion ) && '' !== $resto ) {
			$this->send( $phone, __( '🤔 No me quedó claro: si querés que lo publique tal cual mandá solo *1*; si querés cambiarle algo, mandá *2* y el cambio.', 'cead-acad' ) );
			return;
		}

		if ( 1 === $opcion ) {
			if ( $kind === 'comunicado' ) { $this->execute_comunicado( $phone, $context, $identity ); }
			elseif ( $kind === 'evento' ) { $this->execute_evento( $phone, $context, $identity ); }
			elseif ( $kind === 'invitacion' ) { $this->execute_invitacion( $phone, $context, $identity ); }
			elseif ( $kind === 'nota' ) { $this->execute_nota( $phone, $context, $identity ); }
			elseif ( $kind === 'planilla' ) { $this->execute_planilla( $phone, $context, $identity ); }
			elseif ( $kind === 'articulo' ) { $this->execute_articulo( $phone, $context, $identity ); }
			else { $this->store->set_state( $phone, 'ia_home' ); }
			return;
		}
		if ( 3 === $opcion ) {
			$this->store->set_state( $phone, 'ia_home' );
			$this->send( $phone, __( '❌ Listo, lo descarté. ¿Algo más?', 'cead-acad' ), 'ia_cancel' );
			return;
		}
		$this->send( $phone, __( 'Elegí *1* (publicar), *2* (editar, y decime el cambio) o *3* (cancelar). La propuesta sigue esperando.', 'cead-acad' ) );
	}

	/**
	 * Interpreta la respuesta a una propuesta.
	 *
	 * Devuelve [ opción, resto ]: la opción es 1, 2, 3 o 0 si no se entendió, y
	 * el resto es lo que se escribió después. Acepta la puntuación con la que
	 * la gente contesta de verdad («2.», «3)», «2 - agregale la foto»), que
	 * antes se rechazaba con un «Elegí 1, 2 o 3» aunque la intención fuera obvia.
	 */
	public static function parse_confirm_choice( $texto ) {
		$t = trim( (string) $texto );
		if ( '' === $t ) { return [ 0, '' ]; }

		if ( preg_match( '/^([123])\s*[\.\)\-–—:,]*\s*(.*)$/su', $t, $m ) ) {
			return [ (int) $m[1], trim( (string) $m[2] ) ];
		}

		$lc = mb_strtolower( $t );
		// Con palabras solo se acepta la palabra sola, salvo «editar», donde lo
		// que sigue es justamente el cambio pedido.
		foreach ( [ 'editar', 'edit', 'cambiar', 'modificar', 'corregir' ] as $w ) {
			if ( $lc === $w ) { return [ 2, '' ]; }
			if ( 0 === mb_strpos( $lc, $w . ' ' ) ) {
				return [ 2, trim( mb_substr( $t, mb_strlen( $w ) ) ) ];
			}
		}
		if ( in_array( $lc, [ 'aceptar', 'acepto', 'si', 'sí', 'ok', 'dale', 'confirmar', 'publicar', 'publicalo', 'publicá' ], true ) ) {
			return [ 1, '' ];
		}
		if ( in_array( $lc, [ 'cancelar', 'cancel', 'no', 'denegar', 'descartar' ], true ) ) {
			return [ 3, '' ];
		}
		return [ 0, $t ];
	}

	/** Ejecuta el comunicado aprobado (re-chequea permisos). */
	private function execute_comunicado( $phone, $context, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$mensaje = (string) ( $context['mensaje'] ?? '' );
		$aud     = (string) ( $context['audiencia'] ?? 'students' );
		$image   = $context['image'] ?? null;
		if ( $aud === 'all' && ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast_all' ) ) {
			$aud = 'students';
		}
		$this->create_broadcast_post( $mensaje, $aud, $image );
		$res = $this->broadcaster->enqueue_for( $mensaje, $aud, $image );
		Cead_Acad_Audit::log( 'wa_broadcast_sent', [
			'user_id' => $uid ?: null,
			'payload' => [ 'target' => $aud, 'total' => (int) ( $res['total'] ?? 0 ), 'con_imagen' => (bool) $image, 'via' => 'ia' ],
		] );
		if ( ! empty( $res['busy'] ) ) {
			$this->send( $phone, $this->m( 'comm_busy' ) );
		} elseif ( empty( $res['queued'] ) ) {
			$this->send( $phone, $this->m( 'comm_empty' ) );
		} else {
			$this->send( $phone, $this->interp( $this->m( 'comm_queued' ), [ 'total' => (int) ( $res['total'] ?? 0 ) ] ), 'broadcast_enqueued' );
		}
		$this->store->set_state( $phone, 'ia_home' );
	}

	/** Crea el evento aprobado (re-chequea permisos). */
	private function execute_evento( $phone, $context, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_schedule' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$start  = (string) ( $context['start'] ?? '' );
		$titulo = (string) ( $context['titulo'] ?? 'Evento' );
		$post_id = wp_insert_post( [
			'post_type'   => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $titulo,
			'post_author' => $uid ?: 0,
		], true );
		if ( is_wp_error( $post_id ) ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		update_post_meta( $post_id, '_cead_acad_event_start', $start );
		update_post_meta( $post_id, '_cead_acad_event_type', 'evento' );
		Cead_Acad_Audiences::set( 'event', $post_id, [ [ 'type' => 'all', 'value' => '*' ] ] );
		$this->send( $phone, $this->m( 'event_saved' ), 'event_created' );
		$this->store->set_state( $phone, 'ia_home' );
	}

	/** Ejecuta la invitación aprobada (re-chequea permisos y restringe el rol). */
	/**
	 * Publica el artículo aprobado (re-chequea permisos y el número).
	 *
	 * Integración con Bit Social: no se llama a su API — se le asigna al post la
	 * categoría que el plugin tiene configurada para auto-publicar. Así la
	 * integración sigue funcionando aunque actualicen el plugin, y si algún día
	 * lo desinstalan el artículo se publica igual en la web, solo que sin
	 * replicarse en redes.
	 */
	private function execute_articulo( $phone, $context, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_articles' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$titulo    = (string) ( $context['titulo'] ?? 'Artículo' );
		$contenido = (string) ( $context['contenido'] ?? '' );
		$image     = $context['image'] ?? null;
		// Se vuelve a validar el número: entre la propuesta y la aprobación
		// pudo cambiar la configuración de quién es el director/a.
		$redes = ! empty( $context['redes'] ) && $this->is_director_phone( $phone );

		// La IA redacta en Markdown; WordPress no lo entiende y publicaba los
		// asteriscos y las barritas de las tablas tal cual. Se maqueta acá.
		$html = class_exists( 'Cead_Acad_Article_Format' )
			? Cead_Acad_Article_Format::to_html( $contenido )
			: $contenido;

		$pid = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => $titulo,
			'post_content' => $html,
			'post_author'  => $uid ?: 0,
		], true );
		if ( is_wp_error( $pid ) ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		if ( $image && ! empty( $image['attachment_id'] ) ) {
			set_post_thumbnail( $pid, (int) $image['attachment_id'] );
		}
		// La categoría temática se revalida contra las que existen ahora: entre
		// la propuesta y la aprobación pudieron borrarla.
		$tema = (int) ( $context['categoria'] ?? 0 );
		if ( $tema && ! isset( $this->article_categories()[ $tema ] ) ) { $tema = 0; }
		if ( $tema ) {
			wp_set_post_categories( $pid, [ $tema ], true );
		}
		if ( $redes ) {
			$cat = $this->social_category_id();
			if ( $cat ) {
				// append = true: no pisa la categoría por defecto del sitio.
				wp_set_post_categories( $pid, [ $cat ], true );
			}
		}
		Cead_Acad_Audit::log( 'wa_article_published', [
			'user_id'     => $uid ?: null,
			'entity_type' => 'post',
			'entity_id'   => $pid,
			'payload'     => [ 'redes' => $redes, 'categoria' => $tema ?: null, 'con_imagen' => (bool) $image, 'via' => 'ia' ],
		] );
		$this->send(
			$phone,
			$this->interp( $this->m( 'article_published' ), [ 'url' => get_permalink( $pid ) ] )
				. ( $redes ? "\n📣 " . __( 'Enviado también a las redes del colegio.', 'cead-acad' ) : '' ),
			'article_published'
		);
		$this->store->set_state( $phone, 'ia_home' );
	}

	private function execute_invitacion( $phone, $context, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_invitations' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$role = (string) ( $context['role'] ?? '' );
		$uses = max( 1, min( 500, (int) ( $context['uses'] ?? 1 ) ) );
		// Cinturón de seguridad: nunca más allá de los tres roles permitidos por chat.
		if ( ! in_array( $role, [ 'cead_acad_student', 'cead_acad_delegate', 'cead_acad_teacher' ], true ) ) {
			$this->send( $phone, __( 'Por seguridad, por acá solo puedo crear invitaciones de alumno, delegado o profe.', 'cead-acad' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		if ( ! class_exists( 'Cead_Acad_Invitations' ) ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		// Que el audit log e «invited_by» reflejen a quien la pidió.
		wp_set_current_user( $uid );
		$tokens = Cead_Acad_Invitations::create( [
			'role'     => $role,
			'max_uses' => $uses, // un solo link reutilizable esa cantidad de veces
		] );
		if ( empty( $tokens ) ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$link  = Cead_Acad_Invitations::registration_url( $tokens[0] );
		$label = $this->invite_role_label( $role );
		// Vencimiento según el rol (Delegado/a = 1 año; resto ~3 años).
		$venc  = ( Cead_Acad_Invitations::default_expires_days( $role ) <= 366 )
			? __( '1 año', 'cead-acad' )
			: __( '~3 años', 'cead-acad' );
		$usos  = ( $uses > 1 )
			? sprintf( /* translators: %d: usos */ __( 'sirve para %d registros', 'cead-acad' ), $uses )
			: __( 'de un solo uso', 'cead-acad' );
		$msg = sprintf(
			/* translators: 1: rol, 2: usos, 3: vencimiento, 4: link */
			__( "✅ Link de invitación de *%1\$s* creado (%2\$s, vence en %3\$s). Compartilo:\n%4\$s", 'cead-acad' ),
			$label,
			$usos,
			$venc,
			$link
		);
		$this->send( $phone, $msg, 'invitation_created' );
		$this->store->set_state( $phone, 'ia_home' );
	}

	/* ------------------------------------------- planillas de notas (Excel) */

	/**
	 * Llegó una planilla. Se lee, se interpreta y se propone la carga; el
	 * archivo no se archiva y la grilla queda en memoria un rato para poder
	 * confirmar y hacer preguntas sobre ella.
	 */
	private function sheet_received( $phone, $media, $caption, $identity ) {
		$this->ia_turn = true;
		$uid = (int) ( $identity['user_id'] ?? 0 );

		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_record_grade' ) ) {
			$this->send( $phone, __( 'Recibí la planilla, pero no tenés permiso para cargar notas.', 'cead-acad' ), 'sheet_denied' );
			return;
		}
		// Los formatos viejos (.xls) se atajan antes, en process_message().
		$read = Cead_Acad_Grades_Sheet::read( $media );
		if ( is_wp_error( $read ) ) {
			$this->send( $phone, '📄 ' . $read->get_error_message(), 'sheet_error' );
			return;
		}
		$rows = $read['rows'];
		if ( count( $rows ) < 2 ) {
			$this->send( $phone, __( 'La planilla parece vacía o tiene una sola fila.', 'cead-acad' ), 'sheet_error' );
			return;
		}

		// Curso: del caption si lo mencionó, o el único que tenga a cargo.
		$c = $this->resolve_course_arg( $uid, $caption );
		if ( 'ok' !== $c['status'] ) {
			$c = $this->resolve_course_arg( $uid, '' );
		}

		$interp = $this->sheet_interpret( $rows, $caption, $identity, (int) $c['id'] );
		if ( ! is_array( $interp ) ) {
			$this->send( $phone, __( 'Pude leer la planilla pero no entendí cómo está armada. ¿Me decís qué columna tiene los nombres y cuál las notas?', 'cead-acad' ), 'sheet_error' );
			return;
		}

		// El curso que dedujo la IA manda si la persona puede tocarlo.
		$course_id = (int) ( $interp['course_id'] ?? 0 ) ?: (int) $c['id'];
		if ( ! $course_id || ! Cead_Acad_Grades_Writer::user_can_grade_course( $uid, $course_id ) ) {
			$opts = $this->course_options_text( $c['options'] );
			$this->send( $phone, $opts
				? sprintf( /* translators: %s: cursos */ __( 'Leí la planilla. ¿De qué curso es? Puede ser: %s', 'cead-acad' ), $opts )
				: __( 'Leí la planilla, pero no tenés cursos asignados.', 'cead-acad' ), 'sheet_course' );
			Cead_Acad_Grades_Sheet::remember( $phone, [ 'rows' => $rows, 'caption' => $caption ] );
			return;
		}

		$built = Cead_Acad_Grades_Sheet::build_rows( $rows, $interp, $course_id );

		// La planilla queda disponible un rato para confirmar y preguntar sobre ella.
		Cead_Acad_Grades_Sheet::remember( $phone, [
			'rows'      => $rows,
			'caption'   => $caption,
			'course_id' => $course_id,
			'mapping'   => $interp,
		] );

		if ( ! $built['ok'] ) {
			$this->send( $phone, sprintf(
				/* translators: %d: filas leídas */
				__( "📄 Leí %d filas pero no pude emparejar a ningún alumno del curso. ¿La planilla es de otro curso, o los nombres están escritos distinto?", 'cead-acad' ),
				$built['total']
			), 'sheet_nomatch' );
			return;
		}

		$W       = 'Cead_Acad_Grades_Writer';
		$materia = (string) ( $interp['materia'] ?? '' );
		$periodo = $W::norm_period( $interp['periodo'] ?? '' );

		$resumen = [];
		foreach ( array_slice( $built['ok'], 0, 6 ) as $r ) {
			$resumen[] = '• ' . $r['nombre'] . ': *' . $W::fmt( $r['score'] ) . '*';
		}
		if ( count( $built['ok'] ) > 6 ) {
			/* translators: %d: alumnos restantes */
			$resumen[] = sprintf( __( '…y %d más.', 'cead-acad' ), count( $built['ok'] ) - 6 );
		}

		$avisos = '';
		if ( $built['fallidas'] ) {
			$lst = [];
			foreach ( array_slice( $built['fallidas'], 0, 5 ) as $f ) {
				$lst[] = '· ' . $f['nombre'] . ' (' . $f['motivo'] . ')';
			}
			/* translators: %d: filas con problema */
			$avisos = "\n\n⚠️ " . sprintf( __( '%d fila/s que voy a saltear:', 'cead-acad' ), count( $built['fallidas'] ) )
				. "\n" . implode( "\n", $lst );
		}
		if ( (int) $read['sheets'] > 1 ) {
			/* translators: %d: cantidad de hojas */
			$avisos .= "\n\n" . sprintf( __( 'ℹ️ El archivo tiene %d hojas; leí la primera.', 'cead-acad' ), (int) $read['sheets'] );
		}
		$aplazados = count( array_filter( $built['ok'], static fn( $r ) => $r['score'] < $W::score_pass() ) );
		if ( $aplazados > 0 ) {
			/* translators: %d: cantidad de aplazados */
			$avisos .= "\n\n" . sprintf( __( '📉 Quedan %d aplazado/s.', 'cead-acad' ), $aplazados );
		}

		if ( '' === $materia || '' === $periodo ) {
			$this->send( $phone, sprintf(
				/* translators: 1: cantidad de alumnos, 2: curso */
				__( "📄 Leí la planilla: *%1\$d alumnos* de *%2\$s*.\nMe falta saber **de qué materia y periodo** es. ¿Me lo decís?", 'cead-acad' ),
				count( $built['ok'] ),
				get_the_title( $course_id )
			), 'sheet_need_meta' );
			return;
		}

		$this->store->set_state( $phone, 'ia_staff_confirm', [
			'kind'      => 'planilla',
			'course_id' => $course_id,
			'materia'   => $materia,
			'periodo'   => $periodo,
			'filas'     => $built['ok'],
		] );
		$this->send(
			$phone,
			sprintf(
				/* translators: 1: cantidad, 2: curso, 3: materia, 4: periodo, 5: muestra, 6: avisos */
				__( "📄 *Cargar planilla* — propuesta de CEADI\nCurso: *%2\$s*\nMateria: *%3\$s* · Periodo: *%4\$s*\nAlumnos a cargar: *%1\$d*\n\n%5\$s%6\$s\n\n*1.* ✅ Aceptar y cargar\n*2.* ✏️ Corregir (decime qué cambio)\n*3.* ❌ Cancelar", 'cead-acad' ),
				count( $built['ok'] ),
				get_the_title( $course_id ),
				$materia,
				$periodo,
				implode( "\n", $resumen ),
				$avisos
			),
			'sheet_propose'
		);
	}

	/**
	 * Le pide al modelo que lea la grilla y diga cómo está armada. Devuelve el
	 * mapeo o null. Sin IA no hay interpretación posible de una planilla libre.
	 */
	private function sheet_interpret( $rows, $caption, $identity, $course_hint ) {
		if ( ! $this->ai_enabled() ) { return null; }

		$cursos = [];
		$scope  = $this->courses_scope_for( (int) ( $identity['user_id'] ?? 0 ) );
		foreach ( $this->course_titles( null === $scope ? [] : $scope, 12 ) as $t ) {
			$cursos[] = $t;
		}

		$prompt = "Mirá esta planilla de notas de un colegio y decime cómo está armada.\n\n"
			. "PLANILLA (cada línea es una fila, las celdas van separadas por |):\n"
			. Cead_Acad_Grades_Sheet::to_text( (array) $rows ) . "\n\n"
			. ( '' !== trim( (string) $caption ) ? "LO QUE ESCRIBIÓ EL DOCENTE: " . trim( (string) $caption ) . "\n" : '' )
			. ( $cursos ? 'CURSOS QUE TIENE A CARGO: ' . implode( ', ', $cursos ) . "\n" : '' )
			. "\nRespondé SOLO un JSON con estas claves:\n"
			. '{"fila_encabezado":N,"col_alumno":N,"col_nota":N,"materia":"","periodo":"","curso":"","formato":"nota|porcentaje|puntaje","puntaje_total":null}' . "\n"
			. "Las columnas y filas se numeran desde 1. \"fila_encabezado\" es la fila con los títulos (0 si no hay).\n"
			. "\"col_nota\" es la columna con la calificación final; si hay varias evaluaciones, elegí la de la nota o promedio final.\n"
			. "\"formato\": \"nota\" si ya son notas del colegio, \"porcentaje\" si son 0-100, \"puntaje\" si son puntos sobre un total (poné el total en \"puntaje_total\").\n"
			. "Si la materia, el periodo o el curso no aparecen, dejalos en \"\". No inventes.";

		$res = Cead_Acad_WA_AI::route( $prompt, '', '', [], $this->ai_user_context( $identity ) );
		$txt = is_array( $res ) ? (string) ( $res['reply'] ?? '' ) : '';
		if ( '' === $txt ) { return null; }

		// El modelo suele envolver el JSON en texto o en un bloque de código.
		if ( preg_match( '/\{.*\}/s', $txt, $m ) ) { $txt = $m[0]; }
		$data = json_decode( $txt, true );
		if ( ! is_array( $data ) ) { return null; }

		$course_id = (int) $course_hint;
		if ( ! empty( $data['curso'] ) ) {
			$c = $this->resolve_course_arg( (int) ( $identity['user_id'] ?? 0 ), (string) $data['curso'] );
			if ( 'ok' === $c['status'] ) { $course_id = (int) $c['id']; }
		}

		return [
			'fila_encabezado' => max( 0, (int) ( $data['fila_encabezado'] ?? 1 ) ),
			'col_alumno'      => max( 1, (int) ( $data['col_alumno'] ?? 1 ) ),
			'col_nota'        => max( 1, (int) ( $data['col_nota'] ?? 2 ) ),
			'materia'         => sanitize_text_field( (string) ( $data['materia'] ?? '' ) ),
			'periodo'         => sanitize_text_field( (string) ( $data['periodo'] ?? '' ) ),
			'formato'         => in_array( $data['formato'] ?? '', [ 'nota', 'porcentaje', 'puntaje' ], true ) ? $data['formato'] : 'nota',
			'puntaje_total'   => isset( $data['puntaje_total'] ) && is_numeric( $data['puntaje_total'] ) ? (float) $data['puntaje_total'] : null,
			'course_id'       => $course_id,
		];
	}

	/** Carga las notas de la planilla aprobada. */
	private function execute_planilla( $phone, $context, $identity ) {
		$uid       = (int) ( $identity['user_id'] ?? 0 );
		$course_id = (int) ( $context['course_id'] ?? 0 );

		if ( ! Cead_Acad_Grades_Writer::user_can_grade_course( $uid, $course_id ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		$sub = Cead_Acad_Grades_Writer::match_subject( (string) ( $context['materia'] ?? '' ), $course_id, true );
		$subject_id = (int) $sub['term_id'];
		if ( ! $subject_id ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}

		$period = (string) ( $context['periodo'] ?? '' );
		$hechas = 0;
		$fallos = 0;
		foreach ( (array) ( $context['filas'] ?? [] ) as $f ) {
			$res = Cead_Acad_Grades_Writer::record( [
				'student_user_id' => (int) $f['student_id'],
				'course_id'       => $course_id,
				'subject_term_id' => $subject_id,
				'period'          => $period,
				'score'           => $f['score'],
				'recorded_by'     => $uid,
			] );
			if ( is_wp_error( $res ) ) { $fallos++; } else { $hechas++; }
		}

		Cead_Acad_Audit::log( 'grades_sheet_imported', [
			'user_id'     => $uid,
			'entity_type' => 'course',
			'entity_id'   => $course_id,
			'payload'     => [ 'source' => 'whatsapp', 'materia' => $subject_id, 'periodo' => $period, 'ok' => $hechas, 'fallos' => $fallos ],
		] );

		$msg = sprintf(
			/* translators: 1: cantidad cargada, 2: materia, 3: periodo */
			__( '✅ Cargué *%1$d notas* en %2$s (periodo %3$s). Ya se ven en los boletines.', 'cead-acad' ),
			$hechas,
			(string) ( $context['materia'] ?? '' ),
			$period
		);
		if ( $fallos ) {
			/* translators: %d: filas que fallaron */
			$msg .= "\n" . sprintf( __( '⚠️ %d no se pudieron guardar.', 'cead-acad' ), $fallos );
		}
		$this->send( $phone, $msg, 'sheet_imported' );
		$this->store->set_state( $phone, 'ia_home' );
	}

	/** Guarda la nota aprobada (re-chequea permisos y que el alumno sea del curso). */
	private function execute_nota( $phone, $context, $identity ) {
		$uid       = (int) ( $identity['user_id'] ?? 0 );
		$course_id = (int) ( $context['course_id'] ?? 0 );
		$student   = (int) ( $context['student_id'] ?? 0 );

		if ( ! Cead_Acad_Grades_Writer::user_can_grade_course( $uid, $course_id ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}
		// El alumno tiene que seguir perteneciendo al curso (pudo cambiar entre
		// la propuesta y la aprobación).
		$in_course = array_map( 'intval', (array) Cead_Acad_Courses_Roster::users_in_course( $course_id ) );
		if ( ! in_array( $student, $in_course, true ) ) {
			$this->send( $phone, __( 'Ese alumno ya no figura en el curso, así que no cargué la nota.', 'cead-acad' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}

		// La materia nueva recién se crea acá, con la aprobación ya dada.
		$subject_id = (int) ( $context['subject_id'] ?? 0 );
		if ( ! $subject_id && ! empty( $context['subject_new'] ) ) {
			$made       = Cead_Acad_Grades_Writer::match_subject( (string) ( $context['subject_name'] ?? '' ), $course_id, true );
			$subject_id = (int) $made['term_id'];
		}
		if ( ! $subject_id ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}

		$res = Cead_Acad_Grades_Writer::record( [
			'student_user_id' => $student,
			'course_id'       => $course_id,
			'subject_term_id' => $subject_id,
			'period'          => (string) ( $context['period'] ?? '' ),
			'score'           => $context['score'] ?? null,
			'comments'        => (string) ( $context['comments'] ?? '' ),
			'recorded_by'     => $uid,
		] );

		if ( is_wp_error( $res ) ) {
			$this->send( $phone, '⚠️ ' . $res->get_error_message() );
			$this->store->set_state( $phone, 'ia_home' );
			return;
		}

		$fmt = static fn( $n ) => rtrim( rtrim( number_format( (float) $n, 2, ',', '' ), '0' ), ',' );
		$this->send(
			$phone,
			sprintf(
				/* translators: 1: nota, 2: alumno, 3: materia, 4: periodo */
				__( '✅ Nota *%1$s* guardada para *%2$s* en %3$s (periodo %4$s). Ya se ve en su boletín.', 'cead-acad' ),
				$fmt( $context['score'] ?? 0 ),
				(string) ( $context['student_name'] ?? '' ),
				(string) ( $context['subject_name'] ?? '' ),
				(string) ( $context['period'] ?? '' )
			),
			'grade_recorded'
		);
		$this->store->set_state( $phone, 'ia_home' );
	}

	/** Notas ya cargadas de un curso (lectura para docentes y dirección). */
	private function show_notas_curso( $phone, $identity, $args = [] ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_view_course_grades' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			return;
		}
		$c = $this->resolve_course_arg( $uid, $args['curso'] ?? '' );
		if ( 'ok' !== $c['status'] ) {
			$opts = $this->course_options_text( $c['options'] );
			$this->send( $phone, $opts
				? sprintf( /* translators: %s: cursos */ __( '¿De qué curso? Puede ser: %s', 'cead-acad' ), $opts )
				: __( 'No tenés cursos asignados.', 'cead-acad' ) );
			return;
		}
		$course_id = (int) $c['id'];
		$rows      = Cead_Acad_Grades_Db::for_course( $course_id );
		if ( ! $rows ) {
			$this->send( $phone, sprintf(
				/* translators: %s: curso */
				__( 'Todavía no hay notas cargadas en *%s*.', 'cead-acad' ),
				get_the_title( $course_id )
			) );
			return;
		}

		$f_sub = Cead_Acad_Grades_Writer::norm( (string) ( $args['materia'] ?? '' ) );
		$f_per = Cead_Acad_Grades_Writer::norm_period( $args['periodo'] ?? '' );

		$by_student = [];
		foreach ( $rows as $r ) {
			$sub_name = get_term( (int) $r['subject_term_id'] )->name ?? '—';
			if ( '' !== $f_sub && false === strpos( Cead_Acad_Grades_Writer::norm( $sub_name ), $f_sub ) ) { continue; }
			if ( '' !== $f_per && (string) $r['period'] !== $f_per ) { continue; }
			$u   = get_user_by( 'id', (int) $r['student_user_id'] );
			$key = $u ? $u->display_name : ( '#' . (int) $r['student_user_id'] );
			$val = ( null !== $r['score'] ) ? rtrim( rtrim( number_format( (float) $r['score'], 2, ',', '' ), '0' ), ',' ) : (string) $r['letter'];
			$by_student[ $key ][] = $sub_name . ' P' . $r['period'] . ': *' . $val . '*';
		}
		if ( ! $by_student ) {
			$this->send( $phone, __( 'No hay notas que coincidan con ese filtro.', 'cead-acad' ) );
			return;
		}

		ksort( $by_student );
		$out = [ sprintf( /* translators: %s: curso */ __( '📊 *Notas de %s*', 'cead-acad' ), get_the_title( $course_id ) ) ];
		$n   = 0;
		foreach ( $by_student as $name => $items ) {
			if ( $n++ >= 20 ) {
				$out[] = sprintf(
					/* translators: %d: cantidad restante */
					__( '…y %d alumno/s más. Mirá el detalle completo en el panel.', 'cead-acad' ),
					count( $by_student ) - 20
				);
				break;
			}
			$out[] = '• *' . $name . '* — ' . implode( ' · ', $items );
		}
		$this->send( $phone, implode( "\n", $out ), 'grades_course' );
	}

	/** Mapea texto libre de rol a un slug permitido por chat ('' si no coincide). */
	private function invite_role_from_text( $s ) {
		$s = strtolower( trim( (string) $s ) );
		if ( $s === '' ) { return ''; }
		if ( strpos( $s, 'deleg' ) !== false ) { return 'cead_acad_delegate'; }
		if ( strpos( $s, 'profe' ) !== false || strpos( $s, 'docent' ) !== false ) { return 'cead_acad_teacher'; }
		if ( strpos( $s, 'alumn' ) !== false || strpos( $s, 'estudiant' ) !== false ) { return 'cead_acad_student'; }
		return '';
	}

	/** Etiqueta legible del rol invitable. */
	private function invite_role_label( $role ) {
		switch ( $role ) {
			case 'cead_acad_delegate': return __( 'Delegado/a', 'cead-acad' );
			case 'cead_acad_teacher':  return __( 'Docente', 'cead-acad' );
			default:                   return __( 'Alumno/a', 'cead-acad' );
		}
	}

	/** Contexto compacto de las FAQ para que la IA responda dudas generales. */
	private function faq_context() {
		if ( ! class_exists( 'Cead_Acad_FAQ' ) ) { return ''; }
		$out = [];
		foreach ( array_slice( Cead_Acad_FAQ::all(), 0, 20 ) as $f ) {
			$ans = wp_strip_all_tags( $f->post_content );
			$out[] = '- ' . get_the_title( $f ) . ': ' . wp_trim_words( $ans, 60 );
		}
		return implode( "\n", $out );
	}

	private function back_to_student( $phone ) {
		// Dentro de una acción de la IA no pegamos el menú: la IA rearma el modo.
		if ( $this->in_ia ) { return; }
		$this->store->set_state( $phone, 'student_menu' );
		$this->send_menu( $phone, $this->m( 'student_menu' ), 'student_menu' );
	}

	// A1 Horarios
	private function show_horario( $phone, $identity ) {
		$course_id = $this->user_course_id( $identity );
		if ( ! $course_id ) {
			$this->send( $phone, $this->m( 'horario_none' ) );
			if ( ! ( $identity['user_id'] ?? 0 ) ) { $this->send( $phone, $this->m( 'identify_hint' ) ); }
			$this->back_to_student( $phone );
			return;
		}
		$slots = $this->course_horario( $course_id );
		if ( ! $slots ) {
			$this->send( $phone, $this->m( 'horario_none' ) );
			$this->back_to_student( $phone );
			return;
		}
		$this->send( $phone, $this->render_horario( get_the_title( $course_id ), $slots ) );
		$this->back_to_student( $phone );
	}

	/** Curso actual del usuario (meta o primer curso del roster). */
	private function user_course_id( $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! $uid ) { return 0; }
		$cid = (int) get_user_meta( $uid, '_cead_acad_current_course_id', true );
		if ( $cid ) { return $cid; }
		if ( class_exists( 'Cead_Acad_Courses_Roster' ) ) {
			$courses = Cead_Acad_Courses_Roster::courses_for_user( $uid );
			if ( $courses ) { return (int) $courses[0]; }
		}
		return 0;
	}

	/** Horario de materias del curso (meta JSON). Devuelve array de slots. */
	private function course_horario( $course_id ) {
		$raw   = get_post_meta( (int) $course_id, '_cead_acad_horario', true );
		$slots = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [] );
		return is_array( $slots ) ? $slots : [];
	}

	/** Render del horario de materias agrupado por día. */
	private function render_horario( $course_title, $slots ) {
		$days   = [ 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' ];
		$by_day = [];
		foreach ( $slots as $s ) {
			$d = (int) ( $s['dia'] ?? 0 );
			if ( $d < 1 || $d > 7 ) { continue; }
			$by_day[ $d ][] = $s;
		}
		ksort( $by_day );
		$out = [ '📚 *Horario — ' . $course_title . '*' ];
		foreach ( $by_day as $d => $items ) {
			usort( $items, static function ( $a, $b ) { return strcmp( (string) ( $a['inicio'] ?? '' ), (string) ( $b['inicio'] ?? '' ) ); } );
			$out[] = "\n*" . $days[ $d ] . '*';
			foreach ( $items as $it ) {
				$hi   = (string) ( $it['inicio'] ?? '' );
				$hf   = (string) ( $it['fin'] ?? '' );
				$time = trim( $hi . ( $hf ? '-' . $hf : '' ) );
				$line = trim( $time . ' ' . (string) ( $it['materia'] ?? '' ) );
				$doc  = (string) ( $it['docente'] ?? '' );
				if ( $doc !== '' ) { $line .= ' · ' . $doc; }
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	private function build_agenda( $header, $events ) {
		$out = [ $header ];
		$by_day = [];
		foreach ( $events as $e ) {
			$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			$day   = $start ? substr( $start, 0, 10 ) : 'sin-fecha';
			$by_day[ $day ][] = [ 'post' => $e, 'start' => $start ];
		}
		ksort( $by_day );
		foreach ( $by_day as $day => $items ) {
			$out[] = "\n*" . $this->human_date( $day ) . '*';
			foreach ( $items as $it ) {
				$time = $it['start'] ? substr( $it['start'], 11, 5 ) : '';
				$loc  = (string) get_post_meta( $it['post']->ID, '_cead_acad_event_location', true );
				$out[] = trim( $time . ' ' . get_the_title( $it['post'] ) . ( $loc ? " · {$loc}" : '' ) );
			}
		}
		[ $nowtxt, $next ] = $this->now_next( $events );
		if ( $nowtxt !== '' ) { $out[] = "\n" . $this->interp( $this->m( 'horario_now' ), [ 'now' => $nowtxt ] ); }
		if ( $next !== '' )   { $out[] = $this->interp( $this->m( 'horario_next' ), [ 'next' => $next ] ); }
		return implode( "\n", $out );
	}

	private function now_next( $events ) {
		$now = current_time( 'mysql' );
		$now_t = strtotime( $now );
		$nowtxt = ''; $next = '';
		foreach ( $events as $e ) {
			$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			$end   = (string) get_post_meta( $e->ID, '_cead_acad_event_end', true );
			if ( ! $start ) { continue; }
			$s = strtotime( $start );
			$en = $end ? strtotime( $end ) : $s + 3600;
			if ( $s <= $now_t && $now_t < $en ) {
				$nowtxt = get_the_title( $e );
			} elseif ( $s > $now_t && $next === '' ) {
				$next = substr( $start, 11, 5 ) . ' ' . get_the_title( $e ) . ' (' . $this->human_date( substr( $start, 0, 10 ) ) . ')';
			}
		}
		return [ $nowtxt, $next ];
	}

	// A2 Sitio web
	private function show_links( $phone ) {
		$links = get_option( 'cead_acad_wa_site_links', [] );
		$lines = [ $this->m( 'site_links_header' ) ];
		foreach ( (array) $links as $l ) {
			$lines[] = '• ' . ( $l['label'] ?? '' ) . "\n  " . ( $l['url'] ?? '' );
		}
		$this->send( $phone, implode( "\n", $lines ) );
		$this->back_to_student( $phone );
	}

	// A3 Calendario
	private function show_events( $phone, $identity ) {
		$now = current_time( 'mysql' );
		$to  = date( 'Y-m-d H:i:s', strtotime( $now . ' +90 day' ) );
		$events = $identity['user_id'] ? Cead_Acad_Schedule_Feed::for_user( $identity['user_id'], $now, $to, 20 ) : [];
		if ( empty( $events ) ) { $events = $this->general_events( 20 ); }
		if ( empty( $events ) ) {
			$this->send( $phone, $this->m( 'events_none' ) );
			$this->back_to_student( $phone );
			return;
		}
		$lines = [ $this->m( 'events_header' ) ];
		foreach ( $events as $e ) {
			$start = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
			$lines[] = '• ' . $this->human_date( substr( $start, 0, 10 ) ) . ' — *' . get_the_title( $e ) . '*';
		}
		$this->send( $phone, implode( "\n", $lines ) );
		$this->back_to_student( $phone );
	}

	// A4 Contacto
	private function show_contacts( $phone ) {
		$contacts = get_option( 'cead_acad_wa_contacts', [] );
		$lines = [ $this->m( 'contact_header' ) ];
		foreach ( (array) $contacts as $c ) {
			$lines[] = '• *' . ( $c['name'] ?? '' ) . "*\n  " . ( $c['detail'] ?? '' );
		}
		$this->send( $phone, implode( "\n", $lines ) );
		$this->back_to_student( $phone );
	}

	// A "Comunicados" (lectura)
	private function show_comunicados( $phone, $identity ) {
		$items = $identity['user_id'] ? Cead_Acad_Broadcasts_Feed::for_user( $identity['user_id'], [ 'per_page' => 5 ] ) : $this->general_broadcasts( 5 );
		if ( empty( $items ) ) {
			$this->send( $phone, $this->m( 'comm_read_none' ) );
			$this->back_to_student( $phone );
			return;
		}
		$lines = [ $this->m( 'comm_read_header' ) ];
		foreach ( $items as $p ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $p->post_content ), 25 );
			$lines[] = "\n📌 *" . get_the_title( $p ) . "*\n" . $excerpt;
		}
		$this->send( $phone, implode( "\n", $lines ) );
		if ( ! $identity['user_id'] ) { $this->send( $phone, $this->m( 'identify_hint' ) ); }
		$this->back_to_student( $phone );
	}

	// A5 Reporte
	private function report_start( $phone ) {
		$this->force_new = true; // el prompt sale como mensaje nuevo, separado del menú.
		$this->store->set_state( $phone, 'stu_report_type' );
		$this->send( $phone, $this->m( 'report_type_prompt' ) . $this->cap_hint() );
	}

	private function report_type( $phone, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->back_to_student( $phone ); return; }
		$type = $lc === '1' ? 'anonymous' : ( $lc === '2' ? 'confidential' : '' );
		if ( $type === '' ) { $this->invalid( $phone ); return; }
		$cats = $this->report_categories();
		$this->store->set_state( $phone, 'stu_report_cat', [ 'report_type' => $type, 'categories' => $cats ] );
		$lines = [];
		foreach ( $cats as $i => $c ) { $lines[] = ( $i + 1 ) . '. ' . $c; }
		$this->send( $phone, $this->interp( $this->m( 'report_category_prompt' ), [ 'category_list' => implode( "\n", $lines ) ] ) );
	}

	private function report_cat( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'report_cancelled' ) ); $this->back_to_student( $phone ); return; }
		$cats = $context['categories'] ?? [];
		$idx  = (int) $lc - 1;
		if ( ! isset( $cats[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$this->store->set_state( $phone, 'stu_report_body', [ 'report_type' => $context['report_type'] ?? 'anonymous', 'category' => (string) $cats[ $idx ] ] );
		$this->send( $phone, $this->m( 'report_body_prompt' ) );
	}

	private function report_body( $phone, $body, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'report_cancelled' ) ); $this->back_to_student( $phone ); return; }
		$type = (string) ( $context['report_type'] ?? 'anonymous' );
		$cat  = (string) ( $context['category'] ?? '' );
		$ref  = $this->store->create_report( $type, $type === 'confidential' ? $phone : null, $cat, $body );
		// La gestión es solo por panel; ya no se reenvía al WhatsApp de coordinación.
		$key = $type === 'anonymous' ? 'report_saved_anon' : 'report_saved_conf';
		$this->send( $phone, $this->interp( $this->m( $key ), [ 'ref' => $ref ] ), 'report_received' );
		$this->finish_capture( $phone, 'student' );
	}

	private function forward_report( $ref, $type, $cat, $body, $contact ) {
		$to = preg_replace( '/[^0-9]/', '', (string) get_option( 'cead_acad_wa_report_forward_number', '' ) );
		if ( $to === '' || strlen( $to ) < 7 ) { return; }
		$lines = [ '🛡️ *Nuevo reporte* ' . $ref, 'Tipo: ' . ( $type === 'confidential' ? 'Confidencial' : 'Anónimo' ), 'Tema: ' . ( $cat !== '' ? $cat : '—' ) ];
		if ( $type === 'confidential' && $contact ) { $lines[] = 'Contacto: +' . $contact; }
		$lines[] = ''; $lines[] = $body;
		$this->bridge->send_message( $to, implode( "\n", $lines ) );
		$this->store->log( $to, 'out', '[reporte reenviado] ' . $ref, 'report_forwarded' );
	}

	private function report_categories() {
		$c = get_option( 'cead_acad_wa_report_categories', [] );
		return is_array( $c ) && $c ? array_values( $c ) : [ 'Bullying / acoso', 'Seguridad', 'Otro' ];
	}

	// A6 Sugerencias
	private function suggestion_start( $phone ) {
		$this->force_new = true;
		$this->store->set_state( $phone, 'stu_msg_to' );
		$this->send( $phone, "✉️ *Escribir a un encargado*\n\n¿A quién querés escribirle?\n\n1. Administración / Secretaría\n2. Consejo Estudiantil\n3. Dirección\n\nEnviá *0* para cancelar." );
	}

	/** Elige destinatario del mensaje (#1). */
	private function msg_to( $phone, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->back_to_student( $phone ); return; }
		$map = [ '1' => 'administracion', '2' => 'consejo', '3' => 'direccion' ];
		if ( ! isset( $map[ $lc ] ) ) { $this->invalid( $phone ); return; }
		$labels = [ 'administracion' => 'Administración', 'consejo' => 'Consejo Estudiantil', 'direccion' => 'Dirección' ];
		$cat    = $map[ $lc ];
		$this->store->set_state( $phone, 'stu_msg_body', [ 'category' => $cat ] );
		$this->send( $phone, sprintf( "Escribí tu mensaje para *%s* (0 para cancelar):", $labels[ $cat ] ) . $this->cap_hint() );
	}

	/** Captura el mensaje y lo guarda en el buzón con el remitente (#1). */
	private function msg_body( $phone, $body, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->back_to_student( $phone ); return; }
		$cat  = (string) ( $context['category'] ?? 'administracion' );
		$uid  = (int) ( $identity['user_id'] ?? 0 );
		$who  = $uid ? ( get_userdata( $uid )->display_name ?? '' ) : '';
		$text = ( $who !== '' ? "✉️ De {$who}:\n\n" : '' ) . $body;
		$this->store->create_suggestion( $phone, $text, $cat );
		$this->send( $phone, '✅ Tu mensaje fue enviado. Te van a responder por acá o en el panel.', 'suggestion_received' );
		$this->finish_capture( $phone, 'student' );
	}


	// A7 FAQ
	private function show_faq( $phone ) {
		$faq = get_option( 'cead_acad_wa_faq', [] );
		if ( ! is_array( $faq ) || ! $faq ) { $this->send( $phone, $this->m( 'faq_none' ) ); $this->back_to_student( $phone ); return; }
		$lines = [ $this->m( 'faq_header' ) ];
		foreach ( $faq as $it ) { $lines[] = "\n*" . ( $it['q'] ?? '' ) . "*\n" . ( $it['a'] ?? '' ); }
		$this->send( $phone, implode( "\n", $lines ), 'faq' );
		$this->back_to_student( $phone );
	}

	// A8 Consejo
	private function council_open( $phone ) {
		$board = (string) get_option( 'cead_acad_wa_council_board', '' );
		$text  = $this->m( 'council_header' );
		if ( trim( $board ) !== '' ) { $text .= "\n\n" . $board; }
		$this->send( $phone, $text, 'council' );
		$this->store->set_state( $phone, 'stu_council_menu' );
		$this->send( $phone, $this->m( 'council_menu' ) );
	}

	private function council_menu( $phone, $lc ) {
		if ( $lc === '1' ) {
			$this->force_new = true;
			$this->store->set_state( $phone, 'stu_council_proposal' );
			$this->send( $phone, $this->m( 'council_proposal_prompt' ) . $this->cap_hint() );
		} elseif ( $this->is_cancel( $lc ) ) {
			$this->back_to_student( $phone );
		} else {
			$this->invalid( $phone );
		}
	}

	private function council_proposal( $phone, $body, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->back_to_student( $phone ); return; }
		$this->store->create_suggestion( $phone, $body, 'consejo' );
		$this->send( $phone, $this->m( 'council_proposal_saved' ), 'council_proposal' );
		$this->finish_capture( $phone, 'student' );
	}

	// Recordatorios opt-in
	private function reminders_toggle( $phone ) {
		$new = ! $this->store->has_event_reminders( $phone );
		$this->store->set_event_reminders( $phone, $new );
		$this->send( $phone, $this->m( $new ? 'reminders_on' : 'reminders_off' ), 'reminders_toggle' );
		$this->back_to_student( $phone );
	}

	// Mi panel web
	private function show_panel( $phone ) {
		$this->send( $phone, $this->m( 'panel_promo' ), 'panel_promo' );
		$this->back_to_student( $phone );
	}

	// A12 Ajustes
	private function settings_open( $phone ) {
		$this->store->set_state( $phone, 'stu_settings_menu' );
		$this->send_menu( $phone, $this->m( 'settings_menu' ), 'settings_menu' );
	}

	private function settings_menu( $phone, $lc, $identity ) {
		switch ( $lc ) {
			case '1': $this->settings_show_data( $phone, $identity ); break;
			case '2':
				$this->store->set_state( $phone, 'stu_settings_name' );
				$this->send( $phone, $this->m( 'settings_name_prompt' ) . $this->cap_hint() );
				break;
			case '3':
				$this->store->set_state( $phone, 'stu_settings_phone' );
				$this->send( $phone, $this->m( 'settings_phone_prompt' ) . $this->cap_hint() );
				break;
			case '4': $this->settings_mode_toggle( $phone ); break;
			case '5':
				$new = ! $this->store->has_event_reminders( $phone );
				$this->store->set_event_reminders( $phone, $new );
				$this->send( $phone, $this->m( $new ? 'reminders_on' : 'reminders_off' ), 'reminders_toggle' );
				$this->settings_open( $phone );
				break;
			case '0': $this->back_to_student( $phone ); break;
			default:  $this->invalid( $phone );
		}
	}

	/** Resumen de los datos del panel asociados al número. */
	private function settings_show_data( $phone, $identity ) {
		$uid  = (int) ( $identity['user_id'] ?? 0 );
		$user = $uid ? get_user_by( 'id', $uid ) : null;
		$row  = $this->store->get_number( $phone );

		$lines   = [ $this->m( 'settings_data_header' ) ];
		$lines[] = '👤 Nombre: ' . ( $user ? $user->display_name : (string) ( $row->name ?? '—' ) );
		$lines[] = '📱 Número: +' . $phone;
		if ( $user && $user->user_email !== '' ) {
			$lines[] = '✉️ Correo: ' . $user->user_email;
		}
		$course_id = $this->user_course_id( $identity );
		$lines[]   = '🎓 Curso: ' . ( $course_id ? get_the_title( $course_id ) : '—' );
		$roles = $this->role_labels_for( $user );
		if ( $roles !== '' ) {
			$lines[] = '🧑‍🏫 Roles: ' . $roles;
		}
		$lines[] = '🤖 Modo: ' . ( $this->mode_for( $phone ) === 'ia' ? 'asistente (IA)' : 'menú' );
		$lines[] = '🔔 Recordatorios: ' . ( $this->store->has_event_reminders( $phone ) ? 'activados' : 'desactivados' );
		$this->send( $phone, implode( "\n", $lines ), 'settings_data' );
		$this->settings_open( $phone );
	}

	/** Etiquetas legibles de los roles cead-acad del usuario. */
	private function role_labels_for( $user ) {
		if ( ! $user ) {
			return '';
		}
		$defs = $this->role_menus();
		$out  = [];
		foreach ( (array) $user->roles as $r ) {
			if ( $r === 'cead_acad_student' ) {
				$out[] = 'Estudiante';
			} elseif ( isset( $defs[ $r ] ) ) {
				$out[] = $defs[ $r ]['label'];
			}
		}
		return implode( ', ', $out );
	}

	private function settings_name( $phone, $body, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->settings_open( $phone ); return; }
		$name = trim( preg_replace( '/\s+/', ' ', $body ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( $len < 3 || $len > 60 || ctype_digit( $name ) ) {
			$this->send( $phone, $this->m( 'settings_name_invalid' ) );
			return;
		}
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( $uid ) {
			wp_update_user( [ 'ID' => $uid, 'display_name' => $name ] );
			Cead_Acad_Audit::log( 'wa_name_changed', [ 'user_id' => $uid, 'entity_type' => 'user', 'entity_id' => $uid, 'payload' => [ 'name' => $name, 'phone' => $phone ] ] );
		}
		$this->store->upsert_number( $phone, [ 'name' => $name ] );
		$this->send( $phone, $this->interp( $this->m( 'settings_name_saved' ), [ 'name' => $name ] ), 'settings_name' );
		$this->settings_open( $phone );
	}

	/**
	 * Cambio de número: NO se cambia solo. El número es la identidad ante el bot
	 * y el nuevo no se puede verificar desde este chat, así que se registra una
	 * solicitud en el buzón (Administración) para que la confirmen en el panel.
	 */
	private function settings_phone( $phone, $body, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->settings_open( $phone ); return; }
		$new = Cead_Acad_WA_Identity::normalize_phone( $body );
		if ( strlen( $new ) < 8 ) {
			$this->send( $phone, $this->m( 'settings_phone_invalid' ) );
			return;
		}
		if ( $new === $phone ) {
			$this->send( $phone, $this->m( 'settings_phone_same' ) );
			return;
		}
		$uid  = (int) ( $identity['user_id'] ?? 0 );
		$user = $uid ? get_user_by( 'id', $uid ) : null;
		$who  = $user ? $user->display_name : '';
		$this->store->create_suggestion( $phone, sprintf( 'Solicitud de cambio de número%s: +%s → +%s', $who !== '' ? " de {$who}" : '', $phone, $new ), 'administracion' );
		Cead_Acad_Audit::log( 'wa_phone_change_requested', [ 'user_id' => $uid ?: null, 'payload' => [ 'old' => $phone, 'new' => $new ] ] );
		$this->send( $phone, $this->interp( $this->m( 'settings_phone_requested' ), [ 'new' => '+' . $new ] ), 'settings_phone' );
		$this->settings_open( $phone );
	}

	private function settings_mode_toggle( $phone ) {
		if ( $this->mode_for( $phone ) === 'ia' ) {
			$this->set_mode( $phone, 'menu' );
			$this->send( $phone, $this->m( 'settings_mode_menu_on' ), 'mode' );
		} elseif ( $this->ai_enabled() ) {
			$this->set_mode( $phone, 'ia' );
			$this->send( $phone, $this->m( 'settings_mode_ia_on' ), 'mode' );
		} else {
			$this->send( $phone, $this->m( 'settings_mode_unavailable' ), 'mode' );
		}
		$this->settings_open( $phone );
	}

	// A13 Notas / boletín
	private function show_notas( $phone, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! $uid ) {
			$this->send( $phone, $this->m( 'academic_need_login' ) );
			$this->back_to_student( $phone );
			return;
		}
		$bulletin = class_exists( 'Cead_Acad_Grades_Db' ) ? Cead_Acad_Grades_Db::bulletin_for_student( $uid ) : [];
		if ( ! $bulletin ) {
			$this->send( $phone, $this->m( 'notas_none' ) );
			$this->back_to_student( $phone );
			return;
		}
		$this->send( $phone, $this->render_notas( $bulletin ), 'notas' );
		$this->back_to_student( $phone );
	}

	/** Render del boletín: materias agrupadas por curso, con sus periodos. */
	private function render_notas( $bulletin ) {
		$lines  = [ $this->m( 'notas_header' ) ];
		$course = null;
		foreach ( $bulletin as $row ) {
			if ( ( $row['course_title'] ?? '' ) !== $course ) {
				$course  = (string) ( $row['course_title'] ?? '' );
				$lines[] = "\n🎓 *" . $course . '*';
			}
			$parts = [];
			foreach ( (array) ( $row['grades'] ?? [] ) as $period => $g ) {
				if ( $g['score'] !== null ) {
					$val = rtrim( rtrim( number_format( (float) $g['score'], 2 ), '0' ), '.' );
				} else {
					$val = ( (string) $g['letter'] !== '' ) ? (string) $g['letter'] : '—';
				}
				$parts[] = $period . ': *' . $val . '*';
			}
			$lines[] = '• ' . ( $row['subject_name'] ?? '' ) . ( $parts ? ' — ' . implode( ' · ', $parts ) : '' );
		}
		return implode( "\n", $lines );
	}

	// A14 Tareas pendientes
	private function show_tareas( $phone, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! $uid ) {
			$this->send( $phone, $this->m( 'academic_need_login' ) );
			$this->back_to_student( $phone );
			return;
		}
		$tasks = $this->pending_tasks_for_user( $uid, 15 );
		if ( ! $tasks ) {
			$this->send( $phone, $this->m( 'tareas_none' ) );
			$this->back_to_student( $phone );
			return;
		}
		$this->send( $phone, $this->render_tareas( $tasks ), 'tareas' );
		$this->back_to_student( $phone );
	}

	/** Tareas pendientes/en curso de los cursos del alumno, ordenadas por vencimiento. */
	private function pending_tasks_for_user( $uid, $limit = 15 ) {
		if ( ! class_exists( 'Cead_Acad_Courses_Roster' ) || ! class_exists( 'Cead_Acad_Tasks_CPT' ) ) {
			return [];
		}
		$courses = Cead_Acad_Courses_Roster::courses_for_user( $uid );
		if ( ! $courses ) { return []; }
		$tasks = get_posts( [
			'post_type'      => Cead_Acad_Tasks_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_cead_acad_task_course', 'value' => array_map( 'intval', $courses ), 'compare' => 'IN' ],
				[ 'key' => '_cead_acad_task_status', 'value' => [ 'pendiente', 'en_curso' ], 'compare' => 'IN' ],
			],
		] );
		// Orden por fecha de entrega (las sin fecha, al final).
		usort( $tasks, static function ( $a, $b ) {
			$da = (string) get_post_meta( $a->ID, '_cead_acad_task_due_date', true );
			$db = (string) get_post_meta( $b->ID, '_cead_acad_task_due_date', true );
			if ( $da === $db ) { return 0; }
			if ( $da === '' ) { return 1; }
			if ( $db === '' ) { return -1; }
			return strcmp( $da, $db );
		} );
		return array_slice( $tasks, 0, (int) $limit );
	}

	/** Render de tareas con prioridad y vencimiento (hoy / vencida). */
	private function render_tareas( $tasks ) {
		$lines = [ $this->m( 'tareas_header' ) ];
		$today = current_time( 'Y-m-d' );
		foreach ( $tasks as $t ) {
			$due       = (string) get_post_meta( $t->ID, '_cead_acad_task_due_date', true );
			$prio      = (string) get_post_meta( $t->ID, '_cead_acad_task_priority', true );
			$course_id = (int) get_post_meta( $t->ID, '_cead_acad_task_course', true );
			$flag      = $prio === 'alta' ? '🔴 ' : '';
			$when      = '';
			if ( $due !== '' ) {
				$when = ' — 📅 ' . $this->human_date( $due );
				if ( $due < $today )      { $when .= ' *(vencida)*'; }
				elseif ( $due === $today ) { $when .= ' *(¡hoy!)*'; }
			}
			$line = '• ' . $flag . get_the_title( $t ) . $when;
			if ( $course_id ) { $line .= "\n   _" . get_the_title( $course_id ) . '_'; }
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	// A15 Carné digital
	private function show_carne( $phone, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! $uid ) {
			$this->send( $phone, $this->m( 'academic_need_login' ) );
			$this->back_to_student( $phone );
			return;
		}
		$this->send( $phone, $this->interp( $this->m( 'carne_link' ), [ 'url' => home_url( '/panel/carne' ) ] ), 'carne' );
		$this->back_to_student( $phone );
	}

	// ---------------------------------------------------------------- staff
	private function staff_menu( $phone, $lc, $context, $identity ) {
		if ( in_array( $lc, [ '0', 'salir', 'cancelar' ], true ) ) {
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'goodbye' ) );
			return;
		}
		if ( in_array( $lc, [ 'menu', 'menú', 'inicio' ], true ) ) {
			$this->show_root_menu( $phone, $identity );
			return;
		}
		$keys = $context['options'] ?? [];
		$idx  = (int) $lc - 1;
		if ( ! isset( $keys[ $idx ] ) ) { $this->invalid( $phone ); return; }
		switch ( $keys[ $idx ] ) {
			case 'comm':      $this->comm_start( $phone, $identity ); break;
			case 'event':     $this->event_start( $phone, $identity ); break;
			case 'articles':  $this->articles_start( $phone, $identity ); break;
			case 'roles':     $this->roles_start( $phone, $identity ); break;
			case 'metrics':   $this->metrics_show( $phone, $identity ); break;
			case 'shortcuts': $this->send( $phone, $this->m( 'shortcuts_help' ) ); $this->reenter_staff( $phone ); break;
			default:          $this->invalid( $phone );
		}
	}

	private function require_cap( $phone, $identity, $cap ) {
		if ( Cead_Acad_WA_Identity::can( $identity['user_id'], $cap ) ) { return true; }
		$this->send( $phone, $this->m( 'access_denied' ) );
		$this->reenter_staff( $phone );
		return false;
	}

	// D2 Comunicados
	private function comm_start( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_publish_broadcast' ) ) { return; }
		$this->store->set_state( $phone, 'staff_comm_compose' );
		$prompt = $this->m( 'comm_compose_prompt' );
		if ( $this->templates() ) { $prompt .= "\nEscribí *P* para usar una plantilla."; }
		$this->send( $phone, $prompt );
	}

	private function comm_compose( $phone, $body, $lc, $media = null ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		if ( $lc === 'p' && $this->templates() ) {
			$this->store->set_state( $phone, 'staff_comm_template' );
			$lines = [ 'Elegí una plantilla:' ];
			foreach ( $this->templates() as $i => $t ) { $lines[] = ( $i + 1 ) . '. ' . ( $t['name'] ?? '' ); }
			$lines[] = '0. Cancelar';
			$this->send( $phone, implode( "\n", $lines ) );
			return;
		}
		// Si el comunicado trae una imagen, la guardamos para reenviarla.
		$image = null; $image_failed = false;
		if ( $media ) {
			$image = $this->store_image( $media );
			$image_failed = ! $image;
		}
		$this->comm_ask_audience( $phone, $body, $image, $image_failed );
	}

	private function comm_template( $phone, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		$tpls = $this->templates();
		$idx  = (int) $lc - 1;
		if ( ! isset( $tpls[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$this->comm_ask_audience( $phone, (string) ( $tpls[ $idx ]['body'] ?? '' ) );
	}

	private function comm_ask_audience( $phone, $message, $image = null, $image_failed = false ) {
		$this->store->set_state( $phone, 'staff_comm_audience', [ 'message' => $message, 'image' => $image ] );
		$extra = '';
		if ( $image ) { $extra = "\n📷 (con imagen adjunta)"; }
		elseif ( $image_failed ) { $extra = "\n" . $this->m( 'image_attach_failed' ); }
		$this->send( $phone, $this->m( 'comm_audience_prompt' ) . $extra );
	}

	private function comm_audience( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		$target = $lc === '1' ? 'students' : ( $lc === '2' ? 'staff' : ( $lc === '3' ? 'all' : '' ) );
		if ( $target === '' ) { $this->invalid( $phone ); return; }
		$count = $this->broadcaster->count_for( $target );
		if ( $count === 0 ) { $this->send( $phone, $this->m( 'comm_empty' ) ); $this->reenter_staff( $phone ); return; }
		$this->store->set_state( $phone, 'staff_comm_when', [ 'message' => $context['message'] ?? '', 'image' => $context['image'] ?? null, 'target' => $target, 'count' => $count ] );
		$this->send( $phone, $this->m( 'comm_when_prompt' ) );
	}

	private function comm_when( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		if ( $lc === '1' ) {
			$this->store->set_state( $phone, 'staff_comm_confirm', [ 'message' => $context['message'] ?? '', 'image' => $context['image'] ?? null, 'target' => $context['target'] ?? 'all' ] );
			$this->send( $phone, $this->interp( $this->m( 'comm_confirm_prompt' ), [ 'count' => (int) ( $context['count'] ?? 0 ) ] ) );
		} elseif ( $lc === '2' ) {
			$this->store->set_state( $phone, 'staff_comm_schedule', [ 'message' => $context['message'] ?? '', 'target' => $context['target'] ?? 'all' ] );
			$prompt = $this->m( 'comm_schedule_prompt' );
			if ( ! empty( $context['image'] ) ) { $prompt .= "\n" . $this->m( 'comm_schedule_no_image' ); }
			$this->send( $phone, $prompt );
		} else {
			$this->invalid( $phone );
		}
	}

	private function comm_confirm( $phone, $lc, $context, $identity ) {
		if ( in_array( $lc, [ 'si', 'sí', 'yes' ], true ) ) {
			$uid = (int) ( $identity['user_id'] ?? 0 );
			if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast' ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->reenter_staff( $phone );
				return;
			}
			$message = (string) ( $context['message'] ?? '' );
			$target  = (string) ( $context['target'] ?? 'all' );
			$image   = $context['image'] ?? null;
			if ( $target === 'all' && ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast_all' ) ) {
				$target = 'students';
			}
			// Crear el comunicado como post (se ve en el panel web) y enviarlo por WA.
			$this->create_broadcast_post( $message, $target, $image );
			$res = $this->broadcaster->enqueue_for( $message, $target, $image );
			Cead_Acad_Audit::log( 'wa_broadcast_sent', [
				'user_id' => $uid ?: null,
				'payload' => [ 'target' => $target, 'total' => (int) ( $res['total'] ?? 0 ), 'con_imagen' => (bool) $image ],
			] );
			if ( ! empty( $res['busy'] ) ) { $this->send( $phone, $this->m( 'comm_busy' ) ); }
			elseif ( empty( $res['queued'] ) ) { $this->send( $phone, $this->m( 'comm_empty' ) ); }
			else { $this->send( $phone, $this->interp( $this->m( 'comm_queued' ), [ 'total' => (int) ( $res['total'] ?? 0 ) ] ), 'broadcast_enqueued' ); }
			$this->reenter_staff( $phone );
		} elseif ( in_array( $lc, [ 'no' ], true ) || $this->is_cancel( $lc ) ) {
			$this->send( $phone, $this->m( 'comm_cancelled' ) );
			$this->reenter_staff( $phone );
		} else {
			$this->send( $phone, $this->m( 'confirm_si_no' ) );
		}
	}

	private function comm_schedule( $phone, $body, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		if ( ! Cead_Acad_WA_Identity::can( (int) ( $identity['user_id'] ?? 0 ), 'cead_acad_publish_broadcast' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->reenter_staff( $phone );
			return;
		}
		$run = $this->parse_datetime( trim( $body ) );
		if ( $run === null ) { $this->send( $phone, $this->m( 'datetime_invalid' ) ); return; }
		$this->store->create_scheduled( (string) ( $context['message'] ?? '' ), (string) ( $context['target'] ?? 'all' ), $run, $phone );
		Cead_Acad_Audit::log( 'wa_broadcast_scheduled', [
			'user_id' => (int) ( $identity['user_id'] ?? 0 ) ?: null,
			'payload' => [ 'target' => (string) ( $context['target'] ?? 'all' ), 'run' => $run ],
		] );
		$this->send( $phone, $this->interp( $this->m( 'comm_scheduled_ok' ), [ 'run' => $run ] ), 'broadcast_scheduled' );
		$this->reenter_staff( $phone );
	}

	// D7 Eventos
	private function event_start( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_manage_schedule' ) ) { return; }
		$this->force_new = true;
		$this->store->set_state( $phone, 'staff_event_title' );
		$this->send( $phone, $this->m( 'event_title_prompt' ) . $this->cap_hint() );
	}

	private function event_title( $phone, $body, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$this->store->set_state( $phone, 'staff_event_date', [ 'title' => $body ] );
		$this->send( $phone, $this->m( 'event_date_prompt' ) );
	}

	private function event_date( $phone, $body, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		if ( ! Cead_Acad_WA_Identity::can( (int) ( $identity['user_id'] ?? 0 ), 'cead_acad_manage_schedule' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->reenter_staff( $phone );
			return;
		}
		$start = $this->parse_datetime( trim( $body ) );
		if ( $start === null ) { $this->send( $phone, $this->m( 'event_date_invalid' ) ); return; }
		$post_id = wp_insert_post( [
			'post_type'   => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => (string) ( $context['title'] ?? 'Evento' ),
			'post_author' => (int) ( $identity['user_id'] ?: 0 ),
		], true );
		if ( is_wp_error( $post_id ) ) { $this->send( $phone, $this->m( 'error_generic' ) ); $this->reenter_staff( $phone ); return; }
		update_post_meta( $post_id, '_cead_acad_event_start', $start );
		update_post_meta( $post_id, '_cead_acad_event_type', 'evento' );
		Cead_Acad_Audiences::set( 'event', $post_id, [ [ 'type' => 'all', 'value' => '*' ] ] );
		$this->send( $phone, $this->m( 'event_saved' ), 'event_created' );
		$this->finish_capture( $phone, 'staff' );
	}

	// ---- Artículos del blog WP ----
	private function articles_start( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_manage_articles' ) ) { return; }
		$this->store->set_state( $phone, 'staff_article_menu' );
		$this->send( $phone, $this->m( 'article_menu_prompt' ) );
	}

	private function article_menu( $phone, $lc, $identity ) {
		switch ( $lc ) {
			case '1': $this->store->set_state( $phone, 'staff_article_title' ); $this->send( $phone, $this->m( 'article_title_prompt' ) ); break;
			case '2': $this->article_list( $phone, 'edit' ); break;
			case '3': $this->article_list( $phone, 'delete' ); break;
			case '0': case 'volver': $this->reenter_staff( $phone ); break;
			default: $this->invalid( $phone );
		}
	}

	private function article_title( $phone, $body, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$this->store->set_state( $phone, 'staff_article_body', [ 'title' => $body ] );
		$this->send( $phone, $this->m( 'article_body_prompt' ) );
	}

	/**
	 * Recibe el cuerpo del artículo y, si el sitio tiene categorías cargadas,
	 * ofrece elegir una antes de publicar. Sin categorías configuradas se
	 * publica directo, como antes.
	 */
	private function article_body( $phone, $body, $lc, $context, $identity, $media = null ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		if ( ! Cead_Acad_WA_Identity::can( (int) ( $identity['user_id'] ?? 0 ), 'cead_acad_manage_articles' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->reenter_staff( $phone );
			return;
		}

		// La imagen se sube ahora: el mensaje con la foto es este, no el que
		// traiga la elección de categoría.
		$image_id   = 0;
		$image_note = '';
		if ( $media ) {
			$image = $this->store_image( $media );
			if ( $image && ! empty( $image['attachment_id'] ) ) {
				$image_id = (int) $image['attachment_id'];
			} else {
				$image_note = "\n" . $this->m( 'image_attach_failed' );
			}
		}

		$cats = $this->article_categories();
		if ( ! $cats ) {
			$this->article_publish( $phone, (string) ( $context['title'] ?? 'Artículo' ), $body, 0, $image_id, $image_note, $identity );
			return;
		}

		$ids   = array_keys( $cats );
		$lines = [];
		foreach ( array_values( $cats ) as $i => $label ) {
			$lines[] = ( $i + 1 ) . '. ' . $label;
		}
		// "Sin categoría" va después de la última, no en el 0: el 0 es
		// "cancelar" en todo el bot y cambiarlo acá publicaría sin querer.
		$lines[] = ( count( $ids ) + 1 ) . '. ' . __( 'Sin categoría', 'cead-acad' );
		$lines[] = '0. ' . __( 'Cancelar', 'cead-acad' );

		$this->store->set_state( $phone, 'staff_article_cat', [
			'title'      => (string) ( $context['title'] ?? 'Artículo' ),
			'body'       => $body,
			'cat_ids'    => $ids,
			'image_id'   => $image_id,
			'image_note' => $image_note,
		] );
		$this->send( $phone, $this->m( 'article_cat_prompt' ) . "\n" . implode( "\n", $lines ) );
	}

	private function article_cat( $phone, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		if ( ! preg_match( '/^\d+$/', $lc ) ) { $this->invalid( $phone ); return; }

		$ids = array_values( (array) ( $context['cat_ids'] ?? [] ) );
		$pos = (int) $lc;
		if ( $pos === count( $ids ) + 1 ) {
			$cat = 0; // "Sin categoría".
		} elseif ( isset( $ids[ $pos - 1 ] ) ) {
			$cat = (int) $ids[ $pos - 1 ];
		} else {
			$this->invalid( $phone );
			return;
		}

		$this->article_publish(
			$phone,
			(string) ( $context['title'] ?? 'Artículo' ),
			(string) ( $context['body'] ?? '' ),
			$cat,
			(int) ( $context['image_id'] ?? 0 ),
			(string) ( $context['image_note'] ?? '' ),
			$identity
		);
	}

	/** Inserta el artículo y cierra el flujo. Compartido por el paso con y sin categoría. */
	private function article_publish( $phone, $title, $body, $cat_id, $image_id, $image_note, $identity ) {
		$uid = (int) ( $identity['user_id'] ?? 0 );
		if ( ! Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_articles' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->reenter_staff( $phone );
			return;
		}
		$pid = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $body,
			'post_author'  => $uid ?: 0,
		], true );
		if ( is_wp_error( $pid ) ) { $this->send( $phone, $this->m( 'error_generic' ) ); $this->reenter_staff( $phone ); return; }

		if ( $image_id ) {
			set_post_thumbnail( $pid, $image_id );
		}
		if ( $cat_id && isset( $this->article_categories()[ $cat_id ] ) ) {
			wp_set_post_categories( $pid, [ $cat_id ], true );
		}
		Cead_Acad_Audit::log( 'wa_article_published', [
			'user_id'     => $uid ?: null,
			'entity_type' => 'post',
			'entity_id'   => $pid,
			'payload'     => [ 'categoria' => $cat_id ?: null, 'con_imagen' => (bool) $image_id, 'via' => 'menu' ],
		] );
		$this->send( $phone, $this->interp( $this->m( 'article_published' ), [ 'url' => get_permalink( $pid ) ] ) . $image_note, 'article_published' );
		$this->reenter_staff( $phone );
	}

	private function recent_articles( $limit = 10 ) {
		return get_posts( [ 'post_type' => 'post', 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => (int) $limit, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ] );
	}

	private function article_list( $phone, $mode ) {
		$posts = $this->recent_articles( 10 );
		if ( ! $posts ) { $this->send( $phone, $this->m( 'article_none' ) ); $this->reenter_staff( $phone ); return; }
		$ids = []; $lines = [];
		foreach ( $posts as $i => $p ) { $ids[] = (int) $p->ID; $lines[] = ( $i + 1 ) . '. ' . get_the_title( $p ); }
		$lines[] = '0. Volver';
		$state = $mode === 'edit' ? 'staff_article_edit_pick' : 'staff_article_del_pick';
		$this->store->set_state( $phone, $state, [ 'ids' => $ids ] );
		$head = $mode === 'edit' ? $this->m( 'article_pick_edit' ) : $this->m( 'article_pick_delete' );
		$this->send( $phone, $head . "\n" . implode( "\n", $lines ) );
	}

	private function article_edit_pick( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$ids = $context['ids'] ?? []; $idx = (int) $lc - 1;
		if ( ! isset( $ids[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$this->store->set_state( $phone, 'staff_article_edit_body', [ 'post_id' => (int) $ids[ $idx ] ] );
		$this->send( $phone, $this->m( 'article_edit_body_prompt' ) );
	}

	private function article_edit_body( $phone, $body, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		if ( ! Cead_Acad_WA_Identity::can( (int) ( $identity['user_id'] ?? 0 ), 'cead_acad_manage_articles' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->reenter_staff( $phone );
			return;
		}
		$pid = (int) ( $context['post_id'] ?? 0 );
		$r = wp_update_post( [ 'ID' => $pid, 'post_content' => $body ], true );
		$this->send( $phone, is_wp_error( $r ) ? $this->m( 'error_generic' ) : $this->m( 'article_updated' ), 'article_updated' );
		$this->reenter_staff( $phone );
	}

	private function article_del_pick( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$ids = $context['ids'] ?? []; $idx = (int) $lc - 1;
		if ( ! isset( $ids[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$pid = (int) $ids[ $idx ];
		$this->store->set_state( $phone, 'staff_article_del_confirm', [ 'post_id' => $pid ] );
		$this->send( $phone, $this->interp( $this->m( 'article_del_confirm' ), [ 'title' => get_the_title( $pid ) ] ) );
	}

	private function article_del_confirm( $phone, $lc, $context, $identity ) {
		$pid = (int) ( $context['post_id'] ?? 0 );
		if ( in_array( $lc, [ 'si', 'sí', 'yes' ], true ) ) {
			if ( ! Cead_Acad_WA_Identity::can( (int) ( $identity['user_id'] ?? 0 ), 'cead_acad_manage_articles' ) ) {
				$this->send( $phone, $this->m( 'access_denied' ) );
				$this->reenter_staff( $phone );
				return;
			}
			wp_trash_post( $pid );
			$this->send( $phone, $this->m( 'article_deleted' ), 'article_deleted' );
			$this->reenter_staff( $phone );
		} elseif ( in_array( $lc, [ 'no' ], true ) || $this->is_cancel( $lc ) ) {
			$this->reenter_staff( $phone );
		} else {
			$this->send( $phone, $this->m( 'confirm_si_no' ) );
		}
	}

	// ---- Asignar roles a un número ----
	private function roles_start( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_manage_roles' ) ) { return; }
		$this->store->set_state( $phone, 'staff_role_phone' );
		$this->send( $phone, $this->m( 'role_phone_prompt' ) );
	}

	private function role_phone( $phone, $body, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$target = preg_replace( '/[^0-9]/', '', $body );
		if ( strlen( $target ) < 7 ) { $this->send( $phone, $this->m( 'role_phone_invalid' ) ); return; }
		$this->store->set_state( $phone, 'staff_role_choose', [ 'target' => $target ] );
		$this->send( $phone, $this->m( 'role_choose_prompt' ) );
	}

	private function role_choose( $phone, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		if ( ! Cead_Acad_WA_Identity::can( (int) ( $identity['user_id'] ?? 0 ), 'cead_acad_manage_roles' ) ) {
			$this->send( $phone, $this->m( 'access_denied' ) );
			$this->reenter_staff( $phone );
			return;
		}
		// Whitelist: solo roles no-administrativos.
		$map = [ '1' => 'cead_acad_teacher', '2' => 'cead_acad_delegate', '3' => 'cead_acad_student_council' ];
		if ( ! isset( $map[ $lc ] ) ) { $this->invalid( $phone ); return; }
		$target = (string) ( $context['target'] ?? '' );
		$res    = $this->assign_role_to_phone( $target, $map[ $lc ] );
		if ( ! empty( $res['error'] ) ) {
			$this->send( $phone, $this->m( 'error_generic' ) );
			$this->reenter_staff( $phone );
			return;
		}
		Cead_Acad_Audit::log( 'wa_role_assigned', [
			'user_id'     => (int) ( $identity['user_id'] ?? 0 ) ?: null,
			'entity_type' => 'user',
			'entity_id'   => $res['user_id'] ?? null,
			'payload'     => [ 'phone' => $target, 'role' => $map[ $lc ], 'created' => $res['created'] ],
		] );
		$key = $res['created'] ? 'role_assigned_new' : 'role_assigned';
		$this->send( $phone, $this->interp( $this->m( $key ), [ 'phone' => $target, 'role' => $res['label'] ] ), 'role_assigned' );
		$this->reenter_staff( $phone );
	}

	private function assign_role_to_phone( $target, $role ) {
		$labels   = [ 'cead_acad_teacher' => 'Docente', 'cead_acad_delegate' => 'Delegado', 'cead_acad_student_council' => 'Consejo Estudiantil' ];
		$identity = Cead_Acad_WA_Identity::resolve( $target );
		if ( $identity['user_id'] ) {
			$u = get_user_by( 'id', $identity['user_id'] );
			if ( $u ) { $u->add_role( $role ); }
			return [ 'created' => false, 'label' => $labels[ $role ] ?? $role, 'user_id' => $identity['user_id'] ];
		}
		$login  = 'wa_' . $target;
		$suffix = 1;
		while ( username_exists( $login ) ) { $login = 'wa_' . $target . '_' . $suffix; $suffix++; }
		$uid = wp_insert_user( [
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 20, true ),
			'display_name' => '+' . $target,
			'role'         => $role,
		] );
		if ( is_wp_error( $uid ) ) {
			return [ 'created' => false, 'error' => true, 'label' => $labels[ $role ] ?? $role ];
		}
		update_user_meta( $uid, '_cead_acad_phone', $target );
		return [ 'created' => true, 'label' => $labels[ $role ] ?? $role, 'user_id' => $uid ];
	}


	// D9 Métricas
	private function metrics_show( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_view_metrics' ) ) { return; }
		$in   = $this->store->count_messages( 'in', 30 );
		$out  = $this->store->count_messages( 'out', 30 );
		$usr  = $this->store->count_unique_users( 30 );
		$rep  = $this->store->count_reports_by_status();
		$sug  = $this->store->count_suggestions_by_status();
		$lines = [ $this->m( 'metrics_header' ) ];
		$lines[] = "💬 Mensajes: {$in} entrantes · {$out} salientes";
		$lines[] = "👤 Usuarios activos: {$usr}";
		$lines[] = "📥 Reportes: {$rep['new']} nuevos · {$rep['in_review']} en revisión · {$rep['resolved']} resueltos";
		$lines[] = "💡 Sugerencias: {$sug['new']} nuevas · {$sug['in_review']} en revisión · {$sug['resolved']} resueltas";
		$this->send( $phone, implode( "\n", $lines ) );
		$this->reenter_staff( $phone );
	}

	private function reenter_staff( $phone ) {
		// Dentro de una acción de la IA no pegamos el menú: la IA rearma el modo.
		if ( $this->in_ia ) { return; }
		// Vuelve al menú del rol; si el usuario tiene varios, al selector.
		$identity = $this->resolve_identity( $phone );
		$menus    = $this->available_role_menus( $identity );
		if ( count( $menus ) === 1 ) {
			$this->enter_role_menu( $phone, array_key_first( $menus ), $identity );
		} elseif ( $menus ) {
			$this->enter_role_chooser( $phone, $identity, $menus );
		} else {
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'goodbye' ) );
		}
	}

	// ---------------------------------------------------------------- helpers
	private function general_events( $limit ) {
		global $wpdb;
		$aud = cead_acad_table( 'audiences' );
		$now = current_time( 'mysql' );
		// Sin placeholders → get_col directo ($aud viene del whitelist de cead_acad_table).
		$ids = $wpdb->get_col( "SELECT DISTINCT subject_id FROM {$aud} WHERE subject_type = 'event' AND audience_type = 'all'" );
		$ids = array_map( 'intval', $ids ?: [] );
		if ( ! $ids ) { return []; }
		return get_posts( [
			'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'posts_per_page' => (int) $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cead_acad_event_start',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => '_cead_acad_event_start', 'value' => $now, 'compare' => '>=', 'type' => 'DATETIME' ] ],
		] );
	}

	private function general_broadcasts( $limit ) {
		global $wpdb;
		$aud = cead_acad_table( 'audiences' );
		$ids = $wpdb->get_col( "SELECT DISTINCT subject_id FROM {$aud} WHERE subject_type = 'broadcast' AND audience_type = 'all'" );
		$ids = array_map( 'intval', $ids ?: [] );
		if ( ! $ids ) { return []; }
		return get_posts( [
			'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'posts_per_page' => (int) $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
	}

	private function templates() {
		$t = get_option( 'cead_acad_wa_comm_templates', [] );
		return is_array( $t ) ? array_values( $t ) : [];
	}

	/** Crea el comunicado como post (delegado al broadcaster, compartido con admin). */
	private function create_broadcast_post( $message, $target, $image = null ) {
		$attachment_id = ( is_array( $image ) && ! empty( $image['attachment_id'] ) ) ? (int) $image['attachment_id'] : 0;
		return Cead_Acad_WA_Broadcaster::create_broadcast_post( $message, $target, $attachment_id );
	}

	/**
	 * Guarda una imagen entrante (base64 del bridge) en la biblioteca de medios.
	 * Devuelve [ attachment_id, path, mime, url ] o null.
	 */
	/** ¿El media entrante es un audio (nota de voz)? Se decide por el MIME. */
	private function is_audio_media( $media ) {
		if ( ! is_array( $media ) ) { return false; }
		$mime = strtolower( (string) ( $media['mime'] ?? '' ) );
		return $mime !== '' && strpos( $mime, 'audio' ) === 0;
	}

	private function is_image_media( $media ) {
		if ( ! is_array( $media ) ) { return false; }
		$mime = strtolower( (string) ( $media['mime'] ?? '' ) );
		return $mime !== '' && strpos( $mime, 'image/' ) === 0;
	}

	/** ¿Hay una imagen que la IA pueda mirar en este turno? */
	private function has_ai_image( $media ) {
		return $this->is_image_media( $media )
			&& class_exists( 'Cead_Acad_WA_AI' )
			&& Cead_Acad_WA_AI::vision_enabled();
	}

	/** Explicación de cómo convertir un formato viejo que no se puede abrir. */
	private function unsupported_file_message( $kind ) {
		if ( 'doc' === $kind ) {
			return __( '📄 Ese archivo es un Word viejo (.doc) y no puedo abrirlo. Abrilo en Word y usá *Guardar como → .docx* (o exportalo a PDF), y reenviámelo.', 'cead-acad' );
		}
		return __( '📄 Ese archivo es un Excel viejo (.xls) y no puedo abrirlo. Abrilo en Excel y usá *Guardar como → .xlsx*, después reenviámelo.', 'cead-acad' );
	}

	/**
	 * Vista previa de un texto largo para la propuesta de WhatsApp.
	 *
	 * El artículo entero se guarda igual; esto es solo lo que se muestra. Antes
	 * se cortaba con un mb_substr seco a 600 caracteres, así que la propuesta
	 * terminaba a mitad de palabra y parecía que el artículo había salido
	 * cortado. Ahora se corta en un corte natural (párrafo o espacio) y se
	 * aclara cuánto falta, para que se entienda que está completo.
	 */
	public static function preview_text( $texto, $limite = 700 ) {
		$texto = trim( (string) $texto );
		$total = mb_strlen( $texto );
		if ( $total <= $limite ) { return $texto; }

		$corte = mb_substr( $texto, 0, $limite );
		// Cortar en el último párrafo o espacio, para no partir una palabra.
		$pos = max( mb_strrpos( $corte, "\n" ) ?: 0, mb_strrpos( $corte, ' ' ) ?: 0 );
		if ( $pos > (int) ( $limite * 0.6 ) ) { $corte = mb_substr( $corte, 0, $pos ); }

		return rtrim( $corte ) . "\n\n" . sprintf(
			/* translators: %s: cantidad de caracteres que faltan */
			__( '[…] _Vista previa cortada acá. El artículo completo tiene %s caracteres y se publica entero._', 'cead-acad' ),
			number_format_i18n( $total )
		);
	}

	/**
	 * Deja el estado de espera del modo asistente, PERO sin pisar una propuesta
	 * que quedó esperando confirmación.
	 *
	 * Pasa de verdad: WhatsApp manda un álbum de fotos como mensajes separados,
	 * así que se abren dos turnos casi a la vez. Si el primero propone publicar
	 * un artículo y el segundo termina después, el segundo dejaba el estado en
	 * «ia_home» y borraba la propuesta: la persona contestaba *1* y no se
	 * publicaba nada, sin ningún aviso. La confirmación pendiente manda.
	 */
	private function leave_ia_state( $phone, $home_state ) {
		$now = $this->store->get_state( $phone );
		if ( ( $now['state'] ?? '' ) === 'ia_staff_confirm' ) {
			return;
		}
		$this->store->set_state( $phone, $home_state );
	}

	/** ¿Llegó un documento (PDF, Word) y la lectura está activa? */
	private function has_ai_doc( $media ) {
		return class_exists( 'Cead_Acad_WA_Docs' )
			&& Cead_Acad_WA_Docs::enabled()
			&& Cead_Acad_WA_Docs::is_document( $media );
	}

	private function store_image( $media ) {
		if ( ! is_array( $media ) || empty( $media['data_base64'] ) ) { return null; }
		$mime  = (string) ( $media['mime'] ?? 'image/jpeg' );
		$ext   = $mime === 'image/png' ? 'png' : ( $mime === 'image/webp' ? 'webp' : 'jpg' );
		$bytes = base64_decode( (string) $media['data_base64'], true );
		if ( $bytes === false ) { return null; }
		$filename = 'wa-' . date( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) { return null; }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = wp_insert_attachment( [
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( $filename ),
			'post_status'    => 'inherit',
		], $upload['file'] );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) { return null; }
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		return [ 'attachment_id' => (int) $attachment_id, 'path' => $upload['file'], 'mime' => $mime, 'url' => $upload['url'] ];
	}

	private function human_date( $ymd ) {
		$ts = strtotime( $ymd );
		return $ts ? date_i18n( 'D d/m', $ts ) : $ymd;
	}

	private function parse_datetime( $input ) {
		return self::parse_human_datetime( $input, (int) current_time( 'timestamp' ) );
	}

	/** Aplica am/pm a una hora de 1 a 12. */
	private static function apply_meridiem( $hour, $mer ) {
		if ( 'pm' === $mer && $hour < 12 ) { return $hour + 12; }
		if ( 'am' === $mer && 12 === $hour ) { return 0; }
		return $hour;
	}

	/**
	 * Interpreta una fecha/hora escrita por una persona y devuelve 'Y-m-d H:i:s'
	 * (o null si no la entiende). Acepta desde el formato estricto que usan los
	 * menús (`Y-m-d H:i`) hasta lenguaje natural en español: «mañana 10:00»,
	 * «5/8 14:30», «el viernes a las 9», «hoy 15hs», «12/09/2026».
	 *
	 * Sin hora asume 08:00; sólo con hora asume hoy (o mañana si ya pasó); una
	 * fecha sin año que ya quedó atrás se entiende como del año siguiente.
	 *
	 * Estática y pura (recibe el "ahora") para poder testearla sin WordPress.
	 *
	 * @param string   $input Texto de la persona.
	 * @param int|null $now   Timestamp local de referencia; null = time().
	 * @return string|null
	 */
	public static function parse_human_datetime( $input, $now = null ) {
		$raw = trim( (string) $input );
		if ( '' === $raw ) { return null; }
		$now = ( null === $now ) ? time() : (int) $now;

		// Formato estricto de los menús y del cron: se respeta tal cual.
		$dt = DateTime::createFromFormat( 'Y-m-d H:i', $raw );
		if ( $dt && $dt->format( 'Y-m-d H:i' ) === $raw ) {
			return $dt->format( 'Y-m-d H:i:s' );
		}
		$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $raw );
		if ( $dt && $dt->format( 'Y-m-d H:i:s' ) === $raw ) {
			return $raw;
		}

		$s = self::normalize_text( $raw );
		$s = str_replace( [ ',', ';' ], ' ', $s );

		$hour = null;
		$min  = 0;

		// «14:30», «14.30», «2:30 pm»
		if ( preg_match( '/\b(\d{1,2})[:.](\d{2})\s*(am|pm)?/', $s, $m ) ) {
			$h  = (int) $m[1];
			$mi = (int) $m[2];
			if ( $h <= 23 && $mi <= 59 ) {
				$hour = empty( $m[3] ) ? $h : self::apply_meridiem( $h, $m[3] );
				$min  = $mi;
				$s    = str_replace( $m[0], ' ', $s );
			}
		}
		// «a las 9», «9 hs», «7pm»
		if ( null === $hour && preg_match( '/(?:a\s+las\s+(\d{1,2})|\b(\d{1,2})\s*(am|pm|hs|hrs|horas?)\b)/', $s, $m ) ) {
			$h   = (int) ( '' !== $m[1] ? $m[1] : $m[2] );
			$mer = $m[3] ?? '';
			if ( $h <= 23 ) {
				$hour = ( 'am' === $mer || 'pm' === $mer ) ? self::apply_meridiem( $h, $mer ) : $h;
				$s    = str_replace( $m[0], ' ', $s );
			}
		}

		// Había algo con pinta de hora pero fuera de rango («25:00», «99:99»):
		// preferimos no entender antes que agendar a una hora inventada.
		if ( null === $hour && preg_match( '/\d{1,2}\s*[:.]\s*\d{1,2}/', $s ) ) {
			return null;
		}

		$today = date( 'Y-m-d', $now );
		$date  = null;

		// «5/8», «05-08-2026», «12/09/26»
		if ( preg_match( '/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/', $s, $m ) ) {
			$d       = (int) $m[1];
			$mo      = (int) $m[2];
			$has_year = isset( $m[3] ) && '' !== $m[3];
			$y       = $has_year ? (int) $m[3] : (int) date( 'Y', $now );
			if ( $y < 100 ) { $y += 2000; }
			if ( checkdate( $mo, $d, $y ) ) {
				$date = sprintf( '%04d-%02d-%02d', $y, $mo, $d );
				// Sin año explícito y ya pasó → se entiende el año que viene.
				if ( ! $has_year && $date < $today ) {
					$date = sprintf( '%04d-%02d-%02d', $y + 1, $mo, $d );
				}
			}
		}
		// «hoy», «mañana», «pasado mañana»
		if ( null === $date ) {
			if ( preg_match( '/\bpasado\s+manana\b/', $s ) ) {
				$date = date( 'Y-m-d', strtotime( '+2 days', $now ) );
			} elseif ( preg_match( '/\bmanana\b/', $s ) ) {
				$date = date( 'Y-m-d', strtotime( '+1 day', $now ) );
			} elseif ( preg_match( '/\bhoy\b/', $s ) ) {
				$date = $today;
			}
		}
		// Día de la semana: siempre el próximo (si es hoy, la semana que viene).
		if ( null === $date ) {
			$days = [ 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7 ];
			foreach ( $days as $name => $iso ) {
				if ( preg_match( '/\b' . $name . '\b/', $s ) ) {
					$ahead = ( $iso - (int) date( 'N', $now ) + 7 ) % 7;
					if ( 0 === $ahead ) { $ahead = 7; }
					$date = date( 'Y-m-d', strtotime( '+' . $ahead . ' days', $now ) );
					break;
				}
			}
		}

		if ( null === $date && null === $hour ) { return null; }

		if ( null === $date ) {
			// Sólo hora: hoy, o mañana si esa hora ya pasó.
			$date = $today;
			if ( sprintf( '%02d:%02d', $hour, $min ) <= date( 'H:i', $now ) ) {
				$date = date( 'Y-m-d', strtotime( '+1 day', $now ) );
			}
		}
		if ( null === $hour ) { $hour = 8; $min = 0; }
		if ( $hour > 23 || $min > 59 ) { return null; }

		return sprintf( '%s %02d:%02d:00', $date, $hour, $min );
	}

	private function m( $key ) {
		return $this->store->get_message( $key );
	}

	private function interp( $tpl, $vars ) {
		foreach ( $vars as $k => $v ) { $tpl = str_replace( '{' . $k . '}', (string) $v, $tpl ); }
		return $tpl;
	}

	private function is_cancel( $lc ) {
		return in_array( $lc, [ '0', 'cancelar', 'cancel' ], true );
	}

	/** Límite anti-flood: máx. de mensajes por número en una ventana de tiempo. */
	private function allow_rate( $phone, $max = 25, $window = 60 ) {
		$key = 'cead_acad_wa_rl_' . md5( $phone );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}

	private function invalid( $phone ) {
		$this->send( $phone, $this->m( 'invalid_option' ) );
	}

	/**
	 * Resuelve la identidad del número a partir del usuario asociado (si existe).
	 */
	private function resolve_identity( $phone ) {
		$identity = Cead_Acad_WA_Identity::resolve( $phone );
		// Acceso temporal vigente: el número opera con la identidad prestada,
		// así los permisos y la auditoría salen de un usuario real y no de un
		// sistema de permisos paralelo.
		if ( empty( $identity['user_id'] ) && class_exists( 'Cead_Acad_WA_Temp_Access' ) ) {
			$uid = Cead_Acad_WA_Temp_Access::granted_user( $phone );
			if ( $uid ) {
				$identity['user_id'] = $uid;
				$identity['is_staff'] = true;
				$identity['temp']     = true;
			}
		}
		return $identity;
	}

	// --------------------------------------------------- filtro de lenguaje
	/** Estados donde el usuario escribe texto libre que se guarda/reenvía. */
	private function is_free_text_state( $state ) {
		return in_array( $state, [ 'stu_report_body', 'stu_msg_body', 'stu_council_proposal', 'stu_settings_name', 'staff_comm_compose', 'staff_article_title', 'staff_article_body', 'staff_article_edit_body' ], true );
	}

	/** Lista de palabras prohibidas configurable (una por línea o separadas por coma). */
	private function banned_words() {
		$raw  = (string) get_option( 'cead_acad_banned_words', '' );
		$list = preg_split( '/[\r\n,]+/', $raw );
		$list = array_filter( array_map( 'trim', (array) $list ), static function ( $w ) { return $w !== ''; } );
		return array_values( $list );
	}

	/** Palabras prohibidas detectadas en el texto (match por palabra, sin acentos ni distinción de mayúsculas). */
	private function detect_banned_words( $text ) {
		$list = $this->banned_words();
		if ( ! $list ) { return []; }
		$haystack = $this->normalize_text( $text );
		$hits = [];
		foreach ( $list as $word ) {
			$needle = $this->normalize_text( $word );
			if ( $needle === '' ) { continue; }
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u', $haystack ) ) {
				$hits[] = $word;
			}
		}
		return array_values( array_unique( $hits ) );
	}

	private static function normalize_text( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		return strtr( $s, [ 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ] );
	}

	// --------------------------------------------------- envío
	/**
	 * Ventana en la que WhatsApp permite editar un mensaje propio (~15 min, contados
	 * desde el mensaje original). Pasada la ventana, el bot manda un mensaje nuevo y
	 * arranca otra tanda de ediciones. 840 s = 14 min (margen bajo el límite de WA).
	 */
	const EDIT_WINDOW = 840;

	/**
	 * Cada cuántos mensajes el bot "baja" automáticamente (manda uno nuevo en vez de
	 * editar) para que no quede arriba en el chat. Se cuenta sobre el mismo ancla.
	 */
	const MESSAGES_BEFORE_DROP = 5;

	/**
	 * Encola un mensaje del bot. Todas las respuestas de un mismo turno se juntan y
	 * se entregan como UN solo mensaje (ver flush_outbox), editándolo sobre el
	 * anterior para no llenar el chat.
	 */
	private function send( $phone, $message, $action = '' ) {
		if ( trim( (string) $message ) === '' ) {
			error_log( '[CeadAcadWA] send omitido: mensaje vacío. acción=' . $action );
			return;
		}
		$this->outbox[] = [ 'text' => $message, 'action' => $action ];
	}

	/** Alias histórico: los menús se entregan igual que cualquier otro mensaje. */
	private function send_menu( $phone, $message, $action = 'menu' ) {
		$this->send( $phone, $message, $action );
	}

	/**
	 * Entrega lo encolado en el turno como un único mensaje, EDITÁNDOLO sobre el
	 * último mensaje del bot mientras WhatsApp lo permita (ventana de ~15 min desde
	 * el mensaje original; el ts no se renueva al editar). Si la ventana venció o el
	 * edit es rechazado, manda uno nuevo y empieza una tanda nueva. Así el usuario
	 * trabaja con un mensaje que se va actualizando en vez de recibir decenas.
	 */
	private function flush_outbox( $phone ) {
		if ( empty( $this->outbox ) ) {
			return;
		}
		$texts        = array_map( static function ( $m ) { return $m['text']; }, $this->outbox );
		$last         = end( $this->outbox );
		$action       = ( is_array( $last ) && $last['action'] !== '' ) ? $last['action'] : 'menu';
		$message      = implode( "\n\n", $texts );
		$this->outbox = [];
		// En un turno conversacional (IA) mandamos mensaje nuevo en vez de editar el ancla.
		$force_new       = $this->force_new || $this->ia_turn;
		$this->force_new = false;
		$this->ia_turn   = false;
		$this->deliver( $phone, $message, $action, $force_new );
	}

	/** Pie de ayuda para los prompts de captura. */
	private function cap_hint() {
		return "\n\n_Escribí *volver* o *cancelar* para salir._";
	}

	/**
	 * Cierra una captura (reporte/sugerencia/propuesta/evento): el ✅ ya fue encolado y
	 * edita el mensaje del prompt; NO se muestra el menú ahora. Queda en 'menu_pending'
	 * y el menú baja recién con el próximo mensaje del usuario.
	 */
	private function finish_capture( $phone, $return = 'student' ) {
		$this->store->set_state( $phone, 'menu_pending', [ 'return' => $return ] );
	}

	/** Estado 'menu_pending': ante el próximo mensaje, baja el menú como mensaje nuevo. */
	private function menu_pending( $phone, $identity, $context ) {
		$this->force_new = true;
		if ( ( $context['return'] ?? 'student' ) === 'staff' ) {
			$this->reenter_staff( $phone );
		} else {
			$this->back_to_student( $phone );
		}
	}

	/** Muestra el menú raíz que corresponda (rol o alumnado). */
	private function show_root_menu( $phone, $identity ) {
		$menus = $this->available_role_menus( $identity );
		if ( $menus ) {
			// Siempre el selector, aunque haya UN solo menú de rol: es el único
			// lugar que también ofrece «Estudiantes», y quien tiene rol puede
			// querer cualquiera de los dos.
			$this->enter_role_chooser( $phone, $identity, $menus );
		} else {
			$this->back_to_student( $phone );
		}
	}

	/** Clave del ancla (último mensaje editable) por número. */
	private function anchor_key( $phone ) {
		return 'cead_acad_wa_anchor_' . md5( $phone );
	}

	/**
	 * Entrega un mensaje editando el ancla mientras se pueda; si no, manda uno nuevo.
	 * No edita si: no hay ancla, venció la ventana, ya se editó MESSAGES_BEFORE_DROP-1
	 * veces (toca "bajar"), o se fuerza con $force_new. Guarda el texto para "bajar".
	 */
	private function deliver( $phone, $message, $action = 'menu', $force_new = false ) {
		if ( trim( (string) $message ) === '' ) {
			return;
		}
		$key     = $this->anchor_key( $phone );
		$anchor  = get_transient( $key );
		$edits   = is_array( $anchor ) ? (int) ( $anchor['edits'] ?? 0 ) : 0;
		$can_edit = ! $force_new
			&& is_array( $anchor ) && ! empty( $anchor['id'] )
			&& ( time() - (int) ( $anchor['ts'] ?? 0 ) ) < self::EDIT_WINDOW
			&& $edits < ( self::MESSAGES_BEFORE_DROP - 1 );
		if ( $can_edit ) {
			$res = $this->bridge->edit_message( $phone, $message, (string) $anchor['id'] );
			if ( is_array( $res ) && ! empty( $res['edited'] ) ) {
				$this->store->log( $phone, 'out', $message, $action . '_edit' );
				// Mantener id y ts ORIGINAL: la ventana corre desde el primer mensaje.
				set_transient( $key, [ 'id' => (string) $anchor['id'], 'ts' => (int) $anchor['ts'], 'edits' => $edits + 1, 'text' => $message ], self::EDIT_WINDOW );
				return;
			}
		}
		// Mensaje nuevo (primer mensaje, ventana vencida, toca bajar o edit rechazado).
		$res = $this->bridge->send_message( $phone, $message );
		$this->store->log( $phone, 'out', $message, $action );
		$id  = is_array( $res ) ? (string) ( $res['id'] ?? '' ) : '';
		if ( $id !== '' ) {
			set_transient( $key, [ 'id' => $id, 'ts' => time(), 'edits' => 0, 'text' => $message ], self::EDIT_WINDOW );
		} else {
			delete_transient( $key );
		}
	}

	/** Comando "bajar": reenvía el último mensaje del bot al final del chat (nuevo). */
	private function bring_down( $phone ) {
		$anchor = get_transient( $this->anchor_key( $phone ) );
		$text   = is_array( $anchor ) ? (string) ( $anchor['text'] ?? '' ) : '';
		if ( $text === '' ) {
			return; // no hay nada que bajar todavía.
		}
		$this->deliver( $phone, $text, 'bring_down', true );
	}

	/** Comando "volver": sube un nivel de menú según el estado actual. */
	private function go_back( $phone, $state, $identity ) {
		$student_sub = [ 'stu_report_type', 'stu_report_cat', 'stu_report_body', 'stu_msg_to', 'stu_msg_body', 'stu_council_menu', 'stu_council_proposal', 'stu_settings_menu', 'stu_settings_name', 'stu_settings_phone' ];
		$staff_sub   = [
			'staff_comm_compose', 'staff_comm_template', 'staff_comm_audience', 'staff_comm_when', 'staff_comm_confirm', 'staff_comm_schedule',
			'staff_event_title', 'staff_event_date', 'staff_article_menu', 'staff_article_title', 'staff_article_body', 'staff_article_cat',
			'staff_article_edit_pick', 'staff_article_edit_body', 'staff_article_del_pick', 'staff_article_del_confirm',
			'staff_role_phone', 'staff_role_choose',
		];
		if ( in_array( $state, $student_sub, true ) ) {
			$this->back_to_student( $phone );
			return;
		}
		if ( in_array( $state, $staff_sub, true ) ) {
			$this->reenter_staff( $phone );
			return;
		}
		if ( $state === 'staff_menu' ) {
			// Del menú del rol, subir al selector (que también ofrece «Estudiantes»).
			$menus = $this->available_role_menus( $identity );
			if ( $menus ) {
				$this->enter_role_chooser( $phone, $identity, $menus );
			} else {
				$this->store->reset_state( $phone );
				$this->send( $phone, $this->m( 'goodbye' ) );
			}
			return;
		}
		// student_menu, role_chooser, idle u otros: volver al inicio.
		$this->idle( $phone, '', $identity );
	}
}
