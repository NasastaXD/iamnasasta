# Instalación del Bridge — CEAD Académico (WhatsApp)

El **bridge** es el programa que corre en **una PC del colegio** y conecta
WhatsApp con el plugin de WordPress. Vive en `cead-acad/bridge/`.

> Resumen: instalás Node.js, completás dos datos en un archivo, escaneás un QR
> y dejás la PC prendida. Es un trabajo de **una sola vez**.

> **¿Tenés un servidor o pensás alquilar una VPS?** Seguí
> [`INSTALACION-VPS.md`](INSTALACION-VPS.md) en vez de esta guía: hay un
> instalador que hace todo solo y deja el bot corriendo 24/7, sin depender de
> que una PC quede prendida.

---

## Requisitos

- **Node.js 18 o superior** — descargar de https://nodejs.org (versión LTS).
- Conexión estable a internet.
- El número de WhatsApp del bot, activo en un teléfono.

---

## Paso 1 — Instalar dependencias

Abrí una terminal en la carpeta `cead-acad/bridge`:

```bash
cd ruta/a/cead-acad/bridge
npm install
```

Descarga Baileys y los demás paquetes. Solo se hace una vez.

---

## Paso 2 — Configurar el archivo `.env`

Copiá el ejemplo:

- Windows: `copy .env.example .env`
- Mac/Linux: `cp .env.example .env`

Abrí `.env` con el Bloc de notas y completá:

```
PORT=3000
SHARED_TOKEN=un-token-secreto-largo-y-aleatorio
WP_WEBHOOK_URL=https://TU-SITIO/wp-json/caag-bot/v1/incoming
TYPING_DELAY_MS=2000
```

> **Importante:** el `SHARED_TOKEN` debe ser **el mismo** que cargues en
> WordPress en **CEAD Académico → WhatsApp → Configuración → Token compartido**.

Para generar un token seguro:
```
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

---

## Paso 3 — Ejecutar el bridge

```bash
node index.js
```

La primera vez aparece un **código QR** en la terminal. También se ve en
WordPress: **CEAD Académico → WhatsApp → Estado**.

---

## Paso 4 — Vincular WhatsApp

En el teléfono con el número del bot:

1. WhatsApp → tres puntos (⋮) → **Dispositivos vinculados**.
2. **Vincular dispositivo**.
3. Escaneá el QR de la terminal o del panel de WordPress.

Al conectar verás: `[CaagBridge] Conectado como 595981234567`. En el panel el
semáforo pasa a 🟢.

---

## Paso 5 — Exponer el bridge (Cloudflare Tunnel)

En **otra** terminal (sin cerrar la del bridge):

```bash
npx cloudflared tunnel --url http://localhost:3000
```

Copiá la URL `https://....trycloudflare.com` y pegala en
**CEAD Académico → WhatsApp → Configuración → URL del bridge**.

> El script `setup-tunnel.js` automatiza esto y puede avisarle la URL a
> WordPress solo (endpoint `/wp-json/caag-bot/v1/update-bridge-url`).

> **Nota:** esa URL cambia cada vez que reiniciás cloudflared; actualizala en
> WordPress si pasa.

---

## Mantener el bridge corriendo

**Windows — Programador de tareas:** crear tarea básica → al iniciar sesión →
iniciar `node` con argumento `C:\ruta\a\cead-acad\bridge\index.js`.

**Mac/Linux — pm2 (recomendado):**
```bash
npm install -g pm2
pm2 start index.js --name cead-bridge
pm2 startup && pm2 save
```

---

## Solución de problemas

| Problema | Solución |
|---|---|
| QR expirado | Botón **Reiniciar bridge** en WordPress, o Ctrl+C y `node index.js` otra vez |
| El bot no responde | Verificá que el bridge y el tunnel estén corriendo, y el semáforo 🟢 |
| `SHARED_TOKEN no configurado` | Copiaste `.env.example` pero no lo renombraste a `.env` |
| `npm install` falla | Confirmá Node.js 18+: `node --version` |
| Llega el mensaje pero no responde | Revisá la URL del webhook en `.env` y que el token coincida con WordPress |
| El bot **no te reconoce** (te trata como visitante aunque tu número esté cargado) | WhatsApp pasó a direccionar por **LID**. Corré `npm install` en la carpeta del bridge y reiniciá. El bridge ya lee el número real (`senderPn`); con una versión vieja de Baileys ese dato no llega y el bot solo funciona en modo general |
| **«Esperando este mensaje»** en el celular, o el bot contesta una vez y después deja de andar | Faltaba soporte de LID en la librería. Se resuelve con Baileys 7 (ya fijado en `package.json`): corré `npm install` en la carpeta del bridge y reiniciá. La serie 6.7.x no tiene el módulo `lid-mapping` y no puede mantener la sesión de cifrado con WhatsApp actual |
| **Manda mensajes dobles** | Ya resuelto: el bridge deduplica por ID de mensaje y solo procesa mensajes nuevos (`type: 'notify'`). Asegurate de tener la última versión de `index.js` y reiniciá el bridge |

---

## Notas

- La carpeta `auth_state/` guarda la sesión de WhatsApp. **No la borres** salvo
  que quieras desvincular el bot.
- El bridge no necesita estar en el mismo servidor que WordPress.
- Mientras la PC esté prendida y con internet, el bot funciona. Si se apaga, deja
  de responder.
