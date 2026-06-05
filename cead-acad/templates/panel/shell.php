<?php
/**
 * Shell del panel: sidebar + topbar + slot de contenido.
 * Espera variables: $page_title (string), $body (callable).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = $page_title ?? __( 'Panel', 'cead-acad' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $page_title ); ?> · <?php bloginfo( 'name' ); ?></title>
	<script>try{if(localStorage.getItem('cead_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}</script>
	<?php wp_head(); ?>
</head>
<body class="cead-acad-panel">
	<div class="cead-acad-panel-shell">
		<?php include CEAD_ACAD_DIR . 'templates/panel/partials/sidebar.php'; ?>
		<div class="cead-acad-panel-main">
			<?php include CEAD_ACAD_DIR . 'templates/panel/partials/topbar.php'; ?>
			<main class="cead-acad-panel-content">
				<?php if ( isset( $body ) && is_callable( $body ) ) { call_user_func( $body ); } ?>
			</main>
		</div>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
