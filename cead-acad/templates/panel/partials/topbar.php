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
		<a href="<?php echo esc_url( cead_acad_url( 'panel/comunicados' ) ); ?>" class="cead-acad-bell" aria-label="<?php esc_attr_e( 'Comunicados sin leer', 'cead-acad' ); ?>">
			<span class="cead-acad-bell-icon" aria-hidden="true">!</span>
			<?php if ( $unread > 0 ) : ?>
				<span class="cead-acad-bell-badge"><?php echo (int) $unread; ?></span>
			<?php endif; ?>
		</a>
		<span class="cead-acad-user-chip">
			<span class="cead-acad-user-chip-name"><?php echo esc_html( $user->display_name ); ?></span>
			<span class="cead-acad-user-chip-role"><?php echo esc_html( $rdisp ); ?></span>
		</span>
	</div>
</header>
