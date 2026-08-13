<?php
/**
 * Plugin Name:       CEAD Académico
 * Plugin URI:        https://github.com/nasastaxd/iamnasasta
 * Description:       Módulo académico-administrativo para CEAD: invitaciones, login estilizado, comunicados, encuestas, horarios, recursos, importadores e importación masiva.
 * Version:           0.70.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            CEAD
 * License:           GPL-2.0-or-later
 * Text Domain:       cead-acad
 * Domain Path:       /languages
 * Update URI:        https://github.com/nasastaxd/iamnasasta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEAD_ACAD_VERSION',    '0.70.0' );
define( 'CEAD_ACAD_DB_VERSION', '18' );
define( 'CEAD_ACAD_FILE',       __FILE__ );
define( 'CEAD_ACAD_DIR',        plugin_dir_path( __FILE__ ) );
define( 'CEAD_ACAD_URL',        plugin_dir_url( __FILE__ ) );
define( 'CEAD_ACAD_BASENAME',   plugin_basename( __FILE__ ) );

require_once CEAD_ACAD_DIR . 'includes/helpers.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-capabilities.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-activator.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-deactivator.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-template-loader.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-rewrites.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-assets.php';
require_once CEAD_ACAD_DIR . 'modules/auth/class-invitations.php';
require_once CEAD_ACAD_DIR . 'modules/auth/class-auth-controller.php';
require_once CEAD_ACAD_DIR . 'modules/auth/class-password-reset.php';
require_once CEAD_ACAD_DIR . 'modules/auth/class-user-suspension.php';
require_once CEAD_ACAD_DIR . 'modules/courses/class-courses-cpt.php';
require_once CEAD_ACAD_DIR . 'modules/courses/class-courses-roster.php';
require_once CEAD_ACAD_DIR . 'modules/courses/class-courses-admin.php';
require_once CEAD_ACAD_DIR . 'modules/broadcasts/class-broadcasts-audiences.php';
require_once CEAD_ACAD_DIR . 'modules/broadcasts/class-broadcasts-cpt.php';
require_once CEAD_ACAD_DIR . 'modules/broadcasts/class-broadcasts-targeting.php';
require_once CEAD_ACAD_DIR . 'modules/broadcasts/class-broadcasts-reads.php';
require_once CEAD_ACAD_DIR . 'modules/broadcasts/class-broadcasts-feed.php';
require_once CEAD_ACAD_DIR . 'modules/surveys/class-surveys-cpt.php';
require_once CEAD_ACAD_DIR . 'modules/surveys/class-surveys-questions.php';
require_once CEAD_ACAD_DIR . 'modules/surveys/class-surveys-responses.php';
require_once CEAD_ACAD_DIR . 'modules/surveys/class-surveys-admin.php';
require_once CEAD_ACAD_DIR . 'modules/surveys/class-surveys-frontend.php';
require_once CEAD_ACAD_DIR . 'modules/schedule/class-schedule-cpt.php';
require_once CEAD_ACAD_DIR . 'modules/schedule/class-schedule-admin.php';
require_once CEAD_ACAD_DIR . 'modules/schedule/class-schedule-feed.php';
require_once CEAD_ACAD_DIR . 'modules/resources/class-resources-cpt.php';
require_once CEAD_ACAD_DIR . 'modules/resources/class-resources-admin.php';
require_once CEAD_ACAD_DIR . 'modules/resources/class-resources-acl.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-csv-reader.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-xlsx-reader.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-reader.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-job.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-base.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-students.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-grades.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-courses.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-events.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-horarios.php';
require_once CEAD_ACAD_DIR . 'modules/importers/class-importer-admin.php';
require_once CEAD_ACAD_DIR . 'modules/grades/class-grades-db.php';
require_once CEAD_ACAD_DIR . 'modules/grades/class-grades-writer.php';
require_once CEAD_ACAD_DIR . 'modules/grades/class-grades-sheet.php';
require_once CEAD_ACAD_DIR . 'modules/panels/class-tasks-cpt.php';
require_once CEAD_ACAD_DIR . 'modules/panels/class-tasks-frontend.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-crypto.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-tables.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-store.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-bridge-client.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-identity.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-temp-access.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-broadcaster.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-ai.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-docs.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-memory.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-news.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-tools.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-article-format.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-engine.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-rest.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-cron.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-instagram.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-admin.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-ceadi-panel.php';
require_once CEAD_ACAD_DIR . 'modules/whatsapp/class-wa-module.php';
require_once CEAD_ACAD_DIR . 'modules/account/class-account.php';
require_once CEAD_ACAD_DIR . 'modules/pwa/class-pwa.php';
require_once CEAD_ACAD_DIR . 'modules/notifications/class-notifications.php';
require_once CEAD_ACAD_DIR . 'modules/faq/class-faq.php';
require_once CEAD_ACAD_DIR . 'modules/wiki/class-wiki.php';
require_once CEAD_ACAD_DIR . 'modules/updates/class-updates.php';
require_once CEAD_ACAD_DIR . 'modules/admin-ui/class-admin-dashboard.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-audit.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-email.php';
require_once CEAD_ACAD_DIR . 'includes/class-article-kind.php';
require_once CEAD_ACAD_DIR . 'includes/class-article-categories.php';
require_once CEAD_ACAD_DIR . 'admin/class-admin-menu.php';
require_once CEAD_ACAD_DIR . 'admin/class-admin-logs.php';
require_once CEAD_ACAD_DIR . 'admin/class-admin-metrics.php';
require_once CEAD_ACAD_DIR . 'admin/class-admin-exports.php';
require_once CEAD_ACAD_DIR . 'includes/class-cead-acad-plugin.php';

register_activation_hook(   __FILE__, [ 'Cead_Acad_Activator',   'activate' ] );
register_deactivation_hook( __FILE__, [ 'Cead_Acad_Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	Cead_Acad_Plugin::instance()->boot();
} );
