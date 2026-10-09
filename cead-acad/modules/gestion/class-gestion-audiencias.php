<?php
/**
 * Quién puede publicar a quién.
 *
 * Con la app, publicar un comunicado deja de pasar por el bot, y el bot era el
 * que ponía el límite: Dirección y Secretaría publicaban a todo el colegio; un
 * docente o el Consejo Estudiantil, solo al alumnado. Si la API aceptara
 * cualquier audiencia que mande el teléfono, ese límite desaparecería sin que
 * nadie lo decida: alcanzaría con armar el pedido a mano.
 *
 * Las reglas, iguales a las de siempre:
 *
 *  - Con `cead_acad_publish_broadcast_all`: cualquier audiencia.
 *  - Solo con `cead_acad_publish_broadcast`: lo que esté DENTRO del alumnado —
 *    el rol alumno, el rol delegado, un curso o una promoción—. Un curso es
 *    más acotado que «todo el alumnado», que era lo que el bot ya le permitía;
 *    lo que no puede es hablarle a todo el colegio, al staff, o a una persona
 *    suelta.
 *
 * Los eventos siguen otra regla, también la de siempre: quien puede cargar
 * eventos (`cead_acad_manage_schedule`) los carga para quien sea.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Gestion_Audiencias {

	/** Los roles que forman «el alumnado» para quien no puede publicar a todos. */
	const ROLES_ALUMNADO = [ 'cead_acad_student', 'cead_acad_delegate' ];

	/**
	 * Las audiencias que pidió alguien para un comunicado, ya validadas.
	 *
	 * @return array|WP_Error Normalizadas, o el error con lo que no se permite.
	 */
	public static function para_comunicado( $user_id, $pedidas ) {
		if ( ! user_can( $user_id, 'cead_acad_publish_broadcast' ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para publicar comunicados.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		return self::filtrar(
			$pedidas,
			user_can( $user_id, 'cead_acad_publish_broadcast_all' ),
			array_keys( Cead_Acad_Capabilities::roles() )
		);
	}

	/** Las audiencias de un evento: quien puede cargar eventos, los carga para cualquiera. */
	public static function para_evento( $user_id, $pedidas ) {
		if ( ! user_can( $user_id, 'cead_acad_manage_schedule' ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para cargar eventos.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		return self::filtrar( $pedidas, true, array_keys( Cead_Acad_Capabilities::roles() ) );
	}

	/**
	 * El corazón de la regla, sin WordPress, para poder probarlo.
	 *
	 * Rechaza el pedido entero si una sola audiencia no está permitida, en vez
	 * de publicar a la parte que sí: un comunicado que llega a la mitad de la
	 * gente que se eligió es peor que uno que no sale y dice por qué.
	 *
	 * @param mixed    $pedidas      Lista de [ type, value ] tal como llegó.
	 * @param bool     $todo         ¿Puede publicar a cualquier audiencia?
	 * @param string[] $roles        Roles que existen.
	 * @return array|WP_Error
	 */
	public static function filtrar( $pedidas, $todo, array $roles ) {
		if ( ! is_array( $pedidas ) || ! $pedidas ) {
			return new WP_Error( 'sin_audiencia', __( 'Elegí a quién va dirigido.', 'cead-acad' ), [ 'status' => 400 ] );
		}

		$ok = [];
		foreach ( $pedidas as $a ) {
			$tipo  = is_array( $a ) ? (string) ( $a['type'] ?? $a['tipo'] ?? '' ) : '';
			$valor = is_array( $a ) ? (string) ( $a['value'] ?? $a['valor'] ?? '' ) : '';

			switch ( $tipo ) {
				case 'all':
					if ( ! $todo ) {
						return self::no_permitida( __( 'todo el colegio', 'cead-acad' ) );
					}
					$ok[] = [ 'type' => 'all', 'value' => '*' ];
					break;

				case 'role':
					if ( ! in_array( $valor, $roles, true ) ) {
						return new WP_Error( 'audiencia_invalida', __( 'Ese rol no existe.', 'cead-acad' ), [ 'status' => 400 ] );
					}
					if ( ! $todo && ! in_array( $valor, self::ROLES_ALUMNADO, true ) ) {
						return self::no_permitida( __( 'ese rol', 'cead-acad' ) );
					}
					$ok[] = [ 'type' => 'role', 'value' => $valor ];
					break;

				case 'course':
				case 'cohort':
					if ( ! ctype_digit( $valor ) || (int) $valor <= 0 ) {
						return new WP_Error( 'audiencia_invalida', __( 'Curso o promoción inválida.', 'cead-acad' ), [ 'status' => 400 ] );
					}
					$ok[] = [ 'type' => $tipo, 'value' => (string) (int) $valor ];
					break;

				case 'user':
					if ( ! $todo ) {
						return self::no_permitida( __( 'una persona en particular', 'cead-acad' ) );
					}
					if ( ! ctype_digit( $valor ) || (int) $valor <= 0 ) {
						return new WP_Error( 'audiencia_invalida', __( 'Persona inválida.', 'cead-acad' ), [ 'status' => 400 ] );
					}
					$ok[] = [ 'type' => 'user', 'value' => (string) (int) $valor ];
					break;

				default:
					return new WP_Error( 'audiencia_invalida', __( 'Tipo de audiencia desconocido.', 'cead-acad' ), [ 'status' => 400 ] );
			}
		}

		// Sin repetidos: un mismo curso elegido dos veces no es dos audiencias.
		$unicas = [];
		foreach ( $ok as $a ) {
			$unicas[ $a['type'] . '|' . $a['value'] ] = $a;
		}
		return array_values( $unicas );
	}

	/**
	 * Lo que esta persona puede elegir, para armar el selector en la app.
	 *
	 * Se manda ya filtrado: la app no tiene que saber las reglas de arriba, y
	 * si las supiera, habría dos lugares donde mantenerlas.
	 */
	public static function opciones( $user_id, $para = 'comunicado' ) {
		$todo = 'evento' === $para || user_can( $user_id, 'cead_acad_publish_broadcast_all' );

		$roles = [];
		foreach ( Cead_Acad_Capabilities::roles() as $slug => $cfg ) {
			if ( $todo || in_array( $slug, self::ROLES_ALUMNADO, true ) ) {
				$roles[] = [ 'valor' => $slug, 'nombre' => (string) $cfg['display'] ];
			}
		}

		$cursos = array_map( static function ( $p ) {
			return [ 'valor' => (string) $p->ID, 'nombre' => get_the_title( $p ) ];
		}, get_posts( [
			'post_type'      => Cead_Acad_Courses_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] ) );

		$promos = get_terms( [ 'taxonomy' => Cead_Acad_Courses_CPT::TAX_COHORT, 'hide_empty' => false ] );
		$promos = is_wp_error( $promos ) ? [] : array_map( static function ( $t ) {
			return [ 'valor' => (string) $t->term_id, 'nombre' => $t->name ];
		}, $promos );

		return [
			'todo_el_colegio' => $todo,
			'roles'           => $roles,
			'cursos'          => $cursos,
			'promociones'     => array_values( $promos ),
		];
	}

	protected static function no_permitida( $que ) {
		return new WP_Error(
			'audiencia_no_permitida',
			/* translators: %s: la audiencia que no se puede usar. */
			sprintf( __( 'No podés publicar a %s.', 'cead-acad' ), $que ),
			[ 'status' => 403 ]
		);
	}
}
