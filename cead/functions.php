<?php
/**
 * CEAD — functions.php
 * Setup del tema, includes, registro de CPTs, Customizer y assets.
 */

if (!defined('ABSPATH')) exit;

define('CEAD_VERSION', '1.2.2');
define('CEAD_DIR', get_template_directory());
define('CEAD_URI', get_template_directory_uri());

/* ---------- Setup ---------- */
function cead_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', [
        'height'      => 60,
        'width'       => 180,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('responsive-embeds');
    load_theme_textdomain('cead', CEAD_DIR . '/languages');

    register_nav_menus([
        'primary'  => __('Navegación principal (header)', 'cead'),
        'mega'     => __('Mega menú', 'cead'),
        'footer_1' => __('Footer — Institucional', 'cead'),
        'footer_2' => __('Footer — Bachilleratos', 'cead'),
        'footer_3' => __('Footer — Contacto', 'cead'),
    ]);
}
add_action('after_setup_theme', 'cead_setup');

/* ---------- Assets ---------- */
function cead_enqueue() {
    // Google Fonts
    wp_enqueue_style(
        'cead-fonts',
        'https://fonts.googleapis.com/css2?family=Anton&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,400;1,500&family=Mulish:wght@400;500;600;700;800&display=swap',
        [],
        null
    );

    // CSS plano del tema
    wp_enqueue_style('cead-main', CEAD_URI . '/assets/css/styles.css', [], CEAD_VERSION);

    // Variables de color desde el Customizer (inyectadas inline)
    $custom_css = ':root{'
        . '--brand:'      . esc_attr(get_theme_mod('cead_color_brand',      '#E93B3C')) . ';'
        . '--brand-deep:' . esc_attr(get_theme_mod('cead_color_brand_deep', '#B82A2B')) . ';'
        . '--brand-tint:' . esc_attr(get_theme_mod('cead_color_brand_tint', '#FCE7E7')) . ';'
        . '--acc-blue:'   . esc_attr(get_theme_mod('cead_color_acc_blue',   '#49A3C8')) . ';'
        . '--acc-yellow:' . esc_attr(get_theme_mod('cead_color_acc_yellow', '#EDDF58')) . ';'
        . '--acc-orange:' . esc_attr(get_theme_mod('cead_color_acc_orange', '#F4B74C')) . ';'
        . '}';
    wp_add_inline_style('cead-main', $custom_css);

    // GSAP (CDN) — solo en front-page
    if (is_front_page() || is_home()) {
        wp_enqueue_script('gsap',             'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js',              [],         '3.12.5', true);
        wp_enqueue_script('gsap-scrolltrigger', 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js', ['gsap'],  '3.12.5', true);
    }

    // JS del tema
    wp_enqueue_script('cead-main', CEAD_URI . '/assets/js/main.js', ['gsap', 'gsap-scrolltrigger'], CEAD_VERSION, true);

    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'cead_enqueue');

/* ---------- Includes ---------- */
require_once CEAD_DIR . '/inc/cpt.php';
require_once CEAD_DIR . '/inc/customizer.php';
require_once CEAD_DIR . '/inc/contact.php';
require_once CEAD_DIR . '/inc/helpers.php';
require_once CEAD_DIR . '/inc/elementor.php';
require_once CEAD_DIR . '/inc/public.php';
require_once CEAD_DIR . '/inc/updates.php';

/* ---------- Seeders al activar el tema ----------
 * Marca un flag al activar, e inserta los CPT en el siguiente `init`
 * (después de que `cead_register_cpt` los haya registrado).
 */
function cead_seed_on_activation() {
    if (!get_option('cead_seeded')) {
        update_option('cead_needs_seed', 1);
    }
}
add_action('after_switch_theme', 'cead_seed_on_activation');

function cead_seed_if_needed() {
    if (!get_option('cead_needs_seed') || get_option('cead_seeded')) return;
    cead_seed_divisions();
    cead_seed_life();
    update_option('cead_seeded', 1);
    delete_option('cead_needs_seed');
}
add_action('init', 'cead_seed_if_needed', 20); // prioridad 20: después de cead_register_cpt (default 10)
