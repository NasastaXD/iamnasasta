<?php
/**
 * Handler del formulario de contacto, vía admin-post.php.
 * Usa wp_mail() + nonce + sanitización.
 */

if (!defined('ABSPATH')) exit;

function cead_handle_contact() {

    // Nonce
    if (!isset($_POST['cead_contact_nonce']) || !wp_verify_nonce($_POST['cead_contact_nonce'], 'cead_contact')) {
        wp_safe_redirect(add_query_arg('cead_err', 'nonce', home_url('/#Contacto')));
        exit;
    }

    // Honeypot
    if (!empty($_POST['website'])) {
        wp_safe_redirect(add_query_arg('cead_ok', '1', home_url('/#Contacto')));
        exit;
    }

    $nombre  = sanitize_text_field(wp_unslash($_POST['nombre']  ?? ''));
    $email   = sanitize_email(wp_unslash($_POST['email']   ?? ''));
    $mensaje = sanitize_textarea_field(wp_unslash($_POST['mensaje'] ?? ''));

    if ($nombre === '' || !is_email($email) || $mensaje === '') {
        wp_safe_redirect(add_query_arg('cead_err', '1', home_url('/#Contacto')));
        exit;
    }

    $to          = get_theme_mod('cead_contact_email', 'contacto@cead.caaguazu.net');
    $from        = get_theme_mod('cead_contact_from_email', 'web@cead.caaguazu.net');
    $subject     = 'Nuevo mensaje desde la web — ' . $nombre;
    $body        = "Nombre: $nombre\nEmail: $email\n\nMensaje:\n$mensaje\n";
    $headers     = [
        'From: ' . $from,
        'Reply-To: ' . $email,
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $ok = wp_mail($to, $subject, $body, $headers);

    // Log local de respaldo dentro de uploads/
    $upload = wp_upload_dir();
    if (!empty($upload['basedir'])) {
        @file_put_contents(
            trailingslashit($upload['basedir']) . 'cead-contact-log.txt',
            date('c') . "\t$nombre\t$email\t" . str_replace(["\r", "\n"], ' ', $mensaje) . "\n",
            FILE_APPEND
        );
    }

    $query = $ok ? ['cead_ok' => '1'] : ['cead_err' => 'mail'];
    wp_safe_redirect(add_query_arg($query, home_url('/#Contacto')));
    exit;
}
add_action('admin_post_nopriv_cead_contact', 'cead_handle_contact');
add_action('admin_post_cead_contact',        'cead_handle_contact');
