<?php
/**
 * PWA: manifest, service worker e íconos generados al vuelo (GD).
 * Permite "Agregar a la pantalla de inicio" e instalar el panel como app.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_PWA {

	const CACHE = 'cead-pwa-v4';

	/** Sirve el manifest web. */
	public static function manifest() {
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=UTF-8' );
		$data = [
			'name'             => get_bloginfo( 'name' ) . ' · CEAD',
			'short_name'       => 'CEAD',
			'description'      => __( 'Panel del CEAD: comunicados, calendario, recursos y más.', 'cead-acad' ),
			'start_url'        => home_url( '/panel' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#F7F5F0',
			'theme_color'      => '#E93B3C',
			'lang'             => 'es',
			'icons'            => [
				[ 'src' => home_url( '/cead-icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ],
				[ 'src' => home_url( '/cead-icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ],
				[ 'src' => home_url( '/cead-icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ],
			],
		];
		echo wp_json_encode( $data );
		exit;
	}

	/** Sirve el service worker (scope raíz). */
	public static function service_worker() {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=UTF-8' );
		header( 'Service-Worker-Allowed: /' );
		$origin = home_url();
		?>
const CACHE   = '<?php echo esc_js( self::CACHE ); ?>';
const OFFLINE = '<?php echo esc_js( home_url( '/cead-offline' ) ); ?>';

self.addEventListener('install', function (e) {
	e.waitUntil(
		caches.open(CACHE)
			.then(function (c) { return c.add(OFFLINE); })
			.catch(function () {})
			.then(function () { return self.skipWaiting(); })
	);
});
self.addEventListener('activate', function (e) {
	// Purga caches de versiones anteriores.
	e.waitUntil(
		caches.keys().then(function (keys) {
			return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
		}).then(function () { return self.clients.claim(); })
	);
});

function putInCache(req, resp) {
	if (resp && resp.status === 200 && resp.type === 'basic') {
		var copy = resp.clone();
		caches.open(CACHE).then(function (c) { c.put(req, copy); });
	}
	return resp;
}

// Estrategia:
//  - Assets estáticos (css/js/fuentes/imágenes): cache-first con revalidación
//    en segundo plano (stale-while-revalidate) → carga instantánea y offline.
//    Solo se cachean assets públicos, nunca HTML.
//  - Navegación (HTML): network-only con fallback a la página offline. NO se
//    cachean páginas porque el panel es privado y cachearlas filtraría datos
//    de un usuario a otro en dispositivos compartidos (offline).
//  - Resto de GET (p.ej. descargas, fetch): network, sin cachear.
//  - Nunca se intercepta wp-admin, login, admin-ajax ni la REST API.
self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET') { return; }
	var url;
	try { url = new URL(req.url); } catch (err) { return; }
	if (url.origin !== self.location.origin) { return; }
	if (url.pathname.indexOf('/wp-admin') === 0 || url.pathname.indexOf('/wp-login') !== -1 || url.pathname.indexOf('admin-ajax') !== -1 || url.pathname.indexOf('/wp-json/') === 0) { return; }

	var dest = req.destination;
	if (dest === 'style' || dest === 'script' || dest === 'font' || dest === 'image') {
		e.respondWith(
			caches.match(req).then(function (cached) {
				var fresh = fetch(req).then(function (resp) { return putInCache(req, resp); }).catch(function () { return cached; });
				return cached || fresh;
			})
		);
		return;
	}

	if (req.mode === 'navigate') {
		e.respondWith(
			fetch(req).catch(function () {
				return caches.match(OFFLINE).then(function (cached) {
					return cached || new Response(
						'<!doctype html><meta charset="utf-8"><title>Sin conexión</title><p style="font-family:sans-serif;padding:2rem">Sin conexión. Probá de nuevo cuando vuelva internet.</p>',
						{ headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
					);
				});
			})
		);
		return;
	}

	// Resto: red directa, sin cachear (puede ser contenido privado).
	e.respondWith( fetch(req).catch(function () { return caches.match(req); }) );
});
		<?php
		exit;
	}

	/**
	 * Página offline de respaldo. Autocontenida (sin assets externos) para
	 * que el service worker la pueda precachear y servir sin red.
	 */
	public static function offline_page() {
		header( 'Content-Type: text/html; charset=UTF-8' );
		$site = esc_html( get_bloginfo( 'name' ) );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e( 'Sin conexión', 'cead-acad' ); ?> · <?php echo $site; ?></title>
<style>
	body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F7F5F0;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2327}
	.box{text-align:center;padding:32px;max-width:420px}
	.dot{width:64px;height:64px;border-radius:16px;background:#E93B3C;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:30px}
	h1{font-size:22px;margin:0 0 8px}
	p{font-size:15px;line-height:1.6;color:#3c434a;margin:0 0 20px}
	button{background:#E93B3C;color:#fff;border:0;border-radius:8px;padding:12px 28px;font-size:15px;font-weight:600;cursor:pointer}
</style>
</head>
<body>
	<div class="box">
		<div class="dot">📡</div>
		<h1><?php esc_html_e( 'Sin conexión', 'cead-acad' ); ?></h1>
		<p><?php esc_html_e( 'No hay internet en este momento. Las páginas que ya visitaste siguen disponibles; esta no estaba guardada.', 'cead-acad' ); ?></p>
		<button onclick="location.reload()"><?php esc_html_e( 'Reintentar', 'cead-acad' ); ?></button>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/** Genera un ícono cuadrado con las marcas de la marca CEAD. */
	public static function icon( $size ) {
		$size = max( 48, min( 1024, (int) $size ) );

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: image/png' );

		$img = imagecreatetruecolor( $size, $size );
		$ink = imagecolorallocate( $img, 11, 11, 11 ); // #0B0B0B
		imagefilledrectangle( $img, 0, 0, $size, $size, $ink );

		$cols = [
			imagecolorallocate( $img, 233, 59, 60 ),   // brand
			imagecolorallocate( $img, 73, 163, 200 ),  // blue
			imagecolorallocate( $img, 237, 223, 88 ),  // yellow
			imagecolorallocate( $img, 244, 183, 76 ),  // orange
		];

		$sq    = (int) round( $size * 0.26 );
		$gap   = (int) round( $size * 0.07 );
		$block = $sq * 2 + $gap;
		$x0    = (int) round( ( $size - $block ) / 2 );
		$y0    = $x0;

		for ( $i = 0; $i < 4; $i++ ) {
			$col = $i % 2;
			$row = intdiv( $i, 2 );
			$x   = $x0 + $col * ( $sq + $gap );
			$y   = $y0 + $row * ( $sq + $gap );
			imagefilledrectangle( $img, $x, $y, $x + $sq, $y + $sq, $cols[ $i ] );
		}

		imagepng( $img );
		imagedestroy( $img );
		exit;
	}
}
