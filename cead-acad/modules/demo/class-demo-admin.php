<?php
/**
 * Pantalla para cargar y borrar los datos de demostración.
 *
 * Vive aparte del resto del admin y no se ofrece en ningún otro lado: es una
 * herramienta para preparar una presentación, no una función del sistema. Por
 * eso además se esconde sola cuando el sitio no es de prueba (ver `is_allowed`).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Demo_Admin {

	public function boot() {
		add_action( 'admin_menu', [ $this, 'register' ], 20 );
	}

	/**
	 * Un botón que borra usuarios y contenido no tiene por qué existir en la
	 * instalación real del colegio. Se muestra solo si WP_DEBUG está prendido o
	 * si alguien lo habilitó a propósito con la opción, así en producción no
	 * aparece ni por accidente.
	 */
	public static function is_allowed() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { return true; }
		return (bool) get_option( 'cead_acad_demo_tools', 0 );
	}

	public function register() {
		if ( ! self::is_allowed() ) { return; }
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

		if ( isset( $_POST['cead_acad_demo_action'] ) && check_admin_referer( 'cead_acad_demo' ) ) {
			$action = sanitize_key( wp_unslash( $_POST['cead_acad_demo_action'] ) );

			if ( 'seed' === $action ) {
				$r = Cead_Acad_Demo_Seeder::seed();
				Cead_Acad_Audit::log( 'demo_seeded', [ 'user_id' => get_current_user_id() ?: null, 'payload' => $r ] );
				$notice = [ 'success', sprintf(
					/* translators: 1: usuarios, 2: comunicados, 3: eventos, 4: tareas, 5: recursos, 6: notas */
					__( 'Datos cargados: %1$d usuarios, %2$d comunicados, %3$d eventos, %4$d tareas, %5$d recursos y %6$d notas.', 'cead-acad' ),
					$r['usuarios'], $r['comunicados'], $r['eventos'], $r['tareas'], $r['recursos'], $r['notas']
				) ];
			} elseif ( 'purge' === $action ) {
				$r = Cead_Acad_Demo_Seeder::purge();
				Cead_Acad_Audit::log( 'demo_purged', [ 'user_id' => get_current_user_id() ?: null, 'payload' => $r ] );
				$notice = [ 'success', sprintf(
					/* translators: 1: contenidos borrados, 2: usuarios borrados */
					__( 'Borrado: %1$d contenidos y %2$d usuarios de demo.', 'cead-acad' ),
					$r['posts'], $r['usuarios']
				) ];
			}
		}

		$hay = Cead_Acad_Demo_Seeder::has_data();

		echo '<div class="wrap"><h1>' . esc_html__( 'Datos de demostración', 'cead-acad' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}

		echo '<p class="description" style="max-width:760px">'
			. esc_html__( 'Llena el panel con un curso completo y su gente, para grabar o presentar el sistema sin que las pantallas se vean vacías. Todo lo que se crea queda marcado, y el botón de borrar elimina exactamente eso: no toca el contenido real del colegio.', 'cead-acad' )
			. '</p>';

		echo '<div class="card" style="max-width:760px"><h2>' . esc_html__( 'Qué carga', 'cead-acad' ) . '</h2><ul style="list-style:disc;margin-left:1.4rem">';
		foreach ( [
			__( 'Un curso de 3.º BTI con horario de lunes a viernes (llena «Clases de hoy»).', 'cead-acad' ),
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
