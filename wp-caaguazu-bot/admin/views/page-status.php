<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap" data-caag-page="status">
    <h1>Caaguazú Bot — Estado</h1>

    <div id="caag-status-box" style="margin-bottom:20px;">
        <div id="caag-status-badge" class="caag-badge caag-badge-gray">Cargando...</div>
        <p><strong>Número vinculado:</strong> <span id="caag-linked-number">—</span></p>
        <p><strong>Último heartbeat:</strong> <span id="caag-heartbeat">—</span></p>
        <button type="button" id="caag-refresh-status" class="button">Actualizar estado</button>
    </div>

    <h2>Últimas conversaciones</h2>
    <table class="wp-list-table widefat fixed striped" id="caag-logs-table">
        <thead>
            <tr>
                <th style="width:140px">Fecha</th>
                <th style="width:140px">Número</th>
                <th style="width:50px">Dir.</th>
                <th>Mensaje</th>
                <th style="width:120px">Acción</th>
            </tr>
        </thead>
        <tbody id="caag-logs-body">
            <tr><td colspan="5">Cargando...</td></tr>
        </tbody>
    </table>

    <style>
        .caag-badge { display:inline-block; padding:6px 16px; border-radius:4px; font-weight:bold; margin-bottom:10px; }
        .caag-badge-green  { background:#d4edda; color:#155724; }
        .caag-badge-red    { background:#f8d7da; color:#721c24; }
        .caag-badge-yellow { background:#fff3cd; color:#856404; }
        .caag-badge-gray   { background:#e2e3e5; color:#383d41; }
    </style>
</div>
