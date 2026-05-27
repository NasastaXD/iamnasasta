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
            [ 'reader_menu',          "*Menú*\n1️⃣ Ver artículos por categoría\n2️⃣ Artículos recientes\n0️⃣ Salir\n\nEnvíe *BAJA* para darse de baja.",                    'Menú principal de lectores.' ],
            [ 'opt_out_confirmed',    'Ha sido dado de baja. Ya no recibirá mensajes de este bot. Escríbanos de nuevo para volver.',                                      'Confirmación de opt-out.' ],
            [ 'publish_prompt',       'Perfecto. Envíe el contenido del artículo. El título se generará automáticamente.',                                               'Prompt para contenido del post.' ],
            [ 'category_prompt',      "Seleccione la categoría:\n{category_list}\n0️⃣ Cancelar",                                                                          'Lista de categorías. Usar {category_list}.' ],
            [ 'publish_success',      "✅ Artículo publicado exitosamente.\n🔗 {permalink}",                                                                              'Confirmación de publicación. Usar {permalink}.' ],
            [ 'edit_list_prompt',     "Seleccione el artículo a editar:\n{post_list}\n0️⃣ Cancelar",                                                                      'Lista de posts para editar. Usar {post_list}.' ],
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
