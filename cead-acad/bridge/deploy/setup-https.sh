#!/usr/bin/env bash
# ============================================================================
# CEAD Académico — HTTPS para el bridge (nginx + certbot), en un solo paso.
#
#   sudo bash setup-https.sh bot.tudominio.com
#
# Hace lo mismo que el "Paso 4" de INSTALACION-VPS.md (instalar nginx/certbot,
# copiar la plantilla, activarla, pedir el certificado) pero en un solo
# comando — pensado para cuando hay que tipearlo a mano desde el celular.
# ============================================================================
set -euo pipefail

log()  { echo -e "\n\033[1;36m==>\033[0m $*"; }
die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Corré esto con sudo: sudo bash setup-https.sh TU-DOMINIO"

SRC_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DOMAIN="${1:-}"

if [[ -z "$DOMAIN" ]]; then
    read -rp "Dominio del bridge (ej. bot.tudominio.com): " DOMAIN
fi
[[ -n "$DOMAIN" ]] || die "Hace falta un dominio."

log "Verificando que $DOMAIN apunte a esta VPS"
MY_IP="$( curl -fsS https://api.ipify.org || true )"
DNS_IP="$( getent hosts "$DOMAIN" 2>/dev/null | awk '{print $1}' | head -1 || true )"
if [[ -n "$MY_IP" && -n "$DNS_IP" && "$MY_IP" != "$DNS_IP" ]]; then
    echo "  Esta VPS: $MY_IP   —   $DOMAIN resuelve a: $DNS_IP"
    read -rp "  No coinciden. ¿Seguir igual? (s/N) " ans
    [[ "$ans" =~ ^[sS]$ ]] || die "Corregí el registro DNS y volvé a correr esto."
fi

log "Instalando nginx y certbot"
apt-get update -qq
apt-get install -y -qq nginx certbot python3-certbot-nginx

log "Configurando el sitio para $DOMAIN"
cp "$SRC_DIR/nginx-cead-bridge.conf" /etc/nginx/sites-available/cead-bridge
sed -i "s/bot\.TU-DOMINIO\.com/${DOMAIN}/g" /etc/nginx/sites-available/cead-bridge
ln -sf /etc/nginx/sites-available/cead-bridge /etc/nginx/sites-enabled/cead-bridge
nginx -t
systemctl reload nginx

log "Pidiendo el certificado HTTPS (certbot)"
certbot --nginx -d "$DOMAIN"

echo
echo "============================================================"
echo " Probando: curl https://$DOMAIN/api/status"
echo "============================================================"
sleep 1
curl -sS "https://$DOMAIN/api/status" || true
echo
echo
echo " Si arriba dice {\"error\":\"Unauthorized\"} — está todo OK,"
echo " eso es lo esperado sin el token."
echo
echo " Ahora en WordPress → CEAD Académico → WhatsApp → Configuración:"
echo "   URL del bridge: https://$DOMAIN"
echo "============================================================"
