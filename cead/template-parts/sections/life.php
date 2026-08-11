<?php
$life_q = new WP_Query([
    'post_type'      => 'cead_vida',
    'posts_per_page' => 8,
    'orderby'        => 'menu_order',
    'order'          => 'ASC',
]);
?>
<section id="vida" class="section section--life">
  <div class="container">
    <div class="section-head">
      <div class="eyebrow"><?php echo esc_html(get_theme_mod('cead_life_eyebrow', '— Vida estudiantil')); ?></div>
      <h2 class="display-h2 display-h2--narrow"><?php echo esc_html(get_theme_mod('cead_life_title', 'Lo que pasa fuera del aula define al estudiante.')); ?></h2>
    </div>

    <div class="life-grid">
      <?php
      if ($life_q->have_posts()):
          $li = 0;
          while ($life_q->have_posts()): $life_q->the_post();
              $tag = get_post_meta(get_the_ID(), '_cead_vida_tag', true);
              $img = cead_post_image_url(get_the_ID(), '_cead_vida_image_url');
              ?>
              <a href="<?php the_permalink(); ?>" class="reveal life-card" style="--i: <?php echo (int) $li++; ?>">
                <div class="life-card-image">
                  <?php if ($img): ?>
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" loading="lazy">
                  <?php endif; ?>
                  <div class="life-card-overlay"></div>
                </div>
                <div class="life-card-body">
                  <?php if ($tag): ?><div class="eyebrow"><?php echo esc_html($tag); ?></div><?php endif; ?>
                  <h3 class="life-card-title"><?php the_title(); ?></h3>
                  <p class="life-card-text"><?php echo esc_html(wp_strip_all_tags(get_the_content())); ?></p>
                </div>
              </a>
              <?php
          endwhile;
          wp_reset_postdata();
      endif;
      ?>
    </div>
  </div>
</section>
