<?php
/**
 * Custom Post Types: divisiones (bachilleratos) y vida estudiantil.
 */

if (!defined('ABSPATH')) exit;

function cead_register_cpt() {

    /* ---------- División (Bachillerato) ---------- */
    register_post_type('cead_division', [
        'labels' => [
            'name'               => 'Divisiones',
            'singular_name'      => 'División',
            'add_new'            => 'Agregar división',
            'add_new_item'       => 'Agregar nueva división',
            'edit_item'          => 'Editar división',
            'new_item'           => 'Nueva división',
            'view_item'          => 'Ver división',
            'search_items'       => 'Buscar divisiones',
            'not_found'          => 'No se encontraron divisiones',
            'not_found_in_trash' => 'No hay divisiones en la papelera',
            'menu_name'          => 'Divisiones',
        ],
        'public'              => true,
        'has_archive'         => false,
        'show_in_rest'        => true,
        'menu_position'       => 21,
        'menu_icon'           => 'dashicons-welcome-learn-more',
        'supports'            => ['title', 'editor', 'thumbnail', 'page-attributes', 'excerpt'],
        'rewrite'             => ['slug' => 'division'],
    ]);

    /* ---------- Vida estudiantil ---------- */
    register_post_type('cead_vida', [
        'labels' => [
            'name'               => 'Vida estudiantil',
            'singular_name'      => 'Item de vida',
            'add_new'            => 'Agregar item',
            'add_new_item'       => 'Agregar item de vida',
            'edit_item'          => 'Editar item',
            'new_item'           => 'Nuevo item',
            'view_item'          => 'Ver item',
            'search_items'       => 'Buscar items',
            'menu_name'          => 'Vida',
        ],
        'public'              => true,
        'has_archive'         => false,
        'show_in_rest'        => true,
        'menu_position'       => 22,
        'menu_icon'           => 'dashicons-groups',
        'supports'            => ['title', 'editor', 'thumbnail', 'page-attributes', 'excerpt'],
        'rewrite'             => ['slug' => 'vida'],
    ]);
}
add_action('init', 'cead_register_cpt');

/* ---------- Meta boxes ---------- */
function cead_register_meta() {
    // División
    register_post_meta('cead_division', '_cead_div_subtitle',   ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field']);
    register_post_meta('cead_division', '_cead_div_color',      ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'cead_sanitize_hex']);
    register_post_meta('cead_division', '_cead_div_image_url',  ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'esc_url_raw']);

    // Vida
    register_post_meta('cead_vida', '_cead_vida_tag',       ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field']);
    register_post_meta('cead_vida', '_cead_vida_image_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'esc_url_raw']);
}
add_action('init', 'cead_register_meta');

function cead_add_meta_boxes() {
    add_meta_box('cead_division_meta', 'Datos de la división', 'cead_division_meta_cb', 'cead_division', 'normal', 'high');
    add_meta_box('cead_vida_meta',     'Datos del item',       'cead_vida_meta_cb',     'cead_vida',     'normal', 'high');
}
add_action('add_meta_boxes', 'cead_add_meta_boxes');

function cead_division_meta_cb($post) {
    wp_nonce_field('cead_division_meta', 'cead_division_meta_nonce');
    $subtitle = get_post_meta($post->ID, '_cead_div_subtitle', true);
    $color    = get_post_meta($post->ID, '_cead_div_color', true) ?: '#E93B3C';
    $img      = get_post_meta($post->ID, '_cead_div_image_url', true);
    ?>
    <p>
        <label><strong>Subtítulo</strong> (ej: <em>Bachiller Científico</em>)</label><br>
        <input type="text" name="cead_div_subtitle" value="<?php echo esc_attr($subtitle); ?>" style="width:100%">
    </p>
    <p>
        <label><strong>Color de acento</strong></label><br>
        <input type="color" name="cead_div_color" value="<?php echo esc_attr($color); ?>">
    </p>
    <p>
        <label><strong>URL de imagen</strong> (usar si no se sube como destacada)</label><br>
        <input type="url" name="cead_div_image_url" value="<?php echo esc_attr($img); ?>" style="width:100%" placeholder="https://...">
        <small>Si configurás una imagen destacada, esa toma prioridad.</small>
    </p>
    <?php
}

function cead_vida_meta_cb($post) {
    wp_nonce_field('cead_vida_meta', 'cead_vida_meta_nonce');
    $tag = get_post_meta($post->ID, '_cead_vida_tag', true);
    $img = get_post_meta($post->ID, '_cead_vida_image_url', true);
    ?>
    <p>
        <label><strong>Etiqueta</strong> (ej: <em>Cultural</em>, <em>Deporte</em>)</label><br>
        <input type="text" name="cead_vida_tag" value="<?php echo esc_attr($tag); ?>" style="width:100%">
    </p>
    <p>
        <label><strong>URL de imagen</strong></label><br>
        <input type="url" name="cead_vida_image_url" value="<?php echo esc_attr($img); ?>" style="width:100%" placeholder="https://...">
        <small>Si configurás una imagen destacada, esa toma prioridad.</small>
    </p>
    <?php
}

function cead_save_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // División
    if (isset($_POST['cead_division_meta_nonce']) && wp_verify_nonce($_POST['cead_division_meta_nonce'], 'cead_division_meta')) {
        update_post_meta($post_id, '_cead_div_subtitle',  sanitize_text_field($_POST['cead_div_subtitle'] ?? ''));
        $raw_color = $_POST['cead_div_color'] ?? '#E93B3C';
        $color_ok  = preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $raw_color) ? $raw_color : '#E93B3C';
        update_post_meta($post_id, '_cead_div_color', $color_ok);
        update_post_meta($post_id, '_cead_div_image_url', esc_url_raw($_POST['cead_div_image_url'] ?? ''));
    }

    // Vida
    if (isset($_POST['cead_vida_meta_nonce']) && wp_verify_nonce($_POST['cead_vida_meta_nonce'], 'cead_vida_meta')) {
        update_post_meta($post_id, '_cead_vida_tag',       sanitize_text_field($_POST['cead_vida_tag'] ?? ''));
        update_post_meta($post_id, '_cead_vida_image_url', esc_url_raw($_POST['cead_vida_image_url'] ?? ''));
    }
}
add_action('save_post', 'cead_save_meta');

/* ---------- Seeders ---------- */

function cead_seed_divisions() {
    $defaults = [
        ['title' => 'Ciencias Básicas',     'subtitle' => 'Bachiller Científico',  'color' => '#E93B3C', 'body' => 'Énfasis en matemática, física, química y biología. Formación rigurosa con aplicación tecnológica para estudiantes orientados a ingenierías y ciencias.', 'img' => 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?auto=format&fit=crop&w=1200&q=80'],
        ['title' => 'Ciencias Sociales',    'subtitle' => 'Bachiller Humanístico', 'color' => '#49A3C8', 'body' => 'Historia, economía, derecho y geografía con herramientas tecnológicas modernas. Preparación sólida para carreras sociales y humanísticas.',                'img' => 'https://images.unsplash.com/photo-1521587760476-6c12a4b040da?auto=format&fit=crop&w=1200&q=80'],
        ['title' => 'Letras y Artes',       'subtitle' => 'Bachiller con Énfasis', 'color' => '#EDDF58', 'body' => 'Literatura paraguaya y universal, comunicación, artes visuales y escénicas. Una división que cultiva la sensibilidad y la expresión.',                       'img' => 'https://images.unsplash.com/photo-1513475382585-d06e58bcb0e0?auto=format&fit=crop&w=1200&q=80'],
        ['title' => 'Servicios Turísticos', 'subtitle' => 'Bachiller Técnico',     'color' => '#F4B74C', 'body' => 'Gestión turística, hospitalidad e idiomas. Participación activa en FITPAR y prácticas reales en el sector turístico de Caaguazú.',                          'img' => 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=1200&q=80'],
    ];
    $order = 1;
    foreach ($defaults as $d) {
        $id = wp_insert_post([
            'post_title'   => $d['title'],
            'post_content' => $d['body'],
            'post_status'  => 'publish',
            'post_type'    => 'cead_division',
            'menu_order'   => $order++,
        ]);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_cead_div_subtitle',  $d['subtitle']);
            update_post_meta($id, '_cead_div_color',     $d['color']);
            update_post_meta($id, '_cead_div_image_url', $d['img']);
        }
    }
}

function cead_seed_life() {
    $defaults = [
        ['title' => 'Feria de las Naciones',    'tag' => 'Cultural',  'body' => 'Evento cultural insignia donde cada curso representa un país: gastronomía, danza, vestimenta e historia.',           'img' => 'https://images.unsplash.com/photo-1533174072545-7a4b6ad7a6c3?auto=format&fit=crop&w=1200&q=80'],
        ['title' => 'FITPAR',                   'tag' => 'Turismo',   'body' => 'Participación oficial del bachillerato de Servicios Turísticos en la Feria Internacional de Turismo del Paraguay.', 'img' => 'https://images.unsplash.com/photo-1528605248644-14dd04022da1?auto=format&fit=crop&w=1200&q=80'],
        ['title' => 'Intercurso CEAD',          'tag' => 'Deporte',   'body' => 'Competencia interna anual que reúne a todos los cursos en disciplinas deportivas y desafíos académicos.',            'img' => 'https://images.unsplash.com/photo-1517649763962-0c623066013b?auto=format&fit=crop&w=1200&q=80'],
        ['title' => 'Promotores de Caaguazú',   'tag' => 'Comunidad', 'body' => 'Proyecto estudiantil de extensión: jóvenes embajadores del departamento, su cultura y su gente.',                     'img' => 'https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?auto=format&fit=crop&w=1200&q=80'],
    ];
    $order = 1;
    foreach ($defaults as $d) {
        $id = wp_insert_post([
            'post_title'   => $d['title'],
            'post_content' => $d['body'],
            'post_status'  => 'publish',
            'post_type'    => 'cead_vida',
            'menu_order'   => $order++,
        ]);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_cead_vida_tag',       $d['tag']);
            update_post_meta($id, '_cead_vida_image_url', $d['img']);
        }
    }
}
