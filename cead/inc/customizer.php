<?php
/**
 * Customizer — todos los settings/controles del tema.
 */

if (!defined('ABSPATH')) exit;

function cead_customize_register($wp_customize) {

    /* ============ PANEL CONTENIDO ============ */
    $wp_customize->add_panel('cead_panel_content', [
        'title'    => 'CEAD — Contenido',
        'priority' => 30,
    ]);

    /* ---------- SECCIÓN: Colores de marca ---------- */
    $wp_customize->add_section('cead_sec_brand', [
        'title' => 'Colores de marca',
        'panel' => 'cead_panel_content',
    ]);
    $colors = [
        'cead_color_brand'      => ['#E93B3C', 'Brand (rojo)'],
        'cead_color_brand_deep' => ['#B82A2B', 'Brand deep'],
        'cead_color_brand_tint' => ['#FCE7E7', 'Brand tint'],
        'cead_color_acc_blue'   => ['#49A3C8', 'Acento azul'],
        'cead_color_acc_yellow' => ['#EDDF58', 'Acento amarillo'],
        'cead_color_acc_orange' => ['#F4B74C', 'Acento naranja'],
    ];
    foreach ($colors as $id => [$def, $label]) {
        $wp_customize->add_setting($id, ['default' => $def, 'sanitize_callback' => 'cead_sanitize_hex']);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $id, ['label' => $label, 'section' => 'cead_sec_brand']));
    }

    /* ---------- SECCIÓN: Hero ---------- */
    $wp_customize->add_section('cead_sec_hero', [
        'title' => 'Hero (portada)',
        'panel' => 'cead_panel_content',
    ]);
    cead_add_text($wp_customize, 'cead_hero_eyebrow',   'Eyebrow',            'Bachillerato Técnico — Caaguazú, Paraguay', 'cead_sec_hero');
    cead_add_textarea($wp_customize, 'cead_hero_title', 'Título (HTML permitido)', 'Formar con<br><span class="hero-accent">honor</span> y rigor.', 'cead_sec_hero');
    cead_add_textarea($wp_customize, 'cead_hero_body',  'Bajada', 'Centro Educativo de Alto Desempeño “Félix de Guarania”. Una institución pública que forma técnicos con visión académica internacional, raíz paraguaya y disciplina sostenida.', 'cead_sec_hero');
    cead_add_text($wp_customize, 'cead_hero_btn1_text', 'Botón 1 — texto', '→ Conocer CEAD', 'cead_sec_hero');
    cead_add_text($wp_customize, 'cead_hero_btn1_url',  'Botón 1 — URL',   '#Creemos',       'cead_sec_hero', 'esc_url_raw');
    cead_add_text($wp_customize, 'cead_hero_btn2_text', 'Botón 2 — texto', '→ Admisión 2026', 'cead_sec_hero');
    cead_add_text($wp_customize, 'cead_hero_btn2_url',  'Botón 2 — URL',   '#Admision',      'cead_sec_hero', 'esc_url_raw');

    $wp_customize->add_setting('cead_hero_image', [
        'default' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=2000&q=80',
        'sanitize_callback' => 'esc_url_raw',
    ]);
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'cead_hero_image', [
        'label'   => 'Imagen de fondo del Hero',
        'section' => 'cead_sec_hero',
    ]));

    /* ---------- SECCIÓN: Valores ---------- */
    $wp_customize->add_section('cead_sec_values', [
        'title' => 'Sección Valores',
        'panel' => 'cead_panel_content',
    ]);
    cead_add_text($wp_customize, 'cead_values_eyebrow', 'Eyebrow', '— Creemos', 'cead_sec_values');
    cead_add_textarea($wp_customize, 'cead_values_title', 'Título (HTML)', 'Cuatro valores.<br>Una sola institución.', 'cead_sec_values');
    cead_add_textarea($wp_customize, 'cead_values_intro', 'Intro', 'Toda la cultura de CEAD descansa sobre estos cuatro pilares. No son aspiraciones: son criterios diarios de admisión, evaluación y graduación.', 'cead_sec_values');

    $valores_default = [
        ['Honor',      '#E93B3C', 'square',  'Coherencia entre palabra, acción y compromiso. La palabra dada es contrato dentro y fuera del aula.'],
        ['Disciplina', '#49A3C8', 'diamond', 'Constancia diaria, no esfuerzo episódico. El estudio sostenido construye lo que el talento solo no alcanza.'],
        ['Respeto',    '#EDDF58', 'rect',    'A docentes, compañeros, familias y la lengua guaraní. El respeto es la infraestructura de toda comunidad.'],
        ['Integridad', '#F4B74C', 'circle',  'Decisión correcta cuando nadie observa. La integridad no se enseña, se entrena en cada examen y cada práctica.'],
    ];
    foreach ($valores_default as $i => $v) {
        $n = $i + 1;
        cead_add_text($wp_customize,      "cead_value_{$n}_title", "Valor $n — Título", $v[0], 'cead_sec_values');
        $wp_customize->add_setting("cead_value_{$n}_color", ['default' => $v[1], 'sanitize_callback' => 'cead_sanitize_hex']);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, "cead_value_{$n}_color", ['label' => "Valor $n — Color", 'section' => 'cead_sec_values']));
        $wp_customize->add_setting("cead_value_{$n}_shape", ['default' => $v[2], 'sanitize_callback' => 'cead_sanitize_shape']);
        $wp_customize->add_control("cead_value_{$n}_shape", [
            'label'   => "Valor $n — Forma",
            'section' => 'cead_sec_values',
            'type'    => 'select',
            'choices' => ['square' => 'Cuadrado', 'diamond' => 'Rombo', 'rect' => 'Rectángulo', 'circle' => 'Círculo'],
        ]);
        cead_add_textarea($wp_customize, "cead_value_{$n}_body", "Valor $n — Texto", $v[3], 'cead_sec_values');
    }

    /* ---------- SECCIÓN: Cita / Honor Code ---------- */
    $wp_customize->add_section('cead_sec_quote', [
        'title' => 'Cita Honor Code',
        'panel' => 'cead_panel_content',
    ]);
    cead_add_text($wp_customize, 'cead_quote_eyebrow', 'Eyebrow', '— Honor Code', 'cead_sec_quote');
    cead_add_textarea($wp_customize, 'cead_quote_text', 'Cita (HTML)', '“Estudiamos con honor. Convivimos con respeto.<br>Trabajamos con disciplina. Egresamos con integridad.”', 'cead_sec_quote');
    cead_add_text($wp_customize, 'cead_quote_attr', 'Atribución', 'Compromiso del estudiante CEAD · Cohorte 2026', 'cead_sec_quote');

    /* ---------- SECCIÓN: Marquesina ---------- */
    $wp_customize->add_section('cead_sec_marquee', [
        'title' => 'Marquesina infinita',
        'panel' => 'cead_panel_content',
    ]);
    cead_add_textarea($wp_customize, 'cead_marquee_items', 'Items (uno por línea)', "Honor\nDisciplina\nRespeto\nIntegridad\nCaaguazú\nFélix de Guarania", 'cead_sec_marquee');

    /* ---------- SECCIÓN: Divisiones (intro de la sección) ---------- */
    $wp_customize->add_section('cead_sec_divisions', [
        'title' => 'Bachilleratos (intro)',
        'panel' => 'cead_panel_content',
        'description' => 'El contenido de cada bachillerato se edita en <em>Divisiones</em> en el menú lateral.',
    ]);
    cead_add_text($wp_customize, 'cead_divs_eyebrow', 'Eyebrow', '— Bachilleratos', 'cead_sec_divisions');
    cead_add_textarea($wp_customize, 'cead_divs_title', 'Título (HTML)', 'Cuatro caminos.<br>Una misma exigencia.', 'cead_sec_divisions');
    cead_add_textarea($wp_customize, 'cead_divs_intro', 'Intro', 'Cada bachillerato tiene su propia identidad y currículum. Todos comparten el mismo estándar académico y el mismo Honor Code.', 'cead_sec_divisions');

    /* ---------- SECCIÓN: Vida estudiantil (intro) ---------- */
    $wp_customize->add_section('cead_sec_life', [
        'title' => 'Vida estudiantil (intro)',
        'panel' => 'cead_panel_content',
        'description' => 'El contenido de cada item se edita en <em>Vida estudiantil</em> en el menú lateral.',
    ]);
    cead_add_text($wp_customize, 'cead_life_eyebrow', 'Eyebrow', '— Vida estudiantil', 'cead_sec_life');
    cead_add_textarea($wp_customize, 'cead_life_title', 'Título', 'Lo que pasa fuera del aula define al estudiante.', 'cead_sec_life');

    /* ---------- SECCIÓN: Galería (portada) ---------- */
    $wp_customize->add_section('cead_sec_galeria', [
        'title'       => 'Galería (portada)',
        'panel'       => 'cead_panel_content',
        'description' => 'Vistazos previos en la portada. Los álbumes se cargan en <em>Galería</em> en el menú lateral; acá podés además fijar hasta dos fotos destacadas (por ejemplo, las aéreas del campus) que van primeras en el mosaico.',
    ]);
    cead_add_text($wp_customize, 'cead_galeria_eyebrow', 'Eyebrow', '— Galería', 'cead_sec_galeria');
    cead_add_textarea($wp_customize, 'cead_galeria_home_title', 'Título (HTML)', 'El campus, desde adentro<br>y desde el aire.', 'cead_sec_galeria');
    cead_add_textarea($wp_customize, 'cead_galeria_intro', 'Intro', 'Actos, competencias, aulas y proyectos. Un vistazo a lo que se vive todos los días en el CEAD.', 'cead_sec_galeria');
    cead_add_text($wp_customize, 'cead_galeria_btn_text', 'Botón — texto', '→ Ver la galería completa', 'cead_sec_galeria');

    for ($n = 1; $n <= 2; $n++) {
        $wp_customize->add_setting("cead_galeria_foto_{$n}", ['default' => '', 'sanitize_callback' => 'esc_url_raw']);
        $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, "cead_galeria_foto_{$n}", [
            'label'   => "Foto destacada $n",
            'section' => 'cead_sec_galeria',
        ]));
        cead_add_text($wp_customize, "cead_galeria_foto_{$n}_caption", "Foto destacada $n — Título", '', 'cead_sec_galeria');
        cead_add_text($wp_customize, "cead_galeria_foto_{$n}_tag",     "Foto destacada $n — Etiqueta", '', 'cead_sec_galeria');
    }

    /* ---------- SECCIÓN: Redes sociales (portada) ---------- */
    $wp_customize->add_section('cead_sec_redes', [
        'title'       => 'Redes sociales (portada)',
        'panel'       => 'cead_panel_content',
        'description' => 'Tarjetas que redirigen a los perfiles. Si dejás la URL vacía se usa la que ya está cargada en <em>Footer / Contacto</em>. Las tarjetas sin URL no se muestran.',
    ]);
    cead_add_text($wp_customize, 'cead_redes_eyebrow', 'Eyebrow', '— Redes', 'cead_sec_redes');
    cead_add_textarea($wp_customize, 'cead_redes_title', 'Título (HTML)', 'Seguinos.', 'cead_sec_redes');
    cead_add_textarea($wp_customize, 'cead_redes_intro', 'Intro', 'Publicamos actos, resultados y avisos en nuestras redes. Ahí también se anuncian los cambios de calendario.', 'cead_sec_redes');

    $redes_default = [
        1 => ['Instagram', '@cead_felix_de_guarania', '#E1306C'],
        2 => ['Facebook',  'CEAD Félix de Guarania',  '#1877F2'],
    ];
    foreach ($redes_default as $n => $d) {
        cead_add_text($wp_customize, "cead_redes_{$n}_name",   "Red $n — Nombre",  $d[0], 'cead_sec_redes');
        cead_add_text($wp_customize, "cead_redes_{$n}_handle", "Red $n — Usuario", $d[1], 'cead_sec_redes');
        cead_add_text($wp_customize, "cead_redes_{$n}_url",    "Red $n — URL",     '',    'cead_sec_redes', 'esc_url_raw');
        $wp_customize->add_setting("cead_redes_{$n}_color", ['default' => $d[2], 'sanitize_callback' => 'cead_sanitize_hex']);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, "cead_redes_{$n}_color", [
            'label'   => "Red $n — Color",
            'section' => 'cead_sec_redes',
        ]));
    }

    /* ---------- SECCIÓN: Admisión ---------- */
    $wp_customize->add_section('cead_sec_admission', [
        'title' => 'Admisión',
        'panel' => 'cead_panel_content',
    ]);
    cead_add_text($wp_customize, 'cead_adm_eyebrow', 'Eyebrow', '— Admisión 2026', 'cead_sec_admission');
    cead_add_textarea($wp_customize, 'cead_adm_title', 'Título (HTML)', 'Postulá<br>a CEAD.', 'cead_sec_admission');
    cead_add_textarea($wp_customize, 'cead_adm_body',  'Texto', 'El proceso de admisión 2026 está abierto. Cuatro divisiones técnicas, evaluación académica, entrevista con familia y carta de compromiso al Honor Code.', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_btn1_text', 'Botón 1 — texto', '→ Inscribirse', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_btn1_url',  'Botón 1 — URL',   '#', 'cead_sec_admission', 'esc_url_raw');
    cead_add_text($wp_customize, 'cead_adm_btn2_text', 'Botón 2 — texto', '→ Calendario', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_btn2_url',  'Botón 2 — URL',   '#', 'cead_sec_admission', 'esc_url_raw');

    $wp_customize->add_setting('cead_adm_image', [
        'default' => 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=2000&q=80',
        'sanitize_callback' => 'esc_url_raw',
    ]);
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'cead_adm_image', [
        'label' => 'Imagen de fondo',
        'section' => 'cead_sec_admission',
    ]));

    // Stats (3)
    cead_add_text($wp_customize, 'cead_adm_stat1_n', 'Stat 1 — Número', '400+', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_stat1_l', 'Stat 1 — Label',  'Estudiantes', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_stat2_n', 'Stat 2 — Número', '04', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_stat2_l', 'Stat 2 — Label',  'Bachilleratos', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_stat3_n', 'Stat 3 — Número', '2009', 'cead_sec_admission');
    cead_add_text($wp_customize, 'cead_adm_stat3_l', 'Stat 3 — Label',  'Fundación', 'cead_sec_admission');

    /* ---------- SECCIÓN: Footer ---------- */
    $wp_customize->add_section('cead_sec_footer', [
        'title' => 'Footer / Contacto',
        'panel' => 'cead_panel_content',
    ]);
    cead_add_textarea($wp_customize, 'cead_footer_about', 'Texto institucional', 'Centro Educativo de Alto Desempeño “Félix de Guarania”. Bachillerato público fundado en 2009 en Caaguazú, Paraguay.', 'cead_sec_footer');
    cead_add_text($wp_customize, 'cead_footer_site_label', 'Label del sitio',  'cead.caaguazú.net →', 'cead_sec_footer');
    cead_add_text($wp_customize, 'cead_footer_site_url',   'URL del sitio',     'https://cead.caaguazu.net', 'cead_sec_footer', 'esc_url_raw');
    cead_add_text($wp_customize, 'cead_contact_email',     'Email para formulario', 'contacto@cead.caaguazu.net', 'cead_sec_footer', 'sanitize_email');
    cead_add_text($wp_customize, 'cead_contact_from_email','Email "From" (remitente del servidor)', 'web@cead.caaguazu.net', 'cead_sec_footer', 'sanitize_email');

    cead_add_text($wp_customize, 'cead_footer_note', 'Nota del pie (junto al copyright)', 'Caaguazú, Paraguay', 'cead_sec_footer');
    cead_add_text($wp_customize, 'cead_footer_address', 'Dirección', 'GXGQ+G6J, Bienvenido Gallardo Goiris, Caaguazú 3400', 'cead_sec_footer');
    cead_add_text($wp_customize, 'cead_footer_map_url', 'Dirección — enlace a Maps (vacío = búsqueda automática)', '', 'cead_sec_footer', 'esc_url_raw');

    // Redes sociales: el CEAD solo tiene Instagram y Facebook.
    $cead_socials = [
        'ig' => ['IG', 'https://www.instagram.com/cead_felix_de_guarania'],
        'fb' => ['FB', 'https://www.facebook.com/100010029333535/'],
    ];
    foreach ($cead_socials as $key => $vals) {
        cead_add_text($wp_customize, "cead_social_{$key}_label", "Red social $key — etiqueta", $vals[0], 'cead_sec_footer');
        cead_add_text($wp_customize, "cead_social_{$key}_url",   "Red social $key — URL",      $vals[1], 'cead_sec_footer', 'esc_url_raw');
    }
}
add_action('customize_register', 'cead_customize_register');

/* ---------- Helpers internos ---------- */

function cead_add_text($wp_customize, $id, $label, $default, $section, $sanitize = 'wp_kses_post') {
    $wp_customize->add_setting($id, ['default' => $default, 'sanitize_callback' => $sanitize]);
    $wp_customize->add_control($id, ['label' => $label, 'section' => $section, 'type' => 'text']);
}

function cead_add_textarea($wp_customize, $id, $label, $default, $section, $sanitize = 'wp_kses_post') {
    $wp_customize->add_setting($id, ['default' => $default, 'sanitize_callback' => $sanitize]);
    $wp_customize->add_control($id, ['label' => $label, 'section' => $section, 'type' => 'textarea']);
}

function cead_sanitize_shape($v) {
    return in_array($v, ['square', 'diamond', 'rect', 'circle'], true) ? $v : 'square';
}
