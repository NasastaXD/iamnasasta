<?php
/**
 * CEADI en el panel: un botón abajo a la derecha que despliega el chat.
 *
 * Es un desplegable anclado al botón, no una ventana aparte: el botón se queda
 * en su lugar y el chat crece justo encima. El botón es el único control —
 * abre y cierra— para que nunca haya dudas de cómo sacárselo de encima.
 *
 * Ojo con `hidden` en el panel: la regla `[hidden] { display:none }` vive en la
 * hoja del navegador, y CUALQUIER declaración de autor le gana sin importar la
 * especificidad. Como el CSS del panel declaraba `display:flex`, el chat se
 * dibujaba abierto en todas las pantallas y desde la primera carga, tapando la
 * mitad de abajo. El CSS ahora lo corta explícitamente; el atributo por sí solo
 * no alcanza.
 *
 * Se dibuja en el shell, así que vive en TODAS las pantallas del panel — que es
 * la gracia: la pregunta se hace donde surge. Si la IA está apagada, la
 * plantilla ni siquiera se incluye.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$cead_ceadi_wa = function_exists( 'cead_acad_wa_link' ) ? cead_acad_wa_link() : '';
?>
<div class="cead-ceadi" id="cead-ceadi" data-estado="cerrado">

	<!-- El desplegable -->
	<div class="cead-ceadi-panel" id="cead-ceadi-panel" role="dialog" aria-modal="false"
	     aria-label="<?php esc_attr_e( 'Chat con CEADI', 'cead-acad' ); ?>" hidden>

		<div class="cead-ceadi-head">
			<span class="cead-ceadi-chispa" aria-hidden="true"></span>
			<div class="cead-ceadi-head-txt">
				<strong>CEADI</strong>
				<span><?php esc_html_e( 'Preguntá lo que necesites', 'cead-acad' ); ?></span>
			</div>
			<?php
			/*
			 * Agendar el número va acá, como un ícono al lado del título, y no
			 * como un paso previo: antes había que abrir un menú, elegir «chat»
			 * y recién ahí escribir. Dos toques para lo que se hace siempre, por
			 * dejar a mano lo que se hace una sola vez en la vida.
			 */
			if ( $cead_ceadi_wa ) : ?>
				<a class="cead-ceadi-wa" href="<?php echo esc_url( $cead_ceadi_wa ); ?>" target="_blank" rel="noopener"
				   title="<?php esc_attr_e( 'Agendar CEADI en WhatsApp', 'cead-acad' ); ?>"
				   aria-label="<?php esc_attr_e( 'Agendar CEADI en WhatsApp', 'cead-acad' ); ?>">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.87 9.87 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23a8.2 8.2 0 0 1 8.24 8.24c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.22.25-.85.84-.85 2.03 0 1.2.87 2.35.99 2.51.12.17 1.71 2.62 4.15 3.67.58.25 1.03.4 1.39.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
				</a>
			<?php endif; ?>
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

	<!-- El botón: abre y cierra. Es lo único que se ve con el chat cerrado. -->
	<button type="button" class="cead-ceadi-punto" id="cead-ceadi-abrir"
	        aria-expanded="false" aria-controls="cead-ceadi-panel"
	        aria-label="<?php esc_attr_e( 'Abrir CEADI', 'cead-acad' ); ?>">
		<span class="cead-ceadi-chispa" aria-hidden="true"></span>
		<svg class="cead-ceadi-ico cead-ceadi-ico--abrir" width="22" height="22" viewBox="0 0 24 24" fill="none"
		     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/>
		</svg>
		<svg class="cead-ceadi-ico cead-ceadi-ico--cerrar" width="22" height="22" viewBox="0 0 24 24" fill="none"
		     stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
			<path d="M18 6 6 18M6 6l12 12"/>
		</svg>
	</button>
</div>
