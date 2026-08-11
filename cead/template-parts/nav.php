<?php
/**
 * Header fijo + mega menú.
 *
 * Si hay menús asignados en Apariencia → Menús, mandan esos. Si no, el respaldo
 * se arma con los destinos que el sitio SÍ sirve (`cead_site_links()` en
 * inc/pages.php), y lo que no existe no se imprime: nunca un enlace a un 404.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$site  = function_exists( 'cead_site_links' ) ? cead_site_links() : [];
$divs  = function_exists( 'cead_division_links' ) ? cead_division_links() : [];

$pick = static function ( array $keys ) use ( $site ) {
	$out = [];
	foreach ( $keys as $k ) { if ( isset( $site[ $k ] ) ) { $out[] = $site[ $k ]; } }
	return $out;
};

/*
 * Barra superior: pocas entradas, las que la gente busca de verdad.
 *
 * «La plataforma» va justo antes de Bachilleratos: es lo que hay que mostrar
 * cuando alguien entra a ver el proyecto, y desde ahí cuelga la wiki. Queda
 * segunda si algún día se escribe «Sobre CEAD», y primera mientras esa página
 * siga vacía — que es exactamente el orden de importancia que buscamos.
 */
$nav_top = $pick( [ 'sobre-cead', 'proyecto', 'bachilleratos', 'admision', 'noticias' ] );

// Enlaces de la wiki: existen siempre que el plugin esté activo.
$wiki_links = [];
if ( class_exists( 'Cead_Acad_Wiki' ) ) {
	$wiki_links = [
		[ 'label' => __( 'Guía del usuario', 'cead' ),        'url' => home_url( '/wiki' ) ],
		[ 'label' => __( 'Documentación técnica', 'cead' ),   'url' => home_url( '/wiki/tecnica' ) ],
	];
}

// Mega menú: agrupado, y cada grupo se saltea si quedó vacío.
$nav_groups = array_values( array_filter( [
	[ 'label' => __( 'La plataforma', 'cead' ), 'items' => array_merge( $pick( [ 'proyecto' ] ), $wiki_links ) ],
	[ 'label' => __( 'Institucional', 'cead' ), 'items' => $pick( [ 'sobre-cead', 'historia', 'honor-code', 'autoridades' ] ) ],
	[ 'label' => __( 'Bachilleratos', 'cead' ), 'items' => $divs ?: $pick( [ 'bachilleratos' ] ) ],
	[ 'label' => __( 'Admisión', 'cead' ),      'items' => $pick( [ 'admision' ] ) ],
	[ 'label' => __( 'Explorá', 'cead' ),       'items' => array_merge(
		$pick( [ 'noticias', 'galeria', 'recursos' ] ),
		[ [ 'label' => __( 'Contacto', 'cead' ), 'url' => home_url( '/#Contacto' ) ] ]
	) ],
], static function ( $g ) { return ! empty( $g['items'] ); } ) );
?>
<header id="site-nav" class="site-nav">
  <div class="container site-nav-inner">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="site-nav-logo">
      <?php get_template_part('template-parts/logo'); ?>
    </a>

    <nav class="site-nav-links" aria-label="<?php esc_attr_e( 'Navegación principal', 'cead' ); ?>">
      <?php if (has_nav_menu('primary')): ?>
        <?php wp_nav_menu([
            'theme_location' => 'primary',
            'container'      => false,
            'menu_class'     => 'site-nav-list',
            'depth'          => 1,
            'fallback_cb'    => false,
        ]); ?>
      <?php else: ?>
        <?php foreach ($nav_top as $s): ?>
          <a href="<?php echo esc_url( $s['url'] ); ?>" class="site-nav-link underline-brand">
            <?php echo esc_html($s['label']); ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>

    <div class="site-nav-actions">
      <?php
      /**
       * Búsqueda. Antes era un botón sin ningún listener: parecía clicable y no
       * hacía nada. Ahora lleva a la búsqueda real de WordPress.
       */
      ?>
      <a href="<?php echo esc_url( home_url( '/?s=' ) ); ?>" class="site-nav-iconbtn" aria-label="<?php esc_attr_e( 'Buscar en el sitio', 'cead' ); ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
      </a>
      <button id="menu-open" class="site-nav-iconbtn site-nav-iconbtn--brand"
              aria-label="<?php esc_attr_e( 'Abrir menú', 'cead' ); ?>"
              aria-expanded="false" aria-controls="mega-menu">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
    </div>
  </div>
</header>

<div id="mega-menu" class="mega-menu hidden" role="dialog" aria-modal="true"
     aria-label="<?php esc_attr_e( 'Menú del sitio', 'cead' ); ?>">
  <div class="container mega-menu-top">
    <?php get_template_part('template-parts/logo', null, ['dark' => true]); ?>
    <button id="menu-close" class="mega-menu-close" aria-label="<?php esc_attr_e( 'Cerrar menú', 'cead' ); ?>">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="container mega-menu-grid">
    <?php
    if (has_nav_menu('mega')):
        echo '<div class="mega-menu-col">';
        wp_nav_menu([
            'theme_location' => 'mega',
            'container'      => false,
            'menu_class'     => 'mega-menu-list',
            'depth'          => 1,
            'fallback_cb'    => false,
        ]);
        echo '</div>';
    else:
        foreach ($nav_groups as $i => $s): ?>
            <div class="mega-menu-col">
                <div class="mega-menu-col-title">
                    <?php echo esc_html(cead_pad2($i + 1) . ' — ' . $s['label']); ?>
                </div>
                <ul class="mega-menu-list">
                    <?php foreach ($s['items'] as $it): ?>
                        <li><a href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['label'] ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach;
    endif;
    ?>
  </div>
  <div class="mega-menu-foot">
    <?php echo esc_html( get_theme_mod( 'cead_footer_note', 'Caaguazú, Paraguay' ) ); ?> · <?php echo esc_html( get_theme_mod( 'cead_footer_site_label', 'cead.caaguazú.net' ) ); ?>
  </div>
</div>
