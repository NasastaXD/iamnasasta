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
				'<span class="proy-accent">' . esc_html__( 'en el celular', 'cead' ) . '</span>'
			);
			?>
		</h1>

		<p class="reveal proy-hero-body">
			<?php esc_html_e( 'Las notas, el horario, los comunicados y los trámites del CEAD, en un solo lugar. Se entra desde la web, desde la app o escribiéndole por WhatsApp a CEADI — lo que a cada uno le quede más cómodo.', 'cead' ); ?>
		</p>

		<div class="reveal proy-hero-actions">
			<?php
			cead_audio_button(
				__( 'CEAD Académico es la plataforma propia del colegio. Reúne las notas, el horario, los comunicados y los trámites en un solo lugar, y se usa desde la web, desde la app del celular, o escribiéndole por WhatsApp a CEADI. Bajá para ver cómo funciona.', 'cead' ),
				'hero.mp3'
			);
			?>
			<a class="cead-btn cead-btn-dark" href="#caras"><?php esc_html_e( '→ Ver cómo funciona', 'cead' ); ?></a>
		</div>
	</div>

	<div class="reveal proy-hero-scroll" aria-hidden="true">
		<span><?php esc_html_e( 'Seguí bajando', 'cead' ); ?></span>
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
	</div>
</section>
