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
 * Los números que la página del proyecto afirma sobre el sistema.
 *
 * Viven acá, en un solo lugar, porque `bin/check-symbols.php` los compara
 * contra la realidad en cada push. Afirmar un número en la web es fácil;
 * acordarse de actualizarlo un año después, no. Cuando se escribieron a mano en
 * la documentación pasó exactamente eso: el resumen ejecutivo decía 17 módulos
 * cuando ya eran 18, y 6 tipos de contenido cuando ya eran 7.
 *
 * Si agregás un módulo o una tabla y no tocás esto, CI avisa.
 */
function cead_proyecto_numeros() {
    return [
        'modulos' => [ 18, __( 'módulos', 'cead' ),          __( 'Cada parte del sistema es una pieza aparte que se puede tocar sin romper el resto.', 'cead' ) ],
        'tablas'  => [ 18, __( 'tablas propias', 'cead' ),   __( 'Los datos del colegio en su propia estructura, no encajados a la fuerza en otra cosa.', 'cead' ) ],
        'roles'   => [ 7,  __( 'roles', 'cead' ),            __( 'Dirección, secretaría, docente, delegado, alumno, familia y consejo — cada uno con lo suyo.', 'cead' ) ],
        'cpts'    => [ 7,  __( 'tipos de contenido', 'cead' ), __( 'Cursos, comunicados, eventos, tareas, recursos, encuestas y preguntas frecuentes.', 'cead' ) ],
    ];
}

/**
 * Botón «Escuchar» de una sección de /proyecto.
 *
 * El botón se imprime SIEMPRE oculto (`hidden`) y lo revela el JavaScript solo
 * si el navegador puede reproducir algo. Así, si no hay voz sintética ni
 * archivo, nunca queda un control que parece clicable y no hace nada — que es
 * exactamente el bug que ya tuvimos en el buscador del nav.
 *
 * @param string $texto Lo que se lee en voz alta. Se escribe a mano, corto y
 *                      hablado: leer el texto de pantalla tal cual suena mal
 *                      (títulos sueltos, números, listas sin verbo).
 * @param string $audio Ruta opcional dentro de `assets/audio/`. Si el archivo
 *                      existe, se usa ese en vez de la voz del navegador.
 */
function cead_audio_button( $texto, $audio = '' ) {
    $src = '';
    if ( $audio ) {
        $rel = 'assets/audio/' . ltrim( $audio, '/' );
        // Solo se ofrece el archivo si de verdad está: un `src` roto sería un
        // botón que falla al primer clic.
        if ( file_exists( trailingslashit( get_template_directory() ) . $rel ) ) {
            $src = trailingslashit( CEAD_URI ) . $rel;
        }
    }
    ?>
    <button type="button" class="proyecto-audio" hidden
            aria-pressed="false"
            data-audio-text="<?php echo esc_attr( $texto ); ?>"
            <?php if ( $src ) : ?>data-audio-src="<?php echo esc_url( $src ); ?>"<?php endif; ?>>
        <span class="proyecto-audio-ico" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <path d="M11 5 6 9H2v6h4l5 4V5z"/>
                <path class="proyecto-audio-wave" d="M15.5 8.5a5 5 0 0 1 0 7"/>
                <path class="proyecto-audio-wave" d="M18.5 5.5a9 9 0 0 1 0 13"/>
            </svg>
        </span>
        <span class="proyecto-audio-txt"><?php esc_html_e( 'Escuchar', 'cead' ); ?></span>
    </button>
    <?php
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
