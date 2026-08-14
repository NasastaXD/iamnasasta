<?php
/**
 * /panel/recursos: biblioteca de recursos pedagógicos.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user    = wp_get_current_user();
$subject = isset( $_GET['materia'] ) ? sanitize_title( wp_unslash( $_GET['materia'] ) ) : '';
$type    = isset( $_GET['tipo'] )    ? sanitize_title( wp_unslash( $_GET['tipo'] ) )    : '';
$search  = isset( $_GET['q'] )       ? sanitize_text_field( wp_unslash( $_GET['q'] ) )  : '';
// No usar `p`: WordPress lo reserva para "post ID" y redirige al permalink
// de ese post (ver la nota en panel/comunicados/feed.php).
$paged   = max( 1, (int) ( $_GET['pag'] ?? 1 ) );
$per_page = 12;

$only_fav = isset( $_GET['fav'] ) && '1' === (string) $_GET['fav'];

$resources = Cead_Acad_Resources_Acl::for_user( $user->ID, [
	'per_page' => $only_fav ? 100 : $per_page,
	'paged'    => $only_fav ? 1 : $paged,
	'subject'  => $subject,
	'type'     => $type,
	'search'   => $search,
] );

if ( $only_fav ) {
	$fav_ids   = Cead_Acad_Account::fav_ids( $user->ID );
	$resources = array_values( array_filter( $resources, static function ( $r ) use ( $fav_ids ) {
		return in_array( (int) $r->ID, $fav_ids, true );
	} ) );
}

$subjects = get_terms( [ 'taxonomy' => Cead_Acad_Resources_CPT::TAX_SUBJECT, 'hide_empty' => false ] );
$types    = get_terms( [ 'taxonomy' => Cead_Acad_Resources_CPT::TAX_TYPE,    'hide_empty' => false ] );

$page_title = __( 'Recursos', 'cead-acad' );

$body = function () use ( $resources, $subjects, $types, $subject, $type, $search, $paged, $per_page, $only_fav, $user ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Biblioteca', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Recursos pedagógicos', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Mapas conceptuales, PDFs, enlaces y materiales compartidos para tus materias.', 'cead-acad' ); ?></p>

		<form method="get" class="cead-acad-resources-filters" style="display:flex;gap:0.6rem;flex-wrap:wrap;margin-top:1.5rem">
			<input type="text" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar...', 'cead-acad' ); ?>" class="cead-acad-input" style="border:1px solid var(--cead-acad-border);padding:0.6rem 0.9rem;flex:1;min-width:200px">

			<?php if ( ! empty( $subjects ) && ! is_wp_error( $subjects ) ) : ?>
				<select name="materia" style="border:1px solid var(--cead-acad-border);padding:0.6rem 0.9rem">
					<option value=""><?php esc_html_e( 'Todas las materias', 'cead-acad' ); ?></option>
					<?php foreach ( $subjects as $s ) : ?>
						<option value="<?php echo esc_attr( $s->slug ); ?>" <?php selected( $subject, $s->slug ); ?>><?php echo esc_html( $s->name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( ! empty( $types ) && ! is_wp_error( $types ) ) : ?>
				<select name="tipo" style="border:1px solid var(--cead-acad-border);padding:0.6rem 0.9rem">
					<option value=""><?php esc_html_e( 'Todos los tipos', 'cead-acad' ); ?></option>
					<?php foreach ( $types as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $type, $t->slug ); ?>><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( $only_fav ) : ?><input type="hidden" name="fav" value="1"><?php endif; ?>
			<button class="cead-acad-btn cead-acad-btn--ghost"><?php esc_html_e( 'Filtrar', 'cead-acad' ); ?></button>
			<a class="cead-acad-btn <?php echo $only_fav ? '' : 'cead-acad-btn--ghost'; ?>" href="<?php echo esc_url( $only_fav ? cead_acad_url( 'panel/recursos' ) : add_query_arg( 'fav', '1', cead_acad_url( 'panel/recursos' ) ) ); ?>">★ <?php esc_html_e( 'Favoritos', 'cead-acad' ); ?></a>
		</form>

		<?php
		if ( ! $resources ) :
			/*
			 * Tres motivos distintos para que la grilla esté vacía, y el mensaje
			 * tiene que decir CUÁL. Antes los tres decían «no coinciden con los
			 * filtros actuales»: a un alumno que entra por primera vez, sin tocar
			 * nada, le echaba la culpa de un filtro que nunca puso, y como no hay
			 * filtro que sacar, el mensaje además no le da salida.
			 */
			$hay_filtro = ( '' !== $search || '' !== $subject || '' !== $type );
			if ( $hay_filtro ) {
				$vacio_eyebrow = __( 'Sin resultados', 'cead-acad' );
				$vacio_titulo  = __( 'Nada coincide con tu búsqueda', 'cead-acad' );
				$vacio_texto   = __( 'Probá con otras palabras o quitá los filtros para ver todo.', 'cead-acad' );
			} elseif ( $only_fav ) {
				$vacio_eyebrow = __( 'Favoritos', 'cead-acad' );
				$vacio_titulo  = __( 'Todavía no marcaste favoritos', 'cead-acad' );
				$vacio_texto   = __( 'Tocá la estrella de un recurso para guardarlo acá y encontrarlo rápido.', 'cead-acad' );
			} else {
				$vacio_eyebrow = __( 'Biblioteca', 'cead-acad' );
				$vacio_titulo  = __( 'Todavía no hay recursos', 'cead-acad' );
				$vacio_texto   = __( 'Cuando tus docentes compartan materiales para tus materias, van a aparecer acá.', 'cead-acad' );
			}
			?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php echo esc_html( $vacio_eyebrow ); ?></span>
				<h3><?php echo esc_html( $vacio_titulo ); ?></h3>
				<p><?php echo esc_html( $vacio_texto ); ?></p>
				<?php if ( $hay_filtro || $only_fav ) : ?>
					<p style="margin-top:1rem"><a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( cead_acad_url( 'panel/recursos' ) ); ?>"><?php esc_html_e( 'Ver todos los recursos', 'cead-acad' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="cead-acad-grid cead-acad-grid--3" style="margin-top:1.5rem">
				<?php foreach ( $resources as $r ) :
					$thumb = get_the_post_thumbnail_url( $r, 'medium' );
					$type_terms = get_the_terms( $r->ID, Cead_Acad_Resources_CPT::TAX_TYPE );
					$type_label = ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->name : '';
					$is_fav = Cead_Acad_Account::is_fav( $user->ID, $r->ID );
				?>
					<div class="cead-acad-resource-wrap">
						<form class="cead-acad-fav-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="cead_acad_toggle_fav">
							<input type="hidden" name="resource_id" value="<?php echo (int) $r->ID; ?>">
							<?php wp_nonce_field( 'cead_acad_toggle_fav' ); ?>
							<button type="submit" class="cead-acad-fav<?php echo $is_fav ? ' is-fav' : ''; ?>" aria-label="<?php esc_attr_e( 'Favorito', 'cead-acad' ); ?>"><?php echo $is_fav ? '★' : '☆'; ?></button>
						</form>
						<a class="cead-acad-card cead-acad-card--link cead-acad-resource-card" href="<?php echo esc_url( cead_acad_url( 'panel/recursos/' . $r->ID ) ); ?>">
							<?php if ( $thumb ) : ?>
								<div class="cead-acad-resource-thumb" style="background-image:url('<?php echo esc_url( $thumb ); ?>')"></div>
							<?php endif; ?>
							<?php if ( $type_label ) : ?><span class="cead-acad-pill"><?php echo esc_html( $type_label ); ?></span><?php endif; ?>
							<h3><?php echo esc_html( get_the_title( $r ) ); ?></h3>
							<?php if ( $r->post_excerpt ) : ?>
								<p><?php echo esc_html( wp_trim_words( $r->post_excerpt, 18 ) ); ?></p>
							<?php endif; ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>

			<?php $has_more = count( $resources ) === $per_page; ?>
			<?php if ( $paged > 1 || $has_more ) : ?>
				<nav class="cead-acad-pager">
					<?php if ( $paged > 1 ) : ?>
						<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( 'pag', $paged - 1 ) ); ?>">← <?php esc_html_e( 'Anterior', 'cead-acad' ); ?></a>
					<?php endif; ?>
					<?php if ( $has_more ) : ?>
						<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( 'pag', $paged + 1 ) ); ?>"><?php esc_html_e( 'Siguiente', 'cead-acad' ); ?> →</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
