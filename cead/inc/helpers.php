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
        'modulos' => [ 17, __( 'módulos', 'cead' ),          __( 'Partes independientes del sistema que cumplen funciones distintas y pueden actualizarse por separado.', 'cead' ) ],
        'tablas'  => [ 18, __( 'tablas propias', 'cead' ),   __( 'Estructuras creadas para guardar los datos del colegio de forma ordenada.', 'cead' ) ],
        'roles'   => [ 7,  __( 'roles', 'cead' ),            __( 'Dirección, secretaría, docente, delegado, estudiante, familia y consejo. Cada rol tiene permisos diferentes.', 'cead' ) ],
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
 * Las redes del colegio, resueltas en un solo lugar.
 *
 * La lista se armaba a mano dos veces —en el footer y en la sección de redes de
 * la portada— y cada copia resolvía la URL a su manera: la portada aceptaba una
 * URL propia por tarjeta y caía a la del footer, y el footer solo miraba la
 * suya. O sea que cargando la URL en un lado la portada la mostraba y el footer
 * no. Acá se resuelve una vez y las dos plantillas leen lo mismo.
 *
 * Una red sin URL —o con el `#` de relleno— no entra: mejor nada que un enlace
 * que no lleva a ningún lado.
 *
 * @return array<string,array<string,string>> slug => datos de la red
 */
function cead_social_defaults() {
    return [
        'ig' => [ 'n' => 1, 'name' => 'Instagram', 'handle' => '@cead_felix_de_guarania', 'color' => '#E1306C', 'url' => 'https://www.instagram.com/cead_felix_de_guarania' ],
        'fb' => [ 'n' => 2, 'name' => 'Facebook',  'handle' => 'CEAD Félix de Guarania',  'color' => '#1877F2', 'url' => 'https://www.facebook.com/100010029333535/' ],
    ];
}

function cead_social_links() {
    $out = [];
    foreach ( cead_social_defaults() as $slug => $d ) {
        /*
         * El default va acá y no solo en el Customizer, y eso arregla algo que
         * no se veía: `add_setting()` declara el valor por defecto para el
         * CONTROL, pero las plantillas leían con `get_theme_mod($id, '')`, o
         * sea pasando cadena vacía como respaldo. Resultado: abrías el
         * Customizer, veías la URL de Instagram cargada, y en el sitio no
         * aparecía ningún botón hasta que alguien entrara a guardar sin
         * cambiar nada.
         */
        $url = trim( (string) get_theme_mod( "cead_redes_{$d['n']}_url", '' ) );
        if ( '' === $url || '#' === $url ) {
            $url = trim( (string) get_theme_mod( "cead_social_{$slug}_url", $d['url'] ) );
        }
        if ( '' === $url || '#' === $url ) { continue; }

        // cead_sanitize_hex() devuelve '' si el valor no valida, y un custom
        // property vacío NO dispara el fallback de var(): se repone acá.
        $color = trim( (string) get_theme_mod( "cead_redes_{$d['n']}_color", $d['color'] ) );

        $out[ $slug ] = [
            'slug'   => $slug,
            'name'   => (string) get_theme_mod( "cead_redes_{$d['n']}_name",   $d['name'] ),
            'handle' => (string) get_theme_mod( "cead_redes_{$d['n']}_handle", $d['handle'] ),
            'color'  => '' !== $color ? $color : $d['color'],
            'url'    => $url,
        ];
    }
    return $out;
}

/**
 * El ícono de una red, como SVG en línea.
 *
 * En el footer las redes eran dos pastillas que decían «IG» y «FB» en texto.
 * Se entendía, pero era lo que más barato hacía ver el pie de la página. El SVG
 * va en línea y no como archivo para que herede `currentColor` y no cueste un
 * pedido más.
 */
function cead_social_icon( $slug ) {
    $iconos = [
        'ig' => '<path d="M12 2.2c3.2 0 3.6 0 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.25.07 1.63.07 4.81s-.01 3.56-.07 4.81c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.25.06-1.63.07-4.85.07s-3.6-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.8 3.8 0 0 1-1.38-.9 3.8 3.8 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.21 15.56 2.2 15.18 2.2 12s.01-3.56.07-4.81c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.44 2.21 8.82 2.2 12 2.2Zm0 1.8c-3.13 0-3.49.01-4.72.07-.9.04-1.39.19-1.71.32-.43.17-.74.37-1.06.69-.32.32-.52.63-.69 1.06-.13.32-.28.81-.32 1.71C3.44 9.08 3.43 9.44 3.43 12s.01 2.92.07 4.15c.04.9.19 1.39.32 1.71.17.43.37.74.69 1.06.32.32.63.52 1.06.69.32.13.81.28 1.71.32 1.23.06 1.59.07 4.72.07s3.49-.01 4.72-.07c.9-.04 1.39-.19 1.71-.32.43-.17.74-.37 1.06-.69.32-.32.52-.63.69-1.06.13-.32.28-.81.32-1.71.06-1.23.07-1.59.07-4.15s-.01-2.92-.07-4.15c-.04-.9-.19-1.39-.32-1.71a2.9 2.9 0 0 0-.69-1.06 2.9 2.9 0 0 0-1.06-.69c-.32-.13-.81-.28-1.71-.32C15.49 4.01 15.13 4 12 4Z"/><path d="M12 7.15a4.85 4.85 0 1 0 0 9.7 4.85 4.85 0 0 0 0-9.7Zm0 8a3.15 3.15 0 1 1 0-6.3 3.15 3.15 0 0 1 0 6.3Z"/><circle cx="17.05" cy="6.95" r="1.13"/>',
        'fb' => '<path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.49-3.91 3.77-3.91 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94Z"/>',
    ];
    if ( ! isset( $iconos[ $slug ] ) ) { return ''; }

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false">'
        . $iconos[ $slug ] . '</svg>';
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
