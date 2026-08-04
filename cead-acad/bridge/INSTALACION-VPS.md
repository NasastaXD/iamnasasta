# Instalación del Bridge en una VPS

Guía para dejar el bridge de WhatsApp corriendo **24/7 en un servidor**, en vez
de una PC del colegio. Es la forma recomendada: la PC se apaga, se reinicia con
las actualizaciones de Windows o pierde el WiFi, y cada vez que eso pasa el bot
deja de responder.

> ¿Buscás la instalación en una PC común? Está en [`INSTALACION.md`](INSTALACION.md).

---

## Qué VPS sirve

Alcanza con la más chica de cualquier proveedor:

| | Mínimo | Cómodo |
|---|---|---|
| RAM | 512 MB (+ swap) | 1 GB |
| Disco | 10 GB | 25 GB |
| Sistema | Ubuntu 22.04 / 24.04 LTS | igual |

Node + Baileys se quedan en unos 120–250 MB. El instalador crea 1 GB de swap
solo si detecta poca RAM, así que una VPS de 512 MB aguanta bien.

Opciones baratas: **Hetzner** (~€4/mes), **DigitalOcean** (~US$4–6/mes),
**Oracle Cloud Free Tier** (gratis, pero el registro es más trabajoso).

También vas a necesitar un **subdominio** apuntando a la IP de la VPS
(ej. `bot.tucolegio.edu.py` → registro `A` con la IP). Con eso el bridge tiene
HTTPS y WordPress lo puede alcanzar.

---

## Paso 1 — Subir el código a la VPS

Entrá por SSH y cloná el repo:

```bash
ssh root@LA-IP-DE-TU-VPS

apt-get update && apt-get install -y git
git clone https://github.com/nasastaxd/iamnasasta.git /tmp/cead
```

---

## Paso 2 — Correr el instalador

```bash
sudo bash /tmp/cead/cead-acad/bridge/deploy/install-vps.sh
```

Hace todo lo aburrido: instala Node.js 20, crea el usuario de servicio
`ceadbridge`, copia el bridge a `/opt/cead-bridge`, instala las dependencias,
genera un `SHARED_TOKEN` aleatorio y registra el servicio de systemd.

Al terminar te muestra **el token compartido**. Copialo.

---

## Paso 3 — Completar el `.env` y arrancar

```bash
sudo nano /opt/cead-bridge/.env
```

Cambiá la línea del webhook por el dominio real de tu WordPress:

```
WP_WEBHOOK_URL=https://tucolegio.edu.py/wp-json/caag-bot/v1/incoming
```

Guardá (`Ctrl+O`, `Enter`, `Ctrl+X`) y arrancá:

```bash
sudo systemctl start cead-bridge
sudo systemctl status cead-bridge
```

Tiene que decir `active (running)`.

---

## Paso 4 — Publicarlo con HTTPS (nginx + certbot)

El bridge escucha **solo en `127.0.0.1:3000`**: no está expuesto a internet.
Falta el proxy que le pone el HTTPS y lo hace alcanzable desde WordPress.

**Un solo comando** (pensado para cuando hay que tipearlo a mano, ej. desde el
celular):

```bash
sudo bash /tmp/cead/cead-acad/bridge/deploy/setup-https.sh bot.TU-DOMINIO.com
```

Instala nginx y certbot, configura el sitio, pide el certificado y al final
prueba que responda solo. Si no le pasás el dominio como argumento, te lo
pregunta.

<details>
<summary>Los mismos pasos a mano (por si preferís controlar cada uno)</summary>

```bash
sudo apt-get install -y nginx certbot python3-certbot-nginx

sudo cp /tmp/cead/cead-acad/bridge/deploy/nginx-cead-bridge.conf \
        /etc/nginx/sites-available/cead-bridge
sudo nano /etc/nginx/sites-available/cead-bridge   # reemplazá bot.TU-DOMINIO.com

sudo ln -s /etc/nginx/sites-available/cead-bridge /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d bot.TU-DOMINIO.com
```

</details>

Al final tiene que responder `{"error":"Unauthorized"}` al pedirle
`/api/status` sin token — eso está **bien**: significa que llegaste al bridge
y que rechaza a quien no lo trae.

### Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

El puerto 3000 **no** se abre: solo entra tráfico por nginx.

---

## Paso 5 — Conectarlo con WordPress

En **CEAD Académico → WhatsApp → Estado y configuración**:

| Campo | Valor |
|---|---|
| URL del bridge | `https://bot.TU-DOMINIO.com` |
| Token compartido | el que imprimió el instalador |

Guardá y tocá **Refrescar estado**.

---

## Paso 6 — Vincular WhatsApp

En esa misma pantalla aparece el **QR, que ahora se refresca solo** mientras el
bot esté desconectado (el QR de WhatsApp vence cada ~20 segundos; antes había
que apretar «Refrescar» y casi siempre te lo encontrabas vencido).

En el teléfono del bot: **WhatsApp → ⋮ → Dispositivos vinculados → Vincular
dispositivo**, y escaneás el QR de la pantalla.

Si el QR no te toma, usá **«¿El QR no funciona? Vinculá con el número»**, que te
da un código de 8 caracteres para tipear en el celular.

Cuando conecta, el semáforo pasa a 🟢 y el QR desaparece solo.

---

## Comandos del día a día

```bash
sudo systemctl status cead-bridge     # ¿está andando?
sudo journalctl -u cead-bridge -f     # ver el log en vivo (Ctrl+C para salir)
sudo systemctl restart cead-bridge    # reiniciar
sudo systemctl stop cead-bridge       # parar
```

## Actualizar el bridge

```bash
cd /tmp/cead && git pull
sudo bash /tmp/cead/cead-acad/bridge/deploy/install-vps.sh
```

Volver a correr el instalador **no** borra la sesión de WhatsApp ni el `.env`:
copia el código nuevo, reinstala dependencias y reinicia el servicio.

> Cuando una versión cambia **dependencias** (no solo `index.js`), hay que
> correr el instalador completo — copiar el `index.js` a mano no alcanza,
> porque no actualiza `node_modules`.

> El bridge **no** se actualiza junto con el plugin de WordPress. Cuando una
> versión toca `bridge/index.js`, hay que hacer esto a mano.

---

## Problemas

| Síntoma | Qué mirar |
|---|---|
| `systemctl status` dice `activating (auto-restart)` | Está crasheando en loop: `journalctl -u cead-bridge -n 50` |
| `El puerto 3000 ya está en uso` | Quedó otro bridge vivo: `sudo systemctl restart cead-bridge` |
| WordPress dice que no alcanza el bridge | Probá `curl https://bot.TU-DOMINIO.com/api/status` desde afuera; si falla es DNS/nginx/certificado |
| `401 Unauthorized` en el log | El token de WordPress no coincide con el del `.env` |
| El QR no aparece en WordPress | Revisá que la URL del bridge esté bien y el semáforo no esté en error |
| Las fotos de los comunicados no llegan | Confirmá `client_max_body_size 25m;` en nginx y reiniciá el bridge |
| Se quedó sin memoria | `free -m`; si no hay swap, corré de nuevo el instalador |

---

## Seguridad

- El bridge escucha en `127.0.0.1`: no es alcanzable sin pasar por nginx.
- Todos los endpoints piden el header `X-Caag-Token`.
- Corre como `ceadbridge`, un usuario de sistema sin shell, y systemd le limita
  el acceso al disco a su propia carpeta.
- `/opt/cead-bridge/.env` queda en modo `600` (solo lo lee su dueño).
- La carpeta `auth_state/` **es la sesión de WhatsApp**: si la borrás hay que
  volver a vincular el teléfono. No la subas a ningún lado.
