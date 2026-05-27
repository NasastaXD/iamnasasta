/* Caaguazú Bot — Admin JS */
(function ($) {
    'use strict';

    /* -----------------------------------------------------------------------
     * Utilidades compartidas
     * --------------------------------------------------------------------- */

    function ajaxPost(action, extraData, callback) {
        $.post(caagBot.ajaxUrl, Object.assign({ action: action, nonce: caagBot.nonce }, extraData), callback);
    }

    function statusBadge(connected) {
        if (connected) return '<span class="caag-badge caag-badge-green">✅ CONECTADO</span>';
        return '<span class="caag-badge caag-badge-red">❌ DESCONECTADO</span>';
    }

    /* -----------------------------------------------------------------------
     * Tab: Estado
     * --------------------------------------------------------------------- */

    var StatusTab = {
        timer: null,

        init: function () {
            this.fetch();
            $('#caag-refresh-status').on('click', function () { StatusTab.fetch(); });
            this.timer = setInterval(function () { StatusTab.fetch(); }, 30000);
        },

        fetch: function () {
            ajaxPost('caag_get_status', {}, function (res) {
                if (!res.success) return;
                var d = res.data;
                $('#caag-status-badge').html(statusBadge(d.connected));
                $('#caag-linked-number').text(d.number || '—');
                $('#caag-heartbeat').text(d.last_heartbeat || '—');
                StatusTab.renderLogs(d.logs || []);
            });
        },

        renderLogs: function (logs) {
            if (!logs.length) {
                $('#caag-logs-body').html('<tr><td colspan="5">Sin registros aún.</td></tr>');
                return;
            }
            var html = '';
            logs.forEach(function (l) {
                var dir = l.direction === 'in'
                    ? '<span style="color:#1e7e34;">↓ entrada</span>'
                    : '<span style="color:#0056b3;">↑ salida</span>';
                var preview = (l.message_body || '').substring(0, 60);
                html += '<tr><td>' + (l.timestamp || '—') + '</td><td>' + (l.phone || '') + '</td><td>' + dir + '</td><td>' + $('<div>').text(preview).html() + '</td><td>' + (l.processed_action || '—') + '</td></tr>';
            });
            $('#caag-logs-body').html(html);
        }
    };

    /* -----------------------------------------------------------------------
     * Tab: QR
     * --------------------------------------------------------------------- */

    var QRTab = {
        timer: null,

        init: function () {
            this.fetch();
            this.timer = setInterval(function () { QRTab.fetch(); }, 5000);

            $('#caag-btn-restart').on('click', function () {
                $('#caag-qr-msg').text('Reiniciando...');
                ajaxPost('caag_restart_bridge', {}, function () {
                    $('#caag-qr-msg').text('Reiniciado. Esperando QR...');
                    QRTab.fetch();
                });
            });

            $('#caag-btn-logout').on('click', function () {
                if (!confirm(caagBot.strings.confirmLogout)) return;
                ajaxPost('caag_logout_bridge', {}, function () {
                    $('#caag-qr-msg').text('Sesión cerrada.');
                    QRTab.fetch();
                });
            });
        },

        fetch: function () {
            ajaxPost('caag_get_status', {}, function (res) {
                if (!res.success) return;
                QRTab.render(res.data);
            });
        },

        render: function (d) {
            if (d.connected) {
                clearInterval(QRTab.timer);
                $('#caag-qr-number').text(d.number || '—');
                $('#caag-qr-connected').show();
                $('#caag-qr-waiting').hide();
                $('#caag-qr-msg').text('');
            } else {
                $('#caag-qr-connected').hide();
                $('#caag-qr-waiting').show();
                if (d.qr) {
                    $('#caag-qr-spinner').hide();
                    $('#caag-qr-img').attr('src', d.qr).show();
                    $('#caag-qr-msg').text('Escanee el código con su teléfono.');
                } else {
                    $('#caag-qr-spinner').show();
                    $('#caag-qr-img').hide();
                    $('#caag-qr-msg').text('Esperando QR del bridge...');
                }
            }
        }
    };

    /* -----------------------------------------------------------------------
     * Tab: Mensajes
     * --------------------------------------------------------------------- */

    var MessagesTab = {
        init: function () {
            $('.caag-msg-textarea').on('input', function () {
                var len = $(this).val().length;
                $(this).next('.caag-char-count').text(len + ' caracteres');
            }).trigger('input');
        }
    };

    /* -----------------------------------------------------------------------
     * Tab: Broadcast
     * --------------------------------------------------------------------- */

    var BroadcastTab = {
        init: function () {
            $('#caag-btn-broadcast').on('click', function () {
                BroadcastTab.send();
            });
        },

        send: function () {
            var msg = $('#caag-broadcast-msg').val().trim();
            if (!msg) { alert('El mensaje no puede estar vacío.'); return; }
            if (!confirm(caagBot.strings.confirmBroadcast)) return;

            $('#caag-btn-broadcast').prop('disabled', true).text(caagBot.strings.sending);
            $('#caag-broadcast-spinner').addClass('is-active');
            $('#caag-broadcast-result').hide();

            ajaxPost('caag_send_broadcast', {
                message:        msg,
                target:         $('#caag-broadcast-target').val(),
                custom_numbers: $('#caag-broadcast-custom').val()
            }, function (res) {
                $('#caag-btn-broadcast').prop('disabled', false).text('Enviar mensaje');
                $('#caag-broadcast-spinner').removeClass('is-active');

                if (res.success) {
                    var d = res.data;
                    $('#caag-broadcast-result')
                        .html('<div class="notice notice-success inline"><p>✅ Enviados: <strong>' + d.sent + '</strong> | Fallidos: <strong>' + d.failed + '</strong></p></div>')
                        .show();
                } else {
                    $('#caag-broadcast-result')
                        .html('<div class="notice notice-error inline"><p>❌ ' + (res.data.message || 'Error') + '</p></div>')
                        .show();
                }
            });
        }
    };

    /* -----------------------------------------------------------------------
     * Tab: Configuración
     * --------------------------------------------------------------------- */

    var ConfigTab = {
        init: function () {
            // Probar conexión
            $('#caag-btn-test').on('click', function () {
                var url = $('#caag_bridge_url').val();
                $('#caag-test-result').text('Probando...');
                ajaxPost('caag_test_bridge', { bridge_url: url }, function (res) {
                    if (res.success && res.data.connected) {
                        $('#caag-test-result').html('<span style="color:green;">✅ Conectado</span>');
                    } else {
                        $('#caag-test-result').html('<span style="color:red;">❌ Sin conexión — ' + (res.data && res.data.message ? res.data.message : 'Verifique URL y token') + '</span>');
                    }
                });
            });

            // Mostrar/ocultar token
            $('#caag-token-toggle').on('click', function () {
                var inp = $('#caag_token');
                var visible = inp.attr('type') === 'text';
                inp.attr('type', visible ? 'password' : 'text');
                $(this).text(visible ? 'Mostrar' : 'Ocultar');
            });

            // Agregar número admin
            $('#caag-btn-add-number').on('click', function () {
                var phone = $('#caag-new-phone').val().replace(/\D/g, '');
                var name  = $('#caag-new-name').val().trim();
                if (phone.length < 7) { $('#caag-number-msg').text('Número inválido.'); return; }

                ajaxPost('caag_add_admin_number', { phone: phone, name: name }, function (res) {
                    if (res.success) {
                        $('#caag-no-admins').remove();
                        var row = '<tr data-phone="' + res.data.phone + '"><td>' + res.data.phone + '</td><td>' + (res.data.name || '—') + '</td><td><button type="button" class="button caag-remove-number">Eliminar</button></td></tr>';
                        $('#caag-admin-numbers').append(row);
                        $('#caag-new-phone').val('');
                        $('#caag-new-name').val('');
                        $('#caag-number-msg').text('');
                    } else {
                        $('#caag-number-msg').text(res.data.message || 'Error.');
                    }
                });
            });

            // Eliminar número admin (delegado)
            $('#caag-admin-numbers').on('click', '.caag-remove-number', function () {
                var row   = $(this).closest('tr');
                var phone = row.data('phone');
                ajaxPost('caag_remove_admin_number', { phone: phone }, function (res) {
                    if (res.success) {
                        row.remove();
                        if (!$('#caag-admin-numbers tr').length) {
                            $('#caag-admin-numbers').append('<tr id="caag-no-admins"><td colspan="3">No hay admins configurados.</td></tr>');
                        }
                    }
                });
            });
        }
    };

    /* -----------------------------------------------------------------------
     * Inicialización basada en la página activa
     * --------------------------------------------------------------------- */

    $(document).ready(function () {
        var page = $('[data-caag-page]').data('caag-page');
        switch (page) {
            case 'status':    StatusTab.init();    break;
            case 'qr':        QRTab.init();        break;
            case 'messages':  MessagesTab.init();  break;
            case 'broadcast': BroadcastTab.init(); break;
            case 'config':    ConfigTab.init();    break;
        }
    });

})(jQuery);
