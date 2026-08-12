<?php
/**
 * «Web del colegio»: los datos públicos del sitio, dentro del menú CEAD.
 *
 * Los enlaces de las redes y los datos de contacto del pie son opciones DE
 * TEMA, así que viven donde WordPress las guarda: los `theme_mod` del
 * Customizer. El problema nunca fue dónde se guardan sino dónde se buscan —
 * todo lo demás del colegio (invitaciones, cursos, WhatsApp, CEADI) se
 * administra desde el menú «CEAD Académico», y estos campos quedaban tres
 * niveles adentro de Apariencia → Personalizar, que es el último lugar donde
 * alguien los va a ir a buscar.
 *
 * Esta pantalla es OTRA VISTA de los mismos valores, no una copia: escribe los
 * mismos `theme_mod` que lee el Customizer, así que cambiar acá se ve allá y al
 * revés. Dos lugares para editar está bien; dos lugares donde se guarda, no.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Nota sobre dónde se guardan los enlaces.
 *
 * La URL de cada red vive por duplicado en el tema: `cead_social_*_url` para el
 * pie y `cead_redes_N_url` para la tarjeta de la portada, y la de la tarjeta
 * PISA a la del pie cuando está cargada. Esta pantalla escribe las dos con el
 * mismo valor, así no existe el caso de cambiar el enlace acá y que la portada
 * siga mandando al viejo.
 *
 * La lista de redes sale de `cead_social_defaults()` (inc/helpers.php), la
 * misma que usa el sitio para dibujarlas: si mañana se suma una, aparece acá
 * sola.
 */

/**
 * Registra la pantalla bajo el menú del plugin.
 *
 * Prioridad 20 para correr DESPUÉS de que el plugin registre su menú (que va en
 * la prioridad 10 por defecto), y se comprueba en `$admin_page_hooks` —el
 * registro que WordPress llena en `add_menu_page()`— que ese menú exista de
 * verdad. Sin la comprobación, con el plugin apagado o con alguien que no es
 * staff, `add_submenu_page()` crearía una página huérfana: alcanzable por URL y
 * ausente de todo menú. Ahí cae a Apariencia, que es su casa natural.
 */
add_action( 'admin_menu', function () {
    if ( ! current_user_can( 'edit_theme_options' ) ) { return; }

    $tiene_cead = isset( $GLOBALS['admin_page_hooks']['cead-acad'] );

    add_submenu_page(
        $tiene_cead ? 'cead-acad' : 'themes.php',
        __( 'Web del colegio', 'cead' ),
        __( 'Web del colegio', 'cead' ),
        'edit_theme_options',
        'cead-web',
        'cead_web_render'
    );
}, 20 );

/** Guarda. Vuelve a la misma pantalla con el resultado en la URL. */
function cead_web_guardar() {
    if ( ! current_user_can( 'edit_theme_options' ) ) {
        wp_die( esc_html__( 'Sin permiso para cambiar los datos del sitio.', 'cead' ) );
    }
    check_admin_referer( 'cead_web_guardar' );

    foreach ( cead_social_defaults() as $slug => $d ) {
        $url = esc_url_raw( trim( (string) wp_unslash( $_POST[ "url_{$slug}" ] ?? '' ) ) );
        // Las dos claves con el mismo valor: ver la nota de arriba.
        set_theme_mod( "cead_social_{$slug}_url", $url );
        set_theme_mod( "cead_redes_{$d['n']}_url", $url );

        set_theme_mod( "cead_redes_{$d['n']}_handle", sanitize_text_field( wp_unslash( $_POST[ "handle_{$slug}" ] ?? '' ) ) );
    }

    set_theme_mod( 'cead_footer_address',  sanitize_text_field( wp_unslash( $_POST['direccion'] ?? '' ) ) );
    set_theme_mod( 'cead_footer_map_url',  esc_url_raw( trim( (string) wp_unslash( $_POST['mapa'] ?? '' ) ) ) );
    set_theme_mod( 'cead_contact_email',   sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
    set_theme_mod( 'cead_footer_site_url', esc_url_raw( trim( (string) wp_unslash( $_POST['sitio_url'] ?? '' ) ) ) );
    set_theme_mod( 'cead_footer_note',     sanitize_text_field( wp_unslash( $_POST['nota'] ?? '' ) ) );

    wp_safe_redirect( add_query_arg( [ 'page' => 'cead-web', 'ok' => '1' ], admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_cead_web_guardar', 'cead_web_guardar' );

/** Un campo de texto con su etiqueta y su ayuda. */
function cead_web_campo( $name, $label, $valor, $ph = '', $tipo = 'text', $ayuda = '' ) {
    $id = 'cead-web-' . $name;
    echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
    printf(
        '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
        esc_attr( $tipo ),
        esc_attr( $id ),
        esc_attr( $name ),
        esc_attr( $valor ),
        esc_attr( $ph )
    );
    if ( $ayuda ) {
        echo '<p class="description">' . esc_html( $ayuda ) . '</p>';
    }
    echo '</td></tr>';
}

function cead_web_render() {
    if ( ! current_user_can( 'edit_theme_options' ) ) { return; }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Web del colegio', 'cead' ); ?></h1>

        <?php if ( isset( $_GET['ok'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Listo, los datos del sitio quedaron actualizados.', 'cead' ); ?></p></div>
        <?php endif; ?>

        <p><?php esc_html_e( 'Lo que la web pública muestra del colegio: las redes, la dirección y el correo del formulario de contacto. Se ven en el pie de página y en la sección «Seguinos» de la portada.', 'cead' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="cead_web_guardar">
            <?php wp_nonce_field( 'cead_web_guardar' ); ?>

            <h2><?php esc_html_e( 'Redes sociales', 'cead' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Una red sin enlace no se dibuja en ningún lado: mejor nada que un botón que no lleva a ninguna parte.', 'cead' ); ?>
            </p>
            <table class="form-table" role="presentation">
                <?php foreach ( cead_social_defaults() as $slug => $d ) :
                    // Se lee con los MISMOS respaldos que usa el sitio para
                    // dibujarlas: lo que muestra este formulario es lo que hay
                    // publicado, no un campo vacío al lado de un botón que
                    // igual aparece.
                    $url = (string) get_theme_mod( "cead_redes_{$d['n']}_url", '' );
                    if ( '' === $url || '#' === $url ) { $url = (string) get_theme_mod( "cead_social_{$slug}_url", $d['url'] ); }
                    cead_web_campo( "url_{$slug}", $d['name'], $url, $d['url'], 'url' );
                    cead_web_campo(
                        "handle_{$slug}",
                        /* translators: %s: nombre de la red social */
                        sprintf( __( '%s — usuario', 'cead' ), $d['name'] ),
                        (string) get_theme_mod( "cead_redes_{$d['n']}_handle", $d['handle'] ),
                        $d['handle'],
                        'text',
                        __( 'Se muestra en la tarjeta de la portada, debajo del nombre de la red.', 'cead' )
                    );
                endforeach; ?>
            </table>

            <h2><?php esc_html_e( 'Contacto y ubicación', 'cead' ); ?></h2>
            <table class="form-table" role="presentation">
                <?php
                /*
                 * Cada campo se lee con el MISMO respaldo que usa la plantilla
                 * que lo imprime (footer.php e inc/contact.php). Si acá se
                 * leyera con cadena vacía, el formulario mostraría campos en
                 * blanco mientras el sitio publica la dirección y el correo:
                 * uno los completaría creyendo que faltaban.
                 */
                cead_web_campo( 'direccion', __( 'Dirección', 'cead' ), (string) get_theme_mod( 'cead_footer_address', 'GXGQ+G6J, Bienvenido Gallardo Goiris, Caaguazú 3400' ), '' );
                cead_web_campo( 'mapa', __( 'Enlace a Maps', 'cead' ), (string) get_theme_mod( 'cead_footer_map_url', '' ), '', 'url', __( 'Vacío: se arma solo buscando la dirección de arriba en Google Maps.', 'cead' ) );
                cead_web_campo( 'email', __( 'Correo del formulario', 'cead' ), (string) get_theme_mod( 'cead_contact_email', 'contacto@cead.caaguazu.net' ), '', 'email', __( 'A esta casilla llega lo que la gente escribe en el formulario de contacto del pie.', 'cead' ) );
                cead_web_campo( 'sitio_url', __( 'Sitio web', 'cead' ), (string) get_theme_mod( 'cead_footer_site_url', 'https://cead.caaguazu.net' ), '', 'url' );
                cead_web_campo( 'nota', __( 'Nota del pie', 'cead' ), (string) get_theme_mod( 'cead_footer_note', 'Caaguazú, Paraguay' ), '' );
                ?>
            </table>

            <?php submit_button( __( 'Guardar', 'cead' ) ); ?>
        </form>

        <hr>

        <h2><?php esc_html_e( 'Dos cosas distintas que se llaman «Instagram»', 'cead' ); ?></h2>
        <p>
            <?php esc_html_e( 'Lo de arriba es el ENLACE a la cuenta: el botón que lleva al perfil desde la web. Otra cosa es el detector automático, que revisa la cuenta cada tanto y le manda a CEADI un borrador de nota cuando se publica algo nuevo — eso se configura aparte, junto con lo demás de WhatsApp.', 'cead' ); ?>
        </p>
        <?php if ( isset( $GLOBALS['admin_page_hooks']['cead-acad'] ) ) : ?>
            <p>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-whatsapp' ) ); ?>">
                    <?php esc_html_e( 'Ir al detector de Instagram (WhatsApp)', 'cead' ); ?>
                </a>
            </p>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Lo demás de la web', 'cead' ); ?></h2>
        <p>
            <?php esc_html_e( 'Los textos largos, los colores y las imágenes de cada sección se editan en el Customizer, viéndolos en vivo sobre la página.', 'cead' ); ?>
        </p>
        <p>
            <a class="button" href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>">
                <?php esc_html_e( 'Abrir el Customizer', 'cead' ); ?>
            </a>
        </p>
    </div>
    <?php
}
