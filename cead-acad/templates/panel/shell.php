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
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $page_title ); ?> · <?php bloginfo( 'name' ); ?></title>
	<script>try{if(localStorage.getItem('cead_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}</script>
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
	})();
	</script>
</body>
</html>
