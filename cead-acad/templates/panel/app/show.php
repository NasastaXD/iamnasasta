<?php
/**
 * /panel/app: tutorial para instalar el panel como app (PWA) en el celular.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = __( 'Instalar la app', 'cead-acad' );

$body = function () {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'App del CEAD', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Instalá el CEAD en tu celular', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Agregá el panel a tu pantalla de inicio: se abre como una app, a pantalla completa y entrás de una. Es gratis y no ocupa casi nada.', 'cead-acad' ); ?></p>

		<div class="cead-acad-card cead-acad-install-cta" style="margin-top:1.5rem">
			<div>
				<h3><?php esc_html_e( 'Instalación rápida', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Si tu navegador lo permite, tocá el botón. Si no aparece, seguí los pasos manuales de abajo.', 'cead-acad' ); ?></p>
			</div>
			<button type="button" id="cead-install-btn" class="cead-acad-btn"><?php esc_html_e( 'Instalar app', 'cead-acad' ); ?></button>
		</div>

		<div class="cead-acad-grid cead-acad-grid--2" style="margin-top:1.5rem;align-items:start">
			<!-- Android -->
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow">📱 <?php esc_html_e( 'Android · Chrome', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Paso a paso', 'cead-acad' ); ?></h3>
				<ol class="cead-acad-steps">
					<li><?php esc_html_e( 'Abrí este panel en Chrome (no dentro de Instagram/Facebook).', 'cead-acad' ); ?></li>
					<li><?php printf( esc_html__( 'Tocá el menú %s (tres puntos, arriba a la derecha).', 'cead-acad' ), '<strong>⋮</strong>' ); ?></li>
					<li><?php printf( esc_html__( 'Elegí %s.', 'cead-acad' ), '<strong>' . esc_html__( 'Agregar a la pantalla principal', 'cead-acad' ) . '</strong>' ); ?></li>
					<li><?php printf( esc_html__( 'Confirmá tocando %s.', 'cead-acad' ), '<strong>' . esc_html__( 'Instalar / Agregar', 'cead-acad' ) . '</strong>' ); ?></li>
					<li><?php esc_html_e( 'Listo: vas a ver el ícono del CEAD junto a tus otras apps.', 'cead-acad' ); ?></li>
				</ol>
			</div>

			<!-- iPhone -->
			<div class="cead-acad-card">
				<span class="cead-acad-eyebrow">🍎 <?php esc_html_e( 'iPhone · Safari', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Paso a paso', 'cead-acad' ); ?></h3>
				<ol class="cead-acad-steps">
					<li><?php esc_html_e( 'Abrí este panel en Safari (en iPhone solo funciona con Safari).', 'cead-acad' ); ?></li>
					<li><?php printf( esc_html__( 'Tocá el botón %s (cuadrado con la flecha hacia arriba), abajo en el centro.', 'cead-acad' ), '<strong>' . esc_html__( 'Compartir', 'cead-acad' ) . ' ⬆️</strong>' ); ?></li>
					<li><?php esc_html_e( 'Deslizá y elegí "Agregar a inicio".', 'cead-acad' ); ?></li>
					<li><?php printf( esc_html__( 'Tocá %s arriba a la derecha.', 'cead-acad' ), '<strong>' . esc_html__( 'Agregar', 'cead-acad' ) . '</strong>' ); ?></li>
					<li><?php esc_html_e( 'Listo: el CEAD queda como una app más en tu pantalla.', 'cead-acad' ); ?></li>
				</ol>
			</div>
		</div>

		<div class="cead-acad-card" style="margin-top:1.5rem">
			<span class="cead-acad-eyebrow">💻 <?php esc_html_e( 'Computadora · Chrome / Edge', 'cead-acad' ); ?></span>
			<p><?php printf( esc_html__( 'En la barra de direcciones, a la derecha, tocá el ícono de instalar %s y luego %s.', 'cead-acad' ), '<strong>⤓</strong>', '<strong>' . esc_html__( 'Instalar', 'cead-acad' ) . '</strong>' ); ?></p>
		</div>

		<h3 class="cead-acad-section-h" style="margin-top:2rem"><?php esc_html_e( 'Cómo se maneja', 'cead-acad' ); ?></h3>
		<div class="cead-acad-grid cead-acad-grid--3">
			<div class="cead-acad-card">
				<h3><?php esc_html_e( 'Abrir', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Tocás el ícono del CEAD como cualquier app. Abre directo en el panel, sin barra del navegador.', 'cead-acad' ); ?></p>
			</div>
			<div class="cead-acad-card">
				<h3><?php esc_html_e( 'Actualizar', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Se actualiza sola: cada vez que entrás con internet toma la última versión. No hay que reinstalar.', 'cead-acad' ); ?></p>
			</div>
			<div class="cead-acad-card">
				<h3><?php esc_html_e( 'Desinstalar', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Mantené presionado el ícono y elegí "Quitar / Desinstalar", igual que con cualquier app.', 'cead-acad' ); ?></p>
			</div>
		</div>

		<div class="cead-acad-card" style="margin-top:1.5rem">
			<span class="cead-acad-eyebrow">❓ <?php esc_html_e( 'No me aparece la opción', 'cead-acad' ); ?></span>
			<ul class="cead-acad-steps">
				<li><?php esc_html_e( 'Asegurate de abrirlo desde el navegador (Chrome o Safari), no desde un link dentro de Instagram, Facebook o WhatsApp.', 'cead-acad' ); ?></li>
				<li><?php esc_html_e( 'Si ya lo instalaste antes, no vuelve a ofrecerlo: buscá el ícono en tu pantalla.', 'cead-acad' ); ?></li>
				<li><?php esc_html_e( 'En iPhone tiene que ser sí o sí con Safari.', 'cead-acad' ); ?></li>
			</ul>
		</div>
	</section>

	<script>
	(function () {
		var btn = document.getElementById('cead-install-btn');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			if (window.__ceadInstall) {
				window.__ceadInstall.prompt();
				window.__ceadInstall.userChoice.then(function () { window.__ceadInstall = null; });
			} else {
				alert('<?php echo esc_js( __( 'Seguí los pasos manuales de esta página según tu teléfono. (Tu navegador no ofrece el botón automático.)', 'cead-acad' ) ); ?>');
			}
		});
	})();
	</script>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
