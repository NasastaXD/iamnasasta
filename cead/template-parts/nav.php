<?php
/**
 * Header / nav fijo + mega menú.
 * Si hay menú "primary" asignado, lo usa; si no, hardcodea las 5 secciones del original.
 */
// Secciones con enlaces REALES: páginas (Creemos/Admisión/Comunidad/Vida) +
// Divisiones desde el CPT. Cada item: ['label'=>..., 'url'=>...].
$default_sections = [];
$page_sections = function_exists( 'cead_nav_page_sections' ) ? cead_nav_page_sections() : [];
foreach ( $page_sections as $label => $items ) {
    $links = [];
    foreach ( $items as $it ) {
        $links[] = [ 'label' => $it[0], 'url' => cead_page_url( $it[1] ) ];
    }
    $default_sections[] = [ 'label' => $label, 'items' => $links ];
}
// Divisiones (CPT) → insertarlas después de "Admisión".
$cead_divs = get_posts( [ 'post_type' => 'cead_division', 'posts_per_page' => 8, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
if ( $cead_divs ) {
    $dlinks = [];
    foreach ( $cead_divs as $d ) {
        $dlinks[] = [ 'label' => get_the_title( $d ), 'url' => get_permalink( $d ) ];
    }
    array_splice( $default_sections, 2, 0, [ [ 'label' => 'Divisiones', 'items' => $dlinks ] ] );
}
?>
<header id="site-nav" class="site-nav">
  <div class="container site-nav-inner">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="site-nav-logo">
      <?php get_template_part('template-parts/logo'); ?>
    </a>

    <nav class="site-nav-links" aria-label="Navegación principal">
      <?php if (has_nav_menu('primary')): ?>
        <?php wp_nav_menu([
            'theme_location' => 'primary',
            'container'      => false,
            'menu_class'     => 'site-nav-list',
            'depth'          => 1,
            'fallback_cb'    => false,
        ]); ?>
      <?php else: ?>
        <?php foreach ($default_sections as $s):
            $top_url = ! empty( $s['items'][0]['url'] ) ? $s['items'][0]['url'] : home_url( '/' );
        ?>
          <a href="<?php echo esc_url( $top_url ); ?>" class="site-nav-link underline-brand">
            <?php echo esc_html($s['label']); ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>

    <div class="site-nav-actions">
      <button class="site-nav-iconbtn" aria-label="Buscar">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
      </button>
      <button id="menu-open" class="site-nav-iconbtn site-nav-iconbtn--brand" aria-label="Abrir menú">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
    </div>
  </div>
</header>

<div id="mega-menu" class="mega-menu hidden">
  <div class="container mega-menu-top">
    <?php get_template_part('template-parts/logo', null, ['dark' => true]); ?>
    <button id="menu-close" class="mega-menu-close" aria-label="Cerrar menú">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="container mega-menu-grid">
    <?php
    if (has_nav_menu('mega')):
        // Si el usuario asignó un menú "mega", lo respetamos como una sola columna.
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
        foreach ($default_sections as $i => $s): ?>
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
        <?php endforeach; ?>

        <?php // Enlaces reales a las secciones públicas nuevas. ?>
        <div class="mega-menu-col">
            <div class="mega-menu-col-title"><?php echo esc_html( cead_pad2( count( $default_sections ) + 1 ) . ' — Explorá' ); ?></div>
            <ul class="mega-menu-list">
                <?php
                $cead_explore = [
                    [ 'Noticias', get_post_type_archive_link( 'cead_noticia' ) ],
                    [ 'Recursos', get_post_type_archive_link( 'cead_recurso' ) ],
                    [ 'Galería',  get_post_type_archive_link( 'cead_galeria' ) ],
                    [ 'Contacto', home_url( '/#Contacto' ) ],
                ];
                foreach ( $cead_explore as $lnk ) :
                    if ( empty( $lnk[1] ) ) { continue; } ?>
                    <li><a href="<?php echo esc_url( $lnk[1] ); ?>"><?php echo esc_html( $lnk[0] ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif;
    ?>
  </div>
  <div class="mega-menu-foot">
    Caaguazú, Paraguay · cead.caaguazú.net
  </div>
</div>
