<?php
/**
 * Sección 8 — Lo que pregunta una dirección.
 *
 * Un director no pregunta qué framework usa. Pregunta quién puede ver las notas
 * de su hijo, qué pasa si alguien entra donde no debe, y dónde están los datos.
 * Esta sección contesta eso y nada más.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$puntos = [
	[
		'tit' => __( 'No hay registro abierto', 'cead' ),
		'txt' => __( 'Nadie se crea una cuenta solo. Se entra por invitación de secretaría, y cada invitación sirve una vez y vence.', 'cead' ),
	],
	[
		'tit' => __( 'Cada quien ve lo suyo', 'cead' ),
		'txt' => __( 'Los permisos se verifican en cada acción, no solo al mostrar el menú. Esconder un botón no alcanza: si alguien fuerza la dirección igual se le dice que no.', 'cead' ),
	],
	[
		'tit' => __( 'Las denuncias van cifradas', 'cead' ),
		'txt' => __( 'Un reporte por CEADI se guarda cifrado y con código de seguimiento. Se puede mandar anónimo, y anónimo quiere decir anónimo.', 'cead' ),
	],
	[
		'tit' => __( 'Queda registro de lo importante', 'cead' ),
		'txt' => __( 'Quién cargó una nota, quién mandó un comunicado, quién cambió un rol. No para vigilar a nadie: para poder reconstruir qué pasó si algo sale mal.', 'cead' ),
	],
	[
		'tit' => __( 'Los datos son del colegio', 'cead' ),
		'txt' => __( 'Todo vive en el servidor de la institución. No hay una empresa intermediaria con acceso, ni una suscripción que pueda cortarse.', 'cead' ),
	],
	[
		'tit' => __( 'El bot no se deja convencer', 'cead' ),
		'txt' => __( 'CEADI identifica a la persona por su número registrado. Decirle «soy el director» en el chat no cambia absolutamente nada.', 'cead' ),
	],
];
?>
<section id="seguridad" class="proy-section proy-section--seg">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( '— Lo que suele preguntarse', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( '¿Y quién puede ver qué?', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'Es la primera pregunta que hace cualquier institución, y con razón: acá hay datos de menores de edad. Estas seis respuestas son las que más importan.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'Sobre seguridad: no hay registro abierto, se entra solo por invitación. Los permisos se verifican en cada acción, no solo al dibujar el menú. Las denuncias se guardan cifradas y pueden ser anónimas de verdad. Queda registro de las acciones importantes. Los datos viven en el servidor del colegio, sin empresa intermediaria. Y el bot identifica a la persona por su número: decirle que sos el director no cambia nada.', 'cead' ),
					'seguridad.mp3'
				);
				?>
			</div>
		</header>

		<ul class="proy-seg-grid">
			<?php foreach ( $puntos as $p ) : ?>
				<li class="reveal proy-seg-card">
					<span class="proy-seg-check" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m5 13 4 4L19 7"/></svg>
					</span>
					<h3 class="proy-seg-tit"><?php echo esc_html( $p['tit'] ); ?></h3>
					<p class="proy-seg-txt"><?php echo esc_html( $p['txt'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
