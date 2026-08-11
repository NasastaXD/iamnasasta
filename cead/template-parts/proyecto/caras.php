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
		'sub'   => __( 'Desde cualquier navegador', 'cead' ),
		'txt'   => __( 'Comunicados, horario, calendario, tareas, recursos, boletín y carné digital. Cada persona ve lo que le corresponde según su rol: un alumno no ve lo mismo que secretaría.', 'cead' ),
		'quien' => __( 'Toda la comunidad', 'cead' ),
	],
	[
		'clave' => 'app',
		'color' => 'var(--acc-blue)',
		'tit'   => __( 'La app', 'cead' ),
		'sub'   => __( 'Se instala desde el navegador', 'cead' ),
		'txt'   => __( 'El mismo panel, pero en la pantalla de inicio del celular, con su ícono. No se baja de ninguna tienda y lo ya visitado sigue abriéndose sin internet.', 'cead' ),
		'quien' => __( 'Alumnado y familias', 'cead' ),
	],
	[
		'clave' => 'ceadi',
		'color' => 'var(--acc-orange)',
		'tit'   => __( 'CEADI, por WhatsApp', 'cead' ),
		'sub'   => __( 'Sin instalar nada', 'cead' ),
		'txt'   => __( 'Se le escribe como a una persona: «¿qué clases tengo mañana?». Entiende lenguaje natural, escucha notas de voz y responde con datos reales del sistema.', 'cead' ),
		'quien' => __( 'Quien prefiere no entrar a la web', 'cead' ),
	],
];
?>
<section id="caras" class="proy-section proy-section--caras">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( '— Cómo se usa', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Un solo sistema. Tres puertas de entrada.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'Esto es lo único que hay que entender de todo el proyecto: no son tres sistemas distintos que hay que mantener sincronizados. Es uno solo. Un comunicado que dirección manda desde WhatsApp aparece en el panel del alumno en el mismo momento — y al revés.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'Hay tres formas de usar el sistema: el panel web, la app del celular, y CEADI por WhatsApp. Lo importante es que no son tres sistemas distintos, es uno solo con tres puertas. Un comunicado que dirección manda desde WhatsApp aparece en el panel del alumno en el mismo momento, y al revés.', 'cead' ),
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
			<?php esc_html_e( 'Un mismo dato, una sola vez. Las tres puertas leen y escriben en la misma base: no hay nada que copiar de un lado al otro, y por lo tanto nada que se desactualice.', 'cead' ); ?>
		</p>
	</div>
</section>
