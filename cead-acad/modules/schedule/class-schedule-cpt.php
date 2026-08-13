<?php
/**
 * CPT cead_acad_event: eventos / horarios / reuniones / exámenes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Schedule_CPT {

	const POST_TYPE = 'cead_acad_event';

	/*
	 * Variedad a propósito: un colegio real tiene eventos de naturaleza muy
	 * distinta —académicos, administrativos, sociales, de descanso— y
	 * mientras más específico el tipo, menos eventos caen en el cajón
	 * genérico "evento" (que es donde se pierden en el calendario).
	 */
	const TYPES = [ 'clase', 'reunion', 'examen', 'entrega', 'feriado', 'acto', 'excursion', 'cierre', 'evento' ];

	/**
	 * Color por defecto de cada tipo, para cuando el evento no trae uno
	 * propio (`_cead_acad_event_color`). "cierre" es el único pensado para
	 * períodos largos (vacaciones, período de exámenes) — un color apagado
	 * a propósito, porque se dibuja como franja de fondo, no como punto.
	 */
	const DEFAULT_COLORS = [
		'clase'     => '#8A8F98',
		'reunion'   => '#49A3C8',
		'examen'    => '#E93B3C',
		'entrega'   => '#F4B74C',
		'feriado'   => '#5FAE6B',
		'acto'      => '#9B6FB0',
		'excursion' => '#2AAFA0',
		'cierre'    => '#B08968',
		'evento'    => '#EDDF58',
	];

	public function boot() {
		add_action( 'init',          [ $this, 'register' ], 10 );
		add_action( 'admin_notices', [ $this, 'import_notice' ] );
	}

	public function import_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== self::POST_TYPE ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		printf(
			/* translators: %s URL del importador */
			esc_html__( 'Podés cargar un evento suelto con «Añadir evento», o varios de una vez desde %s. El horario semanal de clases se carga en cada Curso, no acá.', 'cead-acad' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ) . '"><strong>' . esc_html__( 'Importadores', 'cead-acad' ) . '</strong></a>'
		);
		echo '</p></div>';
	}

	public function register() {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => __( 'Eventos', 'cead-acad' ),
				'singular_name' => __( 'Evento', 'cead-acad' ),
				'edit_item'     => __( 'Editar evento', 'cead-acad' ),
				'add_new'       => __( 'Añadir evento', 'cead-acad' ),
				'add_new_item'  => __( 'Añadir evento', 'cead-acad' ),
				'new_item'      => __( 'Nuevo evento', 'cead-acad' ),
				'view_item'     => __( 'Ver evento', 'cead-acad' ),
				'search_items'  => __( 'Buscar eventos', 'cead-acad' ),
				'menu_name'     => __( 'Eventos', 'cead-acad' ),
				'not_found'     => __( 'Sin eventos.', 'cead-acad' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'cead-acad',
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-calendar-alt',
			'supports'            => [ 'title', 'editor', 'author' ],
			'has_archive'         => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			// Se pueden crear a mano (uno suelto: un acto, una reunión) además de
			// importarlos en lote. Antes estaba en 'do_not_allow', lo que obligaba
			// a armar un CSV incluso para cargar un único evento.
		] );

		foreach ( [
			'_cead_acad_event_start'    => 'string',
			'_cead_acad_event_end'      => 'string',
			'_cead_acad_event_all_day'  => 'boolean',
			'_cead_acad_event_location' => 'string',
			'_cead_acad_event_type'     => 'string',
		] as $key => $type ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function () { return current_user_can( 'cead_acad_manage_schedule' ); },
			] );
		}

		// Color propio (opcional): vacío = usar el color por defecto del tipo.
		register_post_meta( self::POST_TYPE, '_cead_acad_event_color', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ) { return (string) ( sanitize_hex_color( $value ) ?? '' ); },
			'auth_callback'     => static function () { return current_user_can( 'cead_acad_manage_schedule' ); },
		] );
	}

	public static function type_label( $type ) {
		$labels = [
			'clase'     => __( 'Clase', 'cead-acad' ),
			'reunion'   => __( 'Reunión', 'cead-acad' ),
			'examen'    => __( 'Examen', 'cead-acad' ),
			'entrega'   => __( 'Entrega de trabajo', 'cead-acad' ),
			'feriado'   => __( 'Feriado', 'cead-acad' ),
			'acto'      => __( 'Acto', 'cead-acad' ),
			'excursion' => __( 'Excursión / salida', 'cead-acad' ),
			'cierre'    => __( 'Fecha de cierre (período largo)', 'cead-acad' ),
			'evento'    => __( 'Evento', 'cead-acad' ),
		];
		return $labels[ $type ] ?? $type;
	}

	/** Color por defecto del tipo. 'evento' si el tipo no se reconoce. */
	public static function default_color( $type ) {
		return self::DEFAULT_COLORS[ $type ] ?? self::DEFAULT_COLORS['evento'];
	}

	/**
	 * El ícono de cada tipo, como SVG en línea.
	 *
	 * El color solo no alcanzaba. En una celda de calendario un evento es una
	 * barrita de nueve píxeles, y nueve píxeles de color no dicen si eso es un
	 * examen o un feriado: hay que ir a buscar la referencia, contar cuál de
	 * los nueve tonos es, y para entonces ya perdiste el vistazo. Con una
	 * figura encima se lee de una, y en el que no distingue bien los colores
	 * —que en un colegio es uno de cada doce varones— pasa de adivinanza a
	 * información.
	 *
	 * Trazo y no relleno, para que herede `currentColor` y sirva sobre claro y
	 * sobre oscuro sin dos juegos de íconos.
	 *
	 * @param string $type Tipo de evento.
	 * @param int    $px   Lado del cuadrado, en píxeles.
	 */
	public static function type_icon( $type, $px = 12 ) {
		$trazos = [
			// Birrete: una clase.
			'clase'     => '<path d="M2 8 12 3l10 5-10 5Z"/><path d="M6 10.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5"/>',
			// Dos personas: una reunión.
			'reunion'   => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.5a3 3 0 0 1 0 5.8"/><path d="M17.5 14.5A5.5 5.5 0 0 1 21 20"/>',
			// Hoja con lápiz: un examen.
			'examen'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8"/><path d="M14 3v5h5"/><path d="m21 3-6 6"/>',
			// Bandeja con flecha: una entrega.
			'entrega'   => '<path d="M12 3v10"/><path d="m8 9 4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
			// Estrella: un feriado.
			'feriado'   => '<path d="m12 3 2.6 5.6 6 .8-4.4 4.2 1.1 6L12 16.8 6.7 19.6l1.1-6L3.4 9.4l6-.8Z"/>',
			// Banderín: un acto.
			'acto'      => '<path d="M5 21V4"/><path d="M5 4h11l-2 3.5L16 11H5"/>',
			// Montaña: una excursión.
			'excursion' => '<path d="m3 19 6-10 4 6 2-3 6 7Z"/><circle cx="17" cy="6" r="2"/>',
			// Llave de cierre: un período largo.
			'cierre'    => '<path d="M4 5v14"/><path d="M20 5v14"/><path d="M4 12h16"/>',
			// Punto marcado en el almanaque: cualquier otro evento.
			'evento'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15" r="1.6" fill="currentColor" stroke="none"/>',
		];
		$d = $trazos[ $type ] ?? $trazos['evento'];

		return '<svg class="cead-acad-ev-ico" width="' . (int) $px . '" height="' . (int) $px . '"'
			. ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"'
			. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. $d . '</svg>';
	}

	/**
	 * El color efectivo de un evento: el propio si cargó uno válido, si no el
	 * de su tipo. Centralizado acá para que el calendario, la agenda y
	 * cualquier vista nueva se vean siempre consistentes entre sí.
	 *
	 * Siempre devuelve 6 dígitos: `sanitize_hex_color()` también acepta la
	 * forma corta (#abc), y quien pinta con este color a veces le suma un
	 * sufijo de transparencia (#rrggbbAA) — sobre 3 dígitos eso da una
	 * cadena inválida que el navegador simplemente ignora.
	 */
	public static function event_color( $post_id ) {
		$own = (string) get_post_meta( (int) $post_id, '_cead_acad_event_color', true );
		$color = $own !== '' ? $own : self::default_color( (string) get_post_meta( (int) $post_id, '_cead_acad_event_type', true ) ?: 'evento' );
		return self::to_six_digit_hex( $color );
	}

	/** #abc → #aabbcc. Cualquier otra cosa se devuelve tal cual. */
	protected static function to_six_digit_hex( $hex ) {
		if ( 1 === preg_match( '/^#([0-9A-Fa-f])([0-9A-Fa-f])([0-9A-Fa-f])$/', $hex, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}
		return $hex;
	}
}
