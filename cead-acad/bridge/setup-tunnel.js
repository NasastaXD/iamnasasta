/**
 * Caaguazú Bot — Configuración del Tunnel Cloudflare (ejecución única)
 *
 * Ejecute: node setup-tunnel.js
 *
 * Requisitos previos:
 *   1. Tener cloudflared instalado (ver instrucciones abajo)
 *   2. Tener su dominio apuntando a los nameservers de Cloudflare
 *   3. Tener el archivo .env configurado con al menos SHARED_TOKEN y WP_WEBHOOK_URL
 */

import { execSync, spawn }  from 'child_process';
import { existsSync, writeFileSync, readFileSync, mkdirSync } from 'fs';
import { homedir }          from 'os';
import { join }             from 'path';
import readline             from 'readline';

// Verificar que npm install fue ejecutado (import dinámico para que el check corra primero)
if ( ! existsSync( './node_modules' ) ) {
    console.error( '\n❌  Faltan las dependencias. Ejecute primero:\n' );
    console.error( '      npm install\n' );
    console.error( '   Luego vuelva a correr:  node setup-tunnel.js\n' );
    process.exit( 1 );
}

// Import dinámico: se ejecuta DESPUÉS del check de arriba
await import( 'dotenv/config' );

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
const ask = (q) => new Promise((res) => rl.question(q, res));

function run(cmd, opts = {}) {
    return execSync(cmd, { stdio: opts.silent ? 'pipe' : 'inherit', encoding: 'utf8', shell: true });
}

function tryRun(cmd) {
    try { return run(cmd, { silent: true }); } catch { return null; }
}

function banner(text) {
    console.log('\n' + '='.repeat(60));
    console.log('  ' + text);
    console.log('='.repeat(60) + '\n');
}

// -----------------------------------------------------------------------
// Verificar cloudflared instalado
// -----------------------------------------------------------------------

function checkCloudflared() {
    // shell:true resuelve el PATH correctamente en Windows
    const version = tryRun('cloudflared --version')
                 || tryRun('cloudflared.exe --version');

    if (!version) {
        // Intentar localizar el ejecutable para dar un diagnóstico más útil
        const where = tryRun('where cloudflared') || tryRun('which cloudflared');
        if (where) {
            console.error('⚠️  cloudflared está en:', where.trim());
            console.error('   pero no se pudo ejecutar. Intente abriendo una nueva terminal y corriendo:');
            console.error('   cloudflared --version\n');
        } else {
            console.error('❌  cloudflared no está instalado o no está en el PATH.\n');
            console.log('Instrucciones de instalación:');
            console.log('  Windows : https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/');
            console.log('  Mac     : brew install cloudflare/cloudflare/cloudflared');
            console.log('  Linux   : https://pkg.cloudflare.com/\n');
            console.log('Si ya lo instaló, cierre y vuelva a abrir la terminal para recargar el PATH.');
        }
        process.exit(1);
    }
    console.log('✅  cloudflared instalado:', version.trim());
}

// -----------------------------------------------------------------------
// Autenticar con Cloudflare (abre el navegador)
// -----------------------------------------------------------------------

function authenticate() {
    const certPath = join(homedir(), '.cloudflared', 'cert.pem');
    if (existsSync(certPath)) {
        console.log('✅  Ya autenticado con Cloudflare (cert.pem encontrado).');
        return;
    }

    console.log('\n🌐  Abriendo el navegador para autenticar con Cloudflare...');
    console.log('    Inicie sesión y autorice el dominio que usará para el bot.\n');
    run('cloudflared tunnel login');
}

// -----------------------------------------------------------------------
// Crear el tunnel
// -----------------------------------------------------------------------

function createTunnel(name) {
    // Verificar si ya existe
    const list = tryRun('cloudflared tunnel list -o json') || '[]';
    let tunnels = [];
    try {
        const parsed = JSON.parse(list);
        tunnels = Array.isArray(parsed) ? parsed : [];
    } catch {}

    const existing = tunnels.find(t => t.name === name);
    if (existing) {
        console.log(`✅  Tunnel "${name}" ya existe (ID: ${existing.id})`);
        return existing.id;
    }

    console.log(`\n🔧  Creando tunnel "${name}"...`);
    run(`cloudflared tunnel create ${name}`, { silent: false });

    // Obtener el ID del tunnel recién creado
    const list2 = tryRun('cloudflared tunnel list -o json') || '[]';
    let tunnels2 = [];
    try {
        const parsed2 = JSON.parse(list2);
        tunnels2 = Array.isArray(parsed2) ? parsed2 : [];
    } catch {}

    // cloudflared create también imprime el ID en su output; buscarlo como fallback
    const created = tunnels2.find(t => t.name === name);
    if (!created) {
        console.error('❌  No se pudo obtener el ID del tunnel creado.');
        console.error('    Intente correr manualmente: cloudflared tunnel list');
        process.exit(1);
    }
    console.log(`✅  Tunnel creado con ID: ${created.id}`);
    return created.id;
}

// -----------------------------------------------------------------------
// Configurar DNS
// -----------------------------------------------------------------------

function setupDns(tunnelName, subdomain) {
    console.log(`\n🌐  Configurando registro DNS ${subdomain} → tunnel...`);
    try {
        run(`cloudflared tunnel route dns ${tunnelName} ${subdomain}`);
        console.log(`✅  DNS configurado: https://${subdomain}`);
    } catch (e) {
        // Si el registro ya existe, cloudflared lanza error pero está bien
        console.log(`ℹ️   El registro DNS puede ya existir. Continuando...`);
    }
}

// -----------------------------------------------------------------------
// Generar config.yml para el tunnel nombrado
// -----------------------------------------------------------------------

function generateConfig(tunnelId, subdomain, port) {
    const cfDir    = join(homedir(), '.cloudflared');
    const cfgPath  = join(cfDir, 'config.yml');
    const credPath = join(cfDir, `${tunnelId}.json`);

    mkdirSync(cfDir, { recursive: true });

    const config = `# Caaguazú Bot — Cloudflare Tunnel config
# Generado por setup-tunnel.js

tunnel: ${tunnelId}
credentials-file: ${credPath}

ingress:
  - hostname: ${subdomain}
    service: http://localhost:${port}
  - service: http_status:404
`;

    writeFileSync(cfgPath, config, 'utf8');
    console.log(`✅  config.yml generado en ${cfgPath}`);
    return cfgPath;
}

// -----------------------------------------------------------------------
// Actualizar .env con la URL del tunnel
// -----------------------------------------------------------------------

function updateEnv(tunnelUrl) {
    const envPath = './.env';
    if (!existsSync(envPath)) {
        console.log('⚠️   No se encontró .env — cree el archivo a partir de .env.example.');
        return;
    }

    let content = readFileSync(envPath, 'utf8');

    if (content.includes('TUNNEL_URL=')) {
        content = content.replace(/^TUNNEL_URL=.*/m, `TUNNEL_URL=${tunnelUrl}`);
    } else {
        content += `\n# URL estable del Cloudflare Tunnel (generada por setup-tunnel.js)\nTUNNEL_URL=${tunnelUrl}\n`;
    }

    writeFileSync(envPath, content, 'utf8');
    console.log(`✅  TUNNEL_URL=${tunnelUrl} guardado en .env`);
}

// -----------------------------------------------------------------------
// Actualizar WordPress con la nueva URL
// -----------------------------------------------------------------------

async function updateWordPress(tunnelUrl) {
    const wpWebhook = process.env.WP_WEBHOOK_URL || '';
    const token     = process.env.SHARED_TOKEN    || '';

    if (!wpWebhook || !token) {
        console.log('⚠️   WP_WEBHOOK_URL o SHARED_TOKEN no están en .env — actualice la URL en WordPress manualmente.');
        return;
    }

    // Derivar la base URL de WordPress desde el webhook
    const wpBase = wpWebhook.replace('/wp-json/caag-bot/v1/incoming', '');
    const updateUrl = `${wpBase}/wp-json/caag-bot/v1/update-bridge-url`;

    console.log(`\n🔄  Actualizando URL del bridge en WordPress (${wpBase})...`);

    try {
        const { default: axios } = await import('axios');
        await axios.post(
            updateUrl,
            { bridge_url: tunnelUrl },
            { headers: { 'X-Caag-Token': token }, timeout: 10000 }
        );
        console.log('✅  URL actualizada en WordPress automáticamente.');
    } catch {
        console.log(`ℹ️   No se pudo actualizar WordPress automáticamente.`);
        console.log(`    Vaya a: WordPress → CEAD Académico → WhatsApp → Configuración`);
        console.log(`    y pegue esta URL: ${tunnelUrl}`);
    }
}

// -----------------------------------------------------------------------
// Guardar instrucciones de inicio
// -----------------------------------------------------------------------

function writeStartScript(tunnelName) {
    // Windows
    writeFileSync('./start.bat', `@echo off
echo Iniciando Caaguazu Bot Bridge...
start "Caaguazu Bot" cmd /k "node index.js"
`, 'utf8');

    // Unix
    writeFileSync('./start.sh', `#!/bin/bash
# Inicia el bridge del bot (que también arranca el tunnel)
node index.js
`, { mode: 0o755 });

    console.log('\n✅  Scripts de inicio generados: start.bat (Windows) y start.sh (Mac/Linux)');
}

// -----------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------

async function main() {
    banner('Configuración del Tunnel Cloudflare — Caaguazú Bot');

    console.log('Este script configura un tunnel Cloudflare con URL estable.');
    console.log('Solo necesita ejecutarlo UNA VEZ.\n');
    console.log('Requisito: su dominio debe estar usando los nameservers de Cloudflare.');
    console.log('Ver: https://developers.cloudflare.com/dns/zone-setups/full-setup/\n');

    const proceed = await ask('¿Continuar? (s/n): ');
    if (proceed.toLowerCase() !== 's') { rl.close(); process.exit(0); }

    // 1. Verificar cloudflared
    checkCloudflared();

    // 2. Datos del tunnel
    const tunnelName = (await ask('\nNombre del tunnel [caaguazu-bot]: ')).trim() || 'caaguazu-bot';
    const subdomain  = (await ask('Subdominio completo para el bot (ej: bot.caaguazu.net): ')).trim();
    const port       = parseInt(process.env.PORT || '3000', 10);

    if (!subdomain || !subdomain.includes('.')) {
        console.error('❌  Subdominio inválido. Ejemplo correcto: bot.caaguazu.net');
        rl.close();
        process.exit(1);
    }

    const tunnelUrl = `https://${subdomain}`;

    // 3. Autenticar
    authenticate();

    // 4. Crear tunnel
    const tunnelId = createTunnel(tunnelName);

    // 5. Configurar DNS
    setupDns(tunnelName, subdomain);

    // 6. Generar config.yml
    generateConfig(tunnelId, subdomain, port);

    // 7. Actualizar .env
    updateEnv(tunnelUrl);

    // 8. Intentar actualizar WordPress
    await updateWordPress(tunnelUrl);

    // 9. Scripts de inicio
    writeStartScript(tunnelName);

    rl.close();

    banner('✅  Configuración completa');
    console.log(`URL estable del bot: ${tunnelUrl}`);
    console.log('\nPróximos pasos:');
    console.log('  1. Si WordPress no se actualizó solo, pegue la URL arriba en:');
    console.log('     CEAD Académico → WhatsApp → Configuración → URL del bridge');
    console.log('  2. Para iniciar el bot, ejecute: node index.js');
    console.log('     (el tunnel arranca automáticamente junto con el bridge)\n');
}

main().catch((err) => {
    console.error('Error inesperado:', err.message);
    rl.close();
    process.exit(1);
});
