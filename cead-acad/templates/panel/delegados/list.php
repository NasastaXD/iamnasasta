<?php
/**
 * /panel/delegados: quiénes son los delegados y cómo escribirles.
 *
 * De delegado para arriba. La idea es que un delegado pueda coordinar con el
 * delegado de otro curso sin pasar por secretaría, y que docentes y dirección
 * tengan el contacto a mano cuando necesitan avisar algo de un curso puntual.
 *
 * El gate va acá y no solo en el menú: esconder el ítem no protege nada porque
 * la URL se escribe a mano, y esta pantalla muestra teléfonos.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( 'cead_acad_view_delegates' ) ) {
	wp_die( esc_html__( 'Esta sección es para delegados y personal del colegio.', 'cead-acad' ), 403 );
}

$user       = wp_get_current_user();
$delegados  = Cead_Acad_Courses_Roster::delegates();

// Buscador: con un delegado por curso la lista crece con el colegio, y a la
// tercera pantalla de scroll ya no sirve para encontrar a nadie.
$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
if ( '' !== $q ) {
	$aguja     = mb_strtolower( $q );
	$delegados = array_values( array_filter( $delegados, static function ( $d ) use ( $aguja ) {
		return false !== mb_strpos( mb_strtolower( $d['nombre'] . ' ' . $d['curso'] ), $aguja );
	} ) );
}

$page_title = __( 'Delegados', 'cead-acad' );

$body = function () use ( $delegados, $q, $user ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Contactos', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Delegados por curso', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Quién representa a cada curso y cómo escribirle. Escribile solo por temas del colegio.', 'cead-acad' ); ?></p>

		<form class="cead-acad-search-form" method="get" action="<?php echo esc_url( cead_acad_url( 'panel/delegados' ) ); ?>">
			<label class="cead-acad-sr-only" for="cead-acad-q-deleg"><?php esc_html_e( 'Buscar delegado o curso', 'cead-acad' ); ?></label>
			<input type="search" id="cead-acad-q-deleg" name="q" value="<?php echo esc_attr( $q ); ?>"
			       placeholder="<?php esc_attr_e( 'Buscar por nombre o curso…', 'cead-acad' ); ?>">
			<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Buscar', 'cead-acad' ); ?></button>
		</form>

		<?php if ( ! $delegados ) : ?>
			<div class="cead-acad-card cead-acad-card--empty">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Sin resultados', 'cead-acad' ); ?></span>
				<h3><?php echo '' !== $q ? esc_html__( 'Nadie coincide con esa búsqueda', 'cead-acad' ) : esc_html__( 'Todavía no hay delegados asignados', 'cead-acad' ); ?></h3>
				<p>
					<?php
					echo '' !== $q
						? esc_html__( 'Probá con otro nombre o con el curso.', 'cead-acad' )
						: esc_html__( 'El delegado se asigna en la ficha de cada curso, desde wp-admin.', 'cead-acad' );
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="cead-acad-deleg-grid">
				<?php foreach ( $delegados as $d ) :
					$wa     = cead_acad_wa_link( $d['telefono'] );
					$soy_yo = (int) $d['user_id'] === (int) $user->ID;
					// Iniciales: no hay foto de perfil en el sistema, y un avatar
					// vacío se ve peor que dos letras.
					$ini = mb_strtoupper( mb_substr( trim( $d['nombre'] ), 0, 1 ) );
					$partes = preg_split( '/\s+/', trim( $d['nombre'] ) );
					if ( count( $partes ) > 1 ) { $ini .= mb_strtoupper( mb_substr( end( $partes ), 0, 1 ) ); }
				?>
					<article class="cead-acad-deleg<?php echo $d['suspendido'] ? ' is-suspendido' : ''; ?>">
						<div class="cead-acad-deleg-cab">
							<span class="cead-acad-deleg-ini" aria-hidden="true"><?php echo esc_html( $ini ); ?></span>
							<div class="cead-acad-deleg-quien">
								<h3 class="cead-acad-deleg-nombre">
									<?php echo esc_html( $d['nombre'] ); ?>
									<?php if ( $soy_yo ) : ?><span class="cead-acad-deleg-vos"><?php esc_html_e( 'vos', 'cead-acad' ); ?></span><?php endif; ?>
								</h3>
								<p class="cead-acad-deleg-curso">
									<?php echo esc_html( $d['curso'] ); ?>
									<?php if ( $d['turno'] ) : ?><span class="cead-acad-deleg-turno"><?php echo esc_html( $d['turno'] ); ?></span><?php endif; ?>
								</p>
							</div>
						</div>

						<?php if ( $d['suspendido'] ) : ?>
							<p class="cead-acad-deleg-aviso"><?php esc_html_e( 'Cuenta suspendida', 'cead-acad' ); ?></p>
						<?php endif; ?>

						<?php
						/*
						 * Sin teléfono cargado no se dibuja el botón. Un «Escribir»
						 * que abre WhatsApp en la nada es peor que no ofrecerlo:
						 * quien lo toca cree que mandó el mensaje.
						 */
						if ( $wa && ! $soy_yo ) : ?>
							<a class="cead-acad-deleg-wa" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener">
								<svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.87 9.87 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23a8.2 8.2 0 0 1 8.24 8.24c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.22.25-.85.84-.85 2.03 0 1.2.87 2.35.99 2.51.12.17 1.71 2.62 4.15 3.67.58.25 1.03.4 1.39.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
								<?php esc_html_e( 'Escribir por WhatsApp', 'cead-acad' ); ?>
							</a>
						<?php elseif ( ! $soy_yo ) : ?>
							<p class="cead-acad-deleg-sintel"><?php esc_html_e( 'Sin teléfono cargado', 'cead-acad' ); ?></p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
