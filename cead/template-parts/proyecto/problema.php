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
		'tit'  => __( 'Una circular que no siempre llega', 'cead' ),
		'txt'  => __( 'El papel puede perderse antes de llegar a casa. Después, resulta difícil saber quién recibió la información y quién no.', 'cead' ),
	],
	[
		'n'    => '02',
		'icono'=> 'chats',
		'color'=> 'var(--acc-blue)',
		'tit'  => __( 'La información repartida en varios chats', 'cead' ),
		'txt'  => __( 'En los grupos de WhatsApp, un aviso importante puede quedar entre muchos otros mensajes y pasar desapercibido.', 'cead' ),
	],
	[
		'n'    => '03',
		'icono'=> 'cuaderno',
		'color'=> 'var(--brand)',
		'tit'  => __( 'Las notas, fuera de un sistema común', 'cead' ),
		'txt'  => __( 'Consultar el rendimiento de un estudiante puede requerir preguntar, esperar una respuesta y revisar datos que están en lugares distintos.', 'cead' ),
	],
];
?>
<section id="problema" class="proy-section proy-section--problema">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( 'El punto de partida', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'El colegio ya tenía formas de comunicarse, pero la información quedaba repartida entre papel, mensajes y registros separados.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'CEAD Académico reúne esa información en un mismo sistema. Así reduce tareas repetidas y facilita que estudiantes, familias y personal encuentren lo que necesitan.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'Antes de la plataforma, mucha información quedaba repartida entre circulares impresas, grupos de WhatsApp y registros separados. Eso hacía más difícil encontrar un aviso, comprobar si había llegado o consultar un dato académico. CEAD Académico reúne esa información para que sea más fácil acceder a ella.', 'cead' ),
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
