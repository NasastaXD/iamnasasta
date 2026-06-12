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
			response   LONGTEXT NULL,
			deleted_at DATETIME NULL,
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
			category   VARCHAR(20) NOT NULL DEFAULT 'administracion',
			status     VARCHAR(20) NOT NULL DEFAULT 'new',
			response   LONGTEXT NULL,
			deleted_at DATETIME NULL,
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
		foreach ( self::catalog() as $key => $meta ) {
			// No pisar el texto si el colegio ya lo editó: solo insertamos los
			// que falten. La descripción (metadato) sí se refresca siempre.
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$t} (msg_key, content, description, updated_at) VALUES (%s, %s, %s, %s)",
				$key, $meta['default'], $meta['label'], $now
			) );
			$wpdb->update( $t, [ 'description' => $meta['label'] ], [ 'msg_key' => $key ] );
		}

		// Migración suave: si el colegio NO editó el menú principal (sigue siendo
		// el default anterior, sin "12. Ajustes"), lo actualizamos al nuevo default.
		$old_menu = "*Menú CEAD*\n1. Horarios\n2. Sitio web\n3. Calendario de eventos\n4. Contacto\n5. Comunicados\n6. Reportar algo\n7. Sugerencias y quejas\n8. Preguntas frecuentes\n9. Consejo Estudiantil\n10. Recordatorios de eventos\n11. Mi panel web\n0. Salir\n\nEnviá *BAJA* para no recibir mensajes.";
		$current  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$t} WHERE msg_key = %s", 'student_menu' ) );
		if ( $current === $old_menu ) {
			$wpdb->update( $t, [ 'content' => self::default_messages()['student_menu'], 'updated_at' => $now ], [ 'msg_key' => 'student_menu' ] );
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
		if ( get_option( 'cead_acad_wa_country_code', null ) === null ) {
			update_option( 'cead_acad_wa_country_code', '595', false );
		}
		if ( get_option( 'cead_acad_wa_bot_number', null ) === null ) {
			update_option( 'cead_acad_wa_bot_number', '', false );
		}
		if ( get_option( 'cead_acad_banned_words', null ) === null ) {
			update_option( 'cead_acad_banned_words', '', false );
		}
		if ( get_option( 'cead_acad_panel_intro', null ) === null ) {
			update_option( 'cead_acad_panel_intro', 'El panel del CEAD es el espacio web donde alumnado y personal gestionan las cosas del colegio: comunicados, horarios, eventos, materiales y más, todo en un solo lugar.', false );
		}
		if ( get_option( 'cead_acad_ceadi_intro', null ) === null ) {
			update_option( 'cead_acad_ceadi_intro', 'CEADI es el asistente del colegio en WhatsApp. Te acerca comunicados, horarios y novedades, y te deja hacer consultas rápidas cuando las necesites.', false );
		}
	}

	/**
	 * Catálogo de mensajes = única fuente de verdad para el panel de edición.
	 * Cada clave: grupo (para agrupar en pantalla), etiqueta legible y texto por
	 * defecto. Para AGREGAR un mensaje nuevo: sumalo en message_meta() (grupo +
	 * etiqueta) y en default_messages() (texto), y usalo en el motor con m('clave').
	 */
	public static function catalog() {
		$defaults = self::default_messages();
		$out = [];
		foreach ( self::message_meta() as $key => $meta ) {
			$out[ $key ] = [ 'group' => $meta[0], 'label' => $meta[1], 'default' => $defaults[ $key ] ?? '' ];
		}
		// Cualquier clave sin metadato igual se incluye (no se pierde).
		foreach ( $defaults as $key => $text ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = [ 'group' => 'otros', 'label' => $key, 'default' => $text ];
			}
		}
		return $out;
	}

	/** Grupos del panel de mensajes, en orden de aparición. */
	public static function message_groups() {
		return [
			'menus'         => __( 'Menú principal', 'cead-acad' ),
			'sistema'       => __( 'Saludos y sistema', 'cead-acad' ),
			'info'          => __( 'Información para alumnado', 'cead-acad' ),
			'reportes'      => __( 'Reportes', 'cead-acad' ),
			'sugerencias'   => __( 'Sugerencias y Consejo', 'cead-acad' ),
			'recordatorios' => __( 'Recordatorios', 'cead-acad' ),
			'ajustes'       => __( 'Ajustes del usuario', 'cead-acad' ),
			'staff'         => __( 'Personal (staff)', 'cead-acad' ),
			'otros'         => __( 'Otros', 'cead-acad' ),
		];
	}

	/** Metadatos de cada mensaje: clave => [ grupo, etiqueta legible ]. */
	public static function message_meta() {
		return [
			// Menú
			'student_menu'      => [ 'menus', 'Menú principal del alumnado (las opciones se reconocen por número en el código)' ],
			'staff_menu_header' => [ 'menus', 'Encabezado del menú del personal' ],
			// Sistema / saludos
			'greeting_staff'    => [ 'sistema', 'Saludo al personal' ],
			'greeting_student'  => [ 'sistema', 'Saludo al alumnado' ],
			'opt_out_confirmed' => [ 'sistema', 'Confirmación de baja (BAJA)' ],
			'goodbye'           => [ 'sistema', 'Despedida / salir' ],
			'invalid_option'    => [ 'sistema', 'Opción inválida' ],
			'error_generic'     => [ 'sistema', 'Error genérico' ],
			'identify_hint'     => [ 'sistema', 'Aviso de respuesta general (número no reconocido)' ],
			'access_denied'     => [ 'sistema', 'Sin permisos' ],
			// Info alumnado
			'horario_none'      => [ 'info', 'Horarios: sin eventos' ],
			'horario_header'    => [ 'info', 'Horarios: encabezado personalizado' ],
			'horario_general'   => [ 'info', 'Horarios: encabezado general' ],
			'horario_now'       => [ 'info', 'Horarios: "Ahora"' ],
			'horario_next'      => [ 'info', 'Horarios: "Sigue"' ],
			'site_links_header' => [ 'info', 'Sitio web: encabezado' ],
			'events_header'     => [ 'info', 'Calendario: encabezado' ],
			'events_none'       => [ 'info', 'Calendario: sin eventos' ],
			'contact_header'    => [ 'info', 'Contacto: encabezado' ],
			'comm_read_header'  => [ 'info', 'Comunicados (lectura): encabezado' ],
			'comm_read_none'    => [ 'info', 'Comunicados (lectura): sin comunicados' ],
			// Reportes
			'report_type_prompt'     => [ 'reportes', 'Pregunta tipo de reporte' ],
			'report_category_prompt' => [ 'reportes', 'Pregunta categoría del reporte' ],
			'report_body_prompt'     => [ 'reportes', 'Pedido del texto del reporte' ],
			'report_saved_conf'      => [ 'reportes', 'Reporte confidencial guardado' ],
			'report_saved_anon'      => [ 'reportes', 'Reporte anónimo guardado' ],
			'report_cancelled'       => [ 'reportes', 'Reporte cancelado' ],
			'vulgar_detected'        => [ 'reportes', 'Filtro de lenguaje: palabra prohibida detectada (usa {words})' ],
			// Sugerencias / Consejo
			'suggestion_prompt'       => [ 'sugerencias', 'Pedido de sugerencia' ],
			'suggestion_saved'        => [ 'sugerencias', 'Sugerencia guardada' ],
			'suggestion_cancelled'    => [ 'sugerencias', 'Sugerencia cancelada' ],
			'faq_header'              => [ 'sugerencias', 'FAQ: encabezado' ],
			'faq_none'                => [ 'sugerencias', 'FAQ: vacío' ],
			'council_header'          => [ 'sugerencias', 'Consejo: encabezado del tablón' ],
			'council_menu'            => [ 'sugerencias', 'Consejo: submenú' ],
			'council_proposal_prompt' => [ 'sugerencias', 'Consejo: pedido de propuesta' ],
			'council_proposal_saved'  => [ 'sugerencias', 'Consejo: propuesta enviada' ],
			// Recordatorios
			'reminders_on'   => [ 'recordatorios', 'Recordatorios activados' ],
			'reminders_off'  => [ 'recordatorios', 'Recordatorios desactivados' ],
			'event_reminder' => [ 'recordatorios', 'Texto del recordatorio (usa {events})' ],
			// Ajustes (opción 12; los números se reconocen en el código)
			'settings_menu'            => [ 'ajustes', 'Ajustes: submenú (las opciones se reconocen por número en el código)' ],
			'settings_data_header'     => [ 'ajustes', 'Ajustes: encabezado de "Ver mis datos"' ],
			'settings_name_prompt'     => [ 'ajustes', 'Ajustes: pedir nombre nuevo' ],
			'settings_name_invalid'    => [ 'ajustes', 'Ajustes: nombre inválido' ],
			'settings_name_saved'      => [ 'ajustes', 'Ajustes: nombre guardado (usa {name})' ],
			'settings_phone_prompt'    => [ 'ajustes', 'Ajustes: pedir número nuevo' ],
			'settings_phone_invalid'   => [ 'ajustes', 'Ajustes: número inválido' ],
			'settings_phone_same'      => [ 'ajustes', 'Ajustes: el número nuevo es el actual' ],
			'settings_phone_requested' => [ 'ajustes', 'Ajustes: solicitud de cambio enviada (usa {new})' ],
			'settings_mode_ia_on'      => [ 'ajustes', 'Ajustes: modo asistente activado' ],
			'settings_mode_menu_on'    => [ 'ajustes', 'Ajustes: modo menú activado' ],
			'settings_mode_unavailable'=> [ 'ajustes', 'Ajustes: modo asistente no disponible' ],
			// Staff
			'comm_compose_prompt'   => [ 'staff', 'Comunicado: pedir texto' ],
			'comm_audience_prompt'  => [ 'staff', 'Comunicado: elegir audiencia' ],
			'comm_confirm_prompt'   => [ 'staff', 'Comunicado: confirmar (usa {count})' ],
			'comm_empty'            => [ 'staff', 'Comunicado: sin destinatarios' ],
			'comm_queued'           => [ 'staff', 'Comunicado: encolado (usa {total})' ],
			'comm_busy'             => [ 'staff', 'Comunicado: envío en curso' ],
			'comm_cancelled'        => [ 'staff', 'Comunicado: cancelado' ],
			'event_title_prompt'    => [ 'staff', 'Evento: pedir título' ],
			'event_date_prompt'     => [ 'staff', 'Evento: pedir fecha' ],
			'event_date_invalid'    => [ 'staff', 'Evento: fecha inválida' ],
			'event_saved'           => [ 'staff', 'Evento: guardado' ],
			'reports_inbox_header'  => [ 'staff', 'Reportes: encabezado de bandeja (usa {new}/{in_review}/{resolved})' ],
			'reports_list_prompt'   => [ 'staff', 'Reportes: lista (usa {report_list})' ],
			'reports_empty'         => [ 'staff', 'Reportes: bandeja vacía' ],
			'report_actions_prompt' => [ 'staff', 'Reportes: acciones' ],
			'report_note_prompt'    => [ 'staff', 'Reportes: pedir nota' ],
			'report_updated'        => [ 'staff', 'Reportes: actualizado' ],
			'sugg_list_prompt'      => [ 'staff', 'Sugerencias: lista (usa {sugg_list})' ],
			'sugg_empty'            => [ 'staff', 'Sugerencias: bandeja vacía' ],
			'sugg_actions_prompt'   => [ 'staff', 'Sugerencias: acciones' ],
			'sugg_updated'          => [ 'staff', 'Sugerencias: actualizada' ],
			'metrics_header'        => [ 'staff', 'Métricas: encabezado' ],
			'comm_when_prompt'      => [ 'staff', 'Comunicado: ¿cuándo enviar?' ],
			'comm_schedule_prompt'  => [ 'staff', 'Comunicado: pedir fecha de programación' ],
			'comm_scheduled_ok'     => [ 'staff', 'Comunicado: programado (usa {run})' ],
			'comm_schedule_no_image'=> [ 'staff', 'Comunicado: aviso imagen no va en programados' ],
			'image_attach_failed'   => [ 'staff', 'Comunicado: no se pudo adjuntar imagen' ],
			'datetime_invalid'      => [ 'staff', 'Fecha/hora inválida' ],
			'confirm_si_no'         => [ 'staff', 'Pedido de confirmación SI/NO' ],
			'shortcut_aa_usage'     => [ 'staff', 'Atajo -AA: uso' ],
			'shortcut_ae_usage'     => [ 'staff', 'Atajo -AE: uso' ],
			'article_pick_edit'     => [ 'staff', 'Artículos: elegir para editar' ],
			'article_pick_delete'   => [ 'staff', 'Artículos: elegir para borrar' ],
			'shortcuts_help'        => [ 'staff', 'Atajos: ayuda (-AA / -AE)' ],
			'shortcut_announce_ok'  => [ 'staff', 'Atajo -AA: anuncio publicado (usa {total})' ],
			'article_menu_prompt'   => [ 'staff', 'Artículos: submenú' ],
			'article_title_prompt'  => [ 'staff', 'Artículos: pedir título' ],
			'article_body_prompt'   => [ 'staff', 'Artículos: pedir contenido' ],
			'article_published'     => [ 'staff', 'Artículos: publicado (usa {url})' ],
			'article_none'          => [ 'staff', 'Artículos: ninguno' ],
			'article_edit_body_prompt' => [ 'staff', 'Artículos: pedir nuevo contenido' ],
			'article_updated'       => [ 'staff', 'Artículos: actualizado' ],
			'article_del_confirm'   => [ 'staff', 'Artículos: confirmar borrado (usa {title})' ],
			'article_deleted'       => [ 'staff', 'Artículos: borrado' ],
			'role_phone_prompt'     => [ 'staff', 'Roles: pedir número' ],
			'role_phone_invalid'    => [ 'staff', 'Roles: número inválido' ],
			'role_choose_prompt'    => [ 'staff', 'Roles: elegir rol' ],
			'role_assigned'         => [ 'staff', 'Roles: asignado (usa {role}/{phone})' ],
			'role_assigned_new'     => [ 'staff', 'Roles: usuario creado (usa {role}/{phone})' ],
			// Menú / info
			'role_chooser_header'   => [ 'menus', 'Selector de menú por rol' ],
			'panel_promo'           => [ 'info', 'Promoción del panel web' ],
		];
	}

	/**
	 * Plantillas por defecto del bot (texto). Editables luego desde wp-admin.
	 * Para cambiar un texto por defecto, editá acá; para que aparezca agrupado y
	 * con nombre claro en el panel, agregá su entrada en message_meta().
	 */
	public static function default_messages() {
		return [
			'greeting_staff'   => 'Hola {name}! 👋 Panel del personal del CEAD.',
			'staff_menu_header'=> '*Panel del personal* — ¿qué querés hacer?',
			'greeting_student' => 'Hola {name}! 👋 Bienvenido/a al bot del CEAD. ¿En qué te ayudo?',
			'student_menu'     => "*Menú CEAD*\n1. Horarios\n2. Sitio web\n3. Calendario de eventos\n4. Contacto\n5. Comunicados\n6. Reportar algo\n7. Sugerencias y quejas\n8. Preguntas frecuentes\n9. Consejo Estudiantil\n10. Recordatorios de eventos\n11. Mi panel web\n12. Ajustes\n0. Salir\n\nEnviá *BAJA* para no recibir mensajes.",
			'opt_out_confirmed'=> 'Listo, ya no vas a recibir mensajes de este bot. Escribinos de nuevo para volver.',
			'goodbye'          => '¡Hasta luego! 👋 Escribí cuando quieras para volver al menú.',
			'invalid_option'   => 'Opción no válida. Elegí una de las opciones del menú.',
			'error_generic'    => 'Ocurrió un error. Probá de nuevo más tarde.',
			'identify_hint'    => 'ℹ️ Te respondo con información general. Si tu número está registrado en el colegio, te muestro tus datos personalizados.',
			'vulgar_detected'  => '🚫 Lenguaje vulgar detectado, elimine las palabras: {words}',

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

			// Ajustes (A12)
			'settings_menu'            => "⚙️ *Ajustes*\n1. Ver mis datos\n2. Cambiar mi nombre\n3. Cambiar mi número\n4. Activar/desactivar modo asistente (IA)\n5. Recordatorios de eventos\n0. Volver",
			'settings_data_header'     => '📋 *Tus datos en el CEAD*',
			'settings_name_prompt'     => '✏️ Escribí tu nombre como querés que aparezca:',
			'settings_name_invalid'    => 'Ese nombre no parece válido (entre 3 y 60 caracteres). Probá de nuevo:',
			'settings_name_saved'      => '✅ Listo, {name}. Tu nombre quedó actualizado.',
			'settings_phone_prompt'    => "📱 Escribí tu número NUEVO (ej.: 0981123456).\nOjo: el cambio lo confirma Secretaría; hasta entonces seguís usando este número.",
			'settings_phone_invalid'   => 'Ese número no parece válido. Escribilo de nuevo (ej.: 0981123456):',
			'settings_phone_same'      => 'Ese ya es tu número actual 😉',
			'settings_phone_requested' => '✅ Solicitud enviada: tu número pasaría a {new}. Secretaría te va a confirmar cuando esté hecho.',
			'settings_mode_ia_on'      => '✅ Modo asistente activado. Escribime en lenguaje natural 💬',
			'settings_mode_menu_on'    => '✅ Modo menú activado. CEADI va a responder solo con el menú numérico. Escribí *modo asistente* si querés volver a la IA.',
			'settings_mode_unavailable'=> '⚠️ El modo asistente no está disponible en este momento.',

			// Staff: comunicados (D2)
			'comm_compose_prompt'  => '✍️ Escribí el comunicado a enviar (0 para cancelar):',
			'comm_audience_prompt' => "¿A quién enviar?\n1. Alumnado\n2. Personal\n3. Todos\n0. Cancelar",
			'comm_confirm_prompt'  => "Se enviará a *{count}* destinatario(s).\n¿Confirmás? Respondé *SI* o *NO*.",
			'comm_empty'           => 'No hay destinatarios para esa audiencia.',
			'comm_queued'          => '📨 Comunicado encolado para {total} destinatario(s). Se envía de forma escalonada.',
			'comm_busy'            => 'Ya hay un envío en curso. Esperá a que termine.',
			'comm_cancelled'       => 'Comunicado cancelado.',
			'comm_when_prompt'     => "¿Cuándo enviar?\n1. Ahora\n2. Programar fecha y hora\n0. Cancelar",
			'comm_schedule_prompt' => 'Indicá fecha y hora (AAAA-MM-DD HH:MM), ej. 2026-07-15 07:00:',
			'comm_scheduled_ok'    => '🗓️ Comunicado programado para {run}.',
			'comm_schedule_no_image' => '(El envío programado se hará solo con texto; la imagen no se adjunta en programados.)',
			'image_attach_failed'  => '⚠️ No pude adjuntar la imagen; el comunicado se enviará solo con texto.',
			'datetime_invalid'     => 'Formato inválido. Usá AAAA-MM-DD HH:MM.',
			'confirm_si_no'        => 'Respondé *SI* o *NO*.',
			'shortcut_aa_usage'    => 'Uso: *-AA* <texto del anuncio>',
			'shortcut_ae_usage'    => 'Uso: *-AE* <título del evento>',

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

			// Selector de menú por rol
			'role_chooser_header' => '¿A qué menú querés entrar?',

			// Promoción del panel web
			'panel_promo'       => "💻 *Tu panel web del CEAD*\nEntrá a tus comunicados, horarios, recursos y boletín:\n" . home_url( '/panel' ),

			// Atajos rápidos (staff)
			'shortcuts_help'    => "⚡ *Atajos rápidos*\nEnviá en una sola línea:\n• *-AA* <texto> → publica un anuncio para todos.\n• *-AE* <texto> → agrega un evento (te pregunto la fecha).",
			'shortcut_announce_ok' => '📣 Anuncio publicado y enviado a {total} destinatario(s).',

			// Staff: artículos del blog
			'article_menu_prompt'     => "📝 *Artículos del sitio*\n1. Publicar artículo\n2. Editar artículo\n3. Borrar artículo\n0. Volver",
			'article_title_prompt'    => 'Título del artículo (0 para cancelar):',
			'article_body_prompt'     => 'Escribí el contenido del artículo (0 para cancelar):',
			'article_published'       => '✅ Artículo publicado: {url}',
			'article_none'            => 'No hay artículos para mostrar.',
			'article_pick_edit'       => 'Elegí el artículo a editar:',
			'article_pick_delete'     => 'Elegí el artículo a borrar:',
			'article_edit_body_prompt'=> 'Escribí el nuevo contenido del artículo (0 para cancelar):',
			'article_updated'         => '✅ Artículo actualizado.',
			'article_del_confirm'     => "¿Borrar el artículo *{title}*? Respondé *SI* o *NO*.",
			'article_deleted'         => '🗑️ Artículo enviado a la papelera.',

			// Staff: asignar roles
			'role_phone_prompt'   => 'Indicá el número (solo dígitos, con código de país) al que asignar un rol (0 para cancelar):',
			'role_phone_invalid'  => 'Número inválido. Probá de nuevo (solo dígitos).',
			'role_choose_prompt'  => "¿Qué rol asignar?\n1. Profesor\n2. Delegado\n3. Consejo Estudiantil\n0. Cancelar",
			'role_assigned'       => '✅ Rol *{role}* asignado a +{phone}.',
			'role_assigned_new'   => '✅ Se creó el usuario para +{phone} con el rol *{role}*.',
		];
	}
}
