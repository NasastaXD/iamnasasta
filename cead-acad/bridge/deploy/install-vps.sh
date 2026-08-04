#!/usr/bin/env bash
# ============================================================================
# CEAD Académico — Instalador del bridge de WhatsApp en una VPS (Ubuntu/Debian)
#
# Deja el bridge corriendo como servicio, arrancando solo con el servidor y
# reiniciándose si se cae.
#
#   sudo bash install-vps.sh
#
# Es idempotente: se puede volver a correr para actualizar el código sin
# perder la sesión de WhatsApp (auth_state/) ni el .env.
# ============================================================================
set -euo pipefail

APP_DIR="/opt/cead-bridge"
APP_USER="ceadbridge"
SERVICE="cead-bridge"
NODE_MAJOR="20"

SRC_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

log()  { echo -e "\n\033[1;36m==>\033[0m $*"; }
warn() { echo -e "\033[1;33m[aviso]\033[0m $*"; }
die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Corré esto con sudo: sudo bash install-vps.sh"
[[ -f "$SRC_DIR/index.js" ]] || die "No encuentro index.js. Corré el script desde cead-acad/bridge/deploy/."

command -v apt-get >/dev/null || die "Este instalador es para Ubuntu/Debian. En otra distro seguí INSTALACION-VPS.md a mano."

# --------------------------------------------------------------------------
# 1. Swap — una VPS de 512 MB–1 GB puede quedarse sin RAM durante npm install
# --------------------------------------------------------------------------
RAM_MB=$( free -m | awk '/^Mem:/{print $2}' )
SWAP_MB=$( free -m | awk '/^Swap:/{print $2}' )

if [[ "$RAM_MB" -lt 1200 && "$SWAP_MB" -lt 256 ]]; then
    log "RAM baja (${RAM_MB} MB) y sin swap — creando 1 GB de swap"
    if [[ ! -f /swapfile ]]; then
        fallocate -l 1G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=1024
        chmod 600 /swapfile
        mkswap /swapfile >/dev/null
    fi
    swapon /swapfile 2>/dev/null || true
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# --------------------------------------------------------------------------
# 2. Node.js
# --------------------------------------------------------------------------
NEED_NODE=1
if command -v node >/dev/null 2>&1; then
    CUR="$( node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0 )"
    if [[ "$CUR" -ge 18 ]]; then
        log "Node.js $( node -v ) ya instalado"
        NEED_NODE=0
    else
        warn "Node.js $( node -v ) es viejo (hace falta 18+). Se instala el $NODE_MAJOR."
    fi
fi

if [[ "$NEED_NODE" -eq 1 ]]; then
    log "Instalando Node.js ${NODE_MAJOR} LTS"
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg --yes
    chmod a+r /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -qq
    apt-get install -y -qq nodejs
    log "Node.js $( node -v ) instalado"
fi

# --------------------------------------------------------------------------
# 3. Usuario de servicio (sin login, sin home propio)
# --------------------------------------------------------------------------
if ! id -u "$APP_USER" >/dev/null 2>&1; then
    log "Creando usuario de sistema $APP_USER"
    useradd --system --shell /usr/sbin/nologin --home-dir "$APP_DIR" "$APP_USER"
fi

# --------------------------------------------------------------------------
# 4. Copiar el código (preservando .env y la sesión de WhatsApp)
# --------------------------------------------------------------------------
log "Copiando el bridge a $APP_DIR"
mkdir -p "$APP_DIR"
for f in index.js package.json setup-tunnel.js; do
    [[ -f "$SRC_DIR/$f" ]] && cp "$SRC_DIR/$f" "$APP_DIR/"
done
[[ -f "$SRC_DIR/package-lock.json" ]] && cp "$SRC_DIR/package-lock.json" "$APP_DIR/"
cp "$SRC_DIR/.env.example" "$APP_DIR/.env.example"

# --------------------------------------------------------------------------
# 5. .env — se crea solo la primera vez; nunca se pisa
# --------------------------------------------------------------------------
FIRST_RUN=0
if [[ ! -f "$APP_DIR/.env" ]]; then
    FIRST_RUN=1
    log "Creando .env inicial"
    TOKEN="$( node -e 'console.log(require("crypto").randomBytes(32).toString("hex"))' )"
    cat > "$APP_DIR/.env" <<EOF
# Generado por install-vps.sh el $( date -Is )
PORT=3000
HOST=127.0.0.1
PORT_STRICT=1
TUNNEL=off

SHARED_TOKEN=${TOKEN}

# ⚠️  COMPLETAR: dominio de tu WordPress.
WP_WEBHOOK_URL=https://TU-SITIO/wp-json/caag-bot/v1/incoming

TYPING_DELAY_MS=1500
WP_TIMEOUT_MS=45000
WP_TIMEOUT_AUDIO_MS=120000
EOF
fi

# --------------------------------------------------------------------------
# 6. Dependencias
# --------------------------------------------------------------------------
# Se instala como root y después se cede la carpeta entera al usuario de
# servicio: hacerlo al revés falla porque ceadbridge todavía no es dueño de
# $APP_DIR (ni de la caché de npm).
log "Instalando dependencias de Node (puede tardar unos minutos)"
cd "$APP_DIR"
if [[ -f package-lock.json ]]; then
    npm ci --omit=dev --no-audit --no-fund || npm install --omit=dev --no-audit --no-fund
else
    npm install --omit=dev --no-audit --no-fund
fi

chown -R "$APP_USER:$APP_USER" "$APP_DIR"
chmod 600 "$APP_DIR/.env"

# --------------------------------------------------------------------------
# 7. Servicio systemd
# --------------------------------------------------------------------------
log "Instalando el servicio systemd"
cp "$SRC_DIR/deploy/cead-bridge.service" "/etc/systemd/system/${SERVICE}.service"
systemctl daemon-reload
systemctl enable "$SERVICE" >/dev/null 2>&1

if [[ "$FIRST_RUN" -eq 1 ]]; then
    warn "Todavía NO arranco el servicio: falta completar WP_WEBHOOK_URL en $APP_DIR/.env"
else
    log "Reiniciando el bridge"
    systemctl restart "$SERVICE"
    sleep 2
    systemctl is-active --quiet "$SERVICE" \
        && log "Bridge activo" \
        || warn "El bridge no quedó activo. Mirá: journalctl -u $SERVICE -n 50"
fi

# --------------------------------------------------------------------------
# Resumen
# --------------------------------------------------------------------------
echo
echo "============================================================"
echo " Bridge instalado en $APP_DIR"
echo "============================================================"
if [[ "$FIRST_RUN" -eq 1 ]]; then
    echo
    echo " FALTAN 2 PASOS:"
    echo
    echo " 1) Editá el .env y poné el dominio de tu WordPress:"
    echo "      sudo nano $APP_DIR/.env"
    echo "      → WP_WEBHOOK_URL=https://TU-SITIO/wp-json/caag-bot/v1/incoming"
    echo
    echo " 2) Arrancalo:"
    echo "      sudo systemctl start $SERVICE"
    echo
    echo " Token compartido (copialo en WordPress → CEAD Académico →"
    echo " WhatsApp → Configuración → Token compartido):"
    echo
    echo "      $( grep '^SHARED_TOKEN=' "$APP_DIR/.env" | cut -d= -f2 )"
    echo
fi
echo " Comandos útiles:"
echo "   sudo systemctl status $SERVICE      # estado"
echo "   sudo journalctl -u $SERVICE -f      # ver el log en vivo"
echo "   sudo systemctl restart $SERVICE     # reiniciar"
echo
echo " El bridge escucha SOLO en 127.0.0.1:3000 (no expuesto a internet)."
echo " Para que WordPress lo alcance falta el proxy con HTTPS:"
echo " seguí la sección «Paso 4» de INSTALACION-VPS.md."
echo "============================================================"
