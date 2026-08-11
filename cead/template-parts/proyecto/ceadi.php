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
	[ 'yo',   __( '¿Qué clases tengo mañana?', 'cead' ), '' ],
	[ 'bot',  __( 'Mañana, martes, tienes:', 'cead' ), __( '07:00 Matemática · 08:40 Ciencias Básicas · 10:00 Guaraní Ñe\'ẽ', 'cead' ) ],
	[ 'yo',   __( 'Nota de voz', 'cead' ), 'voz' ],
	[ 'bot',  __( 'Entendí: «¿me falta entregar algo?».', 'cead' ), __( 'Sí. Tienes 2 tareas pendientes. La más próxima vence el jueves.', 'cead' ) ],
];

$capacidades = [
	[ __( 'Puede leer documentos', 'cead' ),   __( 'Si se envía un PDF o un archivo de Word, CEADI puede leerlo y responder preguntas sobre su contenido.', 'cead' ) ],
	[ __( 'Puede cargar notas', 'cead' ),      __( 'Un docente puede indicar una calificación por mensaje. Antes de guardarla, CEADI muestra los datos y solicita confirmación.', 'cead' ) ],
	[ __( 'Puede leer una planilla', 'cead' ),  __( 'El docente puede enviar su archivo de Excel habitual. CEADI identifica las columnas, muestra qué datos encontró y solo guarda la información después de una confirmación. El archivo original no queda almacenado.', 'cead' ) ],
	[ __( 'Puede recibir reportes', 'cead' ),  __( 'Los reportes pueden enviarse de forma anónima o confidencial. Se guardan cifrados, reciben un código de seguimiento y llegan al buzón correspondiente dentro del panel.', 'cead' ) ],
];
?>
<section id="ceadi" class="proy-section proy-section--ceadi">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( 'CEADI', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( 'Con CEADI basta con escribir una pregunta de forma normal.', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'No hace falta memorizar comandos ni números de menú. Se escribe la consulta y CEADI responde. Quien prefiera un menú tradicional también puede escribir «menú» para verlo.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'CEADI permite consultar el sistema con preguntas normales. Puede responder sobre horarios y tareas, entender notas de voz, leer documentos y ayudar a cargar información. También puede procesar una planilla de Excel y recibir reportes cifrados. Antes de guardar datos importantes, pide una confirmación.', 'cead' ),
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
						<dt><?php echo 'yo' === $m[0] ? esc_html__( 'Estudiante:', 'cead' ) : esc_html__( 'CEADI:', 'cead' ); ?></dt>
						<dd>
							<?php
							echo 'voz' === $m[2]
								? esc_html__( 'Envía una nota de voz de cuatro segundos.', 'cead' )
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
			<?php esc_html_e( 'CEADI atiende únicamente a números registrados. La cuenta y los permisos se identifican por el número de teléfono, por lo que una persona no puede acceder a datos ajenos simplemente diciendo que es otra.', 'cead' ); ?>
		</p>
	</div>
</section>
