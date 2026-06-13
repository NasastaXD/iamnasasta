/**
 * Caaguazú Bot — Bridge WhatsApp
 * Conecta una cuenta de WhatsApp con el plugin WordPress vía Baileys.
 * Corre en la PC del admin y se expone con Cloudflare Tunnel.
 */

import 'dotenv/config';
import makeWASocket, {
    useMultiFileAuthState,
    DisconnectReason,
    Browsers,
    fetchLatestBaileysVersion,
    downloadMediaMessage,
} from '@whiskeysockets/baileys';
import express          from 'express';
import axios            from 'axios';
import qrcode           from 'qrcode';
import qrcodeTerminal   from 'qrcode-terminal';
import pino             from 'pino';
import { spawn }        from 'child_process';
import { createServer } from 'net';
import { randomBytes }  from 'crypto';
import { rmSync, existsSync, readFileSync, writeFileSync } from 'fs';

// -----------------------------------------------------------------------
// Auto-configuración del .env (evita el crash por token faltante)
// -----------------------------------------------------------------------

function ensureToken() {
    if ( process.env.SHARED_TOKEN ) return;

    const token   = randomBytes( 32 ).toString( 'hex' );
    const envPath = './.env';
    let content   = existsSync( envPath ) ? readFileSync( envPath, 'utf8' ) : '';

    if ( content.includes( 'SHARED_TOKEN=' ) ) {
        content = content.replace( /SHARED_TOKEN=.*/m, `SHARED_TOKEN=${ token }` );
    } else {
        content += `\nSHARED_TOKEN=${ token }\n`;
    }

    writeFileSync( envPath, content, 'utf8' );
    process.env.SHARED_TOKEN = token;

    console.log( '\n[CaagBridge] ⚠️  SHARED_TOKEN generado automáticamente y guardado en .env' );
    console.log( `[CaagBridge]    Token: ${ token }` );
    console.log( '[CaagBridge]    Copie este token en: WordPress → CEAD Académico → WhatsApp → Configuración → Token compartido\n' );
}

ensureToken();

// -----------------------------------------------------------------------
// Instancia única
// -----------------------------------------------------------------------
// Dos bridges compartiendo la misma cuenta es la causa típica del bucle
// conectar/desconectar con "reason 440" (connectionReplaced): cada uno
// reemplaza al otro sin parar. Un lock con el PID impide arrancar un segundo.

const LOCK_FILE = './bridge.lock';

function acquireSingleInstanceLock() {
    if ( existsSync( LOCK_FILE ) ) {
        const pid = parseInt( readFileSync( LOCK_FILE, 'utf8' ).trim(), 10 );
        if ( pid && pid !== process.pid ) {
            let alive = false;
            try { process.kill( pid, 0 ); alive = true; } catch { alive = false; }
            if ( alive ) {
                console.error( `\n[CaagBridge] ⛔ Ya hay otra instancia del bridge corriendo (PID ${ pid }).` );
                console.error( '[CaagBridge]    Cerrá esa instancia primero. Tener dos abiertas hace que WhatsApp se' );
                console.error( '[CaagBridge]    desconecte y reconecte en bucle (reason 440).\n' );
                process.exit( 1 );
            }
        }
    }
    writeFileSync( LOCK_FILE, String( process.pid ), 'utf8' );
    const release = () => {
        try {
            if ( existsSync( LOCK_FILE ) && parseInt( readFileSync( LOCK_FILE, 'utf8' ).trim(), 10 ) === process.pid ) {
                rmSync( LOCK_FILE, { force: true } );
            }
        } catch {}
    };
    process.on( 'exit', release );
    process.on( 'SIGINT',  () => { release(); process.exit( 0 ); } );
    process.on( 'SIGTERM', () => { release(); process.exit( 0 ); } );
}

acquireSingleInstanceLock();

// -----------------------------------------------------------------------
// Configuración
// -----------------------------------------------------------------------

const PREFERRED_PORT = process.env.PORT ? parseInt( process.env.PORT, 10 ) : null;
const SHARED_TOKEN   = process.env.SHARED_TOKEN;
const WP_WEBHOOK     = process.env.WP_WEBHOOK_URL || '';
const TYPING_DELAY   = parseInt( process.env.TYPING_DELAY_MS || '1500', 10 );
const AUTH_DIR       = './auth_state';

if ( ! WP_WEBHOOK ) {
    console.warn( '[CaagBridge] ⚠️  WP_WEBHOOK_URL no configurado — los mensajes no se enviarán a WordPress.' );
    console.warn( '[CaagBridge]    Edite .env y agregue: WP_WEBHOOK_URL=https://su-sitio.com/wp-json/caag-bot/v1/incoming\n' );
}

// -----------------------------------------------------------------------
// Puerto aleatorio libre
// -----------------------------------------------------------------------

async function getFreePort( preferred ) {
    return new Promise( ( resolve, reject ) => {
        const server = createServer();
        server.listen( preferred || 0, () => {
            const port = server.address().port;
            server.close( () => resolve( port ) );
        } );
        server.on( 'error', () => {
            const fallback = createServer();
            fallback.listen( 0, () => {
                const port = fallback.address().port;
                fallback.close( () => resolve( port ) );
            } );
            fallback.on( 'error', reject );
        } );
    } );
}

// -----------------------------------------------------------------------
// Estado del bot
// -----------------------------------------------------------------------

let sock              = null;
let qrBase64          = null;
let isConnected       = false;
let linkedNumber      = null;
let waVersion         = null;  // versión del protocolo WA (se obtiene una sola vez)
let reconnectTimer    = null;  // reconexión pendiente (solo una a la vez)
let reconnectAttempts = 0;     // para el backoff exponencial

const logger = pino( { level: 'silent' } );

// Log con hora local (los logs eran ilegibles sin marca de tiempo).
function blog( ...args ) {
    const t = new Date().toLocaleTimeString( 'es-PY', { hour12: false } );
    console.log( `[${ t }][CaagBridge]`, ...args );
}

// Deduplicación de mensajes: WhatsApp/Baileys puede entregar el mismo mensaje
// más de una vez (reintentos, reconexión). Guardamos los IDs vistos un rato
// para no procesar duplicados y evitar respuestas/flujos dobles.
const seenMessages = new Map(); // id → timestamp (ms)
const SEEN_TTL_MS  = 5 * 60 * 1000;

function alreadySeen( id ) {
    if ( ! id ) return false;
    const now = Date.now();
    // Limpieza perezosa de entradas viejas.
    for ( const [ key, ts ] of seenMessages ) {
        if ( now - ts > SEEN_TTL_MS ) seenMessages.delete( key );
    }
    if ( seenMessages.has( id ) ) return true;
    seenMessages.set( id, now );
    return false;
}

// Extrae el número de teléfono REAL de la clave del mensaje. WhatsApp ahora
// puede direccionar por LID (`<id>@lid`), que NO es un número; en ese caso el
// teléfono real viene en senderPn / participantPn (Baileys reciente). Probamos
// varios campos y descartamos cualquier JID `@lid`.
function extractPhone( key ) {
    if ( ! key ) return '';
    // 1) Preferir un JID que SEA un número real (no LID).
    const candidates = [
        key.senderPn,       // número real cuando el chat usa LID
        key.participantPn,  // idem en algunos eventos
        key.remoteJidAlt,   // JID alternativo (según versión)
        key.remoteJid,      // normal: <phone>@s.whatsapp.net
    ];
    for ( const jid of candidates ) {
        if ( ! jid || typeof jid !== 'string' ) continue;
        if ( jid.endsWith( '@lid' ) ) continue;       // un LID no es un teléfono
        const digits = jid.replace( /@.+$/, '' ).replace( /[^0-9]/g, '' );
        if ( digits.length >= 7 ) return digits;
    }
    // 2) Último recurso: usar los dígitos del remoteJid aunque sea un LID. No
    //    permite reconocer al usuario, pero el bot igual responde (modo general)
    //    en vez de quedar mudo. Ocurre solo en versiones viejas de Baileys.
    const fallback = ( key.remoteJid || '' ).replace( /@.+$/, '' ).replace( /[^0-9]/g, '' );
    return fallback.length >= 7 ? fallback : '';
}

// -----------------------------------------------------------------------
// Conexión a WhatsApp
// -----------------------------------------------------------------------

// Cierra y limpia el socket anterior antes de crear uno nuevo. Sin esto, cada
// reconexión deja vivo el socket viejo (con sus listeners), y varias conexiones
// del mismo proceso se reemplazan entre sí → bucle de "reason 440".
function teardownSocket() {
    if ( ! sock ) return;
    try { sock.ev.removeAllListeners(); } catch {}
    try { sock.end( undefined ); } catch {}
    sock = null;
}

// Programa UNA reconexión con backoff exponencial (3s, 6s, 12s… máx. 60s). Si ya
// hay una en camino, no agenda otra (evita reconexiones solapadas).
function scheduleReconnect( clearSession = false ) {
    if ( clearSession ) { clearAuthState(); reconnectAttempts = 0; }
    if ( reconnectTimer ) return;
    const delay = Math.min( 60000, 3000 * Math.pow( 2, reconnectAttempts ) );
    reconnectAttempts++;
    blog( `Reintentando conexión en ${ Math.round( delay / 1000 ) }s…` );
    reconnectTimer = setTimeout( () => {
        reconnectTimer = null;
        connectToWhatsApp();
    }, delay );
}

async function connectToWhatsApp() {
    teardownSocket(); // limpia cualquier socket anterior antes de abrir uno nuevo

    // Versión del protocolo WA: se obtiene UNA sola vez y se reutiliza en las
    // reconexiones (antes se pedía en cada intento y llenaba el log).
    if ( ! waVersion ) {
        try {
            const { version, isLatest } = await fetchLatestBaileysVersion();
            waVersion = version;
            blog( `Protocolo WA: ${ version.join( '.' ) } (última: ${ isLatest })` );
        } catch {
            waVersion = [ 2, 3000, 1015901307 ]; // fallback conocido
            blog( 'No se pudo obtener versión WA, usando fallback.' );
        }
    }

    const { state, saveCreds } = await useMultiFileAuthState( AUTH_DIR );

    sock = makeWASocket( {
        version: waVersion,
        auth:    state,
        logger,
        browser: Browsers.ubuntu( 'Chrome' ),
        connectTimeoutMs:    60000,
        keepAliveIntervalMs: 25000,
    } );

    sock.ev.on( 'creds.update', saveCreds );

    sock.ev.on( 'connection.update', async ( update ) => {
        const { connection, lastDisconnect, qr } = update;

        if ( qr ) {
            isConnected = false;
            qrBase64    = await qrcode.toDataURL( qr );

            console.log( '\n[CaagBridge] Escanee el QR con WhatsApp:' );
            console.log( '  WhatsApp → ⋮ → Dispositivos vinculados → Vincular dispositivo\n' );
            qrcodeTerminal.generate( qr, { small: true } );
        }

        if ( connection === 'open' ) {
            isConnected       = true;
            qrBase64          = null;
            reconnectAttempts = 0; // conexión sana: reinicia el backoff
            linkedNumber      = ( sock.user?.id || '' ).replace( /@.+$/, '' );
            blog( `✅ Conectado como ${ linkedNumber }` );
        }

        if ( connection === 'close' ) {
            isConnected  = false;
            linkedNumber = null;
            const reason = lastDisconnect?.error?.output?.statusCode;

            // 401 (loggedOut) / 500 (badSession): la sesión ya no sirve → borrar
            // credenciales y volver a emparejar con QR.
            if ( reason === DisconnectReason.loggedOut || reason === 500 ) {
                blog( 'Sesión cerrada o inválida — borrando credenciales y pidiendo QR nuevo.' );
                scheduleReconnect( true );
                return;
            }
            // 440 (connectionReplaced): otra sesión tomó el lugar (¿WhatsApp Web
            // abierto, u otra copia del bridge?). Reconectamos con backoff; el lock
            // de instancia única evita el ping-pong entre dos bridges.
            if ( reason === 440 ) {
                blog( 'La conexión fue reemplazada por otra sesión (reason 440). Reconectando con espera…' );
                scheduleReconnect( false );
                return;
            }
            // 515 (restartRequired): paso normal tras emparejar; reconexión rápida.
            blog( `Desconectado (reason: ${ reason ?? 'desconocido' }).` );
            scheduleReconnect( false );
        }
    } );

    sock.ev.on( 'messages.upsert', async ( { messages, type } ) => {
        // Solo mensajes recién recibidos. 'append' llega en sincronización/
        // reconexión y reprocesaría mensajes viejos (causa de respuestas dobles).
        if ( type !== 'notify' ) return;

        for ( const msg of messages ) {
            if ( msg.key.fromMe )                          continue;
            if ( msg.key.remoteJid?.endsWith( '@g.us' ) ) continue;

            // Dedup por ID: evita procesar el mismo mensaje dos veces.
            if ( alreadySeen( msg.key.id ) ) continue;

            const from     = extractPhone( msg.key );
            const imageMsg = msg.message?.imageMessage;
            // Nota de voz (ptt) o audio. WhatsApp manda audioMessage en ambos casos.
            const audioMsg = msg.message?.audioMessage;
            const body = msg.message?.conversation
                      || msg.message?.extendedTextMessage?.text
                      || imageMsg?.caption
                      || '';

            let media = imageMsg ? await downloadImage( msg, imageMsg ) : null;
            if ( ! media && audioMsg ) {
                media = await downloadAudio( msg, audioMsg );
            }

            if ( ! body.trim() && ! media ) continue;

            if ( ! from ) {
                console.warn( '[CaagBridge] ⚠️  No se pudo extraer el número del mensaje (¿LID sin número?):', msg.key.remoteJid );
                continue;
            }

            await sock.readMessages( [ msg.key ] ).catch( () => {} );

            // "Escribiendo…" mientras WordPress procesa (la IA puede tardar varios
            // segundos). WhatsApp apaga el indicador solo a los pocos segundos, así
            // que lo refrescamos hasta que WP responda; recién ahí ponemos 'paused'.
            await sock.sendPresenceUpdate( 'composing', msg.key.remoteJid ).catch( () => {} );
            const typing = setInterval( () => {
                sock.sendPresenceUpdate( 'composing', msg.key.remoteJid ).catch( () => {} );
            }, 8000 );

            try {
                if ( WP_WEBHOOK ) {
                    // Reintentos con backoff: WordPress deduplica por `id`, así que
                    // reenviar tras un timeout no genera respuestas dobles.
                    await postToWordPress( {
                        from:      from,
                        body:      body,
                        pushName:  msg.pushName || '',
                        timestamp: msg.messageTimestamp || Math.floor( Date.now() / 1000 ),
                        media:     media,
                        id:        msg.key.id || '',
                    } );
                }
            } finally {
                clearInterval( typing );
                // Pequeño respiro antes de cortar el indicador, para que se solape
                // con la llegada del mensaje del bot y no haya un parpadeo de silencio.
                setTimeout( () => {
                    sock.sendPresenceUpdate( 'paused', msg.key.remoteJid ).catch( () => {} );
                }, TYPING_DELAY );
            }
        }
    } );
}

// POST a WordPress con reintentos y backoff exponencial (2s, 4s). Solo
// reintenta errores de red y 5xx; un 4xx (token inválido) no se reintenta.
async function postToWordPress( payload, attempts = 3 ) {
    for ( let attempt = 1; attempt <= attempts; attempt++ ) {
        try {
            const resp = await axios.post( WP_WEBHOOK, payload, {
                headers: { 'X-Caag-Token': SHARED_TOKEN },
                timeout: 30000,
                maxBodyLength: Infinity,
            } );
            // WordPress responde 200 incluso cuando descarta el mensaje por rate
            // limit; no reintentamos (reintentar empeoraría el límite), pero lo
            // dejamos visible en el log para no perderlo en silencio.
            if ( resp && resp.data && resp.data.limited ) {
                console.warn( '[CaagBridge] Mensaje descartado por rate limit en WordPress (no se reintenta).' );
            }
            return true;
        } catch ( err ) {
            const status    = err.response?.status;
            const retryable = ! status || status >= 500;
            if ( ! retryable || attempt === attempts ) {
                console.error( `[CaagBridge] Error enviando a WordPress (intento ${attempt}/${attempts}, definitivo):`, err.message );
                return false;
            }
            const delay = 2000 * Math.pow( 2, attempt - 1 );
            console.warn( `[CaagBridge] Error enviando a WordPress (intento ${attempt}/${attempts}):`, err.message, `— reintento en ${delay / 1000}s` );
            await new Promise( ( r ) => setTimeout( r, delay ) );
        }
    }
    return false;
}

function clearAuthState() {
    if ( existsSync( AUTH_DIR ) ) {
        rmSync( AUTH_DIR, { recursive: true, force: true } );
    }
}

// Descarga una imagen entrante y la devuelve en base64 para enviarla a WordPress.
// Solo acepta jpg/png/webp de hasta 5 MB; cualquier otra cosa se ignora.
async function downloadImage( msg, imageMsg ) {
    const allowed  = [ 'image/jpeg', 'image/png', 'image/webp' ];
    const baseMime = ( imageMsg.mimetype || 'image/jpeg' ).split( ';' )[ 0 ].trim();

    if ( ! allowed.includes( baseMime ) ) {
        return null;
    }

    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            { logger, reuploadRequest: sock.updateMediaMessage }
        );

        if ( ! buffer || buffer.length > 5 * 1024 * 1024 ) {
            console.warn( '[CaagBridge] Imagen omitida (vacía o mayor a 5 MB).' );
            return null;
        }

        return {
            mime:        baseMime,
            data_base64: buffer.toString( 'base64' ),
            filename:    `whatsapp-${ Date.now() }.${ baseMime.split( '/' )[ 1 ] }`,
        };
    } catch ( err ) {
        console.error( '[CaagBridge] Error descargando imagen:', err.message );
        return null;
    }
}

// Descarga una nota de voz / audio entrante y la devuelve en base64 para que
// WordPress la transcriba. Acepta hasta 16 MB (notas de voz largas).
async function downloadAudio( msg, audioMsg ) {
    const baseMime = ( audioMsg.mimetype || 'audio/ogg' ).split( ';' )[ 0 ].trim();

    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            { logger, reuploadRequest: sock.updateMediaMessage }
        );

        if ( ! buffer || buffer.length > 16 * 1024 * 1024 ) {
            console.warn( '[CaagBridge] Audio omitido (vacío o mayor a 16 MB).' );
            return null;
        }

        return {
            mime:        baseMime,
            data_base64: buffer.toString( 'base64' ),
            filename:    `whatsapp-${ Date.now() }.ogg`,
        };
    } catch ( err ) {
        console.error( '[CaagBridge] Error descargando audio:', err.message );
        return null;
    }
}

// -----------------------------------------------------------------------
// Servidor Express
// -----------------------------------------------------------------------

const app = express();
app.use( express.json() );

app.use( ( req, res, next ) => {
    const token = req.headers[ 'x-caag-token' ];
    if ( ! token || token !== SHARED_TOKEN ) {
        return res.status( 401 ).json( { error: 'Unauthorized' } );
    }
    next();
} );

app.post( '/api/send', async ( req, res ) => {
    const { to, message } = req.body;
    if ( ! to || ! message ) {
        return res.status( 400 ).json( { sent: false, error: 'Campos requeridos: to, message' } );
    }
    if ( ! isConnected || ! sock ) {
        return res.status( 500 ).json( { sent: false, error: 'No conectado a WhatsApp' } );
    }
    try {
        const result = await sock.sendMessage( `${ to }@s.whatsapp.net`, { text: message } );
        return res.json( { sent: true, id: result?.key?.id } );
    } catch ( err ) {
        console.error( '[CaagBridge] send error:', err.message );
        return res.status( 500 ).json( { sent: false, error: err.message } );
    }
} );

// Envío de imagen (base64) con caption opcional.
app.post( '/api/send-image', async ( req, res ) => {
    const { to, image_base64, caption } = req.body;
    if ( ! to || ! image_base64 ) {
        return res.status( 400 ).json( { sent: false, error: 'Campos requeridos: to, image_base64' } );
    }
    if ( ! isConnected || ! sock ) {
        return res.status( 500 ).json( { sent: false, error: 'No conectado a WhatsApp' } );
    }
    try {
        const buffer = Buffer.from( image_base64, 'base64' );
        const result = await sock.sendMessage(
            `${ to }@s.whatsapp.net`,
            { image: buffer, caption: caption || '' }
        );
        return res.json( { sent: true, id: result?.key?.id } );
    } catch ( err ) {
        console.error( '[CaagBridge] send-image error:', err.message );
        return res.status( 500 ).json( { sent: false, error: err.message } );
    }
} );

// Edición de un mensaje ya enviado (menús que se actualizan en sitio).
app.post( '/api/edit', async ( req, res ) => {
    const { to, message, msg_id } = req.body;
    if ( ! to || ! message || ! msg_id ) {
        return res.status( 400 ).json( { edited: false, error: 'Campos requeridos: to, message, msg_id' } );
    }
    if ( ! isConnected || ! sock ) {
        return res.status( 500 ).json( { edited: false, error: 'No conectado a WhatsApp' } );
    }
    try {
        const jid = `${ to }@s.whatsapp.net`;
        const result = await sock.sendMessage( jid, {
            text: message,
            edit: { remoteJid: jid, fromMe: true, id: msg_id },
        } );
        return res.json( { edited: true, id: result?.key?.id || msg_id } );
    } catch ( err ) {
        console.error( '[CaagBridge] edit error:', err.message );
        return res.status( 500 ).json( { edited: false, error: err.message } );
    }
} );

// Perfil del bot: nombre visible y/o descripción ("info" de la cuenta).
app.post( '/api/profile', async ( req, res ) => {
    const { name, about } = req.body;
    if ( ! isConnected || ! sock ) {
        return res.status( 500 ).json( { ok: false, error: 'No conectado a WhatsApp' } );
    }
    try {
        if ( typeof name === 'string' && name.trim() !== '' ) {
            await sock.updateProfileName( name.trim() );
        }
        if ( typeof about === 'string' ) {
            await sock.updateProfileStatus( about );
        }
        return res.json( { ok: true } );
    } catch ( err ) {
        console.error( '[CaagBridge] profile error:', err.message );
        return res.status( 500 ).json( { ok: false, error: err.message } );
    }
} );

// Foto de perfil del bot (imagen en base64).
app.post( '/api/profile-picture', async ( req, res ) => {
    const { image_base64 } = req.body;
    if ( ! image_base64 ) {
        return res.status( 400 ).json( { ok: false, error: 'Campo requerido: image_base64' } );
    }
    if ( ! isConnected || ! sock ) {
        return res.status( 500 ).json( { ok: false, error: 'No conectado a WhatsApp' } );
    }
    try {
        const buffer = Buffer.from( image_base64, 'base64' );
        const jid = sock.user?.id ? sock.user.id.replace( /:[0-9]+/, '' ) : `${ linkedNumber }@s.whatsapp.net`;
        await sock.updateProfilePicture( jid, buffer );
        return res.json( { ok: true } );
    } catch ( err ) {
        console.error( '[CaagBridge] profile-picture error:', err.message );
        return res.status( 500 ).json( { ok: false, error: err.message } );
    }
} );

app.get( '/api/status', ( _req, res ) => {
    res.json( { connected: isConnected, number: linkedNumber, qr: qrBase64 } );
} );

app.post( '/api/restart', async ( _req, res ) => {
    res.json( { restarting: true } );
    if ( reconnectTimer ) { clearTimeout( reconnectTimer ); reconnectTimer = null; }
    reconnectAttempts = 0;
    teardownSocket(); // quita listeners: su 'close' ya no agenda otra reconexión
    isConnected  = false;
    linkedNumber = null;
    setTimeout( connectToWhatsApp, 1000 );
} );

app.post( '/api/logout', async ( _req, res ) => {
    try { if ( sock ) await sock.logout().catch( () => {} ); } catch {}
    if ( reconnectTimer ) { clearTimeout( reconnectTimer ); reconnectTimer = null; }
    reconnectAttempts = 0;
    teardownSocket();
    clearAuthState();
    isConnected  = false;
    linkedNumber = null;
    qrBase64     = null;
    res.json( { logged_out: true } );
    setTimeout( connectToWhatsApp, 2000 );
} );

// -----------------------------------------------------------------------
// Cloudflare Tunnel
// -----------------------------------------------------------------------

function startTunnel( port ) {
    const tunnelUrl = process.env.TUNNEL_URL || '';

    if ( tunnelUrl ) {
        // Tunnel nombrado (configurado con setup-tunnel.js)
        console.log( `[CaagBridge] Iniciando Cloudflare Tunnel nombrado → ${ tunnelUrl }` );
        // Comando como string para evitar DEP0190 con shell:true + array
        const cf = spawn( 'cloudflared tunnel run', {
            stdio: [ 'ignore', 'pipe', 'pipe' ],
            shell: true,
        } );
        cf.stdout.on( 'data', ( d ) => { const l = d.toString().trim(); if ( l ) console.log( '[CF]', l ); } );
        cf.stderr.on( 'data', ( d ) => { const l = d.toString().trim(); if ( l && ! l.includes( 'INF' ) ) console.error( '[CF]', l ); } );
        cf.on( 'close', ( code ) => {
            if ( code !== 0 ) {
                console.error( `[CaagBridge] Tunnel cerrado (código ${ code }). Reintentando en 10s...` );
                setTimeout( () => startTunnel( port ), 10000 );
            }
        } );
        return;
    }

    // Tunnel rápido (URL temporal — cambia al reiniciar)
    console.log( '[CaagBridge] Iniciando tunnel rápido (URL temporal)...' );

    // Comando como string para evitar DEP0190 con shell:true + array
    const cf = spawn( `cloudflared tunnel --url http://localhost:${ port }`, {
        stdio: [ 'ignore', 'pipe', 'pipe' ],
        shell: true,
    } );

    // cloudflared escribe la URL en stderr; la línea tiene el formato:
    // "... Your quick Tunnel has been created! Visit it at ... https://XXXX.trycloudflare.com"
    // También puede aparecer como: "https://XXXX.trycloudflare.com"
    // Regex: requiere al menos un guión en el subdominio para no capturar api.trycloudflare.com
    const urlRegex = /https:\/\/[a-z0-9](?:[a-z0-9-]*-)[a-z0-9]+\.trycloudflare\.com/;

    function checkLine( line ) {
        const match = line.match( urlRegex );
        if ( match ) {
            const url = match[ 0 ];
            console.log( `\n[CaagBridge] ✅ Tunnel activo: ${ url }` );
            console.log( '[CaagBridge] ⚠️  Esta URL cambia al reiniciar.' );
            console.log( '[CaagBridge]    Actualícela en: WordPress → CEAD Académico → WhatsApp → Configuración → URL del bridge\n' );
        }
    }

    cf.stdout.on( 'data', ( d ) => d.toString().split( '\n' ).forEach( checkLine ) );
    cf.stderr.on( 'data', ( d ) => d.toString().split( '\n' ).forEach( checkLine ) );

    cf.on( 'error', ( err ) => {
        console.error( '[CaagBridge] No se pudo iniciar cloudflared:', err.message );
        console.error( '[CaagBridge] Verifique que cloudflared esté instalado y en el PATH.' );
    } );

    cf.on( 'close', ( code ) => {
        if ( code !== 0 && code !== null ) {
            console.error( `[CaagBridge] Tunnel cerrado (código ${ code }). Reintentando en 15s...` );
            setTimeout( () => startTunnel( port ), 15000 );
        }
    } );
}

// -----------------------------------------------------------------------
// Inicio
// -----------------------------------------------------------------------

async function main() {
    const port = await getFreePort( PREFERRED_PORT );

    app.listen( port, () => {
        if ( PREFERRED_PORT && port !== PREFERRED_PORT ) {
            console.log( `[CaagBridge] Puerto ${ PREFERRED_PORT } ocupado → usando puerto ${ port }` );
        } else {
            console.log( `[CaagBridge] Bridge corriendo en http://localhost:${ port }` );
        }
    } );

    await connectToWhatsApp();
    startTunnel( port );
}

main().catch( ( err ) => {
    console.error( '[CaagBridge] Error fatal:', err );
    process.exit( 1 );
} );
