<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Activator {

    public static function activate(): void {
        // Verificar requisitos mínimos
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            deactivate_plugins( plugin_basename( CAAG_BOT_DIR . 'wp-caaguazu-bot.php' ) );
            wp_die( 'Caaguazú Bot requiere PHP 8.0 o superior.' );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::create_tables();
        self::seed_messages();
        self::seed_defaults();
        self::insert_session_row();
        self::schedule_cron_jobs();

        update_option( 'caag_bot_db_version', CAAG_BOT_VERSION );
    }

    /**
     * Migración idempotente entre versiones. Se ejecuta en cada carga del admin
     * y solo hace trabajo si la versión instalada difiere de la del código.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'caag_bot_db_version', '' ) === CAAG_BOT_VERSION ) {
            return;
        }

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        self::create_tables();         // dbDelta agrega columnas/tablas nuevas (roles, reportes, etc.)
        self::seed_messages();         // INSERT IGNORE agrega solo plantillas faltantes
        self::upgrade_menu_defaults(); // actualiza menús de fábrica si no fueron editados
        self::seed_defaults();         // opciones y datos de ejemplo (solo si faltan)
        self::insert_session_row();
        self::schedule_cron_jobs();

        update_option( 'caag_bot_db_version', CAAG_BOT_VERSION );
    }

    private static function create_tables(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta( "CREATE TABLE {$p}caag_bot_messages (
            id          bigint(20)    NOT NULL AUTO_INCREMENT,
            msg_key     varchar(100)  NOT NULL,
            content     longtext      NOT NULL,
            description varchar(255)  DEFAULT '',
            updated_at  datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_msg_key (msg_key)
        ) ENGINE=InnoDB $c;" );

        dbDelta( "CREATE TABLE {$p}caag_registered_numbers (
            id            bigint(20)   NOT NULL AUTO_INCREMENT,
            phone         varchar(30)  NOT NULL,
            role          varchar(20)  NOT NULL DEFAULT 'reader',
            roles         varchar(191) NOT NULL DEFAULT '',
            name          varchar(100) DEFAULT '',
            opt_out       tinyint(1)   NOT NULL DEFAULT 0,
            subscriptions varchar(255) NOT NULL DEFAULT '',
            event_reminders tinyint(1) NOT NULL DEFAULT 0,
            registered_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen     datetime     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_phone (phone),
            KEY idx_role (role),
            KEY idx_opt_out (opt_out)
        ) ENGINE=InnoDB $c;" );

        dbDelta( "CREATE TABLE {$p}caag_conversation_logs (
            id               bigint(20)   NOT NULL AUTO_INCREMENT,
            phone            varchar(30)  NOT NULL,
            direction        varchar(3)   NOT NULL,
            message_body     longtext     NOT NULL,
            timestamp        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_action varchar(100) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_phone (phone),
            KEY idx_timestamp (timestamp),
            KEY idx_direction (direction)
        ) ENGINE=InnoDB $c;" );

        dbDelta( "CREATE TABLE {$p}caag_session (
            id                bigint(20)    NOT NULL AUTO_INCREMENT,
            bridge_url        varchar(500)  DEFAULT '',
            shared_token      varchar(255)  DEFAULT '',
            qr_data           longtext      DEFAULT NULL,
            connection_status varchar(50)   NOT NULL DEFAULT 'disconnected',
            linked_number     varchar(30)   DEFAULT NULL,
            last_heartbeat    datetime      DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB $c;" );

        dbDelta( "CREATE TABLE {$p}caag_user_state (
            id            bigint(20)   NOT NULL AUTO_INCREMENT,
            phone         varchar(30)  NOT NULL,
            current_state varchar(100) NOT NULL DEFAULT 'idle',
            context_data  longtext     DEFAULT NULL,
            updated_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_phone (phone),
            KEY idx_updated_at (updated_at)
        ) ENGINE=InnoDB $c;" );

        // Reportes (A5/D5) — canal sensible. body_enc almacena el cuerpo cifrado.
        dbDelta( "CREATE TABLE {$p}caag_reports (
            id         bigint(20)   NOT NULL AUTO_INCREMENT,
            ref_code   varchar(20)  NOT NULL,
            type       varchar(20)  NOT NULL DEFAULT 'anonymous',
            phone      varchar(30)  DEFAULT NULL,
            category   varchar(60)  DEFAULT '',
            body_enc   longtext     NOT NULL,
            status     varchar(20)  NOT NULL DEFAULT 'new',
            note       longtext     DEFAULT NULL,
            created_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ref (ref_code),
            KEY idx_status (status)
        ) ENGINE=InnoDB $c;" );

        // Sugerencias / quejas (A6/D6) — buzón separado de reportes.
        dbDelta( "CREATE TABLE {$p}caag_suggestions (
            id         bigint(20)   NOT NULL AUTO_INCREMENT,
            phone      varchar(30)  DEFAULT NULL,
            body       longtext     NOT NULL,
            status     varchar(20)  NOT NULL DEFAULT 'new',
            created_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) ENGINE=InnoDB $c;" );

        // Calendario de eventos (A3/D7) — fuente única.
        dbDelta( "CREATE TABLE {$p}caag_events (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            title       varchar(200) NOT NULL,
            event_date  date         NOT NULL,
            description text         DEFAULT NULL,
            created_by  varchar(30)  DEFAULT '',
            reminder_sent_at datetime DEFAULT NULL,
            created_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_date (event_date)
        ) ENGINE=InnoDB $c;" );

        // Envíos programados (D3) — comunicados a despachar más tarde.
        dbDelta( "CREATE TABLE {$p}caag_scheduled (
            id         bigint(20)   NOT NULL AUTO_INCREMENT,
            message    longtext     NOT NULL,
            target     varchar(20)  NOT NULL DEFAULT 'all',
            run_at     datetime     NOT NULL,
            status     varchar(20)  NOT NULL DEFAULT 'pending',
            created_by varchar(30)  DEFAULT '',
            created_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_run (status, run_at)
        ) ENGINE=InnoDB $c;" );

        // Horarios (A1) — grilla por curso/división. day_of_week: 1=Lun..7=Dom.
        dbDelta( "CREATE TABLE {$p}caag_schedules (
            id           bigint(20)   NOT NULL AUTO_INCREMENT,
            course       varchar(60)  NOT NULL,
            division     varchar(20)  NOT NULL DEFAULT '',
            day_of_week  tinyint(1)   NOT NULL,
            period_order tinyint(2)   NOT NULL DEFAULT 1,
            subject      varchar(120) NOT NULL,
            start_time   time         DEFAULT NULL,
            end_time     time         DEFAULT NULL,
            room         varchar(60)  DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_group (course, division)
        ) ENGINE=InnoDB $c;" );
    }

    private static function seed_messages(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'caag_bot_messages';

        $templates = [
            [ 'greeting_admin',       'Hola {name}! 👋 Panel del personal del CEAD (cead.caaguazu.net).',                                                                'Saludo para personal/staff. Usar {name}.' ],
            [ 'staff_menu_header',    '*Panel del personal* — ¿qué desea hacer?',                                                                                       'Encabezado del menú staff (las opciones se arman según permisos).' ],
            [ 'admin_menu',           "*Gestión de artículos web*\n1️⃣ Publicar\n2️⃣ Editar\n3️⃣ Eliminar\n4️⃣ Ver enlaces\n0️⃣ Volver",                                    'Submenú de artículos web (Editor).' ],
            [ 'greeting_reader',      'Hola {name}! 👋 Bienvenido/a al bot del CEAD. Estoy para ayudarte con información del colegio.',                                  'Saludo para alumnado. Usar {name}.' ],
            [ 'reader_menu',          "*Menú CEAD*\n1️⃣ Horarios\n2️⃣ Sitio web\n3️⃣ Calendario de eventos\n4️⃣ Contacto\n5️⃣ Reportar algo\n6️⃣ Sugerencias y quejas\n7️⃣ Preguntas frecuentes\n8️⃣ Consejo Estudiantil\n9️⃣ Recordatorios de eventos\n0️⃣ Salir\n\nEnvíe *BAJA* para no recibir mensajes.", 'Menú principal del alumnado.' ],
            [ 'opt_out_confirmed',    'Ha sido dado de baja. Ya no recibirá mensajes de este bot. Escríbanos de nuevo para volver.',                                      'Confirmación de opt-out.' ],
            [ 'publish_prompt',       'Perfecto. Envíe el contenido del artículo (puede adjuntar una imagen como portada). El título será la primera línea del texto.', 'Prompt para contenido del post.' ],
            [ 'category_prompt',      "Seleccione la categoría:\n{category_list}\n0️⃣ Cancelar",                                                                          'Lista de categorías. Usar {category_list}.' ],
            [ 'publish_status_prompt',"¿Cómo desea guardar el artículo?\n1️⃣ Publicar ahora\n2️⃣ Guardar como borrador\n0️⃣ Cancelar",                                    'Pregunta publicar vs borrador.' ],
            [ 'publish_success',      "✅ Artículo publicado exitosamente.\n🔗 {permalink}",                                                                              'Confirmación de publicación. Usar {permalink}.' ],
            [ 'draft_success',        "📝 Borrador guardado. Revíselo en el panel de WordPress.\n🔗 {permalink}",                                                         'Confirmación de borrador. Usar {permalink}.' ],
            [ 'edit_list_prompt',     "Seleccione el artículo a editar:\n{post_list}\n0️⃣ Cancelar",                                                                      'Lista de posts para editar. Usar {post_list}.' ],
            [ 'edit_mode_prompt',     "¿Cómo desea editar *{title}*?\n1️⃣ Reemplazar todo el contenido\n2️⃣ Agregar al final\n0️⃣ Cancelar",                                'Pregunta reemplazar vs agregar. Usar {title}.' ],
            [ 'edit_content_prompt',  "Editando: *{title}*\n\nEnvíe el nuevo contenido, o escriba 0 para cancelar.",                                                     'Prompt de nuevo contenido. Usar {title}.' ],
            [ 'edit_success',         "✅ Artículo actualizado.\n🔗 {permalink}",                                                                                         'Confirmación de edición. Usar {permalink}.' ],
            [ 'delete_list_prompt',   "Seleccione el artículo a eliminar:\n{post_list}\n0️⃣ Cancelar",                                                                    'Lista de posts para eliminar. Usar {post_list}.' ],
            [ 'delete_confirm_prompt',"⚠️ ¿Confirma que desea eliminar el artículo *{title}*?\nResponda *SI* o *NO*.",                                                   'Confirmación de borrado. Usar {title}.' ],
            [ 'delete_success',       '🗑️ Artículo eliminado: *{title}*',                                                                                                'Confirmación de borrado. Usar {title}.' ],
            [ 'delete_cancelled',     'Eliminación cancelada.',                                                                                                          '' ],
            [ 'edit_cancelled',       'Edición cancelada.',                                                                                                              '' ],
            [ 'publish_cancelled',    'Publicación cancelada.',                                                                                                          '' ],
            [ 'invalid_option',       'Opción no válida. Por favor seleccione una de las opciones del menú.',                                                            '' ],
            [ 'goodbye',              '¡Hasta luego! 👋 Escriba en cualquier momento para volver al menú.',                                                              '' ],
            [ 'recent_posts_header',  '📰 *Artículos recientes:*',                                                                                                       '' ],
            [ 'no_posts_found',       'No se encontraron artículos en esta categoría.',                                                                                  '' ],
            [ 'search_prompt',        "🔎 Escriba la palabra o frase a buscar (o 0 para cancelar):",                                                                      'Prompt de búsqueda para lectores.' ],
            [ 'search_results_header',"🔎 *Resultados para \"{term}\":*",                                                                                                 'Encabezado de resultados. Usar {term}.' ],
            [ 'search_no_results',    'No se encontraron artículos para *{term}*. Intente con otra palabra.',                                                             'Sin resultados. Usar {term}.' ],
            [ 'subs_prompt',          "📬 *Sus suscripciones*\nResponda el número para activar o desactivar cada categoría (0 para volver):\n\n{subs_list}",              'Lista de suscripciones. Usar {subs_list}.' ],
            [ 'subs_updated',         '✅ Suscripciones actualizadas.',                                                                                                   'Confirmación de cambio de suscripción.' ],
            [ 'error_generic',        'Ocurrió un error. Por favor intente nuevamente más tarde.',                                                                       '' ],

            // -------- Alumnado: Horarios (A1) --------
            [ 'horario_group_prompt', "Seleccione su curso/división:\n{group_list}\n0️⃣ Volver",                                                                         'Lista de cursos. Usar {group_list}.' ],
            [ 'horario_none',         'Todavía no hay horarios cargados. Consulte más tarde.',                                                                           '' ],
            [ 'horario_header',       '📚 *Horario — {group}*',                                                                                                          'Encabezado de la grilla. Usar {group}.' ],
            [ 'horario_now',          '⏰ *Ahora:* {now}',                                                                                                               'Materia actual. Usar {now}.' ],
            [ 'horario_next',         '➡️ *Sigue:* {next}',                                                                                                              'Próxima materia. Usar {next}.' ],
            [ 'horario_idle',         'En este momento no hay clases según el horario cargado.',                                                                         '' ],

            // -------- Alumnado: Sitio web (A2) --------
            [ 'site_links_header',    '🌐 *Sitio web del CEAD*',                                                                                                         'Encabezado de enlaces.' ],

            // -------- Alumnado: Calendario (A3) --------
            [ 'events_header',        '📅 *Próximos eventos*',                                                                                                           '' ],
            [ 'events_none',          'No hay eventos próximos cargados.',                                                                                               '' ],

            // -------- Alumnado: Contacto (A4) --------
            [ 'contact_header',       '☎️ *Contactos del CEAD*',                                                                                                         '' ],

            // -------- Alumnado: Reporte (A5) --------
            [ 'report_type_prompt',   "🛡️ Este canal es para reportar situaciones (bullying, seguridad, etc.).\n¿Cómo desea enviarlo?\n1️⃣ Anónimo total\n2️⃣ Confidencial (quiero que me contacten)\n0️⃣ Cancelar", 'Elección de tipo de reporte.' ],
            [ 'report_category_prompt',"¿Sobre qué es el reporte?\n{category_list}\n0️⃣ Cancelar",                                                                       'Categorías de reporte. Usar {category_list}.' ],
            [ 'report_body_prompt',   'Escriba su reporte con el mayor detalle posible. Será tratado con confidencialidad. (0️⃣ para cancelar)',                          '' ],
            [ 'report_saved_conf',    "✅ Reporte recibido. Código de seguimiento: *{ref}*.\nUna persona del equipo de moderación podrá contactarle.",                  'Confirmación reporte confidencial. Usar {ref}.' ],
            [ 'report_saved_anon',    "✅ Reporte anónimo recibido. Gracias por confiar en nosotros.\nCódigo de referencia: *{ref}*.",                                  'Confirmación reporte anónimo. Usar {ref}.' ],
            [ 'report_cancelled',     'Reporte cancelado. No se guardó nada.',                                                                                          '' ],

            // -------- Alumnado: Sugerencias/quejas (A6) --------
            [ 'suggestion_prompt',    'Escriba su sugerencia o queja (0️⃣ para cancelar):',                                                                              '' ],
            [ 'suggestion_saved',     '✅ ¡Gracias! Su mensaje fue registrado y será revisado.',                                                                         '' ],
            [ 'suggestion_cancelled', 'Cancelado.',                                                                                                                     '' ],

            // -------- Staff: comunicados (D2) --------
            [ 'comm_compose_prompt',  '✍️ Escriba el comunicado a enviar (0️⃣ para cancelar):',                                                                          '' ],
            [ 'comm_audience_prompt', "¿A quién enviar?\n1️⃣ Alumnado\n2️⃣ Personal\n3️⃣ Todos\n0️⃣ Cancelar",                                                            '' ],
            [ 'comm_confirm_prompt',  "Se enviará a *{count}* destinatario(s).\n¿Confirma? Responda *SI* o *NO*.",                                                       'Confirmación de envío. Usar {count}.' ],
            [ 'comm_empty',           'No hay destinatarios para esa audiencia.',                                                                                       '' ],
            [ 'comm_queued',          '📨 Comunicado encolado para {total} destinatario(s). Se enviará de forma escalonada para cuidar el número.',                      'Usar {total}.' ],
            [ 'comm_busy',            'Ya hay un envío en curso. Espere a que termine e intente de nuevo.',                                                              '' ],
            [ 'comm_cancelled',       'Comunicado cancelado.',                                                                                                          '' ],

            // -------- Staff: eventos (D7) --------
            [ 'event_title_prompt',   'Título del evento (0️⃣ para cancelar):',                                                                                          '' ],
            [ 'event_date_prompt',    'Fecha del evento en formato AAAA-MM-DD (ej. 2026-07-15):',                                                                        '' ],
            [ 'event_date_invalid',   'Fecha inválida. Use el formato AAAA-MM-DD.',                                                                                     '' ],
            [ 'event_desc_prompt',    'Descripción breve (o escriba - para omitir):',                                                                                   '' ],
            [ 'event_saved',          '✅ Evento agregado al calendario.',                                                                                               '' ],

            // -------- Staff: bandeja de reportes (D5) --------
            [ 'reports_inbox_header', "📥 *Bandeja de reportes*\nNuevos: {new} · En revisión: {in_review} · Resueltos: {resolved}",                                     'Usar {new},{in_review},{resolved}.' ],
            [ 'reports_list_prompt',  "Seleccione un reporte:\n{report_list}\n0️⃣ Volver",                                                                               'Usar {report_list}.' ],
            [ 'reports_empty',        'No hay reportes nuevos ni en revisión.',                                                                                          '' ],
            [ 'report_actions_prompt',"1️⃣ Marcar en revisión\n2️⃣ Marcar resuelto\n3️⃣ Agregar nota\n0️⃣ Volver",                                                        '' ],
            [ 'report_note_prompt',   'Escriba la nota de seguimiento (0️⃣ para cancelar):',                                                                             '' ],
            [ 'report_updated',       '✅ Reporte actualizado.',                                                                                                         '' ],

            // -------- Staff: bandeja de sugerencias (D6) --------
            [ 'sugg_list_prompt',     "Seleccione una sugerencia:\n{sugg_list}\n0️⃣ Volver",                                                                             'Usar {sugg_list}.' ],
            [ 'sugg_empty',           'No hay sugerencias pendientes.',                                                                                                 '' ],
            [ 'sugg_actions_prompt',  "1️⃣ Marcar en revisión\n2️⃣ Marcar resuelta\n0️⃣ Volver",                                                                         '' ],
            [ 'sugg_updated',         '✅ Sugerencia actualizada.',                                                                                                      '' ],

            // -------- Staff: gestión de usuarios/roles (D8 / SuperAdmin) --------
            [ 'users_menu',           "*Gestión de usuarios*\n1️⃣ Listar personal\n2️⃣ Agregar personal\n3️⃣ Quitar personal\n0️⃣ Volver",                                '' ],
            [ 'users_list_header',    '👥 *Personal registrado*',                                                                                                       '' ],
            [ 'user_add_phone_prompt','Envíe el número del nuevo integrante (con código de país, sin +). 0️⃣ para cancelar:',                                            '' ],
            [ 'user_phone_invalid',   'Número inválido. Intente nuevamente.',                                                                                           '' ],
            [ 'user_roles_prompt',    "Seleccione los roles (números separados por coma, ej. 2,3):\n{role_list}\n0️⃣ Cancelar",                                          'Usar {role_list}.' ],
            [ 'user_added',           '✅ Personal agregado: {phone}\nRoles: {roles}',                                                                                   'Usar {phone},{roles}.' ],
            [ 'user_remove_prompt',   'Envíe el número a quitar del personal (0️⃣ para cancelar):',                                                                      '' ],
            [ 'user_removed',         '✅ {phone} ya no forma parte del personal.',                                                                                       'Usar {phone}.' ],
            [ 'access_denied',        '🔒 No tiene permisos para realizar esa acción.',                                                                                 '' ],
            [ 'back_to_menu',         'Volviendo al menú…',                                                                                                             '' ],

            // -------- Fase 4: Alumnado --------
            [ 'faq_header',           '❓ *Preguntas frecuentes*',                                                                                                       '' ],
            [ 'faq_none',             'Todavía no hay preguntas frecuentes cargadas.',                                                                                  '' ],
            [ 'council_header',       '📌 *Tablón del Consejo Estudiantil*',                                                                                            '' ],
            [ 'council_menu',         "1️⃣ Enviar una propuesta al consejo\n0️⃣ Volver",                                                                                  '' ],
            [ 'council_proposal_prompt','Escriba su propuesta para el Consejo Estudiantil (0️⃣ para cancelar):',                                                         '' ],
            [ 'council_proposal_saved','✅ ¡Gracias! Su propuesta fue enviada al Consejo Estudiantil.',                                                                  '' ],
            [ 'reminders_on',         '🔔 Recordatorios de eventos *activados*. Le avisaremos antes de cada evento.',                                                    '' ],
            [ 'reminders_off',        '🔕 Recordatorios de eventos *desactivados*.',                                                                                     '' ],
            [ 'event_reminder',       "🔔 *Recordatorio de eventos del CEAD*\n{events}",                                                                                 'Recordatorio. Usar {events}.' ],

            // -------- Fase 4: Staff --------
            [ 'comm_template_hint',   'Escriba *P* para elegir una plantilla.',                                                                                         'Sufijo del prompt de comunicado si hay plantillas.' ],
            [ 'comm_template_prompt', "Seleccione una plantilla:\n{template_list}\n0️⃣ Cancelar",                                                                        'Usar {template_list}.' ],
            [ 'comm_when_prompt',     "¿Cuándo enviar?\n1️⃣ Ahora\n2️⃣ Programar fecha y hora\n0️⃣ Cancelar",                                                            '' ],
            [ 'comm_schedule_prompt', 'Indique fecha y hora (AAAA-MM-DD HH:MM), ej. 2026-07-15 07:00:',                                                                 '' ],
            [ 'comm_schedule_invalid','Formato inválido. Use AAAA-MM-DD HH:MM (ej. 2026-07-15 07:00).',                                                                 '' ],
            [ 'comm_scheduled_ok',    '🗓️ Comunicado programado para {when} ({count} destinatario(s)).',                                                                'Usar {when},{count}.' ],
            [ 'tpl_menu',             "*Plantillas de comunicados*\n1️⃣ Listar\n2️⃣ Agregar\n3️⃣ Eliminar\n0️⃣ Volver",                                                  '' ],
            [ 'tpl_empty',            'No hay plantillas guardadas.',                                                                                                   '' ],
            [ 'tpl_list_header',      '🗂️ *Plantillas*',                                                                                                                '' ],
            [ 'tpl_add_name_prompt',  'Nombre de la plantilla (0️⃣ para cancelar):',                                                                                     '' ],
            [ 'tpl_add_body_prompt',  'Texto de la plantilla (0️⃣ para cancelar):',                                                                                      '' ],
            [ 'tpl_added',            '✅ Plantilla guardada: {name}',                                                                                                    'Usar {name}.' ],
            [ 'tpl_delete_prompt',    "Seleccione la plantilla a eliminar:\n{template_list}\n0️⃣ Cancelar",                                                              'Usar {template_list}.' ],
            [ 'tpl_deleted',          '🗑️ Plantilla eliminada.',                                                                                                        '' ],
            [ 'metrics_header',       '📊 *Métricas (últimos 30 días)*',                                                                                                '' ],
        ];

        foreach ( $templates as [ $key, $content, $desc ] ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO `$t` (msg_key, content, description) VALUES (%s, %s, %s)",
                    $key, $content, $desc
                )
            );
        }
    }

    /**
     * Actualiza menús de fábrica a su nueva versión, pero solo si el admin no los
     * personalizó (el contenido guardado todavía coincide con el default anterior).
     */
    private static function upgrade_menu_defaults(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'caag_bot_messages';

        $migrations = [
            'publish_prompt' => [
                'old' => 'Perfecto. Envíe el contenido del artículo. El título se generará automáticamente.',
                'new' => 'Perfecto. Envíe el contenido del artículo (puede adjuntar una imagen como portada). El título será la primera línea del texto.',
            ],
            // Pivot a bot escolar CEAD: actualizar menús de blog → institucionales
            // solo si el admin aún tiene el default de la versión anterior.
            'reader_menu' => [
                'old' => "*Menú*\n1️⃣ Ver artículos por categoría\n2️⃣ Artículos recientes\n3️⃣ Buscar artículos\n4️⃣ Mis suscripciones\n0️⃣ Salir\n\nEnvíe *BAJA* para darse de baja.",
                'new' => "*Menú CEAD*\n1️⃣ Horarios\n2️⃣ Sitio web\n3️⃣ Calendario de eventos\n4️⃣ Contacto\n5️⃣ Reportar algo\n6️⃣ Sugerencias y quejas\n7️⃣ Preguntas frecuentes\n8️⃣ Consejo Estudiantil\n9️⃣ Recordatorios de eventos\n0️⃣ Salir\n\nEnvíe *BAJA* para no recibir mensajes.",
            ],
            'reader_menu_f4' => [
                'key' => 'reader_menu',
                'old' => "*Menú CEAD*\n1️⃣ Horarios\n2️⃣ Sitio web\n3️⃣ Calendario de eventos\n4️⃣ Contacto\n5️⃣ Reportar algo\n6️⃣ Sugerencias y quejas\n0️⃣ Salir\n\nEnvíe *BAJA* para no recibir mensajes.",
                'new' => "*Menú CEAD*\n1️⃣ Horarios\n2️⃣ Sitio web\n3️⃣ Calendario de eventos\n4️⃣ Contacto\n5️⃣ Reportar algo\n6️⃣ Sugerencias y quejas\n7️⃣ Preguntas frecuentes\n8️⃣ Consejo Estudiantil\n9️⃣ Recordatorios de eventos\n0️⃣ Salir\n\nEnvíe *BAJA* para no recibir mensajes.",
            ],
            'greeting_reader' => [
                'old' => 'Hola {name}! 👋 Bienvenido al bot informativo de Caaguazú. ¿En qué le podemos ayudar?',
                'new' => 'Hola {name}! 👋 Bienvenido/a al bot del CEAD. Estoy para ayudarte con información del colegio.',
            ],
            'greeting_admin' => [
                'old' => 'Hola {name}! 👋 Bienvenido al panel de administración de Caaguazú Bot. ¿Qué desea hacer?',
                'new' => 'Hola {name}! 👋 Panel del personal del CEAD (cead.caaguazu.net).',
            ],
            'admin_menu' => [
                'old' => "*Menú Admin*\n1️⃣ Publicar artículo\n2️⃣ Editar artículo\n3️⃣ Eliminar artículo\n4️⃣ Ver enlaces del sitio\n0️⃣ Salir",
                'new' => "*Gestión de artículos web*\n1️⃣ Publicar\n2️⃣ Editar\n3️⃣ Eliminar\n4️⃣ Ver enlaces\n0️⃣ Volver",
            ],
        ];

        foreach ( $migrations as $key => $m ) {
            $msg_key = $m['key'] ?? $key;
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `$t` SET content = %s WHERE msg_key = %s AND content = %s",
                    $m['new'], $msg_key, $m['old']
                )
            );
        }
    }

    /**
     * Siembra opciones de configuración y datos de ejemplo. Idempotente:
     * solo escribe lo que falta (no pisa lo que el colegio ya cargó).
     */
    private static function seed_defaults(): void {
        global $wpdb;

        // Clave de cifrado de reportes (si no hay constante CAAG_REPORT_KEY ni opción).
        if ( ! defined( 'CAAG_REPORT_KEY' ) && ! get_option( 'caag_report_key' ) ) {
            update_option( 'caag_report_key', base64_encode( Caaguazu_Database::random_bytes32() ), false );
        }

        // Enlaces del sitio (A2).
        if ( get_option( 'caag_site_links', null ) === null ) {
            update_option( 'caag_site_links', [
                [ 'label' => 'Sitio web del CEAD', 'url' => 'https://cead.caaguazu.net' ],
                [ 'label' => 'Noticias',           'url' => 'https://cead.caaguazu.net/noticias' ],
                [ 'label' => 'Inscripciones',      'url' => 'https://cead.caaguazu.net/inscripciones' ],
            ], false );
        }

        // Contactos / derivación (A4).
        if ( get_option( 'caag_contacts', null ) === null ) {
            update_option( 'caag_contacts', [
                [ 'name' => 'Administración',      'detail' => 'Lun a Vie 7:00–13:00 · administracion@cead.caaguazu.net' ],
                [ 'name' => 'Secretaría',          'detail' => 'Lun a Vie 7:00–13:00 · secretaria@cead.caaguazu.net' ],
                [ 'name' => 'Consejo Estudiantil', 'detail' => 'consejo@cead.caaguazu.net' ],
            ], false );
        }

        // Categorías de reporte (A5).
        if ( get_option( 'caag_report_categories', null ) === null ) {
            update_option( 'caag_report_categories', [ 'Bullying / acoso', 'Seguridad', 'Infraestructura', 'Otro' ], false );
        }

        // FAQ institucional (A7).
        if ( get_option( 'caag_faq', null ) === null ) {
            update_option( 'caag_faq', [
                [ 'q' => '¿Cómo pido una constancia de alumno regular?', 'a' => 'Solicítela en Secretaría de lunes a viernes de 7:00 a 13:00. Demora 48 hs hábiles.' ],
                [ 'q' => '¿Cuándo son las inscripciones?',             'a' => 'Las fechas se publican en cead.caaguazu.net/inscripciones y en el calendario de eventos.' ],
                [ 'q' => '¿Qué necesito para inscribirme?',            'a' => 'Documento de identidad, certificado del año anterior y formulario de inscripción.' ],
            ], false );
        }

        // Tablón del Consejo Estudiantil (A8).
        if ( get_option( 'caag_council_board', null ) === null ) {
            update_option( 'caag_council_board',
                "El Consejo Estudiantil está trabajando en:\n• Mejoras en el patio y los espacios comunes.\n• Actividades para la semana del estudiante.\n\n¡Tus propuestas son bienvenidas!",
                false
            );
        }

        // Plantillas de comunicados (D4).
        if ( get_option( 'caag_comm_templates', null ) === null ) {
            update_option( 'caag_comm_templates', [
                [ 'name' => 'Suspensión de clases', 'body' => 'Estimadas familias: les informamos que mañana no habrá clases por [motivo]. Saludos, CEAD.' ],
                [ 'name' => 'Reunión de padres',    'body' => 'Estimadas familias: convocamos a reunión de padres el [fecha] a las [hora] en el colegio.' ],
            ], false );
        }

        // Días de anticipación de recordatorios de eventos (A3).
        if ( get_option( 'caag_reminder_days', null ) === null ) {
            update_option( 'caag_reminder_days', 1, false );
        }

        // Número responsable al que se reenvían los reportes (A5/D5). Vacío = no reenviar.
        if ( get_option( 'caag_report_forward_number', null ) === null ) {
            update_option( 'caag_report_forward_number', '', false );
        }

        // Grilla de horario de ejemplo (A1) — solo si la tabla está vacía.
        $sched_table = $wpdb->prefix . 'caag_schedules';
        $has_sched   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$sched_table`" );
        if ( $has_sched === 0 ) {
            $sample = [
                [ '1° Año', 'A', 1, 1, 'Matemática',   '07:00', '07:45', 'Aula 1' ],
                [ '1° Año', 'A', 1, 2, 'Lengua',       '07:45', '08:30', 'Aula 1' ],
                [ '1° Año', 'A', 1, 3, 'Historia',     '08:45', '09:30', 'Aula 1' ],
                [ '1° Año', 'A', 2, 1, 'Biología',     '07:00', '07:45', 'Lab' ],
                [ '1° Año', 'A', 2, 2, 'Inglés',       '07:45', '08:30', 'Aula 1' ],
            ];
            foreach ( $sample as [ $course, $div, $dow, $ord, $subj, $start, $end, $room ] ) {
                $wpdb->insert( $sched_table, [
                    'course'       => $course,
                    'division'     => $div,
                    'day_of_week'  => $dow,
                    'period_order' => $ord,
                    'subject'      => $subj,
                    'start_time'   => $start,
                    'end_time'     => $end,
                    'room'         => $room,
                ] );
            }
        }
    }

    private static function insert_session_row(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'caag_session';
        $exists = $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" );
        if ( ! $exists ) {
            $wpdb->insert( $t, [ 'connection_status' => 'disconnected' ] );
        }
    }

    private static function schedule_cron_jobs(): void {
        if ( ! wp_next_scheduled( 'caag_heartbeat_event' ) ) {
            wp_schedule_event( time(), 'caag_five_minutes', 'caag_heartbeat_event' );
        }
        if ( ! wp_next_scheduled( 'caag_log_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'caag_log_cleanup_event' );
        }
        if ( ! wp_next_scheduled( 'caag_reminders_event' ) ) {
            wp_schedule_event( time(), 'daily', 'caag_reminders_event' );
        }
        if ( ! wp_next_scheduled( 'caag_scheduled_event' ) ) {
            wp_schedule_event( time(), 'caag_five_minutes', 'caag_scheduled_event' );
        }
    }
}
