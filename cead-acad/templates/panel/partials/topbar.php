<?php
/**
 * Topbar del panel: title + buscador + centro de notificaciones + user chip.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user   = wp_get_current_user();
$roles  = Cead_Acad_Capabilities::roles();
$role   = cead_acad_user_role( $user->ID );
$rdisp  = $roles[ $role ]['display'] ?? $role;

$notif_items = class_exists( 'Cead_Acad_Notifications' ) ? Cead_Acad_Notifications::items( $user->ID ) : [];
$unread      = class_exists( 'Cead_Acad_Notifications' ) ? Cead_Acad_Notifications::badge_count( $user->ID ) : 0;

$title  = isset( $page_title ) ? $page_title : __( 'Panel', 'cead-acad' );
?>
<header class="cead-acad-panel-topbar">
	<div class="cead-acad-panel-topbar-l">
		<button type="button" class="cead-acad-burger" aria-controls="cead-acad-menu" aria-label="<?php esc_attr_e( 'Abrir menú', 'cead-acad' ); ?>" aria-expanded="false" onclick="var o=document.body.classList.toggle('cead-acad-nav-open');this.setAttribute('aria-expanded',o);if(!o&amp;&amp;window.ceadCerrarNav){window.ceadCerrarNav(false);}">
			<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
				<line x1="3" y1="6"  x2="21" y2="6"  stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				<line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				<line x1="3" y1="18" x2="21" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
			</svg>
		</button>
		<h1 class="cead-acad-panel-topbar-title"><?php echo esc_html( $title ); ?></h1>
	</div>
	<div class="cead-acad-panel-topbar-r">
		<form class="cead-acad-topsearch" method="get" action="<?php echo esc_url( cead_acad_url( 'panel/buscar' ) ); ?>" role="search">
			<input type="search" name="q" placeholder="<?php esc_attr_e( 'Buscar…', 'cead-acad' ); ?>" aria-label="<?php esc_attr_e( 'Buscar en el panel', 'cead-acad' ); ?>">
		</form>

		<?php
		/*
		 * El botón dice en qué estado está, no solo qué hace.
		 *
		 * Sin `aria-pressed` un lector de pantalla anuncia «cambiar tema» y nada
		 * más: no hay forma de saber si el modo oscuro está puesto o no —y es
		 * justo lo que el botón controla—. Ahora al pulsarlo se anuncia el
		 * cambio.
		 *
		 * El valor inicial lo escribe el mismo script de la cabecera que decide
		 * el tema, así que sale correcto ya en el primer pintado; acá va la
		 * lectura sincrónica del atributo por si ese script no llegó a correr.
		 *
		 * También se quita `data-theme="light"` explícito al pasar a claro: sin
		 * eso, quien tiene el sistema en oscuro no podría volver a claro nunca,
		 * porque la consulta de preferencia del sistema lo devolvería a oscuro.
		 */
		$tema_oscuro = false; // el script de <head> lo corrige antes de pintar
		?>
		<button type="button" class="cead-acad-theme-toggle"
		        aria-pressed="<?php echo $tema_oscuro ? 'true' : 'false'; ?>"
		        aria-label="<?php esc_attr_e( 'Modo oscuro', 'cead-acad' ); ?>"
		        onclick="(function(d,b){var on=d.getAttribute('data-theme')==='dark';if(on){d.setAttribute('data-theme','light');}else{d.setAttribute('data-theme','dark');}b.setAttribute('aria-pressed',on?'false':'true');try{localStorage.setItem('cead_theme',on?'light':'dark');}catch(e){}})(document.documentElement,this)">
			<span aria-hidden="true">🌗</span>
		</button>
		<script>
		/* Sincroniza el estado del botón con el tema que ya se aplicó en <head>. */
		(function(){var d=document.documentElement,b=document.currentScript.previousElementSibling;
		if(b){b.setAttribute('aria-pressed',d.getAttribute('data-theme')==='dark'?'true':'false');}})();
		</script>

		<div class="cead-acad-notif">
			<button type="button" class="cead-acad-bell" aria-label="<?php esc_attr_e( 'Notificaciones', 'cead-acad' ); ?>" aria-expanded="false" onclick="var w=this.closest('.cead-acad-notif');w.classList.toggle('is-open');this.setAttribute('aria-expanded',w.classList.contains('is-open'));">
				<span class="cead-acad-bell-icon" aria-hidden="true">🔔</span>
				<?php if ( $unread > 0 ) : ?><span class="cead-acad-bell-badge"><?php echo (int) $unread; ?></span><?php endif; ?>
			</button>
			<div class="cead-acad-notif-panel">
				<div class="cead-acad-notif-head">
					<strong><?php esc_html_e( 'Novedades', 'cead-acad' ); ?></strong>
					<?php if ( $unread > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="cead_acad_notif_seen">
							<?php wp_nonce_field( 'cead_acad_notif_seen' ); ?>
							<button type="submit" class="cead-acad-notif-clear"><?php esc_html_e( 'Marcar todo leído', 'cead-acad' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<div class="cead-acad-notif-list">
					<?php if ( $notif_items ) : ?>
						<?php foreach ( $notif_items as $it ) : ?>
							<a class="cead-acad-notif-item" href="<?php echo esc_url( $it['url'] ); ?>">
								<span class="cead-acad-notif-ico" aria-hidden="true"><?php echo esc_html( $it['icon'] ); ?></span>
								<span class="cead-acad-notif-txt">
									<span class="cead-acad-notif-title"><?php echo esc_html( $it['title'] ); ?></span>
									<?php if ( ! empty( $it['when'] ) ) : ?><span class="cead-acad-notif-when"><?php echo esc_html( $it['when'] ); ?></span><?php endif; ?>
								</span>
							</a>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="cead-acad-notif-empty"><?php esc_html_e( 'Estás al día 🎉', 'cead-acad' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php
		/*
		 * El chip es un menú, no un enlace suelto. Antes iba directo al perfil y
		 * «Cerrar sesión» vivía solo en el sidebar — que en celular es un drawer
		 * cerrado, así que en la práctica no había forma de salir sin buscarla.
		 *
		 * Se usa <details> para que abra y cierre sin JavaScript.
		 */
		?>
		<details class="cead-acad-user-menu">
			<summary class="cead-acad-user-chip">
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
			</summary>
			<div class="cead-acad-user-menu-panel">
				<a href="<?php echo esc_url( cead_acad_url( 'panel/perfil' ) ); ?>"><?php esc_html_e( 'Mi perfil', 'cead-acad' ); ?></a>
				<?php if ( cead_acad_carne_activo() ) : ?>
					<a href="<?php echo esc_url( cead_acad_url( 'panel/carne' ) ); ?>"><?php esc_html_e( 'Mi carné', 'cead-acad' ); ?></a>
				<?php endif; ?>
				<a class="cead-acad-user-menu-out" href="<?php echo esc_url( cead_acad_logout_url() ); ?>"><?php esc_html_e( 'Cerrar sesión', 'cead-acad' ); ?></a>
			</div>
		</details>
	</div>
</header>
<?php
