<?php
defined( 'ABSPATH' ) || exit;
global $db_instance;

// Acceder a la instancia de DB a través del contexto global del plugin
$db = new Caaguazu_Database();
$messages = $db->get_all_messages();

$labels = [
    'greeting_admin'       => 'Saludo para admins',
    'admin_menu'           => 'Menú de administrador',
    'greeting_reader'      => 'Saludo para lectores',
    'reader_menu'          => 'Menú de lectores',
    'opt_out_confirmed'    => 'Confirmación de baja',
    'publish_prompt'       => 'Solicitud de contenido al publicar',
    'category_prompt'      => 'Selección de categoría',
    'publish_success'      => 'Publicación exitosa',
    'edit_list_prompt'     => 'Lista para editar',
    'edit_content_prompt'  => 'Solicitud de nuevo contenido',
    'edit_success'         => 'Edición exitosa',
    'delete_list_prompt'   => 'Lista para eliminar',
    'delete_confirm_prompt'=> 'Confirmación de borrado',
    'delete_success'       => 'Borrado exitoso',
    'delete_cancelled'     => 'Borrado cancelado',
    'edit_cancelled'       => 'Edición cancelada',
    'publish_cancelled'    => 'Publicación cancelada',
    'invalid_option'       => 'Opción no válida',
    'goodbye'              => 'Despedida',
    'recent_posts_header'  => 'Encabezado de artículos recientes',
    'no_posts_found'       => 'Sin artículos encontrados',
    'error_generic'        => 'Error genérico',
];
?>
<div class="wrap" data-caag-page="messages">
    <h1>Caaguazú Bot — Mensajes del bot</h1>
    <p>Edite los textos que envía el bot. Los marcadores entre llaves <code>{name}</code> serán reemplazados automáticamente. <strong>No los borre.</strong></p>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Mensajes guardados correctamente.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="caag_save_messages">
        <?php wp_nonce_field( 'caag_save_messages' ); ?>

        <?php foreach ( $messages as $msg ) :
            $key   = esc_attr( $msg->msg_key );
            $label = $labels[ $msg->msg_key ] ?? $msg->msg_key;
            $desc  = $msg->description;
        ?>
        <div style="margin-bottom:24px; background:#fff; padding:16px; border:1px solid #ccd0d4; border-radius:4px;">
            <h3 style="margin:0 0 4px;"><?php echo esc_html( $label ); ?></h3>
            <?php if ( $desc ) : ?>
                <p style="margin:0 0 8px; color:#666; font-size:13px;"><?php echo esc_html( $desc ); ?></p>
            <?php endif; ?>
            <textarea
                name="caag_msg[<?php echo $key; ?>]"
                rows="4"
                style="width:100%; font-family:monospace;"
                class="caag-msg-textarea"
            ><?php echo esc_textarea( $msg->content ); ?></textarea>
            <span class="caag-char-count" style="font-size:12px; color:#999;"></span>
        </div>
        <?php endforeach; ?>

        <p>
            <?php submit_button( 'Guardar cambios', 'primary', 'submit', false ); ?>
        </p>
    </form>
</div>
