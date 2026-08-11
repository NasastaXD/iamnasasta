<?php
/**
 * Sección 2 — Por qué hacía falta.
 *
 * Antes de mostrar nada, nombrar el problema con escenas que cualquiera del
 * colegio reconoce. Sin esto, todo lo que viene después parece tecnología
 * porque sí.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$escenas = [
	[
		'n'    => '01',
		'icono'=> 'papel',
		'color'=> 'var(--acc-orange)',
		'tit'  => __( 'La circular que nunca llegó', 'cead' ),
		'txt'  => __( 'Se imprime, se reparte, se pierde en el fondo de la mochila. Nadie sabe quién la leyó de verdad.', 'cead' ),
	],
	[
		'n'    => '02',
		'icono'=> 'chats',
		'color'=> 'var(--acc-blue)',
		'tit'  => __( 'Doce grupos de WhatsApp', 'cead' ),
		'txt'  => __( 'La información importante queda entre memes y buenos días. Lo urgente y lo trivial pesan igual.', 'cead' ),
	],
	[
		'n'    => '03',
		'icono'=> 'cuaderno',
		'color'=> 'var(--brand)',
		'tit'  => __( 'Las notas, en un cuaderno', 'cead' ),
		'txt'  => __( 'Para saber cómo va un alumno hay que preguntar, esperar y confiar en que alguien sumó bien.', 'cead' ),
	],
];
?>
<section id="problema" class="proy-section proy-section--problema">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( '— El punto de partida', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'El colegio ya se comunicaba. El problema era cómo.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'Nada de esto es culpa de nadie: es lo que pasa cuando la información vive en papel y en chats sueltos. La plataforma no reemplaza a las personas, les saca el trabajo de encima.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'Antes de la plataforma, el colegio se comunicaba con circulares en papel, que se perdían; con grupos de WhatsApp, donde lo urgente y lo trivial pesaban igual; y con las notas anotadas en cuadernos. Nada de eso es culpa de nadie: es lo que pasa cuando la información vive dispersa.', 'cead' ),
					'problema.mp3'
				);
				?>
			</div>
		</header>

		<ul class="proy-problema-grid">
			<?php foreach ( $escenas as $e ) : ?>
				<li class="reveal proy-problema-card" style="--card-color:<?php echo esc_attr( $e['color'] ); ?>">
					<span class="proy-problema-num" aria-hidden="true"><?php echo esc_html( $e['n'] ); ?></span>
					<?php get_template_part( 'template-parts/proyecto/svg/escena', null, [ 'tipo' => $e['icono'] ] ); ?>
					<h3 class="proy-problema-tit"><?php echo esc_html( $e['tit'] ); ?></h3>
					<p class="proy-problema-txt"><?php echo esc_html( $e['txt'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
