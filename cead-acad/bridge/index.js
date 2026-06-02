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
// Configuración
// -----------------------------------------------------------------------

const PREFERRED_PORT = process.env.PORT ? parseInt( process.env.PORT, 10 ) : null;
const SHARED_TOKEN   = process.env.SHARED_TOKEN;
const WP_WEBHOOK     = process.env.WP_WEBHOOK_URL || '';
const TYPING_DELAY   = parseInt( process.env.TYPING_DELAY_MS || '2000', 10 );
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

let sock         = null;
let qrBase64     = null;
let isConnected  = false;
let linkedNumber = null;

const logger = pino( { level: 'silent' } );

// -----------------------------------------------------------------------
// Conexión a WhatsApp
// -----------------------------------------------------------------------

async function connectToWhatsApp() {
    // Obtener la versión actual del protocolo WA antes de conectar
    // Sin esto, WhatsApp puede rechazar la conexión con error 405
    let waVersion;
    try {
        const { version, isLatest } = await fetchLatestBaileysVersion();
        waVersion = version;
        console.log( `[CaagBridge] Protocolo WA: ${ version.join( '.' ) } (última disponible: ${ isLatest })` );
    } catch {
        waVersion = [ 2, 3000, 1015901307 ]; // fallback conocido
        console.log( '[CaagBridge] No se pudo obtener versión WA, usando fallback.' );
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
            isConnected  = true;
            qrBase64     = null;
            linkedNumber = ( sock.user?.id || '' ).replace( /@.+$/, '' );
            console.log( `\n[CaagBridge] ✅ Conectado como ${ linkedNumber }\n` );
        }

        if ( connection === 'close' ) {
            isConnected  = false;
            linkedNumber = null;
            const reason = lastDisconnect?.error?.output?.statusCode;
            console.log( `[CaagBridge] Desconectado (reason: ${ reason })` );

            const shouldClearSession =
                reason === DisconnectReason.loggedOut ||   // 401 — logout explícito
                reason === 405 ||                          // connectionReplaced — sesión inválida/reemplazada
                reason === 500;                            // badSession — credenciales corruptas

            if ( shouldClearSession ) {
                console.log( '[CaagBridge] Sesión inválida — borrando auth_state y pidiendo QR nuevo...' );
                clearAuthState();
                setTimeout( connectToWhatsApp, 2000 );
            } else {
                setTimeout( connectToWhatsApp, 5000 );
            }
        }
    } );

    sock.ev.on( 'messages.upsert', async ( { messages } ) => {
        for ( const msg of messages ) {
            if ( msg.key.fromMe )                          continue;
            if ( msg.key.remoteJid?.endsWith( '@g.us' ) ) continue;

            const from     = ( msg.key.remoteJid || '' ).replace( /@s\.whatsapp\.net$/, '' );
            const imageMsg = msg.message?.imageMessage;
            const body = msg.message?.conversation
                      || msg.message?.extendedTextMessage?.text
                      || imageMsg?.caption
                      || '';

            const media = imageMsg ? await downloadImage( msg, imageMsg ) : null;

            if ( ! body.trim() && ! media ) continue;

            await sock.readMessages( [ msg.key ] ).catch( () => {} );
            await sock.sendPresenceUpdate( 'composing', msg.key.remoteJid ).catch( () => {} );

            if ( WP_WEBHOOK ) {
                try {
                    await axios.post(
                        WP_WEBHOOK,
                        {
                            from:      from,
                            body:      body,
                            pushName:  msg.pushName || '',
                            timestamp: msg.messageTimestamp || Math.floor( Date.now() / 1000 ),
                            media:     media,
                        },
                        {
                            headers: { 'X-Caag-Token': SHARED_TOKEN },
                            timeout: 30000,
                            maxBodyLength: Infinity,
                        }
                    );
                } catch ( err ) {
                    console.error( '[CaagBridge] Error enviando a WordPress:', err.message );
                }
            }

            setTimeout( () => {
                sock.sendPresenceUpdate( 'paused', msg.key.remoteJid ).catch( () => {} );
            }, TYPING_DELAY );
        }
    } );
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

app.get( '/api/status', ( _req, res ) => {
    res.json( { connected: isConnected, number: linkedNumber, qr: qrBase64 } );
} );

app.post( '/api/restart', async ( _req, res ) => {
    res.json( { restarting: true } );
    if ( sock ) sock.end( undefined );
    isConnected  = false;
    linkedNumber = null;
    setTimeout( connectToWhatsApp, 1000 );
} );

app.post( '/api/logout', async ( _req, res ) => {
    try { if ( sock ) await sock.logout().catch( () => {} ); } catch {}
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
