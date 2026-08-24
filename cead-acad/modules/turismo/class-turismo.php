<?php
/**
 * Puente hacia el portal turístico de caaguazu.net.
 *
 * El alumnado y los docentes del curso de Servicios Turísticos entran al portal
 * desde el panel del colegio, sin registrarse de nuevo ni recordar otra
 * contraseña. El contrato completo está en `docs/INTEGRACION-TURISMO.md`.
 *
 * Este lado hace tres cosas y ninguna más:
 *   1. Decide quién es elegible.
 *   2. Emite un código opaco de un solo uso que guarda los datos de la persona.
 *   3. Los entrega por REST cuando caaguazu.net los canjea, firmado.
 *
 * Lo que NO hace, a propósito: decidir qué permisos tiene esa persona allá. Acá
 * se afirma quién es; del otro lado deciden qué puede hacer. Si mandáramos sus
 * roles, cualquier cambio en su modelo de permisos nos rompería.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Turismo {

	/** Marca en el curso: «este curso participa del portal turístico». */
	const META_CURSO = '_cead_acad_turismo';

	/** Dónde vive el portal (sin barra final). */
	const OPT_URL = 'cead_acad_turismo_url';

	/** Prefijo de las opciones donde viven los códigos sin canjear. */
	const PREFIJO = 'cead_acad_tur_code_';

	/** Cuánto vive un código. Corto a propósito: se canjea en el mismo clic. */
	const VIDA_SEG = 120;

	/** Tolerancia de reloj entre los dos servidores, para la firma. */
	const DESFASE_SEG = 300;

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'rutas' ] );
		add_action( 'cead_acad_turismo_purga', [ $this, 'purgar' ] );
	}

	/* ===================================================================== */
	/* Elegibilidad                                                           */
	/* ===================================================================== */

	/** ¿Está configurado el puente? Sin URL o sin secreto no se muestra nada. */
	public static function activo() {
		return '' !== self::portal_url() && '' !== self::secreto();
	}

	public static function portal_url() {
		return rtrim( (string) get_option( self::OPT_URL, '' ), '/' );
	}

	/**
	 * El secreto compartido vive en wp-config, no en la base.
	 *
	 * En la base termina en cualquier export o copia de seguridad que alguien
	 * pase por chat, y este secreto es lo único que impide que un tercero canjee
	 * un código robado.
	 */
	protected static function secreto() {
		return ( defined( 'CEAD_TUR_SSO_SECRET' ) && CEAD_TUR_SSO_SECRET )
			? (string) CEAD_TUR_SSO_SECRET
			: '';
	}

	/** Los cursos marcados como turísticos. */
	public static function cursos() {
		$ids = get_posts( [
			'post_type'   => Cead_Acad_Courses_CPT::POST_TYPE,
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
			'meta_key'    => self::META_CURSO,
			'meta_value'  => '1',
		] );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Qué es esta persona para el portal, o '' si no le corresponde entrar.
	 *
	 * Se resuelve por el CURSO marcado y no por el rol suelto: un docente lo es
	 * del colegio entero, y lo que habilita el portal es estar en ESE curso.
	 *
	 * Marcar el curso con una casilla, en vez de mirar si el título dice
	 * «Servicios Turísticos», también evita que renombrarlo deje a todo el mundo
	 * afuera sin que nadie entienda por qué.
	 *
	 * @return string 'alumno_turismo' | 'docente_turismo' | ''
	 */
	public static function rol_de( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! self::activo() ) {
			return '';
		}
		// Una cuenta suspendida no entra a ningún lado.
		if ( class_exists( 'Cead_Acad_User_Suspension' ) && Cead_Acad_User_Suspension::is_suspended( $user_id ) ) {
			return '';
		}

		$cursos = self::cursos();
		if ( ! $cursos ) {
			return '';
		}

		$mios = class_exists( 'Cead_Acad_Courses_Roster' )
			? array_map( 'intval', (array) Cead_Acad_Courses_Roster::courses_for_user( $user_id ) )
			: [];
		$actual = (int) get_user_meta( $user_id, '_cead_acad_current_course_id', true );
		if ( $actual ) {
			$mios[] = $actual;
		}

		if ( array_intersect( $cursos, array_unique( $mios ) ) ) {
			return user_can( $user_id, 'cead_acad_record_grade' ) ? 'docente_turismo' : 'alumno_turismo';
		}

		// El tutor del curso cuenta aunque no figure entre los inscriptos.
		foreach ( $cursos as $cid ) {
			if ( (int) get_post_meta( $cid, '_cead_acad_tutor', true ) === $user_id ) {
				return 'docente_turismo';
			}
		}

		return '';
	}

	/* ===================================================================== */
	/* Emisión del código                                                     */
	/* ===================================================================== */

	/**
	 * Genera el código y devuelve la URL a la que mandar a la persona.
	 *
	 * Por la URL viaja SOLO el código, que no dice nada. Los datos de la persona
	 * quedan acá y se entregan por detrás, cuando caaguazu.net los pide firmando.
	 * Una URL con el email y el rol adentro termina en el historial del
	 * navegador, en la cabecera `Referer` y en los logs de cualquier proxy.
	 *
	 * @return string URL, o '' si esa persona no puede entrar.
	 */
	public static function emitir( $user_id ) {
		$rol = self::rol_de( $user_id );
		if ( '' === $rol ) {
			return '';
		}
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return '';
		}

		$code = bin2hex( random_bytes( 32 ) );

		/*
		 * Se guarda el HASH del código, no el código.
		 *
		 * Si alguien llega a leer la tabla de opciones, con el hash no puede
		 * fabricar un canje: tendría que invertir un SHA-256. Es el mismo
		 * criterio con el que caaguazu-cuentas guarda sus tokens de sesión.
		 */
		update_option( self::PREFIJO . hash( 'sha256', $code ), [
			'claims' => [
				'cead_uid' => (int) $user->ID,
				'email'    => (string) $user->user_email,
				'nombre'   => (string) $user->display_name,
				'telefono' => (string) get_user_meta( $user->ID, '_cead_acad_phone_e164', true ),
				'rol'      => $rol,
				'curso'    => self::titulo_curso( $user->ID ),
				/*
				 * El CEAD NO verifica el email: en el registro la persona lo
				 * escribe y nadie confirma que sea suyo. Va explícito porque del
				 * otro lado se usa para decidir si vincular con una cuenta que
				 * ya existe, y ahí la diferencia entre «esta persona» y «alguien
				 * que escribió su email» es toda la seguridad del asunto.
				 */
				'email_verificado' => false,
				'emitido'          => time(),
			],
			'vence'  => time() + self::VIDA_SEG,
		], false );

		if ( class_exists( 'Cead_Acad_Audit' ) ) {
			Cead_Acad_Audit::log( 'turismo_codigo_emitido', [ 'user' => (int) $user->ID, 'rol' => $rol ] );
		}

		return self::portal_url() . '/acceso-cead?code=' . rawurlencode( $code );
	}

	protected static function titulo_curso( $user_id ) {
		$cursos = self::cursos();
		$mios   = class_exists( 'Cead_Acad_Courses_Roster' )
			? array_map( 'intval', (array) Cead_Acad_Courses_Roster::courses_for_user( $user_id ) )
			: [];
		$comun = array_intersect( $cursos, $mios );
		$id    = $comun ? (int) reset( $comun ) : ( $cursos ? (int) reset( $cursos ) : 0 );
		return $id ? (string) get_the_title( $id ) : '';
	}

	/* ===================================================================== */
	/* Canje                                                                  */
	/* ===================================================================== */

	public function rutas() {
		register_rest_route( 'cead-sso/v1', '/redeem', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'canjear' ],
			// La autenticación es la FIRMA del cuerpo, no una sesión: quien
			// llama es otro servidor, no una persona con cookie.
			'permission_callback' => '__return_true',
		] );
	}

	public function canjear( $req ) {
		if ( ! self::activo() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'no_configurado' ], 503 );
		}
		if ( ! cead_acad_rate_limit( 'tur_redeem', 60, 60 ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'demasiados_pedidos' ], 429 );
		}

		$code = (string) $req->get_param( 'code' );
		$ts   = (int) $req->get_param( 'ts' );
		$sig  = (string) $req->get_param( 'sig' );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $code ) ) {
			return self::error( 'code_invalido' );
		}
		if ( abs( time() - $ts ) > self::DESFASE_SEG ) {
			return self::error( 'desfase_horario' );
		}
		if ( ! self::firma_ok( $code, $ts, $sig ) ) {
			return self::error( 'firma_invalida' );
		}

		$clave    = self::PREFIJO . hash( 'sha256', $code );
		$guardado = get_option( $clave, null );
		if ( ! is_array( $guardado ) || empty( $guardado['claims'] ) ) {
			return self::error( 'code_invalido' );
		}

		/*
		 * El consumo va ANTES de mirar el vencimiento, y es lo que hace que el
		 * código sirva una sola vez: `delete_option()` devuelve true solo para
		 * QUIEN borró la fila. Si dos pestañas canjean a la vez, una gana y la
		 * otra recibe «ya usado», que es lo correcto. Mirar primero y borrar
		 * después dejaría una ventana en la que las dos pasan.
		 */
		if ( ! delete_option( $clave ) ) {
			return self::error( 'code_usado' );
		}
		if ( time() > (int) ( $guardado['vence'] ?? 0 ) ) {
			return self::error( 'code_vencido' );
		}

		if ( class_exists( 'Cead_Acad_Audit' ) ) {
			Cead_Acad_Audit::log( 'turismo_codigo_canjeado', [
				'user' => (int) ( $guardado['claims']['cead_uid'] ?? 0 ),
			] );
		}

		return new WP_REST_Response( array_merge( [ 'ok' => true ], $guardado['claims'] ), 200 );
	}

	/**
	 * Verifica la firma del canje.
	 *
	 * `hash_equals()` y no `===`: comparar firmas con el operador normal corta
	 * apenas encuentra una diferencia, y ese microsegundo de menos le dice a
	 * quien prueba cuántos caracteres del principio acertó. Con eso se
	 * reconstruye la firma de a un carácter por vez en vez de adivinarla entera.
	 */
	public static function firma_ok( $code, $ts, $sig ) {
		return self::firma_valida( $code, $ts, $sig, self::secreto() );
	}

	/**
	 * La comparación en sí, con el secreto como argumento.
	 *
	 * Se separa para poder testearla sin depender de una constante de
	 * `wp-config.php` — y sobre todo para poder probar el borde peligroso: qué
	 * pasa con el secreto VACÍO. Ahí `hash_hmac` no falla, devuelve el hash de
	 * la cadena vacía como clave; alguien que sepa que el sitio está a medio
	 * configurar podría calcularlo y firmar. Cortar de entrada convierte «mal
	 * configurado» en «cerrado», y no en «abierto para quien se dé cuenta».
	 */
	public static function firma_valida( $code, $ts, $sig, $secreto ) {
		if ( '' === (string) $secreto || ! is_string( $sig ) || '' === $sig ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $code . '|' . (int) $ts, (string) $secreto ), $sig );
	}

	protected static function error( $clave ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => $clave ], 400 );
	}

	/**
	 * Barre los códigos que nadie canjeó.
	 *
	 * Viven dos minutos, pero si nadie los borra la fila queda para siempre: un
	 * alumno que toca el botón y cierra la pestaña deja basura, y eso se acumula
	 * de a una por clic.
	 */
	public function purgar() {
		global $wpdb;
		$filas = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( self::PREFIJO ) . '%'
		) );
		foreach ( (array) $filas as $f ) {
			$v = maybe_unserialize( $f->option_value );
			if ( ! is_array( $v ) || time() > (int) ( $v['vence'] ?? 0 ) ) {
				delete_option( $f->option_name );
			}
		}
	}
}
