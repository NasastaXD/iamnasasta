<?php
/**
 * PWA: manifest, service worker e íconos generados al vuelo (GD).
 * Permite "Agregar a la pantalla de inicio" e instalar el panel como app.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_PWA {

	const CACHE = 'cead-pwa-v1';

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
const CACHE = '<?php echo esc_js( self::CACHE ); ?>';
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
	e.waitUntil(
		caches.keys().then(function (keys) {
			return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
		}).then(function () { return self.clients.claim(); })
	);
});
self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET' || req.url.indexOf('<?php echo esc_js( $origin ); ?>') !== 0) { return; }
	if (req.url.indexOf('/wp-admin') !== -1 || req.url.indexOf('admin-ajax') !== -1) { return; }
	e.respondWith(
		caches.open(CACHE).then(function (cache) {
			return cache.match(req).then(function (hit) {
				var net = fetch(req).then(function (resp) {
					if (resp && resp.status === 200 && resp.type === 'basic') { cache.put(req, resp.clone()); }
					return resp;
				}).catch(function () { return hit; });
				return hit || net;
			});
		})
	);
});
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
