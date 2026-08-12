<?php
$divs_q = new WP_Query([
    'post_type'      => 'cead_division',
    'posts_per_page' => 8,
    'orderby'        => 'menu_order',
    'order'          => 'ASC',
]);
$default_colors = ['#E93B3C', '#49A3C8', '#EDDF58', '#F4B74C'];
?>
<section id="divisiones" class="section section--divisions">
  <div class="container">
    <div class="section-head section-head--split">
      <div>
        <div class="eyebrow"><?php echo esc_html(get_theme_mod('cead_divs_eyebrow', '— Bachilleratos')); ?></div>
        <h2 class="display-h2 display-h2--narrow" data-split><?php echo wp_kses_post(get_theme_mod('cead_divs_title', 'Cuatro caminos.<br>Una misma exigencia.')); ?></h2>
      </div>
      <p class="section-intro section-intro--right"><?php echo wp_kses_post(get_theme_mod('cead_divs_intro', 'Cada bachillerato tiene su propia identidad y currículum. Todos comparten el mismo estándar académico y el mismo Honor Code.')); ?></p>
    </div>

    <div class="divisions-grid">
      <?php
      if ($divs_q->have_posts()):
          $i = 0;
          while ($divs_q->have_posts()): $divs_q->the_post();
              $color    = get_post_meta(get_the_ID(), '_cead_div_color', true) ?: $default_colors[$i % 4];
              $subtitle = get_post_meta(get_the_ID(), '_cead_div_subtitle', true);
              $img      = cead_post_image_url(get_the_ID(), '_cead_div_image_url');
              $n        = cead_pad2($i + 1);
              ?>
              <a href="<?php the_permalink(); ?>" class="reveal division-card" style="--card-color: <?php echo esc_attr($color); ?>; --i: <?php echo (int) $i; ?>">
                <div class="division-card-image">
                  <?php if ($img): ?>
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" loading="lazy">
                  <?php endif; ?>
                </div>
                <div class="division-card-body">
                  <div class="division-card-meta">
                    <span class="division-card-num" style="color: <?php echo esc_attr($color); ?>"><?php echo esc_html($n); ?></span>
                    <?php if ($subtitle): ?><span class="division-card-sub"><?php echo esc_html($subtitle); ?></span><?php endif; ?>
                  </div>
                  <h3 class="division-card-title"><?php the_title(); ?></h3>
                  <p class="division-card-text"><?php echo esc_html(wp_strip_all_tags(get_the_content())); ?></p>
                  <div class="division-card-cta">
                    <span class="underline-brand">Conocer división</span>
                    <span class="division-card-plus">+</span>
                  </div>
                </div>
              </a>
              <?php
              $i++;
          endwhile;
          wp_reset_postdata();
      endif;
      ?>
    </div>
  </div>
</section>
