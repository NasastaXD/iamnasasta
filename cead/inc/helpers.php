<?php
/**
 * Helpers compartidos por templates.
 */

if (!defined('ABSPATH')) exit;

/**
 * Devuelve el HTML del shape de un valor (cuadrado, rombo, rect, círculo).
 */
function cead_value_shape_html($shape, $color) {
    $color = esc_attr($color);
    switch ($shape) {
        case 'diamond': return "<span class='value-shape value-shape--diamond' style='background:$color'></span>";
        case 'rect':    return "<span class='value-shape value-shape--rect'    style='background:$color'></span>";
        case 'circle':  return "<span class='value-shape value-shape--circle'  style='background:$color'></span>";
        default:        return "<span class='value-shape value-shape--square'  style='background:$color'></span>";
    }
}

/**
 * Resuelve la URL de imagen de un CPT: primero featured image, luego meta URL externa.
 */
function cead_post_image_url($post_id, $meta_key, $size = 'large') {
    if (has_post_thumbnail($post_id)) {
        $url = get_the_post_thumbnail_url($post_id, $size);
        if ($url) return $url;
    }
    return esc_url(get_post_meta($post_id, $meta_key, true));
}

/**
 * Imprime un número de 2 dígitos con cero a la izquierda.
 */
function cead_pad2($n) {
    return str_pad((int)$n, 2, '0', STR_PAD_LEFT);
}

/**
 * Sanitiza un color HEX (#fff o #ffffff). Devuelve string vacío si no valida.
 * Variante propia que NO depende de sanitize_hex_color() (función disponible
 * solo dentro del Customizer en core WP).
 */
function cead_sanitize_hex($color) {
    $color = is_string($color) ? trim($color) : '';
    return preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color) ? $color : '';
}

/**
 * Título del listado genérico (index.php), según qué se esté mirando.
 * WordPress trae get_the_archive_title(), pero le antepone «Categoría:»,
 * «Búsqueda:» y demás, que acá no queremos.
 */
function cead_listing_title() {
    if (is_search())   { return sprintf(__('Resultados para «%s»', 'cead'), get_search_query()); }
    if (is_category()) { return single_cat_title('', false); }
    if (is_tag())      { return single_tag_title('', false); }
    if (is_author())   { return get_the_author(); }
    if (is_year())     { return get_the_date('Y'); }
    if (is_month())    { return get_the_date('F Y'); }
    if (is_day())      { return get_the_date(); }
    return __('Publicaciones del CEAD', 'cead');
}

/**
 * Etiqueta chica que va sobre el título del listado.
 */
function cead_listing_eyebrow() {
    if (is_search())   { return __('Búsqueda', 'cead'); }
    if (is_category()) { return __('Categoría', 'cead'); }
    if (is_tag())      { return __('Etiqueta', 'cead'); }
    if (is_author())   { return __('Autor', 'cead'); }
    if (is_date())     { return __('Archivo', 'cead'); }
    return __('Novedades', 'cead');
}
