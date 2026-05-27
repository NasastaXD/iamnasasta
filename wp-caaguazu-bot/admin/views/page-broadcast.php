<?php defined( 'ABSPATH' ) || exit; ?>
<?php
$log = get_option( 'caag_broadcast_log', [] );
$log = array_reverse( $log );
$broadcast_cats = ( new Caaguazu_WP_Actions() )->get_categories();
?>
<div class="wrap" data-caag-page="broadcast">
    <h1>Caaguazú Bot — Broadcast</h1>

    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:60%; vertical-align:top; padding-right:20px;">
                <h2>Enviar mensaje masivo</h2>

                <label for="caag-broadcast-msg"><strong>Mensaje:</strong></label><br>
                <textarea id="caag-broadcast-msg" rows="6" style="width:100%; margin-top:4px;" placeholder="Escriba el mensaje aquí..."></textarea>

                <p>
                    <label for="caag-broadcast-target"><strong>Destinatarios:</strong></label><br>
                    <select id="caag-broadcast-target" style="width:100%; margin-top:4px;">
                        <option value="all">Todos (lectores + admins, sin bajas)</option>
                        <option value="readers">Solo lectores</option>
                        <option value="admins">Solo admins</option>
                        <option value="category">Suscriptores de una categoría</option>
                    </select>
                </p>

                <p id="caag-broadcast-category-wrap" style="display:none;">
                    <label for="caag-broadcast-category"><strong>Categoría:</strong></label><br>
                    <select id="caag-broadcast-category" style="width:100%; margin-top:4px;">
                        <?php foreach ( $broadcast_cats as $c ) : ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html( $c['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label for="caag-broadcast-custom"><strong>O bien, números específicos</strong> (separados por comas):</label><br>
                    <input type="text" id="caag-broadcast-custom" style="width:100%; margin-top:4px;" placeholder="595981234567, 595987654321">
                </p>

                <button type="button" id="caag-btn-broadcast" class="button button-primary">Enviar mensaje</button>
                <span class="spinner" id="caag-broadcast-spinner" style="float:none; margin-left:8px;"></span>

                <div id="caag-broadcast-progress" style="margin-top:12px; display:none;">
                    <div style="background:#e2e4e7; border-radius:4px; overflow:hidden; height:18px;">
                        <div id="caag-broadcast-bar" style="background:#2271b1; height:100%; width:0;"></div>
                    </div>
                    <p id="caag-broadcast-progress-text" style="margin:6px 0 0; font-size:13px; color:#646970;"></p>
                </div>

                <div id="caag-broadcast-result" style="margin-top:12px; display:none;"></div>
            </td>

            <td style="width:40%; vertical-align:top;">
                <h2>Historial de envíos</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Enviados</th>
                            <th>Fallidos</th>
                            <th>Mensaje</th>
                        </tr>
                    </thead>
                    <tbody id="caag-broadcast-log">
                        <?php if ( empty( $log ) ) : ?>
                            <tr><td colspan="4">Sin envíos aún.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $log as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['date'] ?? '—' ); ?></td>
                                <td><?php echo (int) ( $entry['sent'] ?? 0 ); ?></td>
                                <td><?php echo (int) ( $entry['failed'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( substr( $entry['message'] ?? '', 0, 40 ) ); ?>...</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>
</div>
