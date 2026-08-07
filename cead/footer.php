<footer class="cead-footer">
  <div class="footer-bars">
    <div style="background: var(--brand)"></div>
    <div style="background: var(--acc-blue)"></div>
    <div style="background: var(--acc-yellow)"></div>
    <div style="background: var(--acc-orange)"></div>
  </div>

  <div class="container footer-inner">
    <div class="footer-grid">
      <div class="footer-col-brand">
        <?php get_template_part('template-parts/logo'); ?>
        <p class="footer-about">
          <?php echo wp_kses_post(get_theme_mod('cead_footer_about', 'Centro Educativo de Alto Desempeño “Félix de Guarania”. Bachillerato público fundado en 2009 en Caaguazú, Paraguay.')); ?>
        </p>
        <?php
        $cead_addr = trim((string) get_theme_mod('cead_footer_address', 'GXGQ+G6J, Bienvenido Gallardo Goiris, Caaguazú 3400'));
        if ($cead_addr !== ''):
          $cead_map = trim((string) get_theme_mod('cead_footer_map_url', ''));
          // Sin URL propia, se arma la búsqueda en Maps con la dirección misma.
          if ($cead_map === '') {
            $cead_map = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($cead_addr);
          }
          ?>
          <a class="footer-address" href="<?php echo esc_url($cead_map); ?>" target="_blank" rel="noopener noreferrer">
            <span aria-hidden="true">📍</span> <?php echo esc_html($cead_addr); ?>
          </a>
        <?php endif; ?>
        <a href="<?php echo esc_url(get_theme_mod('cead_footer_site_url', 'https://cead.caaguazu.net')); ?>" class="footer-site-link underline-brand">
          <?php echo esc_html(get_theme_mod('cead_footer_site_label', 'cead.caaguazú.net →')); ?>
        </a>
      </div>

      <?php
      // Si hay menús asignados, los usamos; si no, columnas estáticas como fallback.
      $cols = [
          ['Institucional', 'footer_1', ['Sobre CEAD', 'Honor Code', 'Historia', 'Autoridades']],
          ['Bachilleratos', 'footer_2', ['Ciencias Básicas', 'Ciencias Sociales', 'Letras y Artes', 'Servicios Turísticos']],
          ['Contacto',      'footer_3', ['Admisión', 'Visitas', 'Prensa', 'Trabajá con nosotros']],
      ];
      foreach ($cols as $c): ?>
        <div class="footer-col">
          <div class="footer-col-title"><?php echo esc_html($c[0]); ?></div>
          <?php if (has_nav_menu($c[1])): ?>
            <?php wp_nav_menu([
                'theme_location' => $c[1],
                'container'      => false,
                'menu_class'     => 'footer-col-list',
                'depth'          => 1,
                'fallback_cb'    => false,
            ]); ?>
          <?php else: ?>
            <ul class="footer-col-list">
              <?php foreach ($c[2] as $it): ?>
                <li><a href="#"><?php echo esc_html($it); ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="footer-socials">
        <?php
        // El CEAD solo tiene Instagram y Facebook. Si alguna queda sin URL, esa
        // pastilla no se imprime (mejor nada que un enlace que no lleva a ningún lado).
        $socials = [
            ['cead_social_ig_label', 'cead_social_ig_url', 'IG'],
            ['cead_social_fb_label', 'cead_social_fb_url', 'FB'],
        ];
        foreach ($socials as $s):
          $s_url = trim((string) get_theme_mod($s[1], ''));
          if ($s_url === '' || $s_url === '#') { continue; }
          ?>
          <a href="<?php echo esc_url($s_url); ?>" class="footer-social-pill" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr(get_theme_mod($s[0], $s[2])); ?>">
            <?php echo esc_html(get_theme_mod($s[0], $s[2])); ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Formulario de contacto -->
    <div id="Contacto" class="footer-contact">
      <div class="footer-contact-intro">
        <div class="eyebrow">— Contacto</div>
        <h3>Escribinos.</h3>
        <p>¿Querés visitar el campus o consultar sobre admisión? Dejanos tu mensaje y te respondemos.</p>
      </div>
      <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="footer-form">
        <input type="hidden" name="action" value="cead_contact">
        <?php wp_nonce_field('cead_contact', 'cead_contact_nonce'); ?>
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-10000px" aria-hidden="true">

        <input required name="nombre"  placeholder="Nombre" class="form-input">
        <input required name="email"  type="email" placeholder="Email" class="form-input">
        <textarea required name="mensaje" rows="4" placeholder="Mensaje" class="form-input form-input--full"></textarea>
        <button type="submit" class="form-submit">Enviar mensaje →</button>

        <?php if (isset($_GET['cead_ok'])): ?>
          <div class="form-msg form-msg--ok">✓ Mensaje enviado correctamente.</div>
        <?php elseif (isset($_GET['cead_err'])): ?>
          <div class="form-msg form-msg--err">✗ No se pudo enviar. Probá de nuevo.</div>
        <?php endif; ?>
      </form>
    </div>

    <div class="footer-bottom">
      <div><span class="text-muted-italic">placeholder</span></div>
      <div class="text-muted">© <?php echo esc_html(date('Y')); ?> CEAD Félix de Guarania</div>
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
