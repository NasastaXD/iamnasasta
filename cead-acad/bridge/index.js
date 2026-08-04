/**
 * Caaguazú Bot — Bridge WhatsApp
 * Conecta una cuenta de WhatsApp con el plugin WordPress vía Baileys.
 * Corre en la PC del admin y se expone con Cloudflare Tunnel.
 */

import 'dotenv/config';
import makeWASocket, {
    useMultiFileAuthState,
    makeCacheableSignalKeyStore,
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

// Interfaz donde escucha el bridge. En una VPS detrás de nginx conviene
// '127.0.0.1': el puerto no queda expuesto a internet y solo entra tráfico
// por el proxy (que además pone el HTTPS). Default '0.0.0.0' para no romper
// las instalaciones caseras que ya andan con Cloudflare Tunnel.
const HOST = process.env.HOST || '0.0.0.0';

// Con el puerto fijo (detrás de un proxy), que se corra solo a otro puerto
// deja al proxy apuntando a la nada. PORT_STRICT hace que falle a la vista.
const PORT_STRICT = /^(1|true|yes|on)$/i.test( process.env.PORT_STRICT || '' );

// En una VPS con dominio propio no hace falta cloudflared; sin esto intenta
// levantarlo y reintenta cada 15s para siempre, ensuciando el log.
const TUNNEL_ENABLED = ! /^(0|false|no|off)$/i.test( process.env.TUNNEL || '' );

// Tiempo que el bridge espera a que WordPress responda antes de cancelar la
// petición (la IA "cancela su mensaje"). En audios se extiende: la nota de voz
// se transcribe (puede tardar) y recién después responde la IA, así que es
// natural que tarde más. Ambos configurables por .env.
const WP_TIMEOUT_MS       = parseInt( process.env.WP_TIMEOUT_MS || '45000', 10 );
const WP_TIMEOUT_AUDIO_MS = parseInt( process.env.WP_TIMEOUT_AUDIO_MS || '120000', 10 );

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
let pairingCode       = null;  // código de 8 caracteres para vincular por número
let pairingPhone      = null;  // número al que se le pidió ese código
let waVersion         = null;  // versión del protocolo WA (se obtiene una sola vez)
let reconnectTimer    = null;  // reconexión pendiente (solo una a la vez)
let reconnectAttempts = 0;     // para el backoff exponencial

// Nivel de log de Baileys. Por defecto 'silent' (su salida en 'debug' es
// enorme), pero configurable: sin esto no hay forma de ver por qué falla un
// descifrado o un reenvío, y se termina adivinando. Para diagnosticar:
//   LOG_LEVEL=warn  → errores de sesión y reintentos
//   LOG_LEVEL=debug → todo (mucho volumen, solo para un rato)
const logger = pino( { level: process.env.LOG_LEVEL || 'silent' } );

// -----------------------------------------------------------------------
// Store de mensajes enviados (para los "retry receipts")
// -----------------------------------------------------------------------
// Cuando el celular del otro lado no puede descifrar un mensaje nuestro (algo
// que pasa cada tanto: se desincroniza la sesión de Signal), NO lo descarta:
// nos manda un "retry receipt" pidiendo que se lo reenviemos. Baileys resuelve
// ese pedido llamando a getMessage() para recuperar el contenido original y
// volver a cifrarlo.
//
// Sin getMessage, ese pedido no se puede responder nunca y el mensaje queda
// para siempre en «Esperando este mensaje» del lado del destinatario. Peor: la
// sesión nunca se recupera sola, así que a partir de la primera desincronización
// el bot deja de funcionar hasta revincular (y vuelve a romperse al rato).
//
// Se guarda acotado en memoria: es un buffer de reenvío, no un historial.
// Se indexa SOLO por id del mensaje, a propósito. WhatsApp migró al
// direccionamiento LID: el mismo contacto puede aparecer como
// «595...@s.whatsapp.net» al enviar y como «...@lid» en el retry receipt. Si
// la clave incluyera el JID, esos dos no coincidirían y el reenvío fallaría
// justamente cuando más se lo necesita. El id de mensaje ya es único.
const SENT_STORE_MAX = 500;
const sentMessages   = new Map(); // id → contenido del mensaje

function rememberSentMessage( id, message ) {
    if ( ! id || ! message ) return;
    sentMessages.set( id, message );
    // Map conserva el orden de inserción: la primera clave es la más vieja.
    while ( sentMessages.size > SENT_STORE_MAX ) {
        sentMessages.delete( sentMessages.keys().next().value );
    }
}

/** Guarda el resultado de un sendMessage y devuelve el mismo resultado. */
function trackSent( result ) {
    if ( result?.key?.id && result?.message ) {
        rememberSentMessage( result.key.id, result.message );
    }
    return result;
}

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
        auth: {
            creds: state.creds,
            // useMultiFileAuthState solo (sin esta caché) escribe cada clave de
            // sesión a un archivo por separado. Si un mensaje nuevo llega antes
            // de que esa escritura termine de sincronizarse a disco, se
            // descifra con una clave vieja → "Bad MAC" / "Failed to decrypt
            // message with any known session" a partir de ahí, sin forma de
            // recuperarse salvo re-vincular. La caché en memoria evita la
            // carrera (recomendada por los propios docs de Baileys).
            keys: makeCacheableSignalKeyStore( state.keys, logger ),
        },
        logger,
        browser: Browsers.ubuntu( 'Chrome' ),
        connectTimeoutMs:    60000,
        keepAliveIntervalMs: 25000,
        // Responde los "retry receipts": sin esto, un mensaje que el
        // destinatario no pudo descifrar queda para siempre en «Esperando este
        // mensaje» y la sesión no se recupera nunca (ver el store más arriba).
        getMessage: async ( key ) => {
            const found = sentMessages.get( key.id );
            // Se loguea siempre (no depende de LOG_LEVEL): es la única forma de
            // saber si los reenvíos se están resolviendo o quedando sin respuesta.
            blog( found
                ? `↻ Reenviando mensaje ${ key.id } (el destinatario no pudo descifrarlo).`
                : `⚠️ Me piden reenviar el mensaje ${ key.id } pero ya no lo tengo en memoria.` );
            return found || undefined;
        },
    } );

    sock.ev.on( 'creds.update', saveCreds );

    // Vinculación por número: alternativa al QR para cuando el QR no funciona
    // (cámara, pantalla chica, o WhatsApp que no lo toma). Solo tiene sentido
    // sobre una sesión nueva: si ya hay credenciales, no hay nada que vincular.
    // El código vence a los pocos minutos; se pide de nuevo y listo.
    if ( pairingPhone && ! state.creds.registered ) {
        setTimeout( async () => {
            try {
                const code  = await sock.requestPairingCode( pairingPhone );
                const clean = String( code || '' ).replace( /[^A-Z0-9]/gi, '' );
                // WhatsApp lo muestra en dos bloques de 4.
                pairingCode = clean.length === 8 ? `${ clean.slice( 0, 4 ) }-${ clean.slice( 4 ) }` : clean;
                blog( `🔗 Código de vinculación para ${ pairingPhone }: ${ pairingCode }` );
                console.log( '  WhatsApp → ⋮ → Dispositivos vinculados → Vincular con número de teléfono\n' );
            } catch ( err ) {
                blog( `No se pudo pedir el código de vinculación: ${ err.message }` );
                pairingCode  = null;
                pairingPhone = null;
            }
        }, 4000 );
    }

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
            pairingCode       = null; // ya vinculado: el código no sirve más
            pairingPhone      = null;
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
            // Planilla (Excel/CSV) que manda un docente para cargar notas.
            const docMsg   = msg.message?.documentMessage
                          || msg.message?.documentWithCaptionMessage?.message?.documentMessage;
            const body = msg.message?.conversation
                      || msg.message?.extendedTextMessage?.text
                      || imageMsg?.caption
                      || docMsg?.caption
                      || msg.message?.documentWithCaptionMessage?.message?.documentMessage?.caption
                      || '';

            let media = imageMsg ? await downloadImage( msg, imageMsg ) : null;
            if ( ! media && audioMsg ) {
                media = await downloadAudio( msg, audioMsg );
            }
            if ( ! media && docMsg ) {
                media = await downloadDocument( msg, docMsg );
            }
            // En audio damos más tiempo: transcripción + IA tardan más que un texto.
            const isAudio = !! ( media && typeof media.mime === 'string' && media.mime.startsWith( 'audio' ) );

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
                    }, { timeout: isAudio ? WP_TIMEOUT_AUDIO_MS : WP_TIMEOUT_MS } );
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
// `timeout` es cuánto espera cada intento (más largo en audios).
async function postToWordPress( payload, { timeout = WP_TIMEOUT_MS, attempts = 3 } = {} ) {
    for ( let attempt = 1; attempt <= attempts; attempt++ ) {
        try {
            const resp = await axios.post( WP_WEBHOOK, payload, {
                headers: { 'X-Caag-Token': SHARED_TOKEN },
                timeout,
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

// Descarga una planilla entrante (Excel/CSV) para que WordPress la interprete
// y cargue las notas. Se pasa en base64 igual que las imágenes; WordPress la
// procesa y la descarta, no la archiva.
//
// El .xls viejo (binario, Excel 97-2003) queda fuera a propósito: el lector de
// WordPress trabaja sobre el formato xlsx (zip + XML) y no puede abrirlo.
async function downloadDocument( msg, docMsg ) {
    const allowed = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'application/vnd.ms-excel',                                         // .xls y algunos .csv mal etiquetados
        'text/csv',
        'text/plain',
        'text/comma-separated-values',
        'application/csv',
    ];
    const baseMime = ( docMsg.mimetype || '' ).split( ';' )[ 0 ].trim().toLowerCase();
    const name     = ( docMsg.fileName || '' ).toLowerCase();
    const extOk    = name.endsWith( '.xlsx' ) || name.endsWith( '.csv' );

    // Vale por extensión o por mime: WhatsApp etiqueta los adjuntos de forma
    // muy despareja según el celular que los mande.
    if ( ! extOk && ! allowed.includes( baseMime ) ) {
        console.warn( `[CaagBridge] Documento ignorado (${ baseMime || 'sin mime' } · ${ name || 'sin nombre' }).` );
        return null;
    }
    if ( name.endsWith( '.xls' ) ) {
        console.warn( '[CaagBridge] .xls antiguo: hay que guardarlo como .xlsx.' );
        return { unsupported: 'xls', filename: docMsg.fileName || 'planilla.xls' };
    }

    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            { logger, reuploadRequest: sock.updateMediaMessage }
        );

        if ( ! buffer || buffer.length > 8 * 1024 * 1024 ) {
            console.warn( '[CaagBridge] Planilla omitida (vacía o mayor a 8 MB).' );
            return null;
        }

        return {
            mime:        baseMime || ( name.endsWith( '.csv' ) ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
            data_base64: buffer.toString( 'base64' ),
            filename:    docMsg.fileName || `planilla-${ Date.now() }.xlsx`,
        };
    } catch ( err ) {
        console.error( '[CaagBridge] Error descargando planilla:', err.message );
        return null;
    }
}

// -----------------------------------------------------------------------
// Servidor Express
// -----------------------------------------------------------------------

const app = express();

// Las imágenes de comunicados/artículos viajan en base64 dentro del JSON, y
// base64 infla ~33%: con el límite por defecto de Express (100 kB) cualquier
// foto de celular se rechazaba con 413 antes de llegar al handler.
app.use( express.json( { limit: process.env.MAX_BODY_SIZE || '25mb' } ) );

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
        const result = trackSent( await sock.sendMessage( `${ to }@s.whatsapp.net`, { text: message } ) );
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
        const result = trackSent( await sock.sendMessage(
            `${ to }@s.whatsapp.net`,
            { image: buffer, caption: caption || '' }
        ) );
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
        const result = trackSent( await sock.sendMessage( jid, {
            text: message,
            edit: { remoteJid: jid, fromMe: true, id: msg_id },
        } ) );
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
    res.json( {
        connected:     isConnected,
        number:        linkedNumber,
        qr:            qrBase64,
        pairing_code:  pairingCode,
        pairing_phone: pairingPhone,
    } );
} );

// Vincular por número de teléfono en vez de escanear el QR. WhatsApp devuelve
// un código de 8 caracteres que se ingresa en el celular:
// WhatsApp → ⋮ → Dispositivos vinculados → Vincular con número de teléfono.
app.post( '/api/pair', async ( req, res ) => {
    const phone = String( req.body?.phone || '' ).replace( /\D/g, '' );

    if ( phone.length < 8 || phone.length > 15 ) {
        return res.status( 400 ).json( { error: 'Número inválido. Usá el formato internacional sin +, por ejemplo 595981123456.' } );
    }
    if ( isConnected ) {
        return res.status( 409 ).json( { error: 'Ya hay un número vinculado. Cerrá sesión antes de vincular otro.' } );
    }

    blog( `Pidiendo código de vinculación para ${ phone }…` );
    pairingCode  = null;
    pairingPhone = phone;

    // El código solo se puede pedir sobre credenciales nuevas: si quedó una
    // sesión a medias, hay que limpiarla antes.
    if ( reconnectTimer ) { clearTimeout( reconnectTimer ); reconnectTimer = null; }
    reconnectAttempts = 0;
    teardownSocket();
    clearAuthState();
    connectToWhatsApp();

    // Le damos un rato a WhatsApp; si tarda más, el panel lo verá en /api/status.
    const deadline = Date.now() + 20000;
    while ( ! pairingCode && Date.now() < deadline ) {
        await new Promise( ( r ) => setTimeout( r, 500 ) );
    }

    res.json( {
        ok:           true,
        phone,
        pairing_code: pairingCode,
        pending:      ! pairingCode,
    } );
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
    pairingCode  = null;
    pairingPhone = null;
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
    const port = PORT_STRICT ? PREFERRED_PORT : await getFreePort( PREFERRED_PORT );

    if ( PORT_STRICT && ! port ) {
        console.error( '[CaagBridge] PORT_STRICT está activo pero no hay PORT definido en .env.' );
        process.exit( 1 );
    }

    const server = app.listen( port, HOST, () => {
        if ( ! PORT_STRICT && PREFERRED_PORT && port !== PREFERRED_PORT ) {
            console.log( `[CaagBridge] Puerto ${ PREFERRED_PORT } ocupado → usando puerto ${ port }` );
        } else {
            console.log( `[CaagBridge] Bridge corriendo en http://${ HOST }:${ port }` );
        }
    } );

    // Con puerto fijo, si está ocupado hay que decirlo y cortar: seguir a medias
    // deja a WordPress hablándole a un puerto que no atiende nadie.
    server.on( 'error', ( err ) => {
        if ( err.code === 'EADDRINUSE' ) {
            console.error( `[CaagBridge] El puerto ${ port } ya está en uso. ¿Hay otro bridge corriendo? (systemctl status cead-bridge)` );
        } else {
            console.error( '[CaagBridge] No se pudo abrir el puerto:', err.message );
        }
        process.exit( 1 );
    } );

    await connectToWhatsApp();

    if ( TUNNEL_ENABLED ) {
        startTunnel( port );
    } else {
        console.log( '[CaagBridge] Tunnel desactivado (TUNNEL=off): se asume dominio propio o proxy inverso.' );
    }
}

main().catch( ( err ) => {
    console.error( '[CaagBridge] Error fatal:', err );
    process.exit( 1 );
} );
