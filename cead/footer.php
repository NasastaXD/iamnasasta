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
        $socials = [
            ['cead_social_yt_label', 'cead_social_yt_url', 'YT'],
            ['cead_social_in_label', 'cead_social_in_url', 'IN'],
            ['cead_social_fb_label', 'cead_social_fb_url', 'FB'],
            ['cead_social_ig_label', 'cead_social_ig_url', 'IG'],
        ];
        foreach ($socials as $s): ?>
          <a href="<?php echo esc_url(get_theme_mod($s[1], '#')); ?>" class="footer-social-pill" aria-label="<?php echo esc_attr(get_theme_mod($s[0], $s[2])); ?>">
            <?php echo esc_html(get_theme_mod($s[0], $s[2])); ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Cómo llegar / mapa -->
    <?php $cead_map = get_theme_mod( 'cead_map_embed', function_exists( 'cead_default_map_embed' ) ? cead_default_map_embed() : '' ); ?>
    <?php if ( $cead_map ) : ?>
      <div id="ComoLlegar" class="footer-map">
        <div class="eyebrow">— <?php echo esc_html( get_theme_mod( 'cead_map_title', 'Cómo llegar' ) ); ?></div>
        <div class="footer-map-frame">
          <iframe src="<?php echo esc_url( $cead_map ); ?>" title="<?php esc_attr_e( 'Mapa del CEAD', 'cead' ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
        <p class="footer-map-addr">📍 <?php echo esc_html( get_theme_mod( 'cead_address', 'Caaguazú, Paraguay' ) ); ?></p>
      </div>
    <?php endif; ?>

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
