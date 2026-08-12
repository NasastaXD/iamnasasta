<?php
/**
 * CEADI en el panel: un punto abajo a la derecha que despliega un menú, y de
 * ahí se abre el chat.
 *
 * Antes esto era una barra de 560px anclada abajo al centro que decía
 * «Preguntale a CEADI». Se veía, sí, pero le comía una franja a todas las
 * pantallas del panel y obligaba a reservarle casi 90px de aire al contenido
 * para que no la tapara. Un punto ocupa lo que ocupa un punto.
 *
 * El menú tiene dos salidas, que son las dos formas reales de hablar con
 * CEADI: acá mismo por el chat de la web, o por WhatsApp, que es donde vive de
 * verdad. Si no hay número cargado la segunda no existe, y entonces el menú
 * tendría un solo ítem: en ese caso el punto abre el chat directo y listo —
 * mismo criterio que el footer con una red social sin URL.
 *
 * Se dibuja en el shell, así que vive en TODAS las pantallas del panel — que
 * es la gracia: la pregunta se hace donde surge, sin ir a buscar nada. Si la
 * IA está apagada, la plantilla ni siquiera se incluye.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_ceadi_wa = function_exists( 'cead_acad_wa_link' ) ? cead_acad_wa_link() : '';
?>
<div class="cead-ceadi" id="cead-ceadi" data-estado="cerrado"
     <?php echo $cead_ceadi_wa ? 'data-menu="1"' : ''; ?>>

	<!-- Cerrado: el punto -->
	<button type="button" class="cead-ceadi-punto" id="cead-ceadi-abrir"
	        aria-expanded="false"
	        aria-controls="<?php echo $cead_ceadi_wa ? 'cead-ceadi-menu' : 'cead-ceadi-panel'; ?>"
	        aria-label="<?php esc_attr_e( 'Abrir CEADI', 'cead-acad' ); ?>">
		<span class="cead-ceadi-chispa" aria-hidden="true"></span>
		<svg class="cead-ceadi-punto-ico" width="22" height="22" viewBox="0 0 24 24" fill="none"
		     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/>
		</svg>
	</button>

	<?php if ( $cead_ceadi_wa ) : ?>
		<!-- Intermedio: las dos formas de hablarle -->
		<div class="cead-ceadi-menu" id="cead-ceadi-menu" hidden>
			<button type="button" class="cead-ceadi-opcion" id="cead-ceadi-chat">
				<span class="cead-ceadi-opcion-ico" aria-hidden="true">💬</span>
				<span>
					<strong><?php esc_html_e( 'Preguntale a CEADI', 'cead-acad' ); ?></strong>
					<small><?php esc_html_e( 'Acá mismo, sin salir del panel', 'cead-acad' ); ?></small>
				</span>
			</button>
			<a class="cead-ceadi-opcion" href="<?php echo esc_url( $cead_ceadi_wa ); ?>" target="_blank" rel="noopener">
				<span class="cead-ceadi-opcion-ico" aria-hidden="true">📲</span>
				<span>
					<strong><?php esc_html_e( 'Agendar en WhatsApp', 'cead-acad' ); ?></strong>
					<small><?php esc_html_e( 'Guardá el número y escribile cuando quieras', 'cead-acad' ); ?></small>
				</span>
			</a>
		</div>
	<?php endif; ?>

	<!-- Abierto: el chat -->
	<div class="cead-ceadi-panel" id="cead-ceadi-panel" role="dialog" aria-modal="false"
	     aria-label="<?php esc_attr_e( 'Chat con CEADI', 'cead-acad' ); ?>" hidden>

		<div class="cead-ceadi-head">
			<span class="cead-ceadi-chispa" aria-hidden="true"></span>
			<div class="cead-ceadi-head-txt">
				<strong>CEADI</strong>
				<span><?php esc_html_e( 'Preguntá lo que necesites', 'cead-acad' ); ?></span>
			</div>
			<button type="button" class="cead-ceadi-cerrar" id="cead-ceadi-cerrar"
			        aria-label="<?php esc_attr_e( 'Cerrar el chat', 'cead-acad' ); ?>">✕</button>
		</div>

		<div class="cead-ceadi-hilo" id="cead-ceadi-hilo" role="log" aria-live="polite" aria-atomic="false">
			<div class="cead-ceadi-msg cead-ceadi-msg--bot">
				<?php esc_html_e( '¡Hola! Preguntame por tu horario, tus notas, las fechas del calendario, los comunicados o cualquier duda del colegio.', 'cead-acad' ); ?>
			</div>
		</div>

		<form class="cead-ceadi-form" id="cead-ceadi-form">
			<label class="cead-acad-sr-only" for="cead-ceadi-input"><?php esc_html_e( 'Tu pregunta', 'cead-acad' ); ?></label>
			<input type="text" id="cead-ceadi-input" autocomplete="off"
			       placeholder="<?php esc_attr_e( 'Escribí tu pregunta…', 'cead-acad' ); ?>" maxlength="1000">
			<button type="submit" aria-label="<?php esc_attr_e( 'Enviar', 'cead-acad' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
			</button>
		</form>
	</div>
</div>
