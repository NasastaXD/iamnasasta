<?php
/**
 * /panel/buscar?q=... : búsqueda global en comunicados, eventos y recursos.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();
$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

$broadcasts = [];
$events     = [];
$resources  = [];

if ( $q !== '' ) {
	$bids = Cead_Acad_Audiences::subjects_for_user( 'broadcast', $user->ID );
	if ( $bids ) {
		$broadcasts = get_posts( [
			'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $bids,
			's'              => $q,
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		] );
	}

	$eids = Cead_Acad_Audiences::subjects_for_user( 'event', $user->ID );
	if ( $eids ) {
		$events = get_posts( [
			'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'post__in'       => $eids,
			's'              => $q,
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		] );
	}

	if ( class_exists( 'Cead_Acad_Resources_Acl' ) ) {
		$resources = Cead_Acad_Resources_Acl::for_user( $user->ID, [ 'search' => $q, 'per_page' => 10 ] );
	}
}

$total = count( $broadcasts ) + count( $events ) + count( $resources );

$page_title = __( 'Buscar', 'cead-acad' );

$body = function () use ( $q, $broadcasts, $events, $resources, $total ) {
	?>
	<section class="cead-acad-panel-section">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Búsqueda', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Buscar en el panel', 'cead-acad' ); ?></h2>

		<form method="get" action="<?php echo esc_url( cead_acad_url( 'panel/buscar' ) ); ?>" class="cead-acad-search-form" style="margin-top:1rem">
			<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Comunicados, eventos, recursos…', 'cead-acad' ); ?>" autofocus>
			<button type="submit" class="cead-acad-btn"><?php esc_html_e( 'Buscar', 'cead-acad' ); ?></button>
		</form>

		<?php if ( $q === '' ) : ?>
			<p class="cead-acad-panel-sub" style="margin-top:1.5rem"><?php esc_html_e( 'Escribí algo para buscar en tus comunicados, eventos y recursos.', 'cead-acad' ); ?></p>
		<?php elseif ( 0 === $total ) : ?>
			<div class="cead-acad-card cead-acad-card--empty" style="margin-top:1.5rem">
				<h3><?php esc_html_e( 'Sin resultados', 'cead-acad' ); ?></h3>
				<p><?php printf( esc_html__( 'No encontramos nada para “%s”.', 'cead-acad' ), esc_html( $q ) ); ?></p>
			</div>
		<?php else : ?>
			<p class="cead-acad-panel-sub" style="margin-top:.75rem"><?php printf( esc_html( _n( '%d resultado', '%d resultados', $total, 'cead-acad' ) ), (int) $total ); ?></p>

			<?php if ( $broadcasts ) : ?>
				<h3 class="cead-acad-section-h" style="margin-top:1.5rem">📣 <?php esc_html_e( 'Comunicados', 'cead-acad' ); ?></h3>
				<div class="cead-acad-grid cead-acad-grid--2">
					<?php foreach ( $broadcasts as $p ) : ?>
						<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( cead_acad_url( 'panel/comunicados/' . $p->ID ) ); ?>">
							<span class="cead-acad-eyebrow"><?php echo esc_html( get_the_date( '', $p ) ); ?></span>
							<h3><?php echo esc_html( get_the_title( $p ) ); ?></h3>
							<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 18 ) ); ?></p>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $events ) : ?>
				<h3 class="cead-acad-section-h" style="margin-top:1.5rem">📅 <?php esc_html_e( 'Eventos', 'cead-acad' ); ?></h3>
				<div class="cead-acad-grid cead-acad-grid--2">
					<?php foreach ( $events as $e ) :
						$st = (string) get_post_meta( $e->ID, '_cead_acad_event_start', true );
					?>
						<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( cead_acad_url( 'panel/calendario/' . $e->ID ) ); ?>">
							<span class="cead-acad-eyebrow"><?php echo $st ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $st ) ) ) : ''; ?></span>
							<h3><?php echo esc_html( get_the_title( $e ) ); ?></h3>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $resources ) : ?>
				<h3 class="cead-acad-section-h" style="margin-top:1.5rem">📁 <?php esc_html_e( 'Recursos', 'cead-acad' ); ?></h3>
				<div class="cead-acad-grid cead-acad-grid--2">
					<?php foreach ( $resources as $r ) : ?>
						<a class="cead-acad-card cead-acad-card--link" href="<?php echo esc_url( cead_acad_url( 'panel/recursos/' . $r->ID ) ); ?>">
							<h3><?php echo esc_html( get_the_title( $r ) ); ?></h3>
							<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $r->post_content ), 18 ) ); ?></p>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
