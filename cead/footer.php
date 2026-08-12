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
            <?php // Un pin dibujado, no el emoji: el emoji cambia de forma y de color en cada sistema. ?>
            <svg class="footer-address-pin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
              <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
            </svg>
            <span><?php echo esc_html($cead_addr); ?></span>
          </a>
        <?php endif; ?>
        <a href="<?php echo esc_url(get_theme_mod('cead_footer_site_url', 'https://cead.caaguazu.net')); ?>" class="footer-site-link underline-brand">
          <?php echo esc_html(get_theme_mod('cead_footer_site_label', 'cead.caaguazú.net →')); ?>
        </a>

        <?php
        /*
         * Las redes van acá, con la marca, y no en una columna propia arrastrada
         * al borde derecho: son dos, y solas ocupaban una doceava parte del
         * ancho para dos pastillas que decían «IG» y «FB» en texto.
         */
        $cead_redes = cead_social_links();
        if ($cead_redes): ?>
          <ul class="footer-socials">
            <?php foreach ($cead_redes as $r): ?>
              <li>
                <a href="<?php echo esc_url($r['url']); ?>" class="footer-social"
                   target="_blank" rel="noopener noreferrer"
                   style="--red: <?php echo esc_attr($r['color']); ?>"
                   aria-label="<?php echo esc_attr($r['name']); ?>">
                  <?php echo cead_social_icon($r['slug']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php
      /**
       * Columnas del footer.
       *
       * Si hay un menú asignado se usa. Si no, el respaldo se arma con destinos
       * que EXISTEN de verdad: antes imprimía doce enlaces a `#` que no
       * llevaban a ningún lado. Es el mismo criterio que ya usaban las redes
       * más abajo — una columna sin ningún destino válido no se imprime.
       */
      $site  = cead_site_links();
      $pick  = static function ( array $keys ) use ( $site ) {
          $out = [];
          foreach ( $keys as $k ) { if ( isset( $site[ $k ] ) ) { $out[] = $site[ $k ]; } }
          return $out;
      };

      /*
       * La cuarta posición es la figura de la marca que abre la columna
       * —cuadrado rojo, rombo azul, círculo naranja, las mismas del logo—. Va
       * declarada acá y no con un `:nth-of-type` en el CSS porque una columna
       * sin destinos se saltea: con el conteo del CSS, esconder «Bachilleratos»
       * le pasaba su forma a «Comunidad».
       */
      $cols = [
          [ 'Institucional', 'footer_1', $pick( ['sobre-cead', 'historia', 'honor-code', 'autoridades'] ), 'cuadrado' ],
          [ 'Bachilleratos', 'footer_2', cead_division_links(), 'rombo' ],
          // `calendario` ya lo devuelve cead_site_links() y el nav lo usa, pero
          // el footer lo ignoraba: la página pública de fechas no tenía ni una
          // entrada acá abajo.
          [ 'Comunidad',     'footer_3', $pick( ['proyecto', 'calendario', 'admision', 'noticias', 'galeria', 'recursos'] ), 'circulo' ],
      ];

      foreach ($cols as $c):
        $tiene_menu = has_nav_menu($c[1]);
        // Sin menú y sin destinos reales, la columna no aporta nada.
        if (!$tiene_menu && empty($c[2])) { continue; }
        ?>
        <div class="footer-col">
          <div class="footer-col-title footer-col-title--<?php echo esc_attr($c[3]); ?>"><?php echo esc_html($c[0]); ?></div>
          <?php if ($tiene_menu): ?>
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
                <li><a href="<?php echo esc_url($it['url']); ?>"><?php echo esc_html($it['label']); ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
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

        <?php
        /*
         * Cada campo lleva su <label> de verdad, aunque no se vea.
         *
         * Antes el `placeholder` era la única etiqueta, y un placeholder
         * desaparece en cuanto empezás a escribir: si te distraés a mitad del
         * formulario, ya no hay forma de saber qué campo estabas llenando. A un
         * lector de pantalla directamente no se lo anuncia como nombre del
         * campo. El `placeholder` se queda como ejemplo, que es para lo que
         * sirve, y la etiqueta pasa a estar donde corresponde.
         */
        $campos = [
            [ 'nombre',  'text',  __( 'Nombre', 'cead' ),     __( 'Ana Rodríguez', 'cead' ) ],
            [ 'email',   'email', __( 'Correo electrónico', 'cead' ),      __( 'ana@ejemplo.com', 'cead' ) ],
        ];
        foreach ( $campos as $c ) :
            $id = 'cead-contacto-' . $c[0];
            ?>
            <p class="form-field">
                <label class="sr-only" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $c[2] ); ?></label>
                <input required id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $c[0] ); ?>"
                       type="<?php echo esc_attr( $c[1] ); ?>"
                       autocomplete="<?php echo 'email' === $c[0] ? 'email' : 'name'; ?>"
                       placeholder="<?php echo esc_attr( $c[3] ); ?>" class="form-input">
            </p>
        <?php endforeach; ?>

        <p class="form-field form-field--full">
            <label class="sr-only" for="cead-contacto-mensaje"><?php esc_html_e( 'Mensaje', 'cead' ); ?></label>
            <textarea required id="cead-contacto-mensaje" name="mensaje" rows="4"
                      placeholder="<?php esc_attr_e( 'Escriba su consulta o mensaje…', 'cead' ); ?>"
                      class="form-input form-input--full"></textarea>
        </p>

        <button type="submit" class="cead-btn cead-btn-dark form-submit"><?php esc_html_e( 'Enviar mensaje', 'cead' ); ?></button>

        <?php
        /*
         * La respuesta va en una región `aria-live`: se envía, la página
         * recarga con `?cead_ok`, y quien no ve la pantalla necesita que el
         * resultado se le anuncie. Sin esto el formulario se vaciaba y no
         * pasaba nada más.
         *
         * El contenedor se imprime siempre, aunque esté vacío: una región viva
         * que aparece junto con su texto no siempre se anuncia — el lector
         * tiene que estar observándola de antes.
         */
        ?>
        <div class="form-msg-slot" role="status" aria-live="polite">
          <?php if ( isset( $_GET['cead_ok'] ) ) : ?>
            <div class="form-msg form-msg--ok"><?php esc_html_e( 'Mensaje enviado correctamente.', 'cead' ); ?></div>
          <?php elseif ( isset( $_GET['cead_err'] ) ) : ?>
            <div class="form-msg form-msg--err"><?php esc_html_e( 'No se pudo enviar el mensaje. Intente de nuevo.', 'cead' ); ?></div>
          <?php endif; ?>
        </div>
      </form>
    </div>

  </div>

  <?php
  /*
   * La barra de cierre va oscura y a sangre completa, fuera del `.container`.
   *
   * El footer entero en oscuro no servía: la sección que viene justo antes
   * (`.section--admission`) ya es oscura, y los dos bloques se fundían en una
   * mancha negra sin principio ni final. Una franja delgada al pie cierra la
   * página sin pelearse con ella.
   */
  ?>
  <div class="footer-bottom">
    <div class="container footer-bottom-inner">
      <div><?php echo esc_html( get_theme_mod( 'cead_footer_note', 'Caaguazú, Paraguay' ) ); ?></div>
      <div>© <?php echo esc_html(date('Y')); ?> CEAD Félix de Guarania</div>
      <a class="footer-top-link" href="#site-nav">
        <?php esc_html_e( 'Volver arriba', 'cead' ); ?>
        <span aria-hidden="true">↑</span>
      </a>
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
