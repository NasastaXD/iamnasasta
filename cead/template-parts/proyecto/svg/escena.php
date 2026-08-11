<?php
/**
 * Ilustraciones planas de la sección «El punto de partida».
 *
 * SVG a mano, en la estética CEAD: trazo grueso, esquinas rectas, sin
 * degradados. Heredan el color de la tarjeta por `currentColor` y `--card-color`,
 * así que siguen al Customizer sin tener que regenerarlas.
 *
 * Son decorativas: el texto de al lado ya dice todo, así que van `aria-hidden`
 * para no repetirle lo mismo a quien usa lector de pantalla.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$tipo = $args['tipo'] ?? 'papel';
?>
<div class="proy-escena" aria-hidden="true">
<?php if ( 'papel' === $tipo ) : ?>
	<svg viewBox="0 0 120 100" fill="none" focusable="false">
		<!-- Hoja arrugada cayendo -->
		<path d="M22 14h46l16 16v46H22z" stroke="currentColor" stroke-width="3"/>
		<path d="M68 14v16h16" stroke="currentColor" stroke-width="3"/>
		<path d="M32 44h34M32 54h34M32 64h20" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".45"/>
		<path class="proy-escena-drift" d="M88 62l12 10-12 10-6-10z" fill="var(--card-color)"/>
		<path class="proy-escena-drift proy-escena-drift--2" d="M96 40l10 6-8 8-5-7z" fill="var(--card-color)" opacity=".55"/>
	</svg>

<?php elseif ( 'chats' === $tipo ) : ?>
	<svg viewBox="0 0 120 100" fill="none" focusable="false">
		<!-- Pila de burbujas: la de abajo es la que importa y queda tapada -->
		<rect x="14" y="16" width="60" height="30" stroke="currentColor" stroke-width="3"/>
		<path d="M26 46v10l12-10" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
		<rect x="34" y="38" width="60" height="30" fill="var(--canvas-soft)" stroke="currentColor" stroke-width="3"/>
		<path d="M46 68v10l12-10" fill="var(--canvas-soft)" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
		<rect class="proy-escena-pulse" x="52" y="58" width="54" height="28" fill="var(--card-color)"/>
		<path d="M62 74h34M62 66h22" stroke="var(--canvas-soft)" stroke-width="3" stroke-linecap="round"/>
	</svg>

<?php else : ?>
	<svg viewBox="0 0 120 100" fill="none" focusable="false">
		<!-- Cuaderno con una columna de notas a mano -->
		<rect x="24" y="12" width="66" height="74" stroke="currentColor" stroke-width="3"/>
		<path d="M38 12v74" stroke="currentColor" stroke-width="3" opacity=".45"/>
		<path d="M50 30h28M50 44h28M50 58h28M50 72h16" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".45"/>
		<circle class="proy-escena-pulse" cx="84" cy="30" r="7" fill="var(--card-color)"/>
		<path d="M96 62l10-10 8 8-10 10-9 3z" fill="var(--card-color)" opacity=".55"/>
	</svg>
<?php endif; ?>
</div>
