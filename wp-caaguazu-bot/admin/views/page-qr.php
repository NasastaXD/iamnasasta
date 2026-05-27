<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap" data-caag-page="qr">
    <h1>Caaguazú Bot — Vincular WhatsApp</h1>

    <p>Asegúrese de que el bridge esté corriendo en su PC antes de continuar.</p>

    <div id="caag-qr-area">
        <div id="caag-qr-connected" style="display:none;">
            <div class="caag-badge caag-badge-green">✅ Vinculado a <strong id="caag-qr-number">—</strong></div>
        </div>
        <div id="caag-qr-waiting">
            <p>Escanee el código QR con WhatsApp en su teléfono.<br>
               <strong>WhatsApp → tres puntos → Dispositivos vinculados → Vincular dispositivo</strong></p>
            <div id="caag-qr-spinner">Iniciando conexión... <span class="spinner is-active" style="float:none"></span></div>
            <img id="caag-qr-img" src="" alt="QR Code" style="display:none; max-width:280px; border:1px solid #ccc; padding:8px;" />
        </div>
    </div>

    <div style="margin-top:20px;">
        <button type="button" id="caag-btn-restart" class="button button-secondary">🔄 Forzar reconexión</button>
        &nbsp;
        <button type="button" id="caag-btn-logout" class="button" style="background:#d63638; color:#fff; border-color:#d63638;">⛔ Cerrar sesión</button>
    </div>
    <p id="caag-qr-msg" style="margin-top:10px; color:#666;"></p>

    <style>
        .caag-badge { display:inline-block; padding:6px 16px; border-radius:4px; font-weight:bold; margin-bottom:10px; }
        .caag-badge-green { background:#d4edda; color:#155724; }
    </style>
</div>
