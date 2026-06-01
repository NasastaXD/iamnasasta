<?php
/**
 * Plugin Name:  CEAD Bot
 * Plugin URI:   https://cead.caaguazu.net
 * Description:  Bot institucional de WhatsApp para el CEAD: horarios, calendario, contacto, reportes confidenciales/anónimos, sugerencias y comunicación del personal. Requiere el bridge Node.js corriendo en la PC del admin.
 * Version:      1.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:       CEAD Caaguazú
 * License:      MIT
 * Text Domain:  caag-bot
 */

defined( 'ABSPATH' ) || exit;

define( 'CAAG_BOT_VERSION', '1.2.0' );
define( 'CAAG_BOT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CAAG_BOT_URL',     plugin_dir_url( __FILE__ ) );

// Carga de clases
require_once CAAG_BOT_DIR . 'includes/class-activator.php';
require_once CAAG_BOT_DIR . 'includes/class-deactivator.php';
require_once CAAG_BOT_DIR . 'includes/class-database.php';
require_once CAAG_BOT_DIR . 'includes/class-bridge-client.php';
require_once CAAG_BOT_DIR . 'includes/class-wp-actions.php';
require_once CAAG_BOT_DIR . 'includes/class-bot-engine.php';
require_once CAAG_BOT_DIR . 'includes/class-broadcaster.php';
require_once CAAG_BOT_DIR . 'includes/class-rest-handler.php';
require_once CAAG_BOT_DIR . 'admin/class-admin.php';
require_once CAAG_BOT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__,   [ 'Caaguazu_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'Caaguazu_Deactivator', 'deactivate' ] );

( new Caaguazu_Plugin() )->run();
