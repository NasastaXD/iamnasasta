<?php
/**
 * Pantalla para activar el modo demo y, con él activo, cargar y borrar los
 * datos de demostración.
 *
 * El submenú siempre se ve para quien tiene manage_options — si no, nadie
 * podría encontrar dónde activarlo la primera vez. Lo que se esconde detrás
 * de un interruptor es la parte peligrosa: un botón que crea y borra usuarios
 * no tiene por qué estar disponible de un clic en la instalación real del
 * colegio, así que hay que prenderlo a propósito antes de que aparezca.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Demo_Admin {

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register' ] );
	}

	/**
	 * ¿Modo demo activo? Por defecto no. `WP_DEBUG` lo prende igual, para no
	 * tener que tocar nada en un entorno de desarrollo — pero ahí el
	 * interruptor de la pantalla no puede apagarlo, y el aviso lo dice.
	 */
	public static function is_allowed() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { return true; }
		return (bool) get_option( 'cead_acad_demo_tools', 0 );
	}

	public function register() {
		add_submenu_page(
			'cead-acad',
			__( 'Datos de demo', 'cead-acad' ),
			__( 'Datos de demo', 'cead-acad' ),
			'manage_options',
			'cead-acad-demo',
			[ $this, 'page' ]
		);
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'cead-acad' ) );
		}
		$notice = null;
		$forced = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( isset( $_POST['cead_acad_demo_action'] ) && check_admin_referer( 'cead_acad_demo' ) ) {
			$action = sanitize_key( wp_unslash( $_POST['cead_acad_demo_action'] ) );

			if ( 'mode_on' === $action ) {
				update_option( 'cead_acad_demo_tools', 1, false );
				$notice = [ 'success', __( 'Modo demo activado.', 'cead-acad' ) ];
			} elseif ( 'mode_off' === $action ) {
				update_option( 'cead_acad_demo_tools', 0, false );
				$notice = [ 'success', __( 'Modo demo desactivado.', 'cead-acad' ) ];
			} elseif ( 'seed' === $action && self::is_allowed() ) {
				$r = Cead_Acad_Demo_Seeder::seed();
				Cead_Acad_Audit::log( 'demo_seeded', [ 'user_id' => get_current_user_id() ?: null, 'payload' => $r ] );
				$notice = [ 'success', sprintf(
					/* translators: 1: usuarios, 2: comunicados, 3: eventos, 4: tareas, 5: recursos, 6: notas */
					__( 'Datos cargados: %1$d usuarios, %2$d comunicados, %3$d eventos, %4$d tareas, %5$d recursos y %6$d notas.', 'cead-acad' ),
					$r['usuarios'], $r['comunicados'], $r['eventos'], $r['tareas'], $r['recursos'], $r['notas']
				) ];
			} elseif ( 'purge' === $action && self::is_allowed() ) {
				$r = Cead_Acad_Demo_Seeder::purge();
				Cead_Acad_Audit::log( 'demo_purged', [ 'user_id' => get_current_user_id() ?: null, 'payload' => $r ] );
				$notice = [ 'success', sprintf(
					/* translators: 1: contenidos borrados, 2: usuarios borrados */
					__( 'Borrado: %1$d contenidos y %2$d usuarios de demo.', 'cead-acad' ),
					$r['posts'], $r['usuarios']
				) ];
			}
		}

		$activo = self::is_allowed();

		echo '<div class="wrap"><h1>' . esc_html__( 'Datos de demostración', 'cead-acad' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}

		// El interruptor de modo demo. Siempre visible, sea cual sea el estado.
		echo '<div class="card" style="max-width:760px">';
		echo '<h2>' . esc_html__( 'Modo demo', 'cead-acad' ) . '</h2>';
		echo '<p>' . ( $activo
			? '🟢 ' . esc_html__( 'Activado: la carga y el borrado de datos de demo están disponibles más abajo.', 'cead-acad' )
			: '🔴 ' . esc_html__( 'Desactivado: activalo para poder cargar datos de demo en el panel.', 'cead-acad' ) ) . '</p>';
		if ( $forced ) {
			echo '<p class="description">' . esc_html__( 'WP_DEBUG está activo en este sitio, así que el modo demo queda forzado a encendido sin importar el interruptor.', 'cead-acad' ) . '</p>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'cead_acad_demo' );
		if ( $activo && ! $forced ) {
			echo '<button class="button" name="cead_acad_demo_action" value="mode_off">' . esc_html__( 'Desactivar modo demo', 'cead-acad' ) . '</button>';
		} elseif ( ! $activo ) {
			echo '<button class="button button-primary" name="cead_acad_demo_action" value="mode_on">' . esc_html__( 'Activar modo demo', 'cead-acad' ) . '</button>';
		}
		echo '</form></div>';

		if ( ! $activo ) {
			echo '</div>';
			return;
		}

		$hay = Cead_Acad_Demo_Seeder::has_data();

		// Qué hay realmente en la base. Sin esto, si algo falla en silencio la
		// pantalla se ve igual que si hubiera funcionado.
		if ( $hay ) {
			echo '<div class="card" style="max-width:760px;margin-top:1.5rem"><h2>' . esc_html__( 'Cargado ahora mismo', 'cead-acad' ) . '</h2><p>';
			$partes = [];
			foreach ( Cead_Acad_Demo_Seeder::counts() as $label => $n ) {
				$partes[] = '<strong>' . (int) $n . '</strong> ' . esc_html( mb_strtolower( $label ) );
			}
			echo implode( ' · ', $partes );
			echo '</p><p class="description">' . esc_html__( 'Tu usuario quedó inscripto en el curso de demo, así que el panel se te ve con datos sin necesidad de entrar como otra persona.', 'cead-acad' ) . '</p></div>';
		}

		echo '<p class="description" style="max-width:760px;margin-top:1.5rem">'
			. esc_html__( 'Llena el panel con un curso completo y su gente, para grabar o presentar el sistema sin que las pantallas se vean vacías. Todo lo que se crea queda marcado, y el botón de borrar elimina exactamente eso: no toca el contenido real del colegio.', 'cead-acad' )
			. '</p>';

		echo '<div class="card" style="max-width:760px"><h2>' . esc_html__( 'Qué carga', 'cead-acad' ) . '</h2><ul style="list-style:disc;margin-left:1.4rem">';
		foreach ( [
			__( 'Un curso de 2.º A CB con horario de lunes a viernes (llena «Clases de hoy»).', 'cead-acad' ),
			__( '8 alumnos/as —  la primera es delegada—  y un docente, todos en el curso.', 'cead-acad' ),
			__( '5 comunicados, los 2 más nuevos sin leer para que se vea el contador.', 'cead-acad' ),
			__( '6 eventos próximos, 6 tareas en distintos estados y 5 recursos.', 'cead-acad' ),
			__( 'Una encuesta respondida por 6 de 8, para que dirección muestre 75% y no 0%.', 'cead-acad' ),
			__( 'Boletín con notas en la escala de 1 a 5, en dos periodos.', 'cead-acad' ),
		] as $li ) {
			echo '<li>' . esc_html( $li ) . '</li>';
		}
		echo '</ul>';
		echo '<p><strong>' . esc_html__( 'Para entrar como alumna:', 'cead-acad' ) . '</strong> <code>sofia.ramirez</code> · <code>' . esc_html( Cead_Acad_Demo_Seeder::PASS ) . '</code><br>';
		echo '<span class="description">' . esc_html__( 'Esa cuenta es además la delegada, así que muestra el panel de alumno y el de delegado.', 'cead-acad' ) . '</span></p>';
		echo '</div>';

		echo '<form method="post" style="margin-top:1.5rem">';
		wp_nonce_field( 'cead_acad_demo' );
		echo '<button class="button button-primary button-hero" name="cead_acad_demo_action" value="seed" '
			. 'onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Se van a crear usuarios y contenido de demo. Si ya había datos de demo cargados, se reemplazan. ¿Seguir?', 'cead-acad' ) ) ) . ')">'
			. esc_html( $hay ? __( 'Volver a cargar los datos', 'cead-acad' ) : __( 'Cargar datos de demo', 'cead-acad' ) ) . '</button>';
		echo '</form>';

		if ( $hay ) {
			echo '<form method="post" style="margin-top:1rem">';
			wp_nonce_field( 'cead_acad_demo' );
			echo '<button class="button" name="cead_acad_demo_action" value="purge" '
				. 'onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Esto borra los usuarios y el contenido de demo. El contenido real no se toca. ¿Seguir?', 'cead-acad' ) ) ) . ')">'
				. esc_html__( 'Borrar los datos de demo', 'cead-acad' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}
}
