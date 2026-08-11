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
	<?php
	/*
	 * El tema, antes del primer pintado.
	 *
	 * Dos cambios sobre lo que había:
	 *
	 * 1. Si no hay elección guardada, se sigue la preferencia del SISTEMA. Antes
	 *    el modo oscuro era solo manual, así que quien tiene el celular en
	 *    oscuro entraba al panel y se comía un panelazo blanco hasta encontrar
	 *    el botón — y en cada visita nueva otra vez.
	 * 2. La elección se escribe tal cual, incluido «light». Antes el claro era
	 *    la ausencia del atributo; ahora que existe la preferencia del sistema,
	 *    sin un «light» explícito quien tiene el sistema en oscuro no podría
	 *    quedarse en claro nunca.
	 *
	 * Va acá, síncrono en el <head>, y no como `@media (prefers-color-scheme)`
	 * en la hoja de estilos, porque el modo oscuro del panel está escrito como
	 * unas sesenta reglas colgadas de `[data-theme="dark"]`: una media query que
	 * solo redefiniera los tokens dejaría texto claro sobre tarjetas blancas.
	 * Poniendo el atributo, todas esas reglas aplican como corresponde.
	 */
	?>
	<script>try{var t=localStorage.getItem('cead_theme');if(t!=='dark'&&t!=='light'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
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
	<?php
	/*
	 * Saltar al contenido. El sitio público lo tiene desde hace rato; el panel
	 * no, y acá pesa más: el sidebar son catorce enlaces que hay que atravesar
	 * con Tab en CADA pantalla antes de llegar a lo que se vino a leer.
	 */
	?>
	<a class="cead-acad-skip" href="#cead-acad-contenido"><?php esc_html_e( 'Saltar al contenido', 'cead-acad' ); ?></a>

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
		<?php
		/*
		 * El fondo que cierra el menú en celular. Era un <div onclick>: con el
		 * dedo cerraba, pero para el teclado sencillamente no existía —no se
		 * podía enfocar ni activar—, así que quien abría el menú sin mouse
		 * quedaba dentro sin salida visible. Como <button> se enfoca, responde a
		 * Enter y Espacio, y se anuncia.
		 */
		?>
		<button type="button" class="cead-acad-nav-backdrop" tabindex="-1"
		        aria-label="<?php esc_attr_e( 'Cerrar menú', 'cead-acad' ); ?>"></button>
		<?php include CEAD_ACAD_DIR . 'templates/panel/partials/sidebar.php'; ?>
		<div class="cead-acad-panel-main">
			<?php include CEAD_ACAD_DIR . 'templates/panel/partials/topbar.php'; ?>
			<main id="cead-acad-contenido" class="cead-acad-panel-content" tabindex="-1">
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
		/*
		 * El menú lateral en celular. Abierto tapa la pantalla, así que el foco
		 * tiene que entrar con él, quedarse adentro mientras dure, y volver a la
		 * hamburguesa al cerrar. Sin eso, quien navega con teclado abre el menú
		 * y sigue tabulando por el contenido de atrás, que no ve.
		 */
		var burger   = document.querySelector('.cead-acad-burger');
		var sidebar  = document.querySelector('.cead-acad-panel-sidebar');
		var backdrop = document.querySelector('.cead-acad-nav-backdrop');
		var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

		function navAbierto() { return document.body.classList.contains('cead-acad-nav-open'); }

		function cerrarNav(devolverFoco) {
			if (!navAbierto()) { return; }
			document.body.classList.remove('cead-acad-nav-open');
			if (burger) {
				burger.setAttribute('aria-expanded', 'false');
				if (devolverFoco === true) { burger.focus(); }
			}
		}
		window.ceadCerrarNav = cerrarNav;

		if (backdrop) { backdrop.addEventListener('click', function () { cerrarNav(true); }); }

		// Al abrir, el foco entra al menú (solo en celular: en escritorio el
		// sidebar está siempre visible y moverle el foco sería molesto).
		if (burger && sidebar) {
			burger.addEventListener('click', function () {
				if (!navAbierto()) { return; }
				var primero = sidebar.querySelector(FOCUSABLE);
				if (primero) { primero.focus(); }
			});
		}

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				cerrarNav(true);
				document.querySelectorAll('.cead-acad-notif.is-open').forEach(function (n) {
					n.classList.remove('is-open');
					var b = n.querySelector('.cead-acad-bell');
					if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
				});
				return;
			}

			if (e.key !== 'Tab' || !navAbierto() || !sidebar) { return; }

			var focusables = Array.prototype.filter.call(
				sidebar.querySelectorAll(FOCUSABLE),
				function (el) { return el.offsetParent !== null; }
			);
			if (!focusables.length) { return; }

			var primero = focusables[0];
			var ultimo  = focusables[focusables.length - 1];

			if (!sidebar.contains(document.activeElement)) {
				e.preventDefault();
				(e.shiftKey ? ultimo : primero).focus();
			} else if (e.shiftKey && document.activeElement === primero) {
				e.preventDefault();
				ultimo.focus();
			} else if (!e.shiftKey && document.activeElement === ultimo) {
				e.preventDefault();
				primero.focus();
			}
		});
		var ceadResizeTimer;
		function ceadHandleResize() {
			if (window.innerWidth >= 900) {
				// Sin devolver el foco: el sidebar pasa a estar siempre visible,
				// así que no hay nada de donde «salir».
				cerrarNav(false);
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
