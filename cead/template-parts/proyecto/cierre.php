<?php
/**
 * Sección 9 — A dónde ir después.
 *
 * Tres salidas según quién esté leyendo: quien quiere usarlo, quien quiere el
 * detalle, y quien quiere el detalle técnico. Los enlaces a la wiki solo se
 * imprimen si el plugin está activo — si no, serían un 404.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$salidas = [];

$salidas[] = [
	'url'  => home_url( '/panel' ),
	'tit'  => __( 'Entrar al panel', 'cead' ),
	'txt'  => __( 'Si ya tenés cuenta, es por acá.', 'cead' ),
	'cta'  => true,
];

if ( class_exists( 'Cead_Acad_Wiki' ) ) {
	$salidas[] = [
		'url' => home_url( '/wiki' ),
		'tit' => __( 'La guía completa', 'cead' ),
		'txt' => __( 'Pantalla por pantalla, con todo lo que hace CEADI y las preguntas frecuentes.', 'cead' ),
	];
	$salidas[] = [
		'url' => home_url( '/wiki/tecnica' ),
		'tit' => __( 'La documentación técnica', 'cead' ),
		'txt' => __( 'Arquitectura, modelo de datos, permisos y despliegue. Para quien va a mantenerlo.', 'cead' ),
	];
}
?>
<section id="cierre" class="proy-section proy-section--cierre">
	<div class="container">
		<div class="proy-cierre-inner">
			<p class="reveal eyebrow proy-cierre-eyebrow"><?php esc_html_e( '— Para seguir', 'cead' ); ?></p>
			<h2 class="reveal proy-h2 proy-h2--inv">
				<?php
				printf(
					/* translators: %s va resaltado. */
					esc_html__( 'Hecho acá, para %s.', 'cead' ),
					'<span class="proy-accent">' . esc_html__( 'este colegio', 'cead' ) . '</span>'
				);
				?>
			</h2>
			<p class="reveal proy-cierre-body">
				<?php esc_html_e( 'No es un sistema genérico al que hubo que adaptarse. Los roles, los permisos, las etapas y la escala de notas son los del CEAD porque se construyó mirando cómo trabaja el CEAD.', 'cead' ); ?>
			</p>

			<ul class="proy-cierre-salidas">
				<?php foreach ( $salidas as $s ) : ?>
					<li class="reveal proy-salida<?php echo ! empty( $s['cta'] ) ? ' proy-salida--cta' : ''; ?>">
						<a href="<?php echo esc_url( $s['url'] ); ?>">
							<span class="proy-salida-tit"><?php echo esc_html( $s['tit'] ); ?></span>
							<span class="proy-salida-txt"><?php echo esc_html( $s['txt'] ); ?></span>
							<span class="proy-salida-flecha" aria-hidden="true">→</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</section>
