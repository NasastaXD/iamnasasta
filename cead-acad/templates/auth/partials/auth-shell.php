<?php
/**
 * Shell visual compartido para pantallas de autenticación.
 * Espera variables: $title (string), $body (callable que pintará el contenido).
 *
 * Reutiliza variables CSS del tema (--brand, --ink, --canvas-soft, fuentes Anton/Mulish).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$title = isset( $title ) ? $title : __( 'CEAD', 'cead-acad' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $title ); ?> · <?php bloginfo( 'name' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="cead-acad-auth">
	<div class="cead-acad-auth-shell">
		<aside class="cead-acad-auth-side">
			<div class="cead-acad-auth-side-inner">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cead-acad-auth-brand">
					<span class="cead-acad-auth-brand-marks" aria-hidden="true">
						<span class="m m--brand"></span>
						<span class="m m--blue"></span>
						<span class="m m--yellow"></span>
						<span class="m m--orange"></span>
					</span>
					<span class="cead-acad-auth-brand-title">CEAD</span>
					<span class="cead-acad-auth-brand-sub"><?php esc_html_e( 'Félix de Guarania', 'cead-acad' ); ?></span>
				</a>
				<blockquote class="cead-acad-auth-quote">
					<span class="cead-acad-auth-eyebrow"><?php esc_html_e( 'Honor Code', 'cead-acad' ); ?></span>
					<p>"Disciplina, integridad, respeto y honor — los cimientos del CEAD."</p>
				</blockquote>
				<p class="cead-acad-auth-foot"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			</div>
		</aside>
		<main class="cead-acad-auth-main">
			<div class="cead-acad-auth-card">
				<?php
				if ( isset( $body ) && is_callable( $body ) ) {
					call_user_func( $body );
				}
				?>
			</div>
			<p class="cead-acad-auth-meta">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>">← <?php esc_html_e( 'Volver al sitio', 'cead-acad' ); ?></a>
			</p>
		</main>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
