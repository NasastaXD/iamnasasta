<?php
/**
 * Singleton de boot. Engancha módulos en plugins_loaded.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cead_Acad_Plugin {

	private static $instance = null;

	private function __construct() {}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		load_plugin_textdomain( 'cead-acad', false, dirname( CEAD_ACAD_BASENAME ) . '/languages' );

		// Filtro de capabilities: admins reciben automáticamente las caps del plugin.
		add_filter( 'user_has_cap', [ 'Cead_Acad_Capabilities', 'grant_plugin_caps_to_admins' ], 10, 4 );

		// CPTs administrativos usan classic editor (no block editor). Comunicados
		// queda en Gutenberg porque es contenido rico.
		add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor_for_admin_cpts' ], 10, 2 );

		// La barra de WordPress no va en el frontend: pisa el diseño del panel y
		// del sitio, y al alumnado no le sirve de nada. Quien administra sigue
		// entrando a /wp-admin normalmente.
		add_filter( 'show_admin_bar', '__return_false' );

		// Duración de la sesión con «recordarme». El default de WordPress son 14
		// días, corto para un panel que el alumnado abre desde el celular todos
		// los días: los obliga a re-loguearse cada dos semanas. 90 días es un
		// plazo razonable para un panel escolar, y sigue venciendo.
		add_filter( 'auth_cookie_expiration', [ $this, 'auth_cookie_expiration' ], 10, 3 );

		// Seeding de términos: una vez por versión, después de registrar taxonomías
		// (init prioridad 20). Guard por opción autoloaded = check barato por request.
		add_action( 'init', [ $this, 'maybe_seed_terms' ], 20 );

		// El menú padre "CEAD Académico" debe registrarse ANTES que los submenús.
		( new Cead_Acad_Admin_Menu() )->boot();
		( new Cead_Acad_Admin_Logs() )->boot();
		( new Cead_Acad_Admin_Metrics() )->boot();
		( new Cead_Acad_Admin_Exports() )->boot();

		( new Cead_Acad_Audit() )->boot();

		( new Cead_Acad_Rewrites() )->boot();
		( new Cead_Acad_Assets() )->boot();
		( new Cead_Acad_Invitations() )->boot();
		( new Cead_Acad_Auth_Controller() )->boot();
		( new Cead_Acad_Password_Reset() )->boot();
		( new Cead_Acad_User_Suspension() )->boot();
		( new Cead_Acad_Courses_CPT() )->boot();
		( new Cead_Acad_Courses_Admin() )->boot();
		( new Cead_Acad_Broadcasts_CPT() )->boot();
		( new Cead_Acad_Broadcasts_Targeting() )->boot();
		( new Cead_Acad_Broadcasts_Feed() )->boot();
		( new Cead_Acad_Surveys_CPT() )->boot();
		( new Cead_Acad_Surveys_Admin() )->boot();
		( new Cead_Acad_Surveys_Frontend() )->boot();
		( new Cead_Acad_Schedule_CPT() )->boot();
		( new Cead_Acad_Schedule_Admin() )->boot();
		( new Cead_Acad_Schedule_Feed() )->boot();
		( new Cead_Acad_Resources_CPT() )->boot();
		( new Cead_Acad_Resources_Admin() )->boot();
		( new Cead_Acad_Importer_Admin() )->boot();
		( new Cead_Acad_Tasks_CPT() )->boot();
		( new Cead_Acad_WA_Module() )->boot();
		Cead_Acad_WA_News::hooks(); // invalida el resumen de noticias al publicar
		( new Cead_Acad_Account() )->boot();
		( new Cead_Acad_Tasks_Frontend() )->boot();
		( new Cead_Acad_Notifications() )->boot();
		( new Cead_Acad_FAQ() )->boot();
		( new Cead_Acad_Updates() )->boot();
		( new Cead_Acad_Admin_Dashboard() )->boot();

		// Migraciones idempotentes en cambio de versión.
		if ( get_option( 'cead_acad_db_version' ) !== CEAD_ACAD_DB_VERSION ) {
			Cead_Acad_Activator::create_tables();
			update_option( 'cead_acad_db_version', CEAD_ACAD_DB_VERSION );
			// Hay reglas de rewrite nuevas (link corto /i/<token>): pedir flush.
			update_option( 'cead_acad_flush_rewrites', 1 );
		}

		// Re-instalar capabilities en cada cambio de versión del plugin (no solo
		// del schema), para refrescar caps del rol administrator tras un upgrade.
		if ( get_option( 'cead_acad_caps_version' ) !== CEAD_ACAD_VERSION ) {
			Cead_Acad_Capabilities::install();
			update_option( 'cead_acad_caps_version', CEAD_ACAD_VERSION );
		}

		// Refrescar rewrite rules en cada cambio de versión (no atado al schema),
		// por si una versión nueva agrega rutas frontend como /wiki.
		if ( get_option( 'cead_acad_rewrites_version' ) !== CEAD_ACAD_VERSION ) {
			update_option( 'cead_acad_flush_rewrites', 1 );
			update_option( 'cead_acad_rewrites_version', CEAD_ACAD_VERSION );
		}
	}

	/**
	 * Cuánto dura la sesión. Solo se estira cuando la persona marcó
	 * «recordarme»: sin eso se respeta el default corto de WordPress, que es lo
	 * correcto en una computadora compartida —el laboratorio del colegio, por
	 * ejemplo— donde nadie quiere dejar la sesión abierta.
	 *
	 * Nota: en iPhone la app instalada guarda sus cookies aparte de Safari, así
	 * que iniciar sesión en el navegador no deja iniciada la de la app. Eso es
	 * del sistema operativo y no se arregla desde acá.
	 *
	 * @param int  $length   Duración en segundos que propone WordPress.
	 * @param int  $user_id  Usuario.
	 * @param bool $remember Si marcó «recordarme».
	 */
	public function auth_cookie_expiration( $length, $user_id, $remember ) {
		return $remember ? 90 * DAY_IN_SECONDS : $length;
	}

	/**
	 * Siembra términos por defecto una sola vez por versión. El check es una
	 * opción autoloaded (sin query extra); las queries term_exists() reales solo
	 * corren cuando la versión sembrada no coincide.
	 */
	public function maybe_seed_terms() {
		if ( get_option( 'cead_acad_terms_seeded' ) === CEAD_ACAD_VERSION ) {
			return;
		}
		Cead_Acad_Broadcasts_CPT::seed_terms();
		Cead_Acad_Resources_CPT::seed_terms();
		self::seed_post_categories();
		self::seed_fixture_intercead_2026();
		update_option( 'cead_acad_terms_seeded', CEAD_ACAD_VERSION );
	}

	/**
	 * Categorías base para las entradas del blog, para que al publicar desde
	 * CEADI haya de dónde elegir. Solo se crean si faltan: si alguien las
	 * renombra o borra, no se vuelven a insertar (se corre una sola vez por
	 * versión y se comprueba por slug).
	 */
	private static function seed_post_categories() {
		$base = [
			'noticias'  => __( 'Noticias', 'cead-acad' ),
			'avisos'    => __( 'Avisos', 'cead-acad' ),
			'academico' => __( 'Académico', 'cead-acad' ),
			'deportes'  => __( 'Deportes', 'cead-acad' ),
			'recursos'  => __( 'Recursos', 'cead-acad' ),
		];
		foreach ( $base as $slug => $name ) {
			if ( get_term_by( 'slug', $slug, 'category' ) ) { continue; }
			wp_insert_term( $name, 'category', [ 'slug' => $slug ] );
		}
	}

	/**
	 * Post fijo pedido para la presentación: el fixture del Inter CEAD 2026.
	 * Se crea una sola vez (por slug); si el staff lo edita o lo borra después,
	 * no se vuelve a tocar.
	 */
	private static function seed_fixture_intercead_2026() {
		if ( get_option( 'cead_acad_fixture_intercead2026_seeded' ) ) {
			return;
		}
		if ( get_page_by_path( 'fixture-intercead-2026', OBJECT, 'post' ) ) {
			update_option( 'cead_acad_fixture_intercead2026_seeded', 1 );
			return;
		}

		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
		$author = $admins ? (int) $admins[0] : 1;

		$content = <<<'HTML'
<p>Este es el fixture completo del <strong>Inter CEAD 2026 &mdash; Champions League</strong>, con la reprogramaci&oacute;n de las finales. Los partidos marcados como <em>&laquo;Ganador P#&raquo;</em> se definen con el resultado del partido correspondiente.</p>

<h2>Turno ma&ntilde;ana</h2>
<figure class="wp-block-table is-style-stripes"><table>
<thead><tr><th style="width:8%">#</th><th>Equipo</th><th></th><th>Equipo</th><th>Modalidad</th></tr></thead>
<tbody>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">1</td><td>JUVENTUS</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>BVB 05</td><td style="color:#5A6169">V&oacute;ley masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">2</td><td>ARSENAL</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>B. LEVERKUSEN</td><td style="color:#5A6169">V&oacute;ley masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">3</td><td>ARSENAL</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>BAYERN MUNICH</td><td style="color:#5A6169">Handbol femenino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">4</td><td>JUVENTUS</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>CELTICS</td><td style="color:#5A6169">Futsal masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">5</td><td>CHELSEA</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>A. MADRID</td><td style="color:#5A6169">Futsal femenino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">6</td><td>ARSENAL</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>BVB 05</td><td style="color:#5A6169">Futsal masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">7</td><td>ARSENAL</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>BAYERN LEVERK.</td><td style="color:#5A6169">Futsal femenino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">8</td><td>M. UNITED</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>BARCELONA</td><td style="color:#5A6169">Futsal masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">9</td><td>BARCELONA</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>AJAX</td><td style="color:#5A6169">Futsal femenino &mdash; Semifinal</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">10</td><td>ROMA</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>AJAX</td><td style="color:#5A6169">Futsal masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">11</td><td>Ganador P4</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P6</td><td style="color:#5A6169">Futsal masculino &mdash; Semifinal</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">12</td><td>Ganador P5</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P7</td><td style="color:#5A6169">Futsal femenino &mdash; Semifinal</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">13</td><td>Ganador P8</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P10</td><td style="color:#5A6169">Futsal masculino &mdash; Semifinal</td></tr>
</tbody>
</table></figure>

<h2>Turno tarde &mdash; Finales</h2>
<figure class="wp-block-table is-style-stripes"><table>
<thead><tr><th style="width:8%">#</th><th>Equipo</th><th></th><th>Equipo</th><th>Modalidad</th></tr></thead>
<tbody>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">1</td><td>Ganador P1</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P2</td><td style="color:#5A6169">FINAL &mdash; V&oacute;ley masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">2</td><td>REAL MADRID</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>ARSENAL</td><td style="color:#5A6169">FINAL &mdash; V&oacute;ley femenino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">3</td><td>BARCELONA</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P3</td><td style="color:#5A6169">FINAL &mdash; Handbol femenino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">4</td><td>JUVENTUS</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>BARCELONA</td><td style="color:#5A6169">FINAL &mdash; Handbol masculino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">5</td><td>Ganador P9</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P12</td><td style="color:#5A6169">FINAL &mdash; Futsal femenino</td></tr>
<tr><td style="text-align:center;font-weight:700;color:#E93B3C">6</td><td>Ganador P11</td><td style="text-align:center;color:#8A9099;font-size:.85em">vs</td><td>Ganador P13</td><td style="color:#5A6169">FINAL &mdash; Futsal masculino</td></tr>
</tbody>
</table></figure>

<p><em>El cronograma puede sufrir ajustes. Los horarios definitivos de cada partido se publican en el calendario del panel.</em></p>
HTML;

		$pid = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_name'    => 'fixture-intercead-2026',
			'post_title'   => 'Fixture Inter CEAD 2026 — Champions League',
			'post_content' => $content,
			'post_author'  => $author,
		], true );

		if ( is_wp_error( $pid ) ) {
			// No se marca como sembrado: se reintenta en el próximo request.
			return;
		}

		$cat = get_term_by( 'slug', 'deportes', 'category' );
		if ( $cat && ! is_wp_error( $cat ) ) {
			wp_set_post_categories( $pid, [ (int) $cat->term_id ], true );
		}

		update_option( 'cead_acad_fixture_intercead2026_seeded', 1 );
	}

	public function disable_block_editor_for_admin_cpts( $use_block_editor, $post_type ) {
		$classic = [
			'cead_acad_course',
			'cead_acad_event',
			'cead_acad_resource',
			'cead_acad_task',
		];
		if ( in_array( $post_type, $classic, true ) ) {
			return false;
		}
		// Entradas del blog: por defecto también en editor clásico (cajas). Es
		// lo que el personal sabe usar; el editor de bloques los frena. Se
		// puede volver a bloques con:
		//   update_option( 'cead_acad_classic_editor_posts', 0 );
		if ( 'post' === $post_type && (int) get_option( 'cead_acad_classic_editor_posts', 1 ) === 1 ) {
			return false;
		}
		return $use_block_editor;
	}
}
