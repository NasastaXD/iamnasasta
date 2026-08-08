<?php
/**
 * Shell del panel: sidebar + topbar + slot de contenido.
 * Espera variables: $page_title (string), $body (callable).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = $page_title ?? __( 'Panel', 'cead-acad' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php /* viewport-fit=cover deja que el contenido use el ancho completo en
	         pantallas con muesca; el CSS después respeta el área segura con
	         env(safe-area-inset-*) para que nada quede bajo el reloj ni bajo la
	         barra de gestos. Sin esto, en iPhone el encabezado se metía debajo
	         de la hora al hacer scroll. */ ?>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( $page_title ); ?> · <?php bloginfo( 'name' ); ?></title>
	<script>try{if(localStorage.getItem('cead_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}</script>
	<script>try{if(sessionStorage.getItem('cead_splash')){document.documentElement.classList.add('cead-splash-done');}else{sessionStorage.setItem('cead_splash','1');}}catch(e){document.documentElement.classList.add('cead-splash-done');}</script>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/cead-manifest.webmanifest' ) ); ?>">
	<meta name="theme-color" content="#E93B3C">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="CEAD">
	<link rel="apple-touch-icon" href="<?php echo esc_url( home_url( '/cead-icon-192.png' ) ); ?>">
	<link rel="icon" type="image/png" href="<?php echo esc_url( home_url( '/cead-icon-192.png' ) ); ?>">
	<?php wp_head(); ?>
</head>
<body class="cead-acad-panel">
	<div class="cead-acad-splash" aria-hidden="true">
		<div class="cead-acad-splash-inner">
			<div class="cead-acad-splash-word">
				<span class="cead-acad-splash-tile t-c">C</span>
				<span class="cead-acad-splash-tile t-e">E</span>
				<span class="cead-acad-splash-tile t-a">A</span>
				<span class="cead-acad-splash-tile t-d">D</span>
			</div>
			<div class="cead-acad-splash-sub">
				<span class="cead-acad-splash-sub1"><?php esc_html_e( 'Centro Educativo de Alto Desempeño', 'cead-acad' ); ?></span>
				<span class="cead-acad-splash-sub2"><?php esc_html_e( 'Félix de Guarania', 'cead-acad' ); ?></span>
			</div>
		</div>
	</div>
	<div class="cead-acad-panel-shell">
		<div class="cead-acad-nav-backdrop" onclick="document.body.classList.remove('cead-acad-nav-open');"></div>
		<?php include CEAD_ACAD_DIR . 'templates/panel/partials/sidebar.php'; ?>
		<div class="cead-acad-panel-main">
			<?php include CEAD_ACAD_DIR . 'templates/panel/partials/topbar.php'; ?>
			<main class="cead-acad-panel-content">
				<?php if ( isset( $body ) && is_callable( $body ) ) { call_user_func( $body ); } ?>
			</main>
		</div>
	</div>
	<?php wp_footer(); ?>
	<script>
	(function () {
		if ('serviceWorker' in navigator) {
			navigator.serviceWorker.register('<?php echo esc_js( home_url( '/cead-sw.js' ) ); ?>', { scope: '/' }).catch(function () {});
		}
		window.addEventListener('beforeinstallprompt', function (e) {
			e.preventDefault();
			window.__ceadInstall = e;
			document.documentElement.classList.add('cead-can-install');
		});
		window.addEventListener('appinstalled', function () {
			document.documentElement.classList.remove('cead-can-install');
		});
		document.addEventListener('click', function (e) {
			document.querySelectorAll('.cead-acad-notif.is-open').forEach(function (n) {
				if (!n.contains(e.target)) {
					n.classList.remove('is-open');
					var b = n.querySelector('.cead-acad-bell');
					if (b) { b.setAttribute('aria-expanded', 'false'); }
				}
			});
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				document.body.classList.remove('cead-acad-nav-open');
				document.querySelectorAll('.cead-acad-notif.is-open').forEach(function (n) {
					n.classList.remove('is-open');
					var b = n.querySelector('.cead-acad-bell');
					if (b) { b.setAttribute('aria-expanded', 'false'); }
				});
			}
		});
		var ceadResizeTimer;
		function ceadHandleResize() {
			if (window.innerWidth >= 900) {
				document.body.classList.remove('cead-acad-nav-open');
			}
		}
		function ceadOnResize() {
			if (ceadResizeTimer) { clearTimeout(ceadResizeTimer); }
			ceadResizeTimer = setTimeout(ceadHandleResize, 150);
		}
		window.addEventListener('resize', ceadOnResize);
		window.addEventListener('orientationchange', ceadOnResize);
	})();
	</script>
</body>
</html>
