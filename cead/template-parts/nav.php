<?php
/**
 * Header / nav fijo + mega menú.
 * Si hay menú "primary" asignado, lo usa; si no, hardcodea las 5 secciones del original.
 */
$default_sections = [
    ['label' => 'Creemos',    'items' => ['Sobre CEAD', 'Misión y Visión', 'Honor Code', 'Historia']],
    ['label' => 'Admisión',   'items' => ['Proceso', 'Calendario', 'Requisitos', 'Visitas']],
    ['label' => 'Divisiones', 'items' => ['Ciencias Básicas', 'Ciencias Sociales', 'Letras y Artes', 'Servicios Turísticos']],
    ['label' => 'Comunidad',  'items' => ['Estudiantes', 'Docentes', 'Familias', 'Egresados']],
    ['label' => 'Vida',       'items' => ['Cultural', 'Deporte', 'Académico', 'Clubes']],
];
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
        <?php foreach ($default_sections as $s): ?>
          <a href="#<?php echo esc_attr(sanitize_title($s['label'])); ?>" class="site-nav-link underline-brand">
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
                        <li><a href="#"><?php echo esc_html($it); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach;
    endif;
    ?>
  </div>
  <div class="mega-menu-foot">
    Caaguazú, Paraguay · cead.caaguazú.net
  </div>
</div>
