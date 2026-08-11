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
		'txt' => __( 'Las cuentas se crean mediante una invitación de secretaría. Cada invitación puede usarse una sola vez y tiene una fecha de vencimiento.', 'cead' ),
	],
	[
		'tit' => __( 'Cada persona accede solo a lo que le corresponde', 'cead' ),
		'txt' => __( 'Los permisos se comprueban cada vez que alguien intenta realizar una acción. Por eso, ocultar una opción del menú no es la única medida de seguridad: el sistema también bloquea el acceso cuando la cuenta no tiene autorización.', 'cead' ),
	],
	[
		'tit' => __( 'Los reportes se guardan cifrados', 'cead' ),
		'txt' => __( 'Los reportes enviados mediante CEADI se guardan cifrados y reciben un código de seguimiento. También pueden enviarse de forma anónima.', 'cead' ),
	],
	[
		'tit' => __( 'Las acciones importantes quedan registradas', 'cead' ),
		'txt' => __( 'El sistema registra acciones como cargar una nota, enviar un comunicado o cambiar un rol. Este historial permite revisar qué ocurrió si aparece un error o una situación que necesita aclararse.', 'cead' ),
	],
	[
		'tit' => __( 'Los datos permanecen bajo control del colegio', 'cead' ),
		'txt' => __( 'La información se almacena en el servidor de la institución. El funcionamiento del sistema no depende de una empresa intermediaria ni de una suscripción externa.', 'cead' ),
	],
	[
		'tit' => __( 'La identidad no se cambia desde el chat', 'cead' ),
		'txt' => __( 'CEADI reconoce a cada persona por su número registrado y aplica los permisos de esa cuenta. Lo que alguien diga sobre su identidad dentro del chat no modifica esos permisos.', 'cead' ),
	],
];
?>
<section id="seguridad" class="proy-section proy-section--seg">
	<div class="container">
		<header class="proy-head">
			<p class="reveal eyebrow"><?php esc_html_e( 'Seguridad y acceso', 'cead' ); ?></p>
			<h2 class="reveal proy-h2"><?php esc_html_e( '¿Quién puede ver cada dato?', 'cead' ); ?></h2>
			<div class="reveal proy-head-aside">
				<p class="proy-lead"><?php esc_html_e( 'Es una pregunta esencial porque el sistema trabaja con información de estudiantes, incluidos menores de edad. Estas son las medidas principales que determinan quién puede entrar y qué puede consultar.', 'cead' ); ?></p>
				<?php
				cead_audio_button(
					__( 'En materia de seguridad, las cuentas se crean por invitación y cada acción comprueba los permisos del usuario. Los reportes se guardan cifrados y pueden enviarse de forma anónima. Las acciones importantes quedan registradas. Los datos permanecen en el servidor del colegio y CEADI identifica a cada persona por su número registrado.', 'cead' ),
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
