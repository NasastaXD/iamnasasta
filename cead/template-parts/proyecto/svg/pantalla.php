<?php
/**
 * Ilustración del panel: sidebar, barra superior y tarjetas.
 *
 * Es una ILUSTRACIÓN, no una captura. Se dibuja a mano para que siga los
 * colores del Customizer y se vea nítida en cualquier pantalla. Si algún día
 * querés poner capturas reales, el procedimiento está en
 * `cead-acad/docs/img/README.md`.
 *
 * Decorativa: todo lo que muestra está escrito al lado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="reveal proy-pantalla" aria-hidden="true">
	<svg viewBox="0 0 300 220" fill="none" focusable="false">
		<!-- Marco -->
		<rect x="6" y="6" width="288" height="208" fill="var(--canvas-soft)" stroke="var(--ink)" stroke-width="3"/>

		<!-- Sidebar -->
		<rect x="6" y="6" width="72" height="208" fill="var(--ink)"/>
		<g>
			<rect x="16" y="18" width="8" height="8" fill="var(--brand)"/>
			<rect x="26" y="18" width="8" height="8" fill="var(--acc-blue)"/>
			<rect x="16" y="28" width="8" height="8" fill="var(--acc-yellow)"/>
			<rect x="26" y="28" width="8" height="8" fill="var(--acc-orange)"/>
		</g>
		<?php // Ítems del menú; el activo lleva la barra roja, como en el panel real. ?>
		<rect class="proy-nav-item" x="6" y="54" width="72" height="14" fill="var(--brand)" opacity=".22"/>
		<rect x="6" y="54" width="3" height="14" fill="var(--brand)"/>
		<path d="M18 61h44" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
		<path class="proy-nav-line" d="M18 79h40M18 95h46M18 111h36M18 127h44M18 143h32" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".4"/>

		<!-- Barra superior -->
		<path d="M78 40h216" stroke="var(--ink)" stroke-width="3"/>
		<path d="M92 23h40" stroke="var(--ink)" stroke-width="4" stroke-linecap="round"/>
		<rect x="196" y="16" width="48" height="14" stroke="var(--ink)" stroke-width="2.5" opacity=".5"/>
		<circle cx="262" cy="23" r="7" stroke="var(--ink)" stroke-width="2.5" opacity=".5"/>
		<circle class="proy-badge" cx="267" cy="18" r="4" fill="var(--brand)"/>

		<!-- Tarjetas: entran escalonadas -->
		<g class="proy-tarjetas">
			<g class="proy-tarjeta">
				<rect x="92" y="56" width="92" height="60" fill="#fff" stroke="var(--ink)" stroke-width="3"/>
				<rect x="92" y="113" width="92" height="3" fill="var(--brand)"/>
				<path d="M102 72h50M102 84h64M102 96h40" stroke="var(--ink)" stroke-width="3" stroke-linecap="round" opacity=".35"/>
			</g>
			<g class="proy-tarjeta proy-tarjeta--2">
				<rect x="196" y="56" width="84" height="60" fill="#fff" stroke="var(--ink)" stroke-width="3"/>
				<rect x="196" y="113" width="84" height="3" fill="var(--acc-blue)"/>
				<path d="M206 72h44M206 84h56M206 96h34" stroke="var(--ink)" stroke-width="3" stroke-linecap="round" opacity=".35"/>
			</g>
			<g class="proy-tarjeta proy-tarjeta--3">
				<rect x="92" y="130" width="188" height="66" fill="#fff" stroke="var(--ink)" stroke-width="3"/>
				<rect x="92" y="193" width="188" height="3" fill="var(--acc-orange)"/>
				<path d="M102 146h70M102 160h120M102 174h86" stroke="var(--ink)" stroke-width="3" stroke-linecap="round" opacity=".35"/>
			</g>
		</g>
	</svg>
	<p class="proy-pantalla-pie"><?php esc_html_e( 'Ilustración del panel — no es una captura', 'cead' ); ?></p>
</div>
