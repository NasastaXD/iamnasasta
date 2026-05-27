<?php
defined( 'ABSPATH' ) || exit;

$db = new Caaguazu_Database();

$in_total   = $db->count_messages( 'in' );
$out_total  = $db->count_messages( 'out' );
$in_week    = $db->count_messages( 'in', 7 );
$out_week   = $db->count_messages( 'out', 7 );
$users_all  = $db->count_unique_users();
$users_week = $db->count_unique_users( 7 );
$by_role    = $db->count_numbers_by_role();
$admins     = (int) ( $by_role['admin'] ?? 0 );
$readers    = (int) ( $by_role['reader'] ?? 0 );

$log = get_option( 'caag_broadcast_log', [] );
$log = array_reverse( $log );

$cards = [
    [ 'Mensajes recibidos',     $in_total,   $in_week  . ' en 7 días' ],
    [ 'Mensajes enviados',      $out_total,  $out_week . ' en 7 días' ],
    [ 'Usuarios únicos',        $users_all,  $users_week . ' en 7 días' ],
    [ 'Admins / Lectores',      $admins . ' / ' . $readers, 'activos (sin bajas)' ],
];
?>
<div class="wrap" data-caag-page="stats">
    <h1>Caaguazú Bot — Estadísticas</h1>

    <div style="display:flex; flex-wrap:wrap; gap:16px; margin:20px 0;">
        <?php foreach ( $cards as [ $label, $value, $sub ] ) : ?>
        <div style="flex:1; min-width:180px; background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:16px;">
            <div style="font-size:13px; color:#646970;"><?php echo esc_html( $label ); ?></div>
            <div style="font-size:28px; font-weight:600; margin:4px 0;"><?php echo esc_html( (string) $value ); ?></div>
            <div style="font-size:12px; color:#787c82;"><?php echo esc_html( $sub ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <h2>Historial de broadcasts</h2>
    <table class="wp-list-table widefat fixed striped" style="max-width:760px;">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Enviados</th>
                <th>Fallidos</th>
                <th>Mensaje</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $log ) ) : ?>
                <tr><td colspan="4">Sin envíos aún.</td></tr>
            <?php else : ?>
                <?php foreach ( $log as $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( $entry['date'] ?? '—' ); ?></td>
                    <td><?php echo (int) ( $entry['sent'] ?? 0 ); ?></td>
                    <td><?php echo (int) ( $entry['failed'] ?? 0 ); ?></td>
                    <td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p class="description" style="margin-top:16px;">
        Los registros de conversación se conservan 90 días y luego se eliminan automáticamente.
    </p>
</div>
