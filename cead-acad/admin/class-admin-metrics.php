<?php
/**
 * Pantalla "CEAD Académico → Métricas": tarjetas y gráficos (Chart.js vía CDN)
 * sobre datos que ya existen: lecturas de comunicados, actividad del bot de
 * WhatsApp y participación en encuestas. Solo lectura.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Admin_Metrics {

	const CHARTJS_SRC = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
	}

	public function register_menu() {
		if ( ! cead_acad_user_is_staff() ) {
			return;
		}
		$hook = add_submenu_page(
			'cead-acad',
			__( 'Métricas', 'cead-acad' ),
			__( 'Métricas', 'cead-acad' ),
			'read',
			'cead-acad-metrics',
			[ $this, 'render' ]
		);
		if ( $hook ) {
			add_action( 'admin_print_scripts-' . $hook, [ $this, 'enqueue_chartjs' ] );
		}
	}

	public function enqueue_chartjs() {
		wp_enqueue_script( 'cead-acad-chartjs', self::CHARTJS_SRC, [], '4.4.1', true );
	}

	public function render() {
		if ( ! cead_acad_user_is_staff() ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}

		$bot   = $this->bot_metrics();
		$reads = $this->broadcast_read_rates( 10 );
		$svys  = $this->survey_participation( 10 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Métricas', 'cead-acad' ) . '</h1>';

		// ── Tarjetas resumen ──
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0">';
		$this->card( __( 'Mensajes del bot (30 días)', 'cead-acad' ), number_format_i18n( $bot['total_30d'] ) );
		$this->card( __( 'Usuarios únicos del bot (30 días)', 'cead-acad' ), number_format_i18n( $bot['unique_30d'] ) );
		$this->card( __( 'Lectura promedio (últimos comunicados)', 'cead-acad' ), $reads['avg_rate'] . '%' );
		$this->card( __( 'Respuestas de encuestas (total)', 'cead-acad' ), number_format_i18n( $svys['total_responses'] ) );
		echo '</div>';

		// ── Gráficos ──
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;max-width:1400px">';
		$this->chart_box( 'cead-chart-bot', __( 'Mensajes del bot por día (30 días)', 'cead-acad' ) );
		$this->chart_box( 'cead-chart-reads', __( 'Tasa de lectura por comunicado (%)', 'cead-acad' ) );
		$this->chart_box( 'cead-chart-surveys', __( 'Participación en encuestas', 'cead-acad' ) );
		echo '</div>';

		$data = [
			'bot'     => $bot,
			'reads'   => $reads,
			'surveys' => $svys,
			'i18n'    => [
				'in'        => __( 'Recibidos', 'cead-acad' ),
				'out'       => __( 'Enviados', 'cead-acad' ),
				'readRate'  => __( '% de lectura', 'cead-acad' ),
				'responses' => __( 'Respuestas', 'cead-acad' ),
			],
		];
		echo '<script>window.ceadAcadMetrics = ' . wp_json_encode( $data ) . ';</script>';
		$this->print_chart_js();

		echo '</div>';
	}

	protected function card( $label, $value ) {
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px">';
		echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.02em">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:28px;font-weight:600;line-height:1.4">' . esc_html( $value ) . '</div>';
		echo '</div>';
	}

	protected function chart_box( $id, $title ) {
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px">';
		echo '<h2 style="margin:0 0 10px;font-size:14px">' . esc_html( $title ) . '</h2>';
		echo '<div style="position:relative;height:300px"><canvas id="' . esc_attr( $id ) . '"></canvas></div>';
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Datos
	// -------------------------------------------------------------------------

	/**
	 * Mensajes del bot por día (últimos 30), separados in/out, más totales.
	 */
	protected function bot_metrics() {
		global $wpdb;
		$t    = cead_acad_table( 'wa_logs' );
		$rows = $wpdb->get_results(
			"SELECT DATE(created_at) AS d, direction, COUNT(*) AS c
			 FROM {$t}
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY DATE(created_at), direction",
			ARRAY_A
		) ?: [];

		// Serie continua de 30 días (rellena días sin datos con 0).
		$labels = [];
		$in     = [];
		$out    = [];
		for ( $i = 29; $i >= 0; $i-- ) {
			$day            = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$labels[]       = $day;
			$in[ $day ]     = 0;
			$out[ $day ]    = 0;
		}
		foreach ( $rows as $r ) {
			if ( ! isset( $in[ $r['d'] ] ) ) {
				continue;
			}
			if ( 'in' === $r['direction'] ) {
				$in[ $r['d'] ] = (int) $r['c'];
			} else {
				$out[ $r['d'] ] = (int) $r['c'];
			}
		}

		$store = new Cead_Acad_WA_Store();
		return [
			'labels'     => $labels,
			'in'         => array_values( $in ),
			'out'        => array_values( $out ),
			'total_30d'  => $store->count_messages( '', 30 ),
			'unique_30d' => $store->count_unique_users( 30 ),
		];
	}

	/**
	 * Tasa de lectura de los últimos N comunicados publicados.
	 */
	protected function broadcast_read_rates( $limit = 10 ) {
		global $wpdb;
		$reads_table = cead_acad_table( 'broadcast_reads' );

		$posts = get_posts( [
			'post_type'      => Cead_Acad_Broadcasts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
		] );

		$labels = [];
		$rates  = [];
		$sum    = 0;
		foreach ( array_reverse( $posts ) as $p ) {
			$recipients = Cead_Acad_Broadcasts_Feed::resolve_recipient_user_ids( $p->ID );
			$reads      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reads_table} WHERE broadcast_id = %d", $p->ID ) );
			$rate       = count( $recipients ) > 0 ? (int) round( $reads / count( $recipients ) * 100 ) : 0;
			$labels[]   = wp_html_excerpt( get_the_title( $p ), 40, '…' );
			$rates[]    = $rate;
			$sum       += $rate;
		}

		return [
			'labels'   => $labels,
			'rates'    => $rates,
			'avg_rate' => $rates ? (int) round( $sum / count( $rates ) ) : 0,
		];
	}

	/**
	 * Respuestas por encuesta (últimas N publicadas) + total histórico.
	 */
	protected function survey_participation( $limit = 10 ) {
		global $wpdb;
		$responses_table = cead_acad_table( 'survey_responses' );

		$posts = get_posts( [
			'post_type'      => Cead_Acad_Surveys_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
		] );

		$labels    = [];
		$responses = [];
		foreach ( array_reverse( $posts ) as $p ) {
			$labels[]    = wp_html_excerpt( get_the_title( $p ), 40, '…' );
			$responses[] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$responses_table} WHERE survey_id = %d", $p->ID ) );
		}

		return [
			'labels'          => $labels,
			'responses'       => $responses,
			'total_responses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$responses_table}" ),
		];
	}

	protected function print_chart_js() {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			if ( typeof Chart === 'undefined' || ! window.ceadAcadMetrics ) { return; }
			var M = window.ceadAcadMetrics;
			var opts = { responsive: true, maintainAspectRatio: false };

			new Chart( document.getElementById( 'cead-chart-bot' ), {
				type: 'line',
				data: {
					labels: M.bot.labels,
					datasets: [
						{ label: M.i18n.in,  data: M.bot.in,  borderColor: '#2271b1', backgroundColor: 'rgba(34,113,177,.15)', fill: true, tension: .3 },
						{ label: M.i18n.out, data: M.bot.out, borderColor: '#00a32a', backgroundColor: 'rgba(0,163,42,.12)',  fill: true, tension: .3 }
					]
				},
				options: Object.assign( {}, opts, { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } )
			} );

			new Chart( document.getElementById( 'cead-chart-reads' ), {
				type: 'bar',
				data: {
					labels: M.reads.labels,
					datasets: [ { label: M.i18n.readRate, data: M.reads.rates, backgroundColor: '#2271b1' } ]
				},
				options: Object.assign( {}, opts, { indexAxis: 'y', scales: { x: { min: 0, max: 100 } } } )
			} );

			new Chart( document.getElementById( 'cead-chart-surveys' ), {
				type: 'bar',
				data: {
					labels: M.surveys.labels,
					datasets: [ { label: M.i18n.responses, data: M.surveys.responses, backgroundColor: '#8c8f94' } ]
				},
				options: Object.assign( {}, opts, { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } )
			} );
		} );
		</script>
		<?php
	}
}
