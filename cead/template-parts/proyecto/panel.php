<?php
/**
 * Sección 4 — Recorrido por el panel.
 *
 * Las pantallas que existen de verdad, agrupadas por para qué sirven. La lista
 * sale del sidebar real del panel (`cead-acad/templates/panel/partials/sidebar.php`),
 * no de una wishlist.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$grupos = [
	[
		'tit' => __( 'Todos los días', 'cead' ),
		'txt' => __( 'Lo que un alumno abre sin pensarlo.', 'cead' ),
		'items' => [
			[ '🏠', __( 'Inicio', 'cead' ),      __( 'Las clases de hoy, los próximos eventos y los últimos comunicados, en una pantalla.', 'cead' ) ],
			[ '📣', __( 'Comunicados', 'cead' ), __( 'Solo los que le tocan. Al abrirlo queda marcado como leído — y dirección ve cuántos lo leyeron.', 'cead' ) ],
			[ '📚', __( 'Horarios', 'cead' ),    __( 'El horario semanal del curso, con materia, hora y docente.', 'cead' ) ],
			[ '📅', __( 'Calendario', 'cead' ),  __( 'Vista mensual o agenda. Se puede suscribir desde Google Calendar o el iPhone.', 'cead' ) ],
		],
	],
	[
		'tit' => __( 'Lo académico', 'cead' ),
		'txt' => __( 'Lo que antes había que ir a preguntar.', 'cead' ),
		'items' => [
			[ '📊', __( 'Boletín', 'cead' ),  __( 'Las notas por materia y etapa, en la escala del colegio. Cada quien ve solo las suyas.', 'cead' ) ],
			[ '📝', __( 'Mis tareas', 'cead' ), __( 'Con fecha de entrega y prioridad. Se marcan como hechas y se puede adjuntar el trabajo.', 'cead' ) ],
			[ '📁', __( 'Recursos', 'cead' ),  __( 'Guías, modelos de examen y apuntes, con buscador, filtros y favoritos.', 'cead' ) ],
		],
	],
	[
		'tit' => __( 'Los trámites', 'cead' ),
		'txt' => __( 'Lo que antes era una fila en secretaría.', 'cead' ),
		'items' => [
			[ '🪪', __( 'Mi carné', 'cead' ),        __( 'Carné digital con QR. Quien lo escanea llega a una página que confirma que es válido.', 'cead' ) ],
			[ '✉️', __( 'Escribir al CEAD', 'cead' ), __( 'Un mensaje directo a dirección, consejo o administración, que cae en su buzón.', 'cead' ) ],
			[ '🗳️', __( 'Encuestas', 'cead' ),       __( 'Las que le corresponden, con resultados que dirección ve en vivo.', 'cead' ) ],
		],
	],
	[
		'tit' => __( 'Para quien gestiona', 'cead' ),
		'txt' => __( 'Pantallas que aparecen solo si el rol las habilita.', 'cead' ),
		'items' => [
			[ '⭐', __( 'Dirección', 'cead' ),  __( 'Cuánta gente hay, cuánto se publica y —lo importante— cuántos leyeron y cuántos respondieron.', 'cead' ) ],
			[ '💼', __( 'Secretaría', 'cead' ), __( 'Invitaciones, cursos, borradores y el historial de importaciones con sus errores.', 'cead' ) ],
			[ '📋', __( 'Delegado', 'cead' ),   __( 'El tablero de tareas del curso, separadas en pendientes y hechas.', 'cead' ) ],
		],
	],
];
?>
<section id="panel" class="proy-section proy-section--panel">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( '— Por dentro', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Catorce pantallas, y cada una se gana el lugar.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'El menú no es igual para todos: se arma según el rol de quien entró. Un alumno no ve la pantalla de secretaría, y no porque esté escondida — sencillamente no existe para él.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'El panel tiene catorce pantallas, agrupadas en cuatro cosas: lo de todos los días, como el inicio, los comunicados y el horario; lo académico, como el boletín y las tareas; los trámites, como el carné digital; y las pantallas de gestión, que aparecen solo si el rol las habilita. Un alumno no ve la pantalla de secretaría: no está escondida, no existe para él.', 'cead' ),
					'panel.mp3'
				);
				?>
			</div>
		</header>

		<div class="proy-panel-layout">
			<?php get_template_part( 'template-parts/proyecto/svg/pantalla' ); ?>

			<div class="proy-panel-grupos">
				<?php foreach ( $grupos as $g ) : ?>
					<div class="reveal proy-grupo">
						<div class="proy-grupo-head">
							<h3 class="proy-grupo-tit"><?php echo esc_html( $g['tit'] ); ?></h3>
							<p class="proy-grupo-txt"><?php echo esc_html( $g['txt'] ); ?></p>
						</div>
						<ul class="proy-grupo-list">
							<?php foreach ( $g['items'] as $it ) : ?>
								<li>
									<span class="proy-grupo-ico" aria-hidden="true"><?php echo esc_html( $it[0] ); ?></span>
									<span class="proy-grupo-body">
										<strong><?php echo esc_html( $it[1] ); ?></strong>
										<?php echo esc_html( $it[2] ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>
