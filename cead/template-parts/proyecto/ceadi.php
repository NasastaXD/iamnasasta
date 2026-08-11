<?php
/**
 * Sección 5 — CEADI en acción.
 *
 * Un chat que se escribe solo. Es la forma más rápida de mostrar «entiende
 * lenguaje natural» sin tener que explicarlo: se ve.
 *
 * Los mensajes son reales en el sentido de que son cosas que el bot de verdad
 * hace hoy (horario, notas por chat, nota de voz). Nada inventado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$chat = [
	[ 'yo',   __( '¿qué clases tengo mañana?', 'cead' ), '' ],
	[ 'bot',  __( 'Mañana (martes) tenés:', 'cead' ), __( '07:00 Matemática · 08:40 Ciencias Básicas · 10:00 Guaraní Ñe\'ẽ', 'cead' ) ],
	[ 'yo',   __( '🎤 Nota de voz', 'cead' ), 'voz' ],
	[ 'bot',  __( 'Escuché: «¿me falta entregar algo?».', 'cead' ), __( 'Sí, tenés 2 tareas pendientes. La más próxima vence el jueves.', 'cead' ) ],
];

$capacidades = [
	[ __( 'Lee documentos', 'cead' ),   __( 'Le mandás un PDF o un Word y lo lee para responderte sobre eso.', 'cead' ) ],
	[ __( 'Carga notas', 'cead' ),      __( 'Un docente dice «ponele 4 a Pérez en Matemática» y CEADI confirma antes de guardar.', 'cead' ) ],
	[ __( 'Lee tu planilla', 'cead' ),  __( 'Se le manda el Excel de siempre: adivina las columnas, muestra qué va a cargar y recién ahí guarda. El archivo no se almacena.', 'cead' ) ],
	[ __( 'Recibe reportes', 'cead' ),  __( 'Anónimos o confidenciales, cifrados, con código de seguimiento. Caen en el buzón del panel.', 'cead' ) ],
];
?>
<section id="ceadi" class="proy-section proy-section--ceadi">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( '— El bot', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'A CEADI se le habla como a una persona.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'No hay que aprenderse comandos ni números de menú: se escribe la pregunta y listo. Y si alguien prefiere el menú de siempre, también está — se escribe «menú» y aparece.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'A CEADI se le escribe como a una persona. Por ejemplo: qué clases tengo mañana. No hay que aprenderse comandos. También entiende notas de voz: las escucha y contesta. Y hace cosas más grandes: lee documentos, carga notas por chat, procesa la planilla de Excel del docente y recibe reportes cifrados.', 'cead' ),
					'ceadi.mp3'
				);
				?>
			</div>
		</header>

		<div class="proy-ceadi-layout">
			<?php
			/*
			 * El chat. `aria-hidden` porque justo debajo va el mismo diálogo en
			 * texto plano para lector de pantalla: la animación de tipeo, leída
			 * mensaje por mensaje mientras aparece, es un desastre.
			 */
			?>
			<div class="reveal proy-chat" aria-hidden="true">
				<div class="proy-chat-top">
					<span class="proy-chat-avatar">C</span>
					<span class="proy-chat-nombre"><?php esc_html_e( 'CEADI', 'cead' ); ?></span>
					<span class="proy-chat-estado"><?php esc_html_e( 'en línea', 'cead' ); ?></span>
				</div>
				<ol class="proy-chat-hilo">
					<?php foreach ( $chat as $i => $m ) : ?>
						<li class="proy-msg proy-msg--<?php echo esc_attr( $m[0] ); ?><?php echo 'voz' === $m[2] ? ' proy-msg--voz' : ''; ?>"
						    style="--paso:<?php echo (int) $i; ?>">
							<?php if ( 'voz' === $m[2] ) : ?>
								<span class="proy-msg-onda" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
								<span class="proy-msg-dur">0:04</span>
							<?php else : ?>
								<span class="proy-msg-txt"><?php echo esc_html( $m[1] ); ?></span>
								<?php if ( $m[2] ) : ?>
									<span class="proy-msg-extra"><?php echo esc_html( $m[2] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>

			<?php // El mismo diálogo, accesible y sin animación. ?>
			<div class="sr-only">
				<h3><?php esc_html_e( 'Ejemplo de conversación con CEADI', 'cead' ); ?></h3>
				<dl>
					<?php foreach ( $chat as $m ) : ?>
						<dt><?php echo 'yo' === $m[0] ? esc_html__( 'Alumno:', 'cead' ) : esc_html__( 'CEADI:', 'cead' ); ?></dt>
						<dd>
							<?php
							echo 'voz' === $m[2]
								? esc_html__( 'Manda una nota de voz de cuatro segundos.', 'cead' )
								: esc_html( trim( $m[1] . ' ' . $m[2] ) );
							?>
						</dd>
					<?php endforeach; ?>
				</dl>
			</div>

			<ul class="proy-ceadi-caps">
				<?php foreach ( $capacidades as $c ) : ?>
					<li class="reveal proy-cap">
						<h3 class="proy-cap-tit"><?php echo esc_html( $c[0] ); ?></h3>
						<p class="proy-cap-txt"><?php echo esc_html( $c[1] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<p class="reveal proy-ceadi-nota">
			<span class="proy-caras-nota-mark" aria-hidden="true"></span>
			<?php esc_html_e( 'CEADI solo atiende números registrados, y nunca le muestra a alguien los datos de otra persona. Quién sos lo define tu número, no lo que digas en el chat.', 'cead' ); ?>
		</p>
	</div>
</section>
