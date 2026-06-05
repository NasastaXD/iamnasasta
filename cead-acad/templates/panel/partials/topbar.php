<?php
/**
 * Topbar del panel: title + bell de no-leídos + user chip.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user   = wp_get_current_user();
$roles  = Cead_Acad_Capabilities::roles();
$role   = cead_acad_user_role( $user->ID );
$rdisp  = $roles[ $role ]['display'] ?? $role;
$unread = (int) Cead_Acad_Broadcasts_Reads::count_unread_for_user( $user->ID );

$title  = isset( $page_title ) ? $page_title : __( 'Panel', 'cead-acad' );
?>
<header class="cead-acad-panel-topbar">
	<div class="cead-acad-panel-topbar-l">
		<h1 class="cead-acad-panel-topbar-title"><?php echo esc_html( $title ); ?></h1>
	</div>
	<div class="cead-acad-panel-topbar-r">
		<button type="button" class="cead-acad-theme-toggle" aria-label="<?php esc_attr_e( 'Cambiar tema claro/oscuro', 'cead-acad' ); ?>" onclick="(function(d){var on=d.getAttribute('data-theme')==='dark';if(on){d.removeAttribute('data-theme');}else{d.setAttribute('data-theme','dark');}try{localStorage.setItem('cead_theme',on?'light':'dark');}catch(e){}})(document.documentElement)">
			<span aria-hidden="true">🌗</span>
		</button>
		<a href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>" class="cead-acad-bell" aria-label="<?php esc_attr_e( 'Comunicados sin leer', 'cead-acad' ); ?>">
			<span class="cead-acad-bell-icon" aria-hidden="true">!</span>
			<?php if ( $unread > 0 ) : ?>
				<span class="cead-acad-bell-badge"><?php echo (int) $unread; ?></span>
			<?php endif; ?>
		</a>
		<a class="cead-acad-user-chip" href="<?php echo esc_url( cead_acad_url( 'panel/perfil' ) ); ?>">
			<?php
			$tb_avatar = class_exists( 'Cead_Acad_Account' ) ? Cead_Acad_Account::avatar_url( $user->ID, 'thumbnail' ) : '';
			if ( $tb_avatar ) : ?>
				<img class="cead-acad-avatar cead-acad-avatar--sm" src="<?php echo esc_url( $tb_avatar ); ?>" alt="">
			<?php else : ?>
				<span class="cead-acad-avatar cead-acad-avatar--sm cead-acad-avatar--initials"><?php echo esc_html( class_exists( 'Cead_Acad_Account' ) ? Cead_Acad_Account::initials( $user->display_name ) : '' ); ?></span>
			<?php endif; ?>
			<span class="cead-acad-user-chip-txt">
				<span class="cead-acad-user-chip-name"><?php echo esc_html( $user->display_name ); ?></span>
				<span class="cead-acad-user-chip-role"><?php echo esc_html( $rdisp ); ?></span>
			</span>
		</a>
	</div>
</header>
