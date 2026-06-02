<?php
/**
 * Esquema de las tablas del módulo WhatsApp (prefijo wp_cead_acad_wa_) + seed
 * de plantillas de mensajes. Idempotente vía dbDelta / INSERT IGNORE.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Tables {

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$session     = cead_acad_table( 'wa_session' );
		$registry    = cead_acad_table( 'wa_registry' );
		$state       = cead_acad_table( 'wa_state' );
		$messages    = cead_acad_table( 'wa_messages' );
		$reports     = cead_acad_table( 'wa_reports' );
		$suggestions = cead_acad_table( 'wa_suggestions' );
		$scheduled   = cead_acad_table( 'wa_scheduled' );
		$logs        = cead_acad_table( 'wa_logs' );

		dbDelta( "CREATE TABLE {$session} (
			id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bridge_url        VARCHAR(500) DEFAULT '',
			shared_token      VARCHAR(255) DEFAULT '',
			qr_data           LONGTEXT NULL,
			connection_status VARCHAR(50) NOT NULL DEFAULT 'disconnected',
			linked_number     VARCHAR(30) NULL,
			last_heartbeat    DATETIME NULL,
			PRIMARY KEY (id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$registry} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phone           VARCHAR(30) NOT NULL,
			user_id         BIGINT(20) UNSIGNED NULL,
			name            VARCHAR(120) DEFAULT '',
			opt_out         TINYINT(1) NOT NULL DEFAULT 0,
			event_reminders TINYINT(1) NOT NULL DEFAULT 0,
			registered_at   DATETIME NOT NULL,
			last_seen       DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY user_id (user_id),
			KEY opt_out (opt_out)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$state} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phone         VARCHAR(30) NOT NULL,
			current_state VARCHAR(100) NOT NULL DEFAULT 'idle',
			context_data  LONGTEXT NULL,
			updated_at    DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY updated_at (updated_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$messages} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			msg_key     VARCHAR(100) NOT NULL,
			content     LONGTEXT NOT NULL,
			description VARCHAR(255) DEFAULT '',
			updated_at  DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY msg_key (msg_key)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$reports} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ref_code   VARCHAR(20) NOT NULL,
			type       VARCHAR(20) NOT NULL DEFAULT 'anonymous',
			phone      VARCHAR(30) NULL,
			category   VARCHAR(60) DEFAULT '',
			body_enc   LONGTEXT NOT NULL,
			status     VARCHAR(20) NOT NULL DEFAULT 'new',
			note       LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY ref_code (ref_code),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$suggestions} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phone      VARCHAR(30) NULL,
			body       LONGTEXT NOT NULL,
			status     VARCHAR(20) NOT NULL DEFAULT 'new',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$scheduled} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			message    LONGTEXT NOT NULL,
			target     VARCHAR(20) NOT NULL DEFAULT 'all',
			run_at     DATETIME NOT NULL,
			status     VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_by VARCHAR(30) DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status_run (status, run_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$logs} (
			id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phone            VARCHAR(30) NOT NULL,
			direction        VARCHAR(3) NOT NULL,
			message_body     LONGTEXT NOT NULL,
			processed_action VARCHAR(100) DEFAULT NULL,
			created_at       DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY phone (phone),
			KEY created_at (created_at)
		) {$charset};" );

		// Fila única de sesión.
		$exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$session}" );
		if ( $exists === 0 ) {
			$wpdb->insert( $session, [ 'connection_status' => 'disconnected' ] );
		}

		self::seed_messages();
		self::seed_options();
	}

	public static function seed_messages() {
		global $wpdb;
		$t   = cead_acad_table( 'wa_messages' );
		$now = current_time( 'mysql' );
		foreach ( self::default_messages() as $key => $content ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$t} (msg_key, content, updated_at) VALUES (%s, %s, %s)",
				$key, $content, $now
			) );
		}
	}

	private static function seed_options() {
		if ( get_option( 'cead_acad_wa_site_links', null ) === null ) {
			update_option( 'cead_acad_wa_site_links', [
				[ 'label' => 'Sitio web del CEAD', 'url' => 'https://cead.caaguazu.net' ],
				[ 'label' => 'Panel del alumnado',  'url' => home_url( '/panel' ) ],
			], false );
		}
		if ( get_option( 'cead_acad_wa_contacts', null ) === null ) {
			update_option( 'cead_acad_wa_contacts', [
				[ 'name' => 'Administración', 'detail' => 'Lun a Vie 7:00–13:00' ],
				[ 'name' => 'Secretaría',     'detail' => 'Lun a Vie 7:00–13:00' ],
				[ 'name' => 'Consejo Estudiantil', 'detail' => 'consejo@cead.caaguazu.net' ],
			], false );
		}
		if ( get_option( 'cead_acad_wa_report_categories', null ) === null ) {
			update_option( 'cead_acad_wa_report_categories', [ 'Bullying / acoso', 'Seguridad', 'Infraestructura', 'Otro' ], false );
		}
		if ( get_option( 'cead_acad_wa_faq', null ) === null ) {
			update_option( 'cead_acad_wa_faq', [
				[ 'q' => '¿Cómo pido una constancia de alumno regular?', 'a' => 'Solicitala en Secretaría de lunes a viernes de 7:00 a 13:00.' ],
				[ 'q' => '¿Cuándo son las inscripciones?', 'a' => 'Las fechas se publican en el calendario de eventos y en el sitio web.' ],
			], false );
		}
		if ( get_option( 'cead_acad_wa_council_board', null ) === null ) {
			update_option( 'cead_acad_wa_council_board', "El Consejo Estudiantil está trabajando en mejoras de espacios comunes y actividades.\n¡Tus propuestas son bienvenidas!", false );
		}
		if ( get_option( 'cead_acad_wa_reminder_days', null ) === null ) {
			update_option( 'cead_acad_wa_reminder_days', 1, false );
		}
		if ( get_option( 'cead_acad_wa_report_forward_number', null ) === null ) {
			update_option( 'cead_acad_wa_report_forward_number', '', false );
		}
	}

	/**
	 * Plantillas por defecto del bot (editables luego desde wp-admin).
	 */
	public static function default_messages() {
		return [
			'greeting_staff'   => 'Hola {name}! 👋 Panel del personal del CEAD.',
			'staff_menu_header'=> '*Panel del personal* — ¿qué querés hacer?',
			'greeting_student' => 'Hola {name}! 👋 Bienvenido/a al bot del CEAD. ¿En qué te ayudo?',
			'student_menu'     => "*Menú CEAD*\n1. Horarios\n2. Sitio web\n3. Calendario de eventos\n4. Contacto\n5. Comunicados\n6. Reportar algo\n7. Sugerencias y quejas\n8. Preguntas frecuentes\n9. Consejo Estudiantil\n10. Recordatorios de eventos\n0. Salir\n\nEnviá *BAJA* para no recibir mensajes.",
			'opt_out_confirmed'=> 'Listo, ya no vas a recibir mensajes de este bot. Escribinos de nuevo para volver.',
			'goodbye'          => '¡Hasta luego! 👋 Escribí cuando quieras para volver al menú.',
			'invalid_option'   => 'Opción no válida. Elegí una de las opciones del menú.',
			'error_generic'    => 'Ocurrió un error. Probá de nuevo más tarde.',
			'identify_hint'    => 'ℹ️ Te respondo con información general. Si tu número está registrado en el colegio, te muestro tus datos personalizados.',

			// Horarios (A1)
			'horario_none'     => 'No tengo horarios/eventos próximos cargados.',
			'horario_header'   => '📚 *Tus próximos horarios y eventos*',
			'horario_general'  => '📅 *Próximos eventos del colegio*',
			'horario_now'      => '⏰ *Ahora:* {now}',
			'horario_next'     => '➡️ *Sigue:* {next}',

			// Sitio / calendario / contacto
			'site_links_header'=> '🌐 *Sitio web del CEAD*',
			'events_header'    => '📅 *Próximos eventos*',
			'events_none'      => 'No hay eventos próximos cargados.',
			'contact_header'   => '☎️ *Contactos del CEAD*',

			// Comunicados (lectura alumno)
			'comm_read_header' => '📣 *Últimos comunicados*',
			'comm_read_none'   => 'No tenés comunicados por ahora.',

			// Reporte (A5)
			'report_type_prompt'    => "🛡️ Canal para reportar situaciones (bullying, seguridad, etc.).\n¿Cómo querés enviarlo?\n1. Anónimo total\n2. Confidencial (quiero que me contacten)\n0. Cancelar",
			'report_category_prompt'=> "¿Sobre qué es el reporte?\n{category_list}\n0. Cancelar",
			'report_body_prompt'    => 'Escribí tu reporte con el mayor detalle posible. Se trata con confidencialidad. (0 para cancelar)',
			'report_saved_conf'     => "✅ Reporte recibido. Código de seguimiento: *{ref}*.\nEl equipo de moderación podrá contactarte.",
			'report_saved_anon'     => "✅ Reporte anónimo recibido. Gracias por confiar.\nCódigo de referencia: *{ref}*.",
			'report_cancelled'      => 'Reporte cancelado. No se guardó nada.',

			// Sugerencias (A6)
			'suggestion_prompt'  => 'Escribí tu sugerencia o queja (0 para cancelar):',
			'suggestion_saved'   => '✅ ¡Gracias! Tu mensaje fue registrado.',
			'suggestion_cancelled'=> 'Cancelado.',

			// FAQ / consejo (A7/A8)
			'faq_header'         => '❓ *Preguntas frecuentes*',
			'faq_none'           => 'Todavía no hay preguntas frecuentes cargadas.',
			'council_header'     => '📌 *Tablón del Consejo Estudiantil*',
			'council_menu'       => "1. Enviar una propuesta al consejo\n0. Volver",
			'council_proposal_prompt'=> 'Escribí tu propuesta para el Consejo Estudiantil (0 para cancelar):',
			'council_proposal_saved' => '✅ ¡Gracias! Tu propuesta fue enviada al Consejo.',

			// Recordatorios (A3 opt-in)
			'reminders_on'   => '🔔 Recordatorios de eventos *activados*.',
			'reminders_off'  => '🔕 Recordatorios de eventos *desactivados*.',
			'event_reminder' => "🔔 *Recordatorio de eventos del CEAD*\n{events}",

			// Staff: comunicados (D2)
			'comm_compose_prompt'  => '✍️ Escribí el comunicado a enviar (0 para cancelar):',
			'comm_audience_prompt' => "¿A quién enviar?\n1. Alumnado\n2. Personal\n3. Todos\n0. Cancelar",
			'comm_confirm_prompt'  => "Se enviará a *{count}* destinatario(s).\n¿Confirmás? Respondé *SI* o *NO*.",
			'comm_empty'           => 'No hay destinatarios para esa audiencia.',
			'comm_queued'          => '📨 Comunicado encolado para {total} destinatario(s). Se envía de forma escalonada.',
			'comm_busy'            => 'Ya hay un envío en curso. Esperá a que termine.',
			'comm_cancelled'       => 'Comunicado cancelado.',

			// Staff: eventos (D7)
			'event_title_prompt' => 'Título del evento (0 para cancelar):',
			'event_date_prompt'  => 'Fecha y hora del evento (AAAA-MM-DD HH:MM), ej. 2026-07-15 07:00:',
			'event_date_invalid' => 'Formato inválido. Usá AAAA-MM-DD HH:MM.',
			'event_saved'        => '✅ Evento agregado al calendario.',

			// Staff: bandeja reportes (D5)
			'reports_inbox_header' => "📥 *Bandeja de reportes*\nNuevos: {new} · En revisión: {in_review} · Resueltos: {resolved}",
			'reports_list_prompt'  => "Elegí un reporte:\n{report_list}\n0. Volver",
			'reports_empty'        => 'No hay reportes nuevos ni en revisión.',
			'report_actions_prompt'=> "1. Marcar en revisión\n2. Marcar resuelto\n3. Agregar nota\n0. Volver",
			'report_note_prompt'   => 'Escribí la nota de seguimiento (0 para cancelar):',
			'report_updated'       => '✅ Reporte actualizado.',

			// Staff: bandeja sugerencias (D6)
			'sugg_list_prompt'  => "Elegí una sugerencia:\n{sugg_list}\n0. Volver",
			'sugg_empty'        => 'No hay sugerencias pendientes.',
			'sugg_actions_prompt'=> "1. Marcar en revisión\n2. Marcar resuelta\n0. Volver",
			'sugg_updated'      => '✅ Sugerencia actualizada.',

			// Staff: métricas (D9)
			'metrics_header'    => '📊 *Métricas (últimos 30 días)*',
			'access_denied'     => '🔒 No tenés permisos para esa acción.',
		];
	}
}
