<?php
/**
 * Sección 6 — Cómo está armado.
 *
 * El organigrama. Va acá y no antes a propósito: recién después de ver QUÉ hace
 * el sistema tiene sentido mostrar de qué piezas está hecho.
 *
 * Se explica en castellano, no en jerga: la idea es que dirección entienda que
 * son tres piezas y qué pasa si una se cae, no que aprenda qué es un plugin.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$piezas = [
	[
		'color' => 'var(--acc-blue)',
		'tit'   => __( 'La web pública', 'cead' ),
		'rol'   => __( 'Lo que ve cualquiera', 'cead' ),
		'txt'   => __( 'El sitio del colegio: novedades, bachilleratos, galería y esta misma página. No hace falta cuenta.', 'cead' ),
	],
	[
		'color' => 'var(--brand)',
		'tit'   => __( 'La aplicación', 'cead' ),
		'rol'   => __( 'El corazón del sistema', 'cead' ),
		'txt'   => __( 'Acá vive todo: usuarios, roles, permisos, notas, comunicados, encuestas y el cerebro de CEADI. Es la única pieza que guarda datos.', 'cead' ),
	],
	[
		'color' => 'var(--acc-orange)',
		'tit'   => __( 'El puente a WhatsApp', 'cead' ),
		'rol'   => __( 'Un programa chico, aparte', 'cead' ),
		'txt'   => __( 'Lo único que hace es pasar mensajes entre WhatsApp y la aplicación. Si se apaga, el panel y la web siguen funcionando igual: solo deja de contestar el bot.', 'cead' ),
	],
];
?>
<section id="arquitectura" class="proy-section proy-section--arq">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( '— Cómo está armado', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Tres piezas, y ninguna depende de un tercero.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'Todo corre en el servidor del colegio. No hay una empresa de por medio que pueda subir el precio, cerrar el servicio o quedarse con los datos del alumnado.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'El sistema son tres piezas. La web pública, que ve cualquiera. La aplicación, que es el corazón: ahí viven los usuarios, los permisos, las notas y el cerebro del bot. Y un programa chico aparte, que es el puente a WhatsApp. Si el puente se apaga, la web y el panel siguen andando igual: solo deja de contestar el bot. Todo corre en el servidor del colegio.', 'cead' ),
					'arquitectura.mp3'
				);
				?>
			</div>
		</header>

		<?php get_template_part( 'template-parts/proyecto/svg/organigrama' ); ?>

		<ul class="proy-arq-grid">
			<?php foreach ( $piezas as $p ) : ?>
				<li class="reveal proy-arq-card" style="--card-color:<?php echo esc_attr( $p['color'] ); ?>">
					<span class="proy-arq-mark" aria-hidden="true"></span>
					<p class="proy-arq-rol"><?php echo esc_html( $p['rol'] ); ?></p>
					<h3 class="proy-arq-tit"><?php echo esc_html( $p['tit'] ); ?></h3>
					<p class="proy-arq-txt"><?php echo esc_html( $p['txt'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="reveal proy-arq-nota">
			<span class="proy-caras-nota-mark" aria-hidden="true"></span>
			<?php esc_html_e( 'Se actualiza solo. Cuando hay una versión nueva, aparece el aviso en el panel de administración y se instala con un clic — igual que cualquier actualización de WordPress.', 'cead' ); ?>
		</p>
	</div>
</section>
