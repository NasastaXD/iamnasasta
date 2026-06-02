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

	public function __construct( Cead_Acad_WA_Store $store, Cead_Acad_WA_Bridge_Client $bridge, Cead_Acad_WA_Broadcaster $broadcaster ) {
		$this->store       = $store;
		$this->bridge      = $bridge;
		$this->broadcaster = $broadcaster;
	}

	public function process_message( array $msg ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) ( $msg['from'] ?? '' ) );
		$body  = sanitize_textarea_field( (string) ( $msg['body'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $msg['pushName'] ?? '' ) );
		if ( $phone === '' || $body === '' ) {
			return;
		}

		$identity = Cead_Acad_WA_Identity::resolve( $phone );

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
		$this->log_inbound( $phone, $state, $context, $body );

		if ( $this->store->is_opted_out( $phone ) ) {
			return;
		}

		$lc = strtolower( trim( $body ) );
		if ( $lc === 'baja' ) {
			$this->store->set_opt_out( $phone, true );
			$this->store->reset_state( $phone );
			$this->send( $phone, $this->m( 'opt_out_confirmed' ), 'opt_out' );
			return;
		}

		$this->dispatch( $phone, $body, $lc, $state, $context, $name, $identity );
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

	private function dispatch( $phone, $body, $lc, $state, $context, $name, $identity ) {
		switch ( $state ) {
			case 'idle':                 $this->idle( $phone, $name, $identity ); break;
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
			case 'staff_comm_compose':   $this->comm_compose( $phone, $body, $lc ); break;
			case 'staff_comm_template':  $this->comm_template( $phone, $lc ); break;
			case 'staff_comm_audience':  $this->comm_audience( $phone, $lc, $context ); break;
			case 'staff_comm_when':      $this->comm_when( $phone, $lc, $context ); break;
			case 'staff_comm_confirm':   $this->comm_confirm( $phone, $lc, $context ); break;
			case 'staff_comm_schedule':  $this->comm_schedule( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_event_title':    $this->event_title( $phone, $body, $lc ); break;
			case 'staff_event_date':     $this->event_date( $phone, $body, $lc, $context, $identity ); break;
			case 'staff_reports_list':   $this->reports_list( $phone, $lc, $context ); break;
			case 'staff_report_view':    $this->report_view( $phone, $lc, $context ); break;
			case 'staff_report_note':    $this->report_note( $phone, $body, $lc, $context ); break;
			case 'staff_sugg_list':      $this->sugg_list( $phone, $lc, $context ); break;
			case 'staff_sugg_view':      $this->sugg_view( $phone, $lc, $context ); break;
			default:                     $this->idle( $phone, $name, $identity );
		}
	}

	// ---------------------------------------------------------------- idle
	private function idle( $phone, $name, $identity ) {
		if ( $identity['is_staff'] ) {
			$greeting = $this->interp( $this->m( 'greeting_staff' ), [ 'name' => $name ?: 'Profe' ] );
			$this->enter_staff_menu( $phone, $identity, $greeting );
		} else {
			$greeting = $this->interp( $this->m( 'greeting_student' ), [ 'name' => $name ?: 'che' ] );
			$this->store->set_state( $phone, 'student_menu' );
			$this->send( $phone, $greeting . "\n\n" . $this->m( 'student_menu' ) );
		}
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
			case '0': case 'salir': case 'adios': case 'adiós':
				$this->store->reset_state( $phone );
				$this->send( $phone, $this->m( 'goodbye' ) );
				break;
			default: $this->invalid( $phone );
		}
	}

	private function back_to_student( $phone ) {
		$this->store->set_state( $phone, 'student_menu' );
		$this->send( $phone, $this->m( 'student_menu' ) );
	}

	// A1 Horarios
	private function show_horario( $phone, $identity ) {
		$now = current_time( 'mysql' );
		$to  = date( 'Y-m-d H:i:s', strtotime( $now . ' +60 day' ) );
		$events = [];
		if ( $identity['user_id'] ) {
			$events = Cead_Acad_Schedule_Feed::for_user( $identity['user_id'], $now, $to, 50 );
		}
		$personalized = ! empty( $events );
		if ( ! $personalized ) {
			$events = $this->general_events( 50 );
		}
		if ( empty( $events ) ) {
			$this->send( $phone, $this->m( 'horario_none' ) );
			$this->back_to_student( $phone );
			return;
		}
		$header = $personalized ? $this->m( 'horario_header' ) : $this->m( 'horario_general' );
		$this->send( $phone, $this->build_agenda( $header, $events ) );
		if ( ! $identity['user_id'] ) {
			$this->send( $phone, $this->m( 'identify_hint' ) );
		}
		$this->back_to_student( $phone );
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
		$this->store->set_state( $phone, 'stu_report_type' );
		$this->send( $phone, $this->m( 'report_type_prompt' ) );
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
		$this->forward_report( $ref, $type, $cat, $body, $type === 'confidential' ? $phone : null );
		$key = $type === 'anonymous' ? 'report_saved_anon' : 'report_saved_conf';
		$this->send( $phone, $this->interp( $this->m( $key ), [ 'ref' => $ref ] ), 'report_received' );
		$this->back_to_student( $phone );
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
		$this->store->set_state( $phone, 'stu_suggestion_body' );
		$this->send( $phone, $this->m( 'suggestion_prompt' ) );
	}

	private function suggestion_body( $phone, $body, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'suggestion_cancelled' ) ); $this->back_to_student( $phone ); return; }
		$this->store->create_suggestion( $phone, $body );
		$this->send( $phone, $this->m( 'suggestion_saved' ), 'suggestion_received' );
		$this->back_to_student( $phone );
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
			$this->store->set_state( $phone, 'stu_council_proposal' );
			$this->send( $phone, $this->m( 'council_proposal_prompt' ) );
		} elseif ( $this->is_cancel( $lc ) ) {
			$this->back_to_student( $phone );
		} else {
			$this->invalid( $phone );
		}
	}

	private function council_proposal( $phone, $body, $lc, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->back_to_student( $phone ); return; }
		$this->store->create_suggestion( $phone, '[Propuesta al Consejo] ' . $body );
		$this->send( $phone, $this->m( 'council_proposal_saved' ), 'council_proposal' );
		$this->back_to_student( $phone );
	}

	// Recordatorios opt-in
	private function reminders_toggle( $phone ) {
		$new = ! $this->store->has_event_reminders( $phone );
		$this->store->set_event_reminders( $phone, $new );
		$this->send( $phone, $this->m( $new ? 'reminders_on' : 'reminders_off' ), 'reminders_toggle' );
		$this->back_to_student( $phone );
	}

	// ---------------------------------------------------------------- staff
	private function staff_options( $uid ) {
		$opts = [];
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_publish_broadcast' ) ) {
			$opts[] = [ 'key' => 'comm',  'label' => 'Enviar comunicado' ];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_schedule' ) ) {
			$opts[] = [ 'key' => 'event', 'label' => 'Agregar evento al calendario' ];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_manage_reports' ) ) {
			$opts[] = [ 'key' => 'reports', 'label' => 'Bandeja de reportes' ];
			$opts[] = [ 'key' => 'sugg',    'label' => 'Bandeja de sugerencias' ];
		}
		if ( Cead_Acad_WA_Identity::can( $uid, 'cead_acad_view_metrics' ) ) {
			$opts[] = [ 'key' => 'metrics', 'label' => 'Métricas' ];
		}
		return $opts;
	}

	private function enter_staff_menu( $phone, $identity, $prefix = '' ) {
		$opts = $this->staff_options( $identity['user_id'] );
		$this->store->set_state( $phone, 'staff_menu', [ 'options' => array_column( $opts, 'key' ) ] );
		$lines = [ $this->m( 'staff_menu_header' ) ];
		foreach ( $opts as $i => $o ) { $lines[] = ( $i + 1 ) . '. ' . $o['label']; }
		$lines[] = '0. Salir';
		$this->send( $phone, ( $prefix !== '' ? $prefix . "\n\n" : '' ) . implode( "\n", $lines ) );
	}

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
			case 'comm':    $this->comm_start( $phone, $identity ); break;
			case 'event':   $this->event_start( $phone, $identity ); break;
			case 'reports': $this->reports_open( $phone, $identity ); break;
			case 'sugg':    $this->sugg_open( $phone, $identity ); break;
			case 'metrics': $this->metrics_show( $phone, $identity ); break;
			default:        $this->invalid( $phone );
		}
	}

	private function require_cap( $phone, $identity, $cap ) {
		if ( Cead_Acad_WA_Identity::can( $identity['user_id'], $cap ) ) { return true; }
		$this->send( $phone, $this->m( 'access_denied' ) );
		$this->enter_staff_menu( $phone, $identity );
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

	private function comm_compose( $phone, $body, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		if ( $lc === 'p' && $this->templates() ) {
			$this->store->set_state( $phone, 'staff_comm_template' );
			$lines = [ 'Elegí una plantilla:' ];
			foreach ( $this->templates() as $i => $t ) { $lines[] = ( $i + 1 ) . '. ' . ( $t['name'] ?? '' ); }
			$lines[] = '0. Cancelar';
			$this->send( $phone, implode( "\n", $lines ) );
			return;
		}
		$this->comm_ask_audience( $phone, $body );
	}

	private function comm_template( $phone, $lc ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		$tpls = $this->templates();
		$idx  = (int) $lc - 1;
		if ( ! isset( $tpls[ $idx ] ) ) { $this->invalid( $phone ); return; }
		$this->comm_ask_audience( $phone, (string) ( $tpls[ $idx ]['body'] ?? '' ) );
	}

	private function comm_ask_audience( $phone, $message ) {
		$this->store->set_state( $phone, 'staff_comm_audience', [ 'message' => $message ] );
		$this->send( $phone, $this->m( 'comm_audience_prompt' ) );
	}

	private function comm_audience( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		$target = $lc === '1' ? 'students' : ( $lc === '2' ? 'staff' : ( $lc === '3' ? 'all' : '' ) );
		if ( $target === '' ) { $this->invalid( $phone ); return; }
		$count = $this->broadcaster->count_for( $target );
		if ( $count === 0 ) { $this->send( $phone, $this->m( 'comm_empty' ) ); $this->reenter_staff( $phone ); return; }
		$this->store->set_state( $phone, 'staff_comm_when', [ 'message' => $context['message'] ?? '', 'target' => $target, 'count' => $count ] );
		$this->send( $phone, "¿Cuándo enviar?\n1. Ahora\n2. Programar fecha y hora\n0. Cancelar" );
	}

	private function comm_when( $phone, $lc, $context ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		if ( $lc === '1' ) {
			$this->store->set_state( $phone, 'staff_comm_confirm', [ 'message' => $context['message'] ?? '', 'target' => $context['target'] ?? 'all' ] );
			$this->send( $phone, $this->interp( $this->m( 'comm_confirm_prompt' ), [ 'count' => (int) ( $context['count'] ?? 0 ) ] ) );
		} elseif ( $lc === '2' ) {
			$this->store->set_state( $phone, 'staff_comm_schedule', [ 'message' => $context['message'] ?? '', 'target' => $context['target'] ?? 'all' ] );
			$this->send( $phone, 'Indicá fecha y hora (AAAA-MM-DD HH:MM), ej. 2026-07-15 07:00:' );
		} else {
			$this->invalid( $phone );
		}
	}

	private function comm_confirm( $phone, $lc, $context ) {
		if ( in_array( $lc, [ 'si', 'sí', 'yes' ], true ) ) {
			$res = $this->broadcaster->enqueue_for( (string) ( $context['message'] ?? '' ), (string) ( $context['target'] ?? 'all' ) );
			if ( ! empty( $res['busy'] ) ) { $this->send( $phone, $this->m( 'comm_busy' ) ); }
			elseif ( empty( $res['queued'] ) ) { $this->send( $phone, $this->m( 'comm_empty' ) ); }
			else { $this->send( $phone, $this->interp( $this->m( 'comm_queued' ), [ 'total' => (int) ( $res['total'] ?? 0 ) ] ), 'broadcast_enqueued' ); }
			$this->reenter_staff( $phone );
		} elseif ( in_array( $lc, [ 'no' ], true ) || $this->is_cancel( $lc ) ) {
			$this->send( $phone, $this->m( 'comm_cancelled' ) );
			$this->reenter_staff( $phone );
		} else {
			$this->send( $phone, 'Respondé *SI* o *NO*.' );
		}
	}

	private function comm_schedule( $phone, $body, $lc, $context, $identity ) {
		if ( $this->is_cancel( $lc ) ) { $this->send( $phone, $this->m( 'comm_cancelled' ) ); $this->reenter_staff( $phone ); return; }
		$run = $this->parse_datetime( trim( $body ) );
		if ( $run === null ) { $this->send( $phone, 'Formato inválido. Usá AAAA-MM-DD HH:MM.' ); return; }
		$this->store->create_scheduled( (string) ( $context['message'] ?? '' ), (string) ( $context['target'] ?? 'all' ), $run, $phone );
		$this->send( $phone, "🗓️ Comunicado programado para {$run}.", 'broadcast_scheduled' );
		$this->reenter_staff( $phone );
	}

	// D7 Eventos
	private function event_start( $phone, $identity ) {
		if ( ! $this->require_cap( $phone, $identity, 'cead_acad_manage_schedule' ) ) { return; }
		$this->store->set_state( $phone, 'staff_event_title' );
		$this->send( $phone, $this->m( 'event_title_prompt' ) );
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
		$this->reenter_staff( $phone );
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
		// Reconstruye el menú staff con la identidad actual del número.
		$identity = Cead_Acad_WA_Identity::resolve( $phone );
		$this->enter_staff_menu( $phone, $identity );
	}

	// ---------------------------------------------------------------- helpers
	private function general_events( $limit ) {
		global $wpdb;
		$aud = cead_acad_table( 'audiences' );
		$now = current_time( 'mysql' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT subject_id FROM {$aud} WHERE subject_type = 'event' AND audience_type = 'all'"
		) );
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

	private function invalid( $phone ) {
		$this->send( $phone, $this->m( 'invalid_option' ) );
	}

	private function send( $phone, $message, $action = '' ) {
		if ( trim( (string) $message ) === '' ) {
			error_log( '[CeadAcadWA] send omitido: mensaje vacío. acción=' . $action );
			return;
		}
		$this->bridge->send_message( $phone, $message );
		$this->store->log( $phone, 'out', $message, $action );
	}
}
