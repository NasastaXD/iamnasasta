# Instalación del Bridge — Caaguazú Bot

El bridge es el programa que corre en **su PC** y conecta WhatsApp con el plugin de WordPress.

---

## Requisitos

- **Node.js 18 o superior** — descargar desde https://nodejs.org (elegir versión LTS)
- Conexión estable a internet
- El número de WhatsApp que usará para el bot debe estar activo en un teléfono

---

## Paso 1 — Instalar dependencias

Abra una terminal (CMD, PowerShell o Terminal según su sistema) en la carpeta `bridge/`:

```bash
cd ruta/a/wp-caaguazu-bot/bridge
npm install
```

Esto descarga Baileys y el resto de paquetes. Solo se hace una vez.

---

## Paso 2 — Configurar el archivo `.env`

Copie el archivo de ejemplo:

- En Windows: `copy .env.example .env`
- En Mac/Linux: `cp .env.example .env`

Abra `.env` con el Bloc de notas o cualquier editor y complete los valores:

```
PORT=3000
SHARED_TOKEN=un-token-secreto-largo-y-aleatorio
WP_WEBHOOK_URL=https://caaguazu.net/wp-json/caag-bot/v1/incoming
TYPING_DELAY_MS=2000
```

> **Importante:** El `SHARED_TOKEN` debe ser igual al que configure en la pestaña **Configuración** del plugin de WordPress.

Para generar un token seguro puede usar https://generate-secret.now.sh/32 o ejecutar en terminal:
```
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

---

## Paso 3 — Ejecutar el bridge

```bash
node index.js
```

La primera vez, aparecerá un código QR en la terminal. También podrá verlo en la pestaña **Vincular WhatsApp** del plugin.

---

## Paso 4 — Escanear el QR con WhatsApp

En el teléfono que tiene el número del bot:

1. Abrir WhatsApp
2. Tocar los tres puntos (⋮) → **Dispositivos vinculados**
3. Tocar **Vincular dispositivo**
4. Escanear el QR que aparece en la terminal o en el panel de WordPress

Cuando se conecte verá en la terminal:
```
[CaagBridge] Conectado como 595981234567
```

---

## Paso 5 — Exponer el bridge con Cloudflare Tunnel

En otra terminal (sin cerrar la del bridge), ejecute:

```bash
npx cloudflared tunnel --url http://localhost:3000
```

Verá una línea similar a:
```
https://abc-def-ghi.trycloudflare.com
```

Copie esa URL y péguela en la pestaña **Configuración** del plugin de WordPress, campo **URL del Bridge**.

> **Nota:** Esta URL cambia cada vez que reinicia cloudflared. Si reinicia el tunnel, deberá actualizar la URL en WordPress.

---

## Mantener el bridge corriendo

### Windows — Task Scheduler (Programador de tareas)

1. Buscar "Programador de tareas" en el menú inicio
2. Crear tarea básica
3. Desencadenador: Al iniciar sesión
4. Acción: Iniciar un programa → `node`  
   Argumentos: `C:\ruta\a\bridge\index.js`

### Mac/Linux — usando `pm2` (recomendado)

```bash
npm install -g pm2
pm2 start index.js --name caaguazu-bridge
pm2 startup   # para que inicie automáticamente
pm2 save
```

---

## Solución de problemas

| Problema | Solución |
|---|---|
| QR expirado antes de escanearlo | Use el botón "Forzar reconexión" en WordPress o presione Ctrl+C y ejecute `node index.js` de nuevo |
| El bot no responde | Verifique que el bridge esté corriendo y que el tunnel esté activo |
| Error `SHARED_TOKEN no configurado` | Copió `.env.example` pero no lo renombró a `.env` |
| `npm install` falla | Verifique que tiene Node.js 18+: `node --version` |
| El mensaje llega pero WordPress no responde | Verifique la URL del webhook en `.env` y que el plugin esté activado |

---

## Notas importantes

- La carpeta `auth_state/` contiene la sesión de WhatsApp. **No la borre** a menos que quiera desconectar el bot.
- Si borra `auth_state/`, deberá escanear el QR nuevamente.
- El bridge no necesita estar en el mismo servidor que WordPress.
- Mientras el bridge corra en su PC, el bot funciona. Si apaga la PC, el bot deja de responder.
