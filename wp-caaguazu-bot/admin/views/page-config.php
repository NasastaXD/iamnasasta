<?php
defined( 'ABSPATH' ) || exit;

$db      = new Caaguazu_Database();
$session = $db->get_session();

$bridge_url    = $session->bridge_url    ?? '';
$token         = $session->shared_token  ?? '';
$contact_email = get_option( 'caag_contact_email', '' );
$posts_reader  = (int) get_option( 'caag_posts_per_page_reader', 5 );
$posts_admin   = (int) get_option( 'caag_posts_per_page_admin', 10 );

$all_cats        = get_categories( [ 'hide_empty' => false ] );
$reader_cats     = get_option( 'caag_reader_categories', [] );
$admin_numbers   = $db->get_numbers_by_role( 'admin' );
$report_forward  = get_option( 'caag_report_forward_number', '' );
?>
<div class="wrap" data-caag-page="config">
    <h1>Caaguazú Bot — Configuración</h1>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="caag_save_config">
        <?php wp_nonce_field( 'caag_save_config' ); ?>

        <!-- Sección A: Bridge -->
        <h2>Bridge WhatsApp</h2>
        <table class="form-table">
            <tr>
                <th><label for="caag_bridge_url">URL del Bridge (Cloudflare Tunnel)</label></th>
                <td>
                    <input type="url" id="caag_bridge_url" name="caag_bridge_url" value="<?php echo esc_attr( $bridge_url ); ?>" class="regular-text" placeholder="https://abc.trycloudflare.com">
                    <button type="button" id="caag-btn-test" class="button" style="margin-left:8px;">Probar conexión</button>
                    <span id="caag-test-result"></span>
                    <p class="description">URL que genera Cloudflare Tunnel al ejecutar el bridge.</p>
                </td>
            </tr>
            <tr>
                <th><label for="caag_token">Token compartido</label></th>
                <td>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="password" id="caag_token" name="caag_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text">
                        <button type="button" id="caag-token-toggle" class="button">Mostrar</button>
                    </div>
                    <p class="description">Debe coincidir exactamente con el valor <code>SHARED_TOKEN</code> del archivo <code>.env</code> del bridge.</p>
                </td>
            </tr>
        </table>

        <!-- Sección B: Admins de WhatsApp -->
        <h2>Números administradores</h2>
        <p>Los mensajes de estos números activarán el menú de administración del bot.</p>

        <table class="wp-list-table widefat fixed striped" style="max-width:500px; margin-bottom:12px;">
            <thead><tr><th>Número</th><th>Nombre</th><th></th></tr></thead>
            <tbody id="caag-admin-numbers">
                <?php foreach ( $admin_numbers as $n ) : ?>
                <tr data-phone="<?php echo esc_attr( $n->phone ); ?>">
                    <td><?php echo esc_html( $n->phone ); ?></td>
                    <td><?php echo esc_html( $n->name ?: '—' ); ?></td>
                    <td><button type="button" class="button caag-remove-number">Eliminar</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( empty( $admin_numbers ) ) : ?>
                <tr id="caag-no-admins"><td colspan="3">No hay admins configurados.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="display:flex; gap:8px; align-items:center; margin-bottom:20px;">
            <input type="text" id="caag-new-phone" placeholder="595981234567" style="width:160px;">
            <input type="text" id="caag-new-name" placeholder="Nombre (opcional)" style="width:180px;">
            <button type="button" id="caag-btn-add-number" class="button">Agregar admin</button>
            <span id="caag-number-msg" style="color:#666;"></span>
        </div>

        <!-- Sección C: Categorías para lectores -->
        <h2>Categorías visibles para lectores</h2>
        <p>Si no selecciona ninguna, se mostrarán todas las categorías.</p>
        <div style="column-count:3; max-width:600px;">
            <?php foreach ( $all_cats as $cat ) : ?>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="caag_reader_categories[]" value="<?php echo (int) $cat->term_id; ?>"
                    <?php checked( in_array( $cat->term_id, $reader_cats, true ) ); ?>>
                <?php echo esc_html( $cat->name ); ?>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- Sección D: General -->
        <h2>Opciones generales</h2>
        <table class="form-table">
            <tr>
                <th><label for="caag_contact_email">Email de contacto</label></th>
                <td><input type="email" id="caag_contact_email" name="caag_contact_email" value="<?php echo esc_attr( $contact_email ); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="caag_posts_reader">Posts para lectores (máx. 10)</label></th>
                <td><input type="number" id="caag_posts_reader" name="caag_posts_reader" value="<?php echo (int) $posts_reader; ?>" min="1" max="10" style="width:80px;"></td>
            </tr>
            <tr>
                <th><label for="caag_posts_admin">Posts en listas admin (máx. 20)</label></th>
                <td><input type="number" id="caag_posts_admin" name="caag_posts_admin" value="<?php echo (int) $posts_admin; ?>" min="1" max="20" style="width:80px;"></td>
            </tr>
        </table>

        <!-- Sección E: Canal de reportes -->
        <h2>Canal de reportes</h2>
        <table class="form-table">
            <tr>
                <th><label for="caag_report_forward_number">Número que recibe los reportes</label></th>
                <td>
                    <input type="text" id="caag_report_forward_number" name="caag_report_forward_number" value="<?php echo esc_attr( $report_forward ); ?>" class="regular-text" placeholder="595981234567">
                    <p class="description">Número de WhatsApp (con código de país, sin <code>+</code>) al que se reenviará cada reporte recibido. Déjelo vacío para no reenviar. En reportes anónimos no se incluye el número del remitente.</p>
                </td>
            </tr>
        </table>

        <?php submit_button( 'Guardar configuración' ); ?>
    </form>
</div>
