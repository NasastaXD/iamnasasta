<?php
/**
 * /panel/faq: preguntas frecuentes (acordeón). Las redacta el staff en wp-admin.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$faqs = class_exists( 'Cead_Acad_FAQ' ) ? Cead_Acad_FAQ::all() : [];

$page_title = __( 'Preguntas frecuentes', 'cead-acad' );

$body = function () use ( $faqs ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Ayuda', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Preguntas frecuentes', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Respuestas a las dudas más comunes del CEAD.', 'cead-acad' ); ?></p>

		<?php if ( ! $faqs ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<h3><?php esc_html_e( 'Todavía no hay preguntas', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'Cuando el equipo del CEAD cargue preguntas frecuentes, aparecerán acá.', 'cead-acad' ); ?></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-faq" style="margin-top:1.5rem">
				<?php foreach ( $faqs as $f ) : ?>
					<details class="cead-acad-faq-item">
						<summary class="cead-acad-faq-q"><?php echo esc_html( get_the_title( $f ) ); ?></summary>
						<div class="cead-acad-faq-a"><?php echo wp_kses_post( apply_filters( 'the_content', $f->post_content ) ); ?></div>
					</details>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
