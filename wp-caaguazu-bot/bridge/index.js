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
} from '@whiskeysockets/baileys';
import express         from 'express';
import axios           from 'axios';
import qrcode          from 'qrcode';
import qrcodeTerminal  from 'qrcode-terminal';
import pino            from 'pino';
import { spawn }       from 'child_process';
import { createServer } from 'net';
import { rmSync, existsSync } from 'fs';

// -----------------------------------------------------------------------
// Puerto: usa PORT del .env si está definido; si no, elige uno libre al azar
// -----------------------------------------------------------------------

async function getFreePort( preferred ) {
    return new Promise( ( resolve, reject ) => {
        const server = createServer();
        server.listen( preferred || 0, () => {
            const port = server.address().port;
            server.close( () => resolve( port ) );
        } );
        server.on( 'error', () => {
            // El puerto preferido está ocupado → pedir uno aleatorio al SO
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
// Configuración
// -----------------------------------------------------------------------

const PREFERRED_PORT = process.env.PORT ? parseInt( process.env.PORT, 10 ) : null;
const SHARED_TOKEN   = process.env.SHARED_TOKEN  || '';
const WP_WEBHOOK     = process.env.WP_WEBHOOK_URL || '';
const TYPING_DELAY   = parseInt( process.env.TYPING_DELAY_MS || '2000', 10 );
const AUTH_DIR       = './auth_state';

if ( ! SHARED_TOKEN ) {
    console.error( '[CaagBridge] ERROR: SHARED_TOKEN no configurado en .env' );
    process.exit( 1 );
}
if ( ! WP_WEBHOOK ) {
    console.error( '[CaagBridge] ERROR: WP_WEBHOOK_URL no configurado en .env' );
    process.exit( 1 );
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
    const { state, saveCreds } = await useMultiFileAuthState( AUTH_DIR );

    sock = makeWASocket( {
        auth:    state,
        logger,
        browser: Browsers.macOS( 'Chrome' ),
        // printQRInTerminal eliminado: las versiones nuevas de Baileys
        // ya no lo soportan y lanzan advertencia; imprimimos el QR manualmente.
    } );

    sock.ev.on( 'creds.update', saveCreds );

    sock.ev.on( 'connection.update', async ( update ) => {
        const { connection, lastDisconnect, qr } = update;

        if ( qr ) {
            isConnected = false;
            qrBase64    = await qrcode.toDataURL( qr );

            // Imprimir QR en la terminal manualmente
            console.log( '\n[CaagBridge] Escanee el QR con WhatsApp:' );
            console.log( '  (WhatsApp → ⋮ → Dispositivos vinculados → Vincular dispositivo)\n' );
            qrcodeTerminal.generate( qr, { small: true } );
        }

        if ( connection === 'open' ) {
            isConnected  = true;
            qrBase64     = null;
            linkedNumber = ( sock.user?.id || '' ).replace( /@.+$/, '' );
            console.log( `\n[CaagBridge] ✅ Conectado como ${ linkedNumber }` );
        }

        if ( connection === 'close' ) {
            isConnected  = false;
            linkedNumber = null;
            const reason = lastDisconnect?.error?.output?.statusCode;
            console.log( `[CaagBridge] Desconectado (reason: ${ reason })` );

            if ( reason === DisconnectReason.loggedOut ) {
                console.log( '[CaagBridge] Sesión cerrada. Borrando auth_state y reconectando...' );
                clearAuthState();
                await connectToWhatsApp();
            } else {
                setTimeout( connectToWhatsApp, 5000 );
            }
        }
    } );

    sock.ev.on( 'messages.upsert', async ( { messages } ) => {
        for ( const msg of messages ) {
            if ( msg.key.fromMe )                          continue;
            if ( msg.key.remoteJid?.endsWith( '@g.us' ) ) continue;

            const from = ( msg.key.remoteJid || '' ).replace( /@s\.whatsapp\.net$/, '' );
            const body = msg.message?.conversation
                      || msg.message?.extendedTextMessage?.text
                      || '';

            if ( ! body.trim() ) continue;

            // Marcar como leído
            await sock.readMessages( [ msg.key ] ).catch( () => {} );

            // Indicador "escribiendo..."
            await sock.sendPresenceUpdate( 'composing', msg.key.remoteJid ).catch( () => {} );

            // Enviar al webhook de WordPress
            try {
                await axios.post(
                    WP_WEBHOOK,
                    {
                        from:      from,
                        body:      body,
                        pushName:  msg.pushName || '',
                        timestamp: msg.messageTimestamp || Math.floor( Date.now() / 1000 ),
                    },
                    {
                        headers: { 'X-Caag-Token': SHARED_TOKEN },
                        timeout: 30000,
                    }
                );
            } catch ( err ) {
                console.error( '[CaagBridge] Error enviando a WordPress:', err.message );
            }

            // Detener indicador "escribiendo..."
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

// -----------------------------------------------------------------------
// Servidor Express
// -----------------------------------------------------------------------

const app = express();
app.use( express.json() );

// Middleware de autenticación
app.use( ( req, res, next ) => {
    const token = req.headers[ 'x-caag-token' ];
    if ( ! token || token !== SHARED_TOKEN ) {
        return res.status( 401 ).json( { error: 'Unauthorized' } );
    }
    next();
} );

// Enviar mensaje
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

// Estado
app.get( '/api/status', ( _req, res ) => {
    res.json( {
        connected: isConnected,
        number:    linkedNumber,
        qr:        qrBase64,
    } );
} );

// Reiniciar conexión
app.post( '/api/restart', async ( _req, res ) => {
    res.json( { restarting: true } );
    if ( sock ) sock.end( undefined );
    isConnected  = false;
    linkedNumber = null;
    setTimeout( connectToWhatsApp, 1000 );
} );

// Cerrar sesión
app.post( '/api/logout', async ( _req, res ) => {
    try {
        if ( sock ) await sock.logout().catch( () => {} );
    } catch {}

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
        console.log( `[CaagBridge] Iniciando Cloudflare Tunnel nombrado → ${ tunnelUrl }` );
        const cf = spawn( 'cloudflared', [ 'tunnel', 'run' ], {
            stdio: [ 'ignore', 'pipe', 'pipe' ],
            shell: true,
        } );

        cf.stdout.on( 'data', ( d ) => {
            const line = d.toString().trim();
            if ( line ) console.log( '[Cloudflare]', line );
        } );
        cf.stderr.on( 'data', ( d ) => {
            const line = d.toString().trim();
            if ( line && ! line.includes( 'INF' ) ) console.error( '[Cloudflare]', line );
        } );
        cf.on( 'close', ( code ) => {
            if ( code !== 0 ) {
                console.error( `[CaagBridge] Tunnel terminó (código ${ code }). Reintentando en 10s...` );
                setTimeout( () => startTunnel( port ), 10000 );
            }
        } );

        console.log( `[CaagBridge] URL del bot: ${ tunnelUrl }` );
        return;
    }

    // Sin TUNNEL_URL: tunnel rápido con URL temporal
    console.log( '[CaagBridge] TUNNEL_URL no configurado → tunnel rápido (URL temporal).' );
    console.log( '[CaagBridge] Para URL estable ejecute: node setup-tunnel.js\n' );

    const cf = spawn( 'npx', [ 'cloudflared', 'tunnel', '--url', `http://localhost:${ port }` ], {
        stdio: [ 'ignore', 'pipe', 'pipe' ],
        shell: true,
    } );

    cf.stderr.on( 'data', ( d ) => {
        const match = d.toString().match( /https:\/\/[a-z0-9-]+\.trycloudflare\.com/ );
        if ( match ) {
            console.log( `[CaagBridge] ✅ Tunnel activo: ${ match[ 0 ] }` );
            console.log( '[CaagBridge] ⚠️  Esta URL cambia al reiniciar — actualícela en WordPress → Caaguazú Bot → Configuración\n' );
        }
    } );

    cf.on( 'close', ( code ) => {
        if ( code !== 0 ) {
            console.error( `[CaagBridge] Tunnel terminó (código ${ code }). Reintentando en 10s...` );
            setTimeout( () => startTunnel( port ), 10000 );
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
    console.error( '[CaagBridge] Error fatal al iniciar:', err );
    process.exit( 1 );
} );
