<?php
/**
 * Sección 1 — Qué es esto, en una frase.
 *
 * Sin jerga y sin números. Si alguien lee solo esta pantalla y se va, tiene que
 * poder repetirle a otra persona de qué se trata.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<section class="proy-hero">
	<div class="container proy-hero-inner">
		<p class="reveal eyebrow proy-hero-eyebrow">
			<span class="proy-hero-line" aria-hidden="true"></span>
			<?php esc_html_e( 'CEAD Académico', 'cead' ); ?>
		</p>

		<h1 class="reveal proy-hero-title">
			<?php
			printf(
				/* translators: %s va resaltado en rojo. */
				esc_html__( 'Todo el colegio, %s.', 'cead' ),
				'<span class="proy-accent">' . esc_html__( 'en un solo lugar', 'cead' ) . '</span>'
			);
			?>
		</h1>

		<p class="reveal proy-hero-body">
			<?php esc_html_e( 'Notas, horarios, comunicados y trámites del CEAD, reunidos en una sola plataforma. Se puede acceder desde la web, instalarla en el celular o consultar a CEADI por WhatsApp.', 'cead' ); ?>
		</p>

		<div class="reveal proy-hero-actions">
			<?php
			cead_audio_button(
				__( 'CEAD Académico es la plataforma propia del colegio. Reúne notas, horarios, comunicados y trámites en un solo lugar. Puede usarse desde la web, instalarse en el celular o consultarse por WhatsApp mediante CEADI. A continuación se explica cómo funciona.', 'cead' ),
				'hero.mp3'
			);
			?>
			<a class="cead-btn cead-btn-dark" href="#caras"><?php esc_html_e( 'Ver cómo funciona', 'cead' ); ?></a>
		</div>
	</div>

	<div class="reveal proy-hero-scroll" aria-hidden="true">
		<span><?php esc_html_e( 'Continuar', 'cead' ); ?></span>
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
	</div>
</section>
