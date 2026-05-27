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

        self::create_tables();         // dbDelta agrega columnas nuevas (ej. subscriptions)
        self::seed_messages();         // INSERT IGNORE agrega solo plantillas faltantes
        self::upgrade_menu_defaults(); // actualiza menús de fábrica si no fueron editados
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
            name          varchar(100) DEFAULT '',
            opt_out       tinyint(1)   NOT NULL DEFAULT 0,
            subscriptions varchar(255) NOT NULL DEFAULT '',
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
    }

    private static function seed_messages(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'caag_bot_messages';

        $templates = [
            [ 'greeting_admin',       'Hola {name}! 👋 Bienvenido al panel de administración de Caaguazú Bot. ¿Qué desea hacer?',                                       'Saludo para admins. Usar {name}.' ],
            [ 'admin_menu',           "*Menú Admin*\n1️⃣ Publicar artículo\n2️⃣ Editar artículo\n3️⃣ Eliminar artículo\n4️⃣ Ver enlaces del sitio\n0️⃣ Salir",                 'Menú principal de admins.' ],
            [ 'greeting_reader',      'Hola {name}! 👋 Bienvenido al bot informativo de Caaguazú. ¿En qué le podemos ayudar?',                                           'Saludo para lectores. Usar {name}.' ],
            [ 'reader_menu',          "*Menú*\n1️⃣ Ver artículos por categoría\n2️⃣ Artículos recientes\n3️⃣ Buscar artículos\n4️⃣ Mis suscripciones\n0️⃣ Salir\n\nEnvíe *BAJA* para darse de baja.", 'Menú principal de lectores.' ],
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
            'reader_menu' => [
                'old' => "*Menú*\n1️⃣ Ver artículos por categoría\n2️⃣ Artículos recientes\n0️⃣ Salir\n\nEnvíe *BAJA* para darse de baja.",
                'new' => "*Menú*\n1️⃣ Ver artículos por categoría\n2️⃣ Artículos recientes\n3️⃣ Buscar artículos\n4️⃣ Mis suscripciones\n0️⃣ Salir\n\nEnvíe *BAJA* para darse de baja.",
            ],
            'publish_prompt' => [
                'old' => 'Perfecto. Envíe el contenido del artículo. El título se generará automáticamente.',
                'new' => 'Perfecto. Envíe el contenido del artículo (puede adjuntar una imagen como portada). El título será la primera línea del texto.',
            ],
        ];

        foreach ( $migrations as $key => $m ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `$t` SET content = %s WHERE msg_key = %s AND content = %s",
                    $m['new'], $key, $m['old']
                )
            );
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
    }
}
