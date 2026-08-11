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
		'tit' => __( 'Uso diario', 'cead' ),
		'txt' => __( 'La información que un estudiante suele necesitar con más frecuencia.', 'cead' ),
		'items' => [
			[ '🏠', __( 'Inicio', 'cead' ),      __( 'Muestra las clases del día, los próximos eventos y los comunicados más recientes.', 'cead' ) ],
			[ '📣', __( 'Comunicados', 'cead' ), __( 'Cada persona recibe solo los comunicados que le corresponden. Al abrir uno, queda registrado como leído para que Dirección pueda conocer su alcance.', 'cead' ) ],
			[ '📚', __( 'Horarios', 'cead' ),    __( 'Muestra el horario semanal del curso con la materia, la hora y el docente de cada clase.', 'cead' ) ],
			[ '📅', __( 'Calendario', 'cead' ),  __( 'Puede verse por mes o como agenda. También puede añadirse a Google Calendar o al calendario del iPhone.', 'cead' ) ],
		],
	],
	[
		'tit' => __( 'Información académica', 'cead' ),
		'txt' => __( 'Datos que pueden consultarse directamente, sin tener que solicitarlos por separado.', 'cead' ),
		'items' => [
			[ '📊', __( 'Boletín', 'cead' ),  __( 'Muestra las calificaciones por materia y etapa según la escala del colegio. Cada estudiante accede únicamente a sus propios resultados.', 'cead' ) ],
			[ '📝', __( 'Mis tareas', 'cead' ), __( 'Cada tarea muestra su fecha de entrega y prioridad. Puede marcarse como terminada y, cuando corresponde, permite adjuntar un archivo.', 'cead' ) ],
			[ '📁', __( 'Recursos', 'cead' ),  __( 'Reúne guías, modelos de examen y apuntes. Incluye búsqueda, filtros y favoritos para encontrarlos con mayor facilidad.', 'cead' ) ],
		],
	],
	[
		'tit' => __( 'Trámites y gestiones', 'cead' ),
		'txt' => __( 'Funciones que permiten resolver desde el sistema gestiones que antes requerían acudir a secretaría.', 'cead' ),
		'items' => [
			[ '🪪', __( 'Mi carné', 'cead' ),        __( 'Carné digital con código QR. Al escanearlo, se abre una página que permite comprobar si el carné es válido.', 'cead' ) ],
			[ '✉️', __( 'Contactar al CEAD', 'cead' ), __( 'Permite enviar un mensaje directo a Dirección, al Consejo o a Administración desde el propio panel.', 'cead' ) ],
			[ '🗳️', __( 'Encuestas', 'cead' ),       __( 'Muestra las encuestas asignadas a cada persona. Dirección puede consultar los resultados a medida que se reciben.', 'cead' ) ],
		],
	],
	[
		'tit' => __( 'Funciones de gestión', 'cead' ),
		'txt' => __( 'Estas pantallas aparecen únicamente para los roles que necesitan utilizarlas.', 'cead' ),
		'items' => [
			[ '⭐', __( 'Dirección', 'cead' ),  __( 'Resume la cantidad de usuarios, publicaciones, lecturas y respuestas para ofrecer una visión general del uso del sistema.', 'cead' ) ],
			[ '💼', __( 'Secretaría', 'cead' ), __( 'Permite gestionar invitaciones, cursos, borradores e importaciones, además de revisar los errores que hayan ocurrido durante una carga.', 'cead' ) ],
			[ '📋', __( 'Delegado', 'cead' ),   __( 'Muestra las tareas del curso y las separa entre pendientes y realizadas.', 'cead' ) ],
		],
	],
];
?>
<section id="panel" class="proy-section proy-section--panel">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( 'Organización del panel', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Las catorce pantallas se organizan por función para que cada persona encuentre solo lo que necesita.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'El menú cambia según el rol. Un estudiante, por ejemplo, no recibe acceso a las funciones de secretaría porque esas funciones no forman parte de su cuenta.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'El panel reúne catorce pantallas. Algunas sirven para el uso diario, como Inicio, Comunicados y Horarios. Otras reúnen información académica, trámites o funciones de gestión. Cada cuenta ve únicamente las pantallas que necesita según su rol.', 'cead' ),
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
