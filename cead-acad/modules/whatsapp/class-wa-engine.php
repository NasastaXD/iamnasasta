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
		if ( ! $existing ) {
			$this->store->upsert_number( $phone, [ 'name' => $name, 'user_id' => $identity['user_id'] ] );
		} elseif ( $identity['user_id'] && empty( $existing->user_id ) ) {
			$this->store->upsert_number( $phone, [ 'user_id' => $identity['user_id'] ] );
		}
		$this->store->update_last_seen( $phone );

		$st      = $this->store->get_state( $phone );
		$state   = $st['state'];
		$context = $st['context'];

		// Log redactado para reportes sensibles.
		$this->log_inbound( $phone, $state, $context, $body !== '' ? $body : '[imagen]' );

		if ( $this->store->is_opted_out( $phone ) ) {
			return;
		}

		// Todas las respuestas de este turno se acumulan y se entregan juntas.
		$this->outbox = [];

		$lc = strtolower( trim( $body ) );
		if ( $lc === 'baja' ) {
			$this->store->set_opt_out( $phone, true );
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'opt_out_confirmed' ), 'opt_out' );
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

		// Atajos rápidos de staff (-AA / -AE), solo en estados de menú.
		if ( $body !== '' && $this->maybe_handle_shortcut( $phone, $body, $state, $identity ) ) {
			$this->flush_outbox( $phone );
			return;
		}

		$this->dispatch( $phone, $body, $lc, $state, $context, $name, $identity, $media );
		$this->flush_outbox( $phone );
	}

	private function log_inbound( $phone, $state, $context, $body ) {
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
			case 'idle':                 $this->idle( $phone, $name, $identity ); break;
			case 'menu_pending':         $this->menu_pending( $phone, $identity, $context ); break;
			case 'role_chooser':         $this->role_chooser( $phone, $lc, $context, $identity ); break;
			// Alumnado
			case 'student_menu':         $this->student_menu( $phone, $lc, $identity ); break;
			case 'stu_report_type':      $this->report_type( $phone, $lc ); break;
			case 'stu_report_cat':       $this->report_cat( $phone, $lc, $context ); break;
			case 'stu_report_body':      $this->report_body( $phone, $body, $lc, $context ); break;
			case 'stu_suggestion_body':  $this->suggestion_body( $phone, $body, $lc, $identity ); break;
			case 'stu_council_menu':     $this->council_menu( $phone, $lc ); break;
			case 'stu_council_proposal': $this->council_proposal( $phone, $body, $lc, $identity ); break;
			// Staff
			case 'staff_menu':           $this->staff_menu( $phone, $lc, $context, $identity ); break;
			case 'staff_comm_compose':   $this->comm_compose( $phone, $body, $lc, $media ); break;
			case 'staff_comm_template':  $this->comm_template( $phone, $lc ); break;
			case 'staff_comm_audience':  $this->comm_audience( $phone, $lc, $context ); break;
			case 'staff_comm_when':      $this->comm_when( $phone, $lc, $context ); break;
			case 'staff_comm_confirm':   $this->comm_confirm( $phone, $lc, $context ); break;
			case 'staff_comm_schedule':  $this->comm_schedule( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_event_title':    $this->event_title( $phone, $body, $lc ); break;
			case 'staff_event_date':     $this->event_date( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_article_menu':   $this->article_menu( $phone, $lc, $identity ); break;
			case 'staff_article_title':  $this->article_title( $phone, $body, $lc ); break;
			case 'staff_article_body':   $this->article_body( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_article_edit_pick': $this->article_edit_pick( $phone, $lc, $context ); break;
			case 'staff_article_edit_body': $this->article_edit_body( $phone, $body, $lc, $context ); break;
			case 'staff_article_del_pick':  $this->article_del_pick( $phone, $lc, $context ); break;
			case 'staff_article_del_confirm': $this->article_del_confirm( $phone, $lc, $context ); break;
			case 'staff_role_phone':     $this->role_phone( $phone, $body, $lc ); break;
			case 'staff_role_choose':    $this->role_choose( $phone, $lc, $context, $identity ); break;
			case 'staff_reports_list':   $this->reports_list( $phone, $lc, $context ); break;
			case 'staff_report_view':    $this->report_view( $phone, $lc, $context ); break;
			case 'staff_report_note':    $this->report_note( $phone, $body, $lc, $context ); break;
			case 'staff_sugg_list':      $this->sugg_list( $phone, $lc, $context ); break;
			case 'staff_sugg_view':      $this->sugg_view( $phone, $lc, $context ); break;
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
			if ( ! Cead_Acad_WA_Identity::can( $identity['user_id'], 'cead_acad_publish_broadcast' ) ) {
				return false;
			}
			if ( $text === '' ) { $this->send( $phone, $this->m( 'shortcut_aa_usage' ) ); return true; }
			$this->create_broadcast_post( $text, 'all' );
			$res = $this->broadcaster->enqueue_for( $text, 'all' );
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
	private function idle( $phone, $name, $identity ) {
		$menus = $this->available_role_menus( $identity );
		if ( $menus ) {
			$greeting = $this->interp( $this->m( 'greeting_staff' ), [ 'name' => $name ?: 'Profe' ] );
			$this->enter_role_chooser( $phone, $identity, $menus, $greeting );
			return;
		}
		$greeting = $this->interp( $this->m( 'greeting_student' ), [ 'name' => $name ?: 'che' ] );
		$this->store->set_state( $phone, 'student_menu' );
		$this->send_menu( $phone, $greeting . "\n\n" . $this->m( 'student_menu' ) . "\n\n" . $this->m( 'panel_promo' ), 'student_menu' );
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
	private function student_menu( $phone, $lc, $identity ) {
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
			case '0': case 'salir': case 'adios': case 'adiós':
				$this->store->reset_state( $phone );
				$this->send( $phone, $this->m( 'goodbye' ) );
				break;
			default: $this->invalid( $phone );
		}
	}

	private function back_to_student( $phone ) {
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
		$this->store->set_state( $phone, 'stu_suggestion_body' );
		$this->send( $phone, $this->m( 'suggestion_prompt' ) . $this->cap_hint() );
	}

	private function suggestion_body( $phone, $body, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'suggestion_cancelled' ) ); $this->back_to_student( $phone ); return; }
		$this->store->create_suggestion( $phone, $body, 'administracion' );
		$this->send( $phone, $this->m( 'suggestion_saved' ), 'suggestion_received' );
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

	// ---------------------------------------------------------------- staff
	private function staff_menu( $phone, $lc, $context, $identity ) {
		if ( in_array( $lc, [ '0', 'salir', 'cancelar' ], true ) ) {
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'goodbye' ) );
			return;
		}
		$keys = $context['options'] ?? [];
		$idx  = (int) $lc - 1;
		if ( ! isset( $keys[ $idx ] ) ) { $this->invalid( $phone ); return; }
		switch ( $keys[ $idx ] ) {
			case 'comm':      $this->comm_start( $phone, $identity ); break;
			case 'event':     $this->event_start( $phone, $identity ); break;
			case 'articles':  $this->articles_start( $phone, $identity ); break;
			case 'reports':   $this->reports_open( $phone, $identity ); break;
			case 'sugg':      $this->sugg_open( $phone, $identity ); break;
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

	private function comm_confirm( $phone, $lc, $context ) {
		if ( in_array( $lc, [ 'si', 'sí', 'yes' ], true ) ) {
			$message = (string) ( $context['message'] ?? '' );
			$target  = (string) ( $context['target'] ?? 'all' );
			$image   = $context['image'] ?? null;
			// Crear el comunicado como post (se ve en el panel web) y enviarlo por WA.
			$this->create_broadcast_post( $message, $target, $image );
			$res = $this->broadcaster->enqueue_for( $message, $target, $image );
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
		$run = $this->parse_datetime( trim( $body ) );
		if ( $run === null ) { $this->send( $phone, $this->m( 'datetime_invalid' ) ); return; }
		$this->store->create_scheduled( (string) ( $context['message'] ?? '' ), (string) ( $context['target'] ?? 'all' ), $run, $phone );
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

	private function article_body( $phone, $body, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$pid = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => (string) ( $context['title'] ?? 'Artículo' ),
			'post_content' => $body,
			'post_author'  => (int) ( $identity['user_id'] ?: 0 ),
		], true );
		if ( is_wp_error( $pid ) ) { $this->send( $phone, $this->m( 'error_generic' ) ); $this->reenter_staff( $phone ); return; }
		$this->send( $phone, $this->interp( $this->m( 'article_published' ), [ 'url' => get_permalink( $pid ) ] ), 'article_published' );
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

	private function article_edit_body( $phone, $body, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
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

	private function article_del_confirm( $phone, $lc, $context ) {
		$pid = (int) ( $context['post_id'] ?? 0 );
		if ( in_array( $lc, [ 'si', 'sí', 'yes' ], true ) ) {
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
		// Whitelist: solo roles no-administrativos.
		$map = [ '1' => 'cead_acad_teacher', '2' => 'cead_acad_delegate', '3' => 'cead_acad_student_council' ];
		if ( ! isset( $map[ $lc ] ) ) { $this->invalid( $phone ); return; }
		$target = (string) ( $context['target'] ?? '' );
		$res    = $this->assign_role_to_phone( $target, $map[ $lc ] );
		$key    = $res['created'] ? 'role_assigned_new' : 'role_assigned';
		$this->send( $phone, $this->interp( $this->m( $key ), [ 'phone' => $target, 'role' => $res['label'] ] ), 'role_assigned' );
		$this->reenter_staff( $phone );
	}

	private function assign_role_to_phone( $target, $role ) {
		$labels   = [ 'cead_acad_teacher' => 'Docente', 'cead_acad_delegate' => 'Delegado', 'cead_acad_student_council' => 'Consejo Estudiantil' ];
		$identity = Cead_Acad_WA_Identity::resolve( $target );
		if ( $identity['user_id'] ) {
			$u = get_user_by( 'id', $identity['user_id'] );
			if ( $u ) { $u->add_role( $role ); }
			return [ 'created' => false, 'label' => $labels[ $role ] ?? $role ];
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
		if ( ! is_wp_error( $uid ) ) {
			update_user_meta( $uid, '_cead_acad_phone', $target );
		}
		return [ 'created' => true, 'label' => $labels[ $role ] ?? $role ];
	}

	// D5 Bandeja de reportes
	private function reports_open( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_manage_reports' ) ) { return; }
		$counts  = $this->store->count_reports_by_status();
		$reports = array_merge( $this->store->reports_by_status( 'new', 15 ), $this->store->reports_by_status( 'in_review', 15 ) );
		$header  = $this->interp( $this->m( 'reports_inbox_header' ), [ 'new' => $counts['new'], 'in_review' => $counts['in_review'], 'resolved' => $counts['resolved'] ] );
		if ( empty( $reports ) ) { $this->send( $phone, $header . "\n\n" . $this->m( 'reports_empty' ) ); $this->reenter_staff( $phone ); return; }
		$ids = []; $lines = [];
		foreach ( $reports as $i => $r ) {
			$ids[] = (int) $r->id;
			$lines[] = ( $i + 1 ) . '. ' . $r->ref_code . ' · ' . ( $r->type === 'confidential' ? 'Confidencial' : 'Anónimo' ) . ' · ' . $this->status_label( $r->status );
		}
		$this->store->set_state( $phone, 'staff_reports_list', [ 'ids' => $ids ] );
		$this->send( $phone, $header );
		$this->send( $phone, $this->interp( $this->m( 'reports_list_prompt' ), [ 'report_list' => implode( "\n", $lines ) ] ) );
	}

	private function reports_list( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$ids = $context['ids'] ?? [];
		$idx = (int) $lc - 1;
		if ( ! isset( $ids[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$this->show_report( $phone, (int) $ids[ $idx ] );
	}

	private function show_report( $phone, $id ) {
		$r = $this->store->get_report( $id );
		if ( ! $r ) { $this->send( $phone, $this->m( 'error_generic' ) ); return; }
		$body = Cead_Acad_WA_Crypto::decrypt( (string) $r->body_enc );
		$lines = [ '📄 *' . $r->ref_code . '*', 'Tipo: ' . ( $r->type === 'confidential' ? 'Confidencial' : 'Anónimo' ), 'Estado: ' . $this->status_label( $r->status ), 'Tema: ' . ( $r->category !== '' ? $r->category : '—' ) ];
		if ( $r->type === 'confidential' && ! empty( $r->phone ) ) { $lines[] = 'Contacto: +' . $r->phone; }
		$lines[] = ''; $lines[] = $body !== null ? $body : '⚠️ No se pudo descifrar (clave cambiada).';
		if ( trim( (string) $r->note ) !== '' ) { $lines[] = ''; $lines[] = '📝 Notas:'; $lines[] = (string) $r->note; }
		$this->store->set_state( $phone, 'staff_report_view', [ 'report_id' => $id ] );
		$this->send( $phone, implode( "\n", $lines ) );
		$this->send( $phone, $this->m( 'report_actions_prompt' ) );
	}

	private function report_view( $phone, $lc, $context ) {
		$id = (int) ( $context['report_id'] ?? 0 );
		switch ( $lc ) {
			case '1': $this->store->update_report_status( $id, 'in_review' ); $this->send( $phone, $this->m( 'report_updated' ) ); $this->show_report( $phone, $id ); break;
			case '2': $this->store->update_report_status( $id, 'resolved' ); $this->send( $phone, $this->m( 'report_updated' ) ); $this->reports_open_keep( $phone ); break;
			case '3': $this->store->set_state( $phone, 'staff_report_note', [ 'report_id' => $id ] ); $this->send( $phone, $this->m( 'report_note_prompt' ) ); break;
			case '0': case 'volver': $this->reports_open_keep( $phone ); break;
			default: $this->invalid( $phone );
		}
	}

	private function reports_open_keep( $phone ) {
		// Reabrir la bandeja sin recomputar caps (ya validadas al entrar).
		$counts  = $this->store->count_reports_by_status();
		$reports = array_merge( $this->store->reports_by_status( 'new', 15 ), $this->store->reports_by_status( 'in_review', 15 ) );
		$header  = $this->interp( $this->m( 'reports_inbox_header' ), [ 'new' => $counts['new'], 'in_review' => $counts['in_review'], 'resolved' => $counts['resolved'] ] );
		if ( empty( $reports ) ) {
			$this->send( $phone, $header . "\n\n" . $this->m( 'reports_empty' ) );
			$this->store->set_state( $phone, 'idle' );
			$this->send( $phone, $this->m( 'goodbye' ) );
			return;
		}
		$ids = []; $lines = [];
		foreach ( $reports as $i => $r ) {
			$ids[] = (int) $r->id;
			$lines[] = ( $i + 1 ) . '. ' . $r->ref_code . ' · ' . $this->status_label( $r->status );
		}
		$this->store->set_state( $phone, 'staff_reports_list', [ 'ids' => $ids ] );
		$this->send( $phone, $this->interp( $this->m( 'reports_list_prompt' ), [ 'report_list' => implode( "\n", $lines ) ] ) );
	}

	private function report_note( $phone, $body, $lc, $context ) {
		$id = (int) ( $context['report_id'] ?? 0 );
		if ( $this->is_cancel( $lc ) ) { $this->show_report( $phone, $id ); return; }
		$this->store->append_report_note( $id, $body );
		$this->send( $phone, $this->m( 'report_updated' ) );
		$this->show_report( $phone, $id );
	}

	// D6 Bandeja de sugerencias
	private function sugg_open( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_manage_reports' ) ) { return; }
		$items = array_merge( $this->store->suggestions_by_status( 'new', 15 ), $this->store->suggestions_by_status( 'in_review', 15 ) );
		if ( empty( $items ) ) { $this->send( $phone, $this->m( 'sugg_empty' ) ); $this->reenter_staff( $phone ); return; }
		$ids = []; $lines = [];
		foreach ( $items as $i => $s ) {
			$ids[] = (int) $s->id;
			$lines[] = ( $i + 1 ) . '. ' . $this->status_label( $s->status ) . ' · ' . mb_substr( (string) $s->body, 0, 40 );
		}
		$this->store->set_state( $phone, 'staff_sugg_list', [ 'ids' => $ids ] );
		$this->send( $phone, $this->interp( $this->m( 'sugg_list_prompt' ), [ 'sugg_list' => implode( "\n", $lines ) ] ) );
	}

	private function sugg_list( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->reenter_staff( $phone ); return; }
		$ids = $context['ids'] ?? [];
		$idx = (int) $lc - 1;
		if ( ! isset( $ids[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$this->show_suggestion( $phone, (int) $ids[ $idx ] );
	}

	private function show_suggestion( $phone, $id ) {
		$s = $this->store->get_suggestion( $id );
		if ( ! $s ) { $this->send( $phone, $this->m( 'error_generic' ) ); return; }
		$lines = [ '💬 *Sugerencia*', 'Estado: ' . $this->status_label( $s->status ), ! empty( $s->phone ) ? 'De: +' . $s->phone : 'De: (sin número)', '', (string) $s->body ];
		$this->store->set_state( $phone, 'staff_sugg_view', [ 'sugg_id' => $id ] );
		$this->send( $phone, implode( "\n", $lines ) );
		$this->send( $phone, $this->m( 'sugg_actions_prompt' ) );
	}

	private function sugg_view( $phone, $lc, $context ) {
		$id = (int) ( $context['sugg_id'] ?? 0 );
		switch ( $lc ) {
			case '1': $this->store->update_suggestion_status( $id, 'in_review' ); $this->send( $phone, $this->m( 'sugg_updated' ) ); $this->show_suggestion( $phone, $id ); break;
			case '2': $this->store->update_suggestion_status( $id, 'resolved' ); $this->send( $phone, $this->m( 'sugg_updated' ) ); $this->store->set_state( $phone, 'idle' ); $this->send( $phone, $this->m( 'goodbye' ) ); break;
			case '0': case 'volver': $this->store->set_state( $phone, 'idle' ); $this->send( $phone, $this->m( 'goodbye' ) ); break;
			default: $this->invalid( $phone );
		}
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

	private function status_label( $s ) {
		return match ( $s ) {
			'new'       => '🆕 Nuevo',
			'in_review' => '🔎 En revisión',
			'resolved'  => '✅ Resuelto',
			default     => $s,
		};
	}

	private function human_date( $ymd ) {
		$ts = strtotime( $ymd );
		return $ts ? date_i18n( 'D d/m', $ts ) : $ymd;
	}

	private function parse_datetime( $input ) {
		$dt = DateTime::createFromFormat( 'Y-m-d H:i', $input );
		if ( ! $dt || $dt->format( 'Y-m-d H:i' ) !== $input ) { return null; }
		return $dt->format( 'Y-m-d H:i:s' );
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
		return Cead_Acad_WA_Identity::resolve( $phone );
	}

	// --------------------------------------------------- filtro de lenguaje
	/** Estados donde el usuario escribe texto libre que se guarda/reenvía. */
	private function is_free_text_state( $state ) {
		return in_array( $state, [ 'stu_report_body', 'stu_suggestion_body', 'stu_council_proposal', 'staff_comm_compose' ], true );
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

	private function normalize_text( $s ) {
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
		$force_new       = $this->force_new;
		$this->force_new = false;
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
		if ( count( $menus ) === 1 ) {
			$this->enter_role_menu( $phone, array_key_first( $menus ), $identity );
		} elseif ( $menus ) {
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
		$student_sub = [ 'stu_report_type', 'stu_report_cat', 'stu_report_body', 'stu_suggestion_body', 'stu_council_menu', 'stu_council_proposal' ];
		$staff_sub   = [
			'staff_comm_compose', 'staff_comm_template', 'staff_comm_audience', 'staff_comm_when', 'staff_comm_confirm', 'staff_comm_schedule',
			'staff_event_title', 'staff_event_date', 'staff_article_menu', 'staff_article_title', 'staff_article_body',
			'staff_article_edit_pick', 'staff_article_edit_body', 'staff_article_del_pick', 'staff_article_del_confirm',
			'staff_role_phone', 'staff_role_choose', 'staff_reports_list', 'staff_report_view', 'staff_report_note',
			'staff_sugg_list', 'staff_sugg_view',
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
			// Del menú del rol, subir al selector (si hay varios) o salir.
			$menus = $this->available_role_menus( $identity );
			if ( count( $menus ) > 1 ) {
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
