<?php
/**
 * Sección 3 — Las tres caras.
 *
 * Es la idea central de todo el proyecto y la que hay que entender sí o sí:
 * NO son tres sistemas. Es uno solo con tres puertas de entrada, y lo que
 * entra por una sale por las otras.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$caras = [
	[
		'clave' => 'panel',
		'color' => 'var(--brand)',
		'tit'   => __( 'El panel web', 'cead' ),
		'sub'   => __( 'Disponible desde cualquier navegador', 'cead' ),
		'txt'   => __( 'Reúne comunicados, horarios, calendario, tareas, recursos, boletín y carné digital. Cada persona accede únicamente a las funciones que corresponden a su rol.', 'cead' ),
		'quien' => __( 'Para toda la comunidad educativa', 'cead' ),
	],
	[
		'clave' => 'app',
		'color' => 'var(--acc-blue)',
		'tit'   => __( 'La aplicación', 'cead' ),
		'sub'   => __( 'Se instala directamente desde el navegador', 'cead' ),
		'txt'   => __( 'Ofrece el mismo panel desde la pantalla de inicio del celular. No requiere una tienda de aplicaciones y parte del contenido consultado puede seguir disponible sin conexión.', 'cead' ),
		'quien' => __( 'Para estudiantes y familias', 'cead' ),
	],
	[
		'clave' => 'ceadi',
		'color' => 'var(--acc-orange)',
		'tit'   => __( 'CEADI por WhatsApp', 'cead' ),
		'sub'   => __( 'Sin instalar una aplicación adicional', 'cead' ),
		'txt'   => __( 'Se puede escribir una pregunta normal, como «¿qué clases tengo mañana?». CEADI entiende texto y notas de voz, y responde con información del sistema.', 'cead' ),
		'quien' => __( 'Para quienes prefieren usar WhatsApp', 'cead' ),
	],
];
?>
<section id="caras" class="proy-section proy-section--caras">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( 'Formas de acceso', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Un solo sistema, con tres formas de entrar.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'El panel, la aplicación y CEADI trabajan con la misma información. Si Dirección publica un comunicado desde WhatsApp, el estudiante puede verlo en su panel sin que nadie tenga que copiarlo a otro lugar.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'El sistema puede usarse de tres formas: desde el panel web, desde la aplicación del celular o por WhatsApp mediante CEADI. Las tres formas consultan la misma información. Si se publica un comunicado desde una de ellas, aparece también en las demás sin necesidad de copiarlo.', 'cead' ),
					'caras.mp3'
				);
				?>
			</div>
		</header>

		<ul class="proy-caras-grid">
			<?php foreach ( $caras as $i => $c ) : ?>
				<li class="reveal proy-cara" style="--card-color:<?php echo esc_attr( $c['color'] ); ?>">
					<?php get_template_part( 'template-parts/proyecto/svg/cara', null, [ 'tipo' => $c['clave'] ] ); ?>
					<div class="proy-cara-body">
						<p class="proy-cara-sub"><?php echo esc_html( $c['sub'] ); ?></p>
						<h3 class="proy-cara-tit"><?php echo esc_html( $c['tit'] ); ?></h3>
						<p class="proy-cara-txt"><?php echo esc_html( $c['txt'] ); ?></p>
						<p class="proy-cara-quien">
							<span class="proy-cara-quien-lbl"><?php esc_html_e( 'Para', 'cead' ); ?></span>
							<?php echo esc_html( $c['quien'] ); ?>
						</p>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php /* El puente: lo que ata las tres caras entre sí. */ ?>
		<p class="reveal proy-caras-nota">
			<span class="proy-caras-nota-mark" aria-hidden="true"></span>
			<?php esc_html_e( 'Cada dato se carga una sola vez. Las tres formas de acceso consultan la misma base, lo que reduce duplicaciones y evita versiones diferentes de una misma información.', 'cead' ); ?>
		</p>
	</div>
</section>
