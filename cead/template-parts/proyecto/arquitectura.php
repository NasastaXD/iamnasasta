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
		'rol'   => __( 'Información abierta para cualquier visitante', 'cead' ),
		'txt'   => __( 'Es el sitio público del colegio: incluye novedades, bachilleratos, galería y esta página. No requiere una cuenta.', 'cead' ),
	],
	[
		'color' => 'var(--brand)',
		'tit'   => __( 'La aplicación', 'cead' ),
		'rol'   => __( 'Donde funciona el sistema principal', 'cead' ),
		'txt'   => __( 'Aquí se administran los usuarios, los roles, los permisos, las notas, los comunicados, las encuestas y las funciones de CEADI. Es la parte que guarda la información del sistema.', 'cead' ),
	],
	[
		'color' => 'var(--acc-orange)',
		'tit'   => __( 'El puente con WhatsApp', 'cead' ),
		'rol'   => __( 'Un componente separado y pequeño', 'cead' ),
		'txt'   => __( 'Su función es llevar los mensajes entre WhatsApp y la aplicación. Si deja de funcionar, la web y el panel continúan disponibles; únicamente CEADI deja de responder por WhatsApp.', 'cead' ),
	],
];
?>
<section id="arquitectura" class="proy-section proy-section--arq">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( 'Estructura del sistema', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Tres componentes que trabajan como un solo sistema.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'El sistema funciona en el servidor del colegio. La institución mantiene el control de los datos y no depende de una suscripción externa para conservar el acceso a la plataforma.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'El sistema tiene tres partes. La web pública muestra la información abierta del colegio. La aplicación administra usuarios, permisos, notas y demás datos. Un componente separado conecta esa aplicación con WhatsApp. Si esa conexión se detiene, la web y el panel siguen funcionando. Todo se ejecuta en el servidor del colegio.', 'cead' ),
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
			<?php esc_html_e( 'Las actualizaciones se administran desde el panel. Cuando hay una nueva versión, aparece un aviso y puede instalarse desde allí.', 'cead' ); ?>
		</p>
	</div>
</section>
