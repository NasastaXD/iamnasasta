<?php
/**
 * /panel/recursos: biblioteca de recursos pedagógicos.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user    = wp_get_current_user();
$subject = isset( $_GET['materia'] ) ? sanitize_title( wp_unslash( $_GET['materia'] ) ) : '';
$type    = isset( $_GET['tipo'] )    ? sanitize_title( wp_unslash( $_GET['tipo'] ) )    : '';
$search  = isset( $_GET['q'] )       ? sanitize_text_field( wp_unslash( $_GET['q'] ) )  : '';
$paged   = max( 1, (int) ( $_GET['p'] ?? 1 ) );
$per_page = 12;

$resources = Cead_Acad_Resources_Acl::for_user( $user->ID, [
	'per_page' => $per_page,
	'paged'    => $paged,
	'subject'  => $subject,
	'type'     => $type,
	'search'   => $search,
] );

$subjects = get_terms( [ 'taxonomy' => Cead_Acad_Resources_CPT::TAX_SUBJECT, 'hide_empty' => false ] );
$types    = get_terms( [ 'taxonomy' => Cead_Acad_Resources_CPT::TAX_TYPE,    'hide_empty' => false ] );

$page_title = __( 'Recursos', 'cead-acad' );

$body = function () use ( $resources, $subjects, $types, $subject, $type, $search, $paged, $per_page ) {
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

			<button class="cead-acad-btn cead-acad-btn--ghost"><?php esc_html_e( 'Filtrar', 'cead-acad' ); ?></button>
		</form>

		<?php if ( ! $resources ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<span class="cead-acad-eyebrow"><?php esc_html_e( 'Sin resultados', 'cead-acad' ); ?></span>
				<h3><?php esc_html_e( 'Nada para mostrar', 'cead-acad' ); ?></h3>
				<p><?php esc_html_e( 'No hay recursos que coincidan con los filtros actuales.', 'cead-acad' ); ?></p>
			</div>
		<?php else : ?>
			<div class="cead-acad-grid cead-acad-grid--3" style="margin-top:1.5rem">
				<?php foreach ( $resources as $r ) :
					$thumb = get_the_post_thumbnail_url( $r, 'medium' );
					$type_terms = get_the_terms( $r->ID, Cead_Acad_Resources_CPT::TAX_TYPE );
					$type_label = ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->name : '';
				?>
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
				<?php endforeach; ?>
			</div>

			<?php $has_more = count( $resources ) === $per_page; ?>
			<?php if ( $paged > 1 || $has_more ) : ?>
				<nav class="cead-acad-pager">
					<?php if ( $paged > 1 ) : ?>
						<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( 'p', $paged - 1 ) ); ?>">← <?php esc_html_e( 'Anterior', 'cead-acad' ); ?></a>
					<?php endif; ?>
					<?php if ( $has_more ) : ?>
						<a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( add_query_arg( 'p', $paged + 1 ) ); ?>"><?php esc_html_e( 'Siguiente', 'cead-acad' ); ?> →</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
