<?php
/**
 * Sidebar del panel. Ítems dinámicos según rol.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user = wp_get_current_user();
$role = cead_acad_user_role( $user->ID );

$base_items = [
	[ 'href' => 'panel',              'label' => __( 'Inicio', 'cead-acad' ),       'icon' => 'home' ],
	[ 'href' => 'panel/comunicados',  'label' => __( 'Comunicados', 'cead-acad' ),  'icon' => 'megaphone' ],
	[ 'href' => 'panel/encuestas',    'label' => __( 'Encuestas', 'cead-acad' ),    'icon' => 'feedback' ],
	[ 'href' => 'panel/horarios',     'label' => __( 'Horarios', 'cead-acad' ),     'icon' => 'calendar' ],
	[ 'href' => 'panel/calendario',   'label' => __( 'Calendario', 'cead-acad' ),   'icon' => 'calendar' ],
	[ 'href' => 'panel/tareas',       'label' => __( 'Mis tareas', 'cead-acad' ),   'icon' => 'clipboard' ],
	[ 'href' => 'panel/recursos',     'label' => __( 'Recursos', 'cead-acad' ),     'icon' => 'portfolio' ],
	[ 'href' => 'panel/carne',        'label' => __( 'Mi carné', 'cead-acad' ),     'icon' => 'id' ],
	[ 'href' => 'panel/perfil',       'label' => __( 'Mi perfil', 'cead-acad' ),    'icon' => 'admin-users' ],
	[ 'href' => 'panel/app',          'label' => __( 'Instalar app', 'cead-acad' ), 'icon' => 'smartphone' ],
];

// Boletín solo para roles con acceso a notas propias.
if ( current_user_can( 'cead_acad_view_own_grades' ) ) {
	$base_items[] = [ 'href' => 'panel/boletin', 'label' => __( 'Boletín', 'cead-acad' ), 'icon' => 'clipboard' ];
}

// Buzón para quien gestiona reportes y/o sugerencias.
if ( current_user_can( 'cead_acad_manage_reports' ) || current_user_can( 'cead_acad_manage_suggestions' ) ) {
	$base_items[] = [ 'href' => 'panel/buzon', 'label' => __( 'Buzón', 'cead-acad' ), 'icon' => 'email' ];
}

$by_role = [
	'cead_acad_direction' => [
		[ 'href' => 'panel/direccion',  'label' => __( 'Dirección', 'cead-acad' ),  'icon' => 'star' ],
		[ 'href' => 'panel/secretaria', 'label' => __( 'Secretaría', 'cead-acad' ), 'icon' => 'briefcase' ],
	],
	'cead_acad_secretary' => [
		[ 'href' => 'panel/secretaria', 'label' => __( 'Secretaría', 'cead-acad' ), 'icon' => 'briefcase' ],
	],
	'cead_acad_delegate' => [
		[ 'href' => 'panel/delegado',   'label' => __( 'Tareas del curso', 'cead-acad' ), 'icon' => 'clipboard' ],
	],
];

$items = array_merge( $base_items, $by_role[ $role ] ?? [] );

$current_route = trim( get_query_var( Cead_Acad_Rewrites::QUERY_VAR ), '/' );
?>
<nav class="cead-acad-panel-sidebar" aria-label="<?php esc_attr_e( 'Menú del panel', 'cead-acad' ); ?>">
	<a href="<?php echo esc_url( cead_acad_url( '' ) ); ?>" class="cead-acad-panel-brand">
		<span class="cead-acad-panel-brand-marks" aria-hidden="true">
			<span class="m m--brand"></span>
			<span class="m m--blue"></span>
			<span class="m m--yellow"></span>
			<span class="m m--orange"></span>
		</span>
		<span class="cead-acad-panel-brand-title">CEAD</span>
	</a>

	<ul class="cead-acad-panel-nav">
		<?php foreach ( $items as $it ) :
			$href = cead_acad_url( $it['href'] );
			// "panel" (Inicio) solo se marca activo en la home exacta; si no, sería
			// prefijo de todas las sub-rutas (panel/encuestas, etc.) y quedarían dos.
			$active = ( $current_route === $it['href'] )
				|| ( 'panel' !== $it['href'] && str_starts_with( $current_route, $it['href'] . '/' ) );
		?>
			<li class="<?php echo $active ? 'is-active' : ''; ?>">
				<a href="<?php echo esc_url( $href ); ?>">
					<span class="cead-acad-panel-nav-dot" aria-hidden="true"></span>
					<?php echo esc_html( $it['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="cead-acad-panel-sidefoot">
		<a href="<?php echo esc_url( cead_acad_url( 'salir' ) ); ?>" class="cead-acad-panel-logout"><?php esc_html_e( 'Cerrar sesión', 'cead-acad' ); ?></a>
	</div>
</nav>
