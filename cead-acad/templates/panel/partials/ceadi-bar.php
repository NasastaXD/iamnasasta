<?php
/**
 * Barra de CEADI: cerrada es una línea abajo de todo; al tocarla se expande en
 * un panel de chat.
 *
 * Se dibuja en el shell, así que vive en TODAS las pantallas del panel — que
 * es la gracia: la pregunta se hace donde surge, sin ir a buscar nada.
 *
 * Nace cerrada y sin nada cargado. Si la IA está apagada, la plantilla ni
 * siquiera se incluye: más vale que no exista a que exista y no conteste.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cead-ceadi" id="cead-ceadi" data-estado="cerrado">

	<!-- Cerrada: la barra que invita -->
	<button type="button" class="cead-ceadi-barra" id="cead-ceadi-abrir"
	        aria-expanded="false" aria-controls="cead-ceadi-panel">
		<span class="cead-ceadi-chispa" aria-hidden="true"></span>
		<span class="cead-ceadi-barra-txt"><?php esc_html_e( 'Preguntale a CEADI', 'cead-acad' ); ?></span>
		<span class="cead-ceadi-barra-hint" aria-hidden="true">↑</span>
	</button>

	<!-- Abierta: el chat -->
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
