<?php
/**
 * Escritorio (wp-admin) con estética CEAD: limpia los widgets por defecto,
 * agrega un panel con métricas y accesos, y aplica branding (login, barra, pie).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Admin_Dashboard {

	public function boot() {
		// Quitar el panel de bienvenida de WordPress.
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		add_action( 'wp_dashboard_setup', [ $this, 'dashboard_setup' ] );

		// Branding.
		add_action( 'login_enqueue_scripts', [ $this, 'login_logo' ] );
		add_filter( 'login_headerurl',  [ $this, 'login_url' ] );
		add_filter( 'login_headertext', [ $this, 'login_text' ] );
		add_action( 'admin_bar_menu',   [ $this, 'admin_bar' ], 999 );
		add_filter( 'admin_footer_text', [ $this, 'footer_text' ] );
		add_filter( 'update_footer', '__return_empty_string', 11 );
	}

	/* ------------------------------------------------------------------ */
	/* Escritorio                                                          */
	/* ------------------------------------------------------------------ */

	public function dashboard_setup() {
		// Quitar widgets por defecto de WordPress.
		foreach ( [ 'dashboard_primary', 'dashboard_quick_press', 'dashboard_activity', 'dashboard_site_health', 'dashboard_right_now', 'dashboard_php_nag', 'dashboard_secondary' ] as $id ) {
			remove_meta_box( $id, 'dashboard', 'normal' );
			remove_meta_box( $id, 'dashboard', 'side' );
		}

		wp_add_dashboard_widget(
			'cead_acad_dashboard',
			__( 'CEAD Académico', 'cead-acad' ),
			[ $this, 'render_widget' ]
		);

		// Mover nuestro widget al tope.
		global $wp_meta_boxes;
		$normal = &$wp_meta_boxes['dashboard']['normal']['core'];
		if ( isset( $normal['cead_acad_dashboard'] ) ) {
			$ours = [ 'cead_acad_dashboard' => $normal['cead_acad_dashboard'] ];
			unset( $normal['cead_acad_dashboard'] );
			$normal = $ours + $normal;
		}
	}

	/** Métricas (cacheadas 5 min, salvo el estado del bot que va en vivo). */
	protected function metrics() {
		$m = get_transient( 'cead_acad_dash_metrics' );
		if ( ! is_array( $m ) ) {
			$counts   = count_users();
			$students = (int) ( $counts['avail_roles']['cead_acad_student'] ?? 0 );
			$teachers = (int) ( $counts['avail_roles']['cead_acad_teacher'] ?? 0 );

			$courses = (int) ( wp_count_posts( Cead_Acad_Courses_CPT::POST_TYPE )->publish ?? 0 );

			$ev = new WP_Query( [
				'post_type'      => Cead_Acad_Schedule_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [ [
					'key'     => '_cead_acad_event_start',
					'value'   => current_time( 'Y-m-d H:i:s' ),
					'compare' => '>=',
					'type'    => 'DATETIME',
				] ],
			] );
			$upcoming = (int) $ev->found_posts;

			$first = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
			$uq    = new WP_User_Query( [
				'date_query'  => [ [ 'after' => $first, 'column' => 'user_registered', 'inclusive' => true ] ],
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
			] );
			$new_users = (int) $uq->get_total();

			$m = compact( 'students', 'teachers', 'courses', 'upcoming', 'new_users' );
			set_transient( 'cead_acad_dash_metrics', $m, 5 * MINUTE_IN_SECONDS );
		}

		// Estado del bot (lectura barata, en vivo).
		$m['bot_connected'] = false;
		$m['bot_label']     = __( 'sin configurar', 'cead-acad' );
		if ( class_exists( 'Cead_Acad_WA_Store' ) ) {
			$s  = ( new Cead_Acad_WA_Store() )->session();
			$cs = $s->connection_status ?? '';
			$m['bot_connected'] = ( 'connected' === $cs );
			$m['bot_label']     = $m['bot_connected'] ? __( 'Conectado', 'cead-acad' ) : ( $cs ? $cs : __( 'desconectado', 'cead-acad' ) );
		}

		return $m;
	}

	public function render_widget() {
		$m       = $this->metrics();
		$icon    = home_url( '/cead-icon-192.png' );
		$acad    = admin_url( 'admin.php?page=cead-acad' );
		$wa      = admin_url( 'admin.php?page=cead-acad-whatsapp' );
		$users   = admin_url( 'admin.php?page=cead-acad-users' );
		$courses = admin_url( 'edit.php?post_type=' . Cead_Acad_Courses_CPT::POST_TYPE );
		$panel   = cead_acad_url( 'panel' );

		$cards = [
			[ 'n' => $m['students'],  'l' => __( 'Alumnos', 'cead-acad' ),          'c' => '#E93B3C', 'i' => '🎓' ],
			[ 'n' => $m['teachers'],  'l' => __( 'Docentes', 'cead-acad' ),         'c' => '#49A3C8', 'i' => '👩‍🏫' ],
			[ 'n' => $m['courses'],   'l' => __( 'Cursos', 'cead-acad' ),           'c' => '#F4B74C', 'i' => '📚' ],
			[ 'n' => $m['upcoming'],  'l' => __( 'Eventos próximos', 'cead-acad' ), 'c' => '#5FAE6B', 'i' => '📅' ],
			[ 'n' => $m['new_users'], 'l' => __( 'Altas este mes', 'cead-acad' ),   'c' => '#9B6FB0', 'i' => '📈' ],
		];
		?>
		<style>
			#cead_acad_dashboard .inside { margin:0; padding:0; }
			.cead-dash { font-family:-apple-system,system-ui,sans-serif; }
			.cead-dash-head { display:flex; align-items:center; gap:.8rem; padding:1rem 1.1rem; background:#0B0B0B; color:#fff; }
			.cead-dash-head img { width:38px; height:38px; border-radius:8px; }
			.cead-dash-head h3 { margin:0; font-size:1.05rem; color:#fff; }
			.cead-dash-head .sub { margin:0; font-size:.78rem; color:#bdbdbd; }
			.cead-dash-bot { margin-left:auto; font-size:.78rem; font-weight:700; padding:.25rem .6rem; border-radius:999px; }
			.cead-dash-bot.on  { background:#15351f; color:#5FCF7F; }
			.cead-dash-bot.off { background:#3a1b1b; color:#ff8a8a; }
			.cead-dash-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.6rem; padding:1rem 1.1rem; }
			.cead-dash-card { border:1px solid #e4e1da; border-left-width:5px; border-radius:10px; padding:.7rem .8rem; background:#fff; }
			.cead-dash-card .num { font-size:1.7rem; font-weight:800; line-height:1; color:#0B0B0B; }
			.cead-dash-card .lbl { font-size:.78rem; color:#666; margin-top:.2rem; display:flex; align-items:center; gap:.3rem; }
			.cead-dash-actions { display:flex; flex-wrap:wrap; gap:.5rem; padding:0 1.1rem 1.1rem; }
		</style>
		<div class="cead-dash">
			<div class="cead-dash-head">
				<img src="<?php echo esc_url( $icon ); ?>" alt="">
				<div>
					<h3>CEAD Académico</h3>
					<p class="sub"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> · v<?php echo esc_html( CEAD_ACAD_VERSION ); ?></p>
				</div>
				<span class="cead-dash-bot <?php echo $m['bot_connected'] ? 'on' : 'off'; ?>">
					<?php echo $m['bot_connected'] ? '🟢' : '🔴'; ?> <?php echo esc_html__( 'Bot', 'cead-acad' ) . ': ' . esc_html( $m['bot_label'] ); ?>
				</span>
			</div>

			<div class="cead-dash-grid">
				<?php foreach ( $cards as $c ) : ?>
					<div class="cead-dash-card" style="border-left-color:<?php echo esc_attr( $c['c'] ); ?>">
						<div class="num"><?php echo (int) $c['n']; ?></div>
						<div class="lbl"><span><?php echo esc_html( $c['i'] ); ?></span> <?php echo esc_html( $c['l'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="cead-dash-actions">
				<a class="button button-primary" href="<?php echo esc_url( $acad ); ?>"><?php esc_html_e( 'Ir a CEAD Académico', 'cead-acad' ); ?></a>
				<a class="button" href="<?php echo esc_url( $users ); ?>"><?php esc_html_e( 'Usuarios', 'cead-acad' ); ?></a>
				<a class="button" href="<?php echo esc_url( $courses ); ?>"><?php esc_html_e( 'Cursos', 'cead-acad' ); ?></a>
				<a class="button" href="<?php echo esc_url( $wa ); ?>"><?php esc_html_e( 'WhatsApp', 'cead-acad' ); ?></a>
				<a class="button" href="<?php echo esc_url( $panel ); ?>" target="_blank"><?php esc_html_e( 'Ver panel', 'cead-acad' ); ?></a>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Branding                                                            */
	/* ------------------------------------------------------------------ */

	public function login_logo() {
		$icon = home_url( '/cead-icon-192.png' );
		?>
		<style>
			body.login h1 a {
				background-image:url('<?php echo esc_url( $icon ); ?>');
				background-size:84px 84px; width:84px; height:84px;
			}
			body.login { background:#F7F5F0; }
			.login #backtoblog a, .login #nav a { color:#B82A2B !important; }
			.wp-core-ui .button-primary { background:#E93B3C; border-color:#B82A2B; }
		</style>
		<?php
	}

	public function login_url() { return home_url( '/panel' ); }
	public function login_text() { return get_bloginfo( 'name' ) . ' · CEAD'; }

	public function admin_bar( $bar ) {
		if ( ! is_object( $bar ) ) { return; }
		$bar->remove_node( 'wp-logo' );
		$bar->remove_node( 'wp-logo-external' );
		$bar->add_node( [
			'id'    => 'cead-acad',
			'title' => '★ CEAD',
			'href'  => admin_url( 'admin.php?page=cead-acad' ),
		] );
	}

	public function footer_text() {
		return 'CEAD Académico · Félix de Guarania';
	}
}
