<?php
/**
 * Tipos de nota: con qué maqueta se dibuja cada publicación del sitio.
 *
 * Hasta acá todo lo que se publicaba —una crónica de la jornada deportiva, la
 * convocatoria a una reunión de padres, la suspensión de clases por lluvia—
 * salía con la misma maqueta de noticia: volanta, titular gigante, foto, texto.
 * Funciona para la crónica, que es para lo que se diseñó, y desperdicia a las
 * otras tres: en un aviso de suspensión lo único que importa es CUÁNDO, y
 * quedaba enterrado en el tercer párrafo.
 *
 * Un tipo de nota es un guion de lectura distinto, no una piel distinta. Por eso
 * son pocos y cada uno cambia la ESTRUCTURA de la página, no el color.
 *
 * ── Quién manda ──────────────────────────────────────────────────────────────
 *
 * El catálogo vive acá, en el tema, porque es una decisión de presentación: qué
 * maquetas existen depende de qué archivos hay en `template-parts/nota/`. El
 * plugin (que es quien escribe el tipo cuando CEADI publica desde WhatsApp) lo
 * lee por el filtro `cead_nota_tipos`, y si el filtro no devuelve nada —tema
 * viejo, tema cambiado— simplemente no elige tipo y todo sale como noticia.
 * Nunca se guarda un tipo que el tema no sepa dibujar.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Dónde se guarda el tipo. El plugin repite esta cadena; `bin/check-symbols.php` cruza que sean la misma. */
define( 'CEAD_NOTA_META', '_cead_nota_tipo' );
/** Datos que solo usan algunos tipos. */
define( 'CEAD_NOTA_FECHA', '_cead_nota_fecha' );
define( 'CEAD_NOTA_LUGAR', '_cead_nota_lugar' );
/** El tipo que se usa cuando no hay ninguno, o cuando el guardado no se sostiene. */
define( 'CEAD_NOTA_DEFECTO', 'noticia' );

/**
 * El catálogo.
 *
 * Cada entrada:
 *   label  — cómo se llama en wp-admin.
 *   pista  — cómo se le explica a CEADI cuándo corresponde. Se manda tal cual en
 *            la definición de la herramienta, así que tiene que distinguir este
 *            tipo de los otros cuatro, no describirlo en abstracto.
 *   pide   — campos SIN LOS CUALES la maqueta no tiene sentido. Un evento sin
 *            fecha dibujaría un bloque de fecha vacío; antes de eso, se degrada
 *            a noticia. Es la única validación dura y existe porque el modelo
 *            puede elegir bien el tipo y olvidarse del dato.
 */
function cead_nota_tipos_del_tema( $tipos ) {
	return array_merge( $tipos, [
		'noticia' => [
			'label' => __( 'Noticia', 'cead' ),
			'pista' => 'Crónica de algo que YA PASÓ o que se cuenta como relato: la jornada del viernes, '
				. 'la visita de una delegación, cómo salió el torneo. Es el formato por defecto: '
				. 'ante la duda, este.',
			'pide'  => [],
		],
		'evento' => [
			'label' => __( 'Evento', 'cead' ),
			'pista' => 'Algo que VA A PASAR en una fecha concreta y a lo que la gente tiene que ir o estar: '
				. 'acto, reunión de padres, jornada, torneo, fecha de examen, inscripciones que abren. '
				. 'Solo si sabés la fecha.',
			'pide'  => [ 'fecha' ],
		],
		'aviso' => [
			'label' => __( 'Aviso', 'cead' ),
			'pista' => 'Comunicación corta y urgente que le cambia el día a alguien: suspensión de clases, '
				. 'cambio de horario, cierre por feriado, corte de agua. Pocas líneas y sin vueltas.',
			'pide'  => [],
		],
		'informe' => [
			'label' => __( 'Informe', 'cead' ),
			'pista' => 'Texto institucional largo y con secciones, que se lee como documento y no como nota: '
				. 'memoria anual, reglamento, resultados de una evaluación, rendición de cuentas.',
			'pide'  => [],
		],
		'logro' => [
			'label' => __( 'Logro', 'cead' ),
			'pista' => 'Un reconocimiento o una victoria, donde el protagonista es QUIÉN lo consiguió: '
				. 'olimpiadas, un campeonato, una beca, un premio, un puesto en un concurso.',
			'pide'  => [],
		],
	] );
}
add_filter( 'cead_nota_tipos', 'cead_nota_tipos_del_tema' );

/**
 * El catálogo, ya filtrado. Es la misma llamada que hace el plugin.
 *
 * @return array<string,array<string,mixed>>
 */
function cead_nota_tipos() {
	$tipos = apply_filters( 'cead_nota_tipos', [] );
	return is_array( $tipos ) ? $tipos : [];
}

/** Los tipos de post que pueden llevar maqueta. */
function cead_nota_post_types() {
	return [ 'post', 'cead_noticia' ];
}

/**
 * El tipo de una nota, ya validado.
 *
 * Se valida acá y no solo al guardar porque el meta se puede escribir por
 * muchas puertas —el plugin, la pantalla de edición, una importación, alguien
 * con acceso a la base— y la página se dibuja igual con lo que encuentre. Que un
 * `evento` sin fecha caiga a `noticia` acá significa que la única forma de ver
 * el bloque de fecha vacío es no pasar por esta función.
 */
function cead_nota_tipo( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) { return CEAD_NOTA_DEFECTO; }

	$tipos = cead_nota_tipos();
	$slug  = sanitize_key( (string) get_post_meta( $post->ID, CEAD_NOTA_META, true ) );

	if ( '' === $slug || ! isset( $tipos[ $slug ] ) ) { return CEAD_NOTA_DEFECTO; }

	foreach ( (array) ( $tipos[ $slug ]['pide'] ?? [] ) as $campo ) {
		$meta = 'fecha' === $campo ? CEAD_NOTA_FECHA : CEAD_NOTA_LUGAR;
		if ( '' === trim( (string) get_post_meta( $post->ID, $meta, true ) ) ) {
			return CEAD_NOTA_DEFECTO;
		}
	}

	return $slug;
}

/**
 * Cuánto falta, en palabras.
 *
 * La fecha sola («14 de agosto») obliga a quien lee a hacer la cuenta contra el
 * día de hoy. Esta línea la hace por él, que es la mitad del valor de publicar
 * un evento.
 *
 * @param int $ts    Momento del evento.
 * @param int $ahora Referencia; se inyecta en los tests.
 */
function cead_nota_cuando( $ts, $ahora = null ) {
	$ahora = null === $ahora ? (int) current_time( 'timestamp' ) : (int) $ahora;
	if ( $ts <= 0 ) { return ''; }

	// Por días de calendario, no por múltiplos de 24 horas: a las 23:00 de un
	// lunes, un evento del martes a las 8:00 está a nueve horas, y decir «hoy»
	// —que es lo que da la resta— sería falso.
	$dia_ev = (int) floor( $ts / DAY_IN_SECONDS );
	$dia_ho = (int) floor( $ahora / DAY_IN_SECONDS );
	$faltan = $dia_ev - $dia_ho;

	if ( $faltan < 0 )  { return __( 'Ya pasó', 'cead' ); }
	if ( 0 === $faltan ) { return __( 'Es hoy', 'cead' ); }
	if ( 1 === $faltan ) { return __( 'Es mañana', 'cead' ); }
	/* translators: %d: cantidad de días que faltan para el evento */
	return sprintf( _n( 'Faltan %d día', 'Faltan %d días', $faltan, 'cead' ), $faltan );
}

/**
 * Le pone `id` a los `<h2>` del contenido y devuelve el índice.
 *
 * Solo lo usa el informe, que es el único tipo lo bastante largo como para que
 * saltar entre secciones valga la pena.
 *
 * @return array{0:string,1:array<int,array{id:string,texto:string}>}
 */
function cead_nota_indice( $html ) {
	$indice = [];
	$usados = [];

	$out = preg_replace_callback(
		'#<h2\b([^>]*)>(.*?)</h2>#is',
		static function ( $m ) use ( &$indice, &$usados ) {
			$texto = trim( wp_strip_all_tags( $m[2] ) );
			if ( '' === $texto ) { return $m[0]; }

			// Un `id` puesto a mano manda: si alguien ya enlazó a esa sección
			// desde otro lado, reescribirlo le rompe el enlace.
			if ( preg_match( '/\sid=["\']([^"\']+)["\']/i', $m[1], $mid ) ) {
				$id = $mid[1];
			} else {
				$base = sanitize_title( $texto ) ?: 'seccion';
				$id   = $base;
				$n    = 2;
				// Dos secciones con el mismo nombre existen de verdad
				// («Resultados» por curso): sin desambiguar, el índice llevaría
				// siempre a la primera.
				while ( isset( $usados[ $id ] ) ) { $id = $base . '-' . $n++; }
				$m[1] .= ' id="' . esc_attr( $id ) . '"';
			}

			$usados[ $id ] = true;
			$indice[]      = [ 'id' => $id, 'texto' => $texto ];

			return '<h2' . $m[1] . '>' . $m[2] . '</h2>';
		},
		(string) $html
	);

	// `preg_replace_callback` devuelve null si el motor se queda sin recursos
	// (contenido enorme, backtracking). Mejor el HTML sin índice que la página
	// en blanco.
	if ( null === $out ) { return [ (string) $html, [] ]; }

	return [ $out, $indice ];
}

/* ───────────────────────────── wp-admin ───────────────────────────── */

function cead_nota_register_meta() {
	foreach ( cead_nota_post_types() as $pt ) {
		register_post_meta( $pt, CEAD_NOTA_META,  [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_key' ] );
		register_post_meta( $pt, CEAD_NOTA_FECHA, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field' ] );
		register_post_meta( $pt, CEAD_NOTA_LUGAR, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field' ] );
	}
}
add_action( 'init', 'cead_nota_register_meta' );

function cead_nota_add_meta_box() {
	foreach ( cead_nota_post_types() as $pt ) {
		add_meta_box( 'cead_nota_tipo', __( 'Maqueta de la nota', 'cead' ), 'cead_nota_meta_box_cb', $pt, 'side', 'high' );
	}
}
add_action( 'add_meta_boxes', 'cead_nota_add_meta_box' );

function cead_nota_meta_box_cb( $post ) {
	wp_nonce_field( 'cead_nota_tipo', 'cead_nota_tipo_nonce' );
	$tipos  = cead_nota_tipos();
	$actual = sanitize_key( (string) get_post_meta( $post->ID, CEAD_NOTA_META, true ) ) ?: CEAD_NOTA_DEFECTO;
	$fecha  = (string) get_post_meta( $post->ID, CEAD_NOTA_FECHA, true );
	$lugar  = (string) get_post_meta( $post->ID, CEAD_NOTA_LUGAR, true );
	?>
	<p>
		<label for="cead_nota_tipo_sel"><strong><?php esc_html_e( 'Cómo se dibuja', 'cead' ); ?></strong></label><br>
		<select id="cead_nota_tipo_sel" name="cead_nota_tipo" style="width:100%">
			<?php foreach ( $tipos as $slug => $cfg ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $actual, $slug ); ?>>
					<?php echo esc_html( $cfg['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="cead_nota_fecha"><strong><?php esc_html_e( 'Fecha y hora', 'cead' ); ?></strong></label><br>
		<input type="datetime-local" id="cead_nota_fecha" name="cead_nota_fecha" style="width:100%"
		       value="<?php echo esc_attr( $fecha ? str_replace( ' ', 'T', substr( $fecha, 0, 16 ) ) : '' ); ?>">
	</p>
	<p>
		<label for="cead_nota_lugar"><strong><?php esc_html_e( 'Lugar', 'cead' ); ?></strong></label><br>
		<input type="text" id="cead_nota_lugar" name="cead_nota_lugar" style="width:100%"
		       value="<?php echo esc_attr( $lugar ); ?>" placeholder="<?php esc_attr_e( 'Ej: Patio central', 'cead' ); ?>">
	</p>
	<p class="description">
		<?php esc_html_e( 'Fecha y lugar solo los usa la maqueta «Evento». Sin fecha, un evento se publica como noticia.', 'cead' ); ?>
	</p>
	<?php
}

function cead_nota_save_meta( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( empty( $_POST['cead_nota_tipo_nonce'] ) ) { return; }
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cead_nota_tipo_nonce'] ) ), 'cead_nota_tipo' ) ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

	$tipos = cead_nota_tipos();
	$slug  = sanitize_key( wp_unslash( $_POST['cead_nota_tipo'] ?? '' ) );
	// Un slug que el tema no sabe dibujar no se guarda: la página saldría igual
	// como noticia, pero el selector mostraría un tipo que no es el real.
	if ( ! isset( $tipos[ $slug ] ) ) { $slug = CEAD_NOTA_DEFECTO; }
	update_post_meta( $post_id, CEAD_NOTA_META, $slug );

	$fecha = sanitize_text_field( wp_unslash( $_POST['cead_nota_fecha'] ?? '' ) );
	update_post_meta( $post_id, CEAD_NOTA_FECHA, $fecha ? str_replace( 'T', ' ', $fecha ) . ':00' : '' );
	update_post_meta( $post_id, CEAD_NOTA_LUGAR, sanitize_text_field( wp_unslash( $_POST['cead_nota_lugar'] ?? '' ) ) );
}
add_action( 'save_post', 'cead_nota_save_meta' );
