<?php
/**
 * Los tres dispositivos de la sección «Un solo sistema, tres puertas».
 *
 * Decorativos: cada tarjeta ya se explica con texto al lado, así que van
 * `aria-hidden` para no duplicarle el contenido a un lector de pantalla.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$tipo = $args['tipo'] ?? 'panel';
?>
<div class="proy-cara-ilu" aria-hidden="true">
<?php if ( 'panel' === $tipo ) : ?>
	<svg viewBox="0 0 160 110" fill="none" focusable="false">
		<!-- Monitor con sidebar y tarjetas -->
		<rect x="8" y="8" width="144" height="86" fill="var(--canvas-soft)" stroke="currentColor" stroke-width="3"/>
		<rect x="8" y="8" width="34" height="86" fill="var(--card-color)"/>
		<path d="M16 24h18M16 34h18M16 44h18M16 54h12" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".75"/>
		<rect class="proy-ilu-card" x="52" y="22" width="42" height="28" fill="#fff" stroke="currentColor" stroke-width="3"/>
		<rect class="proy-ilu-card proy-ilu-card--2" x="102" y="22" width="38" height="28" fill="#fff" stroke="currentColor" stroke-width="3"/>
		<rect class="proy-ilu-card proy-ilu-card--3" x="52" y="60" width="88" height="22" fill="#fff" stroke="currentColor" stroke-width="3"/>
		<path d="M60 96v6h40v-6" stroke="currentColor" stroke-width="3"/>
	</svg>

<?php elseif ( 'app' === $tipo ) : ?>
	<svg viewBox="0 0 160 110" fill="none" focusable="false">
		<!-- Celular con el ícono en la pantalla de inicio -->
		<rect x="56" y="6" width="48" height="92" rx="6" fill="var(--canvas-soft)" stroke="currentColor" stroke-width="3"/>
		<path d="M72 6h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
		<rect class="proy-ilu-tile" x="66" y="24" width="12" height="12" fill="var(--card-color)"/>
		<rect class="proy-ilu-tile proy-ilu-tile--2" x="82" y="24" width="12" height="12" fill="var(--brand)"/>
		<rect class="proy-ilu-tile proy-ilu-tile--3" x="66" y="40" width="12" height="12" fill="var(--acc-yellow)"/>
		<rect class="proy-ilu-tile proy-ilu-tile--4" x="82" y="40" width="12" height="12" fill="var(--acc-orange)"/>
		<path d="M66 64h28M66 72h20" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".4"/>
		<circle cx="80" cy="88" r="4" stroke="currentColor" stroke-width="3"/>
		<!-- Señal de que anda sin internet -->
		<path d="M118 44h16M126 36v16" stroke="var(--card-color)" stroke-width="3" stroke-linecap="round"/>
		<path d="M26 44h16M34 36v16" stroke="var(--card-color)" stroke-width="3" stroke-linecap="round" opacity=".5"/>
	</svg>

<?php else : ?>
	<svg viewBox="0 0 160 110" fill="none" focusable="false">
		<!-- Conversación: pregunta y respuesta -->
		<rect x="10" y="18" width="76" height="26" fill="#fff" stroke="currentColor" stroke-width="3"/>
		<path d="M22 44v10l12-10" fill="#fff" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
		<path d="M20 28h56M20 36h34" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".4"/>
		<rect class="proy-ilu-reply" x="66" y="58" width="84" height="30" fill="var(--card-color)"/>
		<path d="M138 88v10l-12-10" fill="var(--card-color)"/>
		<path d="M76 68h58M76 78h38" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".85"/>
		<!-- Nota de voz -->
		<g class="proy-ilu-voz">
			<path d="M22 74v10M30 70v18M38 66v26M46 72v14M54 76v6" stroke="var(--card-color)" stroke-width="3" stroke-linecap="round"/>
		</g>
	</svg>
<?php endif; ?>
</div>
