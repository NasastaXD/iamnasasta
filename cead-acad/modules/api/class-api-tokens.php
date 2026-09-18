<?php
/**
 * Tokens de acceso para las apps nativas.
 *
 * El panel corre sobre usuarios de WordPress, pero la app no puede usar ninguna
 * de las formas que WordPress trae para autenticarse desde afuera:
 *
 *  - Las cookies no sirven: una app nativa no es un navegador con sesión, y
 *    atarla a cookies obliga a arrastrar nonces y dominios por todos lados.
 *  - Las contraseñas de aplicación están apagadas a propósito para la gente del
 *    plugin (ver `Cead_Acad_Hardening`), y por un motivo que sigue valiendo acá:
 *    una vez generadas sirven para siempre, aunque después la persona cambie la
 *    contraseña.
 *
 * Así que la app tiene su propio token, y se le pide justo lo que a esa puerta
 * trasera se le reprochaba:
 *
 *  1. Muere cuando cambia la contraseña. Se guarda un pedazo del hash de la
 *     contraseña junto al token —el mismo truco que usan las cookies de
 *     WordPress— y si deja de coincidir, el token no vale más. Cambiar la
 *     contraseña vuelve a ser lo que la gente cree que es: echar a todos.
 *  2. Se puede revocar de a uno. Cada dispositivo tiene su entrada con nombre,
 *     así que perder el celular se arregla sacando ESE, sin desloguear al resto.
 *  3. Vence solo. Sesenta días sin usarse y se cae; usarlo lo renueva. Un
 *     teléfono que se usa todos los días no pide contraseña nunca más, y uno
 *     que quedó en un cajón deja de ser una llave a los dos meses.
 *
 * El token viaje como `Authorization: Bearer <token>` y tiene tres partes,
 * `<id de usuario>.<id del dispositivo>.<secreto>`. Las dos primeras están a la
 * vista a propósito: son las que permiten encontrar la entrada sin recorrer la
 * tabla de usuarios entera. Lo único secreto es la tercera, y de esa se guarda
 * el hash, nunca el valor.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_API_Tokens {

	/** Dónde viven los tokens de una persona. */
	const META = '_cead_acad_api_tokens';

	/** Sin usarse, un token dura esto. Cada uso lo renueva. */
	const VIDA_SEG = 60 * DAY_IN_SECONDS;

	/**
	 * Cuántos dispositivos puede tener una persona a la vez.
	 *
	 * No es una restricción de producto: es para que el meta no crezca sin
	 * techo. Al llegar al tope se cae el más viejo por falta de uso, que es
	 * casi siempre el que ya nadie tiene en la mano.
	 */
	const MAX_DISPOSITIVOS = 10;

	/* ------------------------------------------------------------- emisión */

	/**
	 * Crea un token nuevo para una persona.
	 *
	 * @param WP_User $user
	 * @param string  $dispositivo Nombre visible ("Motorola de Ana"), para que
	 *                             la lista de sesiones signifique algo.
	 * @return string El token en claro. Es la única vez que existe: de acá en
	 *                más solo queda su hash.
	 */
	public static function emitir( $user, $dispositivo = '' ) {
		$jti     = bin2hex( random_bytes( 6 ) );
		$secreto = bin2hex( random_bytes( 32 ) );
		$ahora   = time();

		/*
		 * Se poda ANTES de agregar, y hasta uno menos que el tope.
		 *
		 * Podar después parece igual y no lo es: los tokens que nacen en el
		 * mismo segundo empatan en «último uso», y con el empate el que sobra
		 * puede terminar siendo el que se acaba de emitir. Es decir, alguien se
		 * loguea y queda deslogueado en el mismo acto, sin error visible.
		 * Haciéndolo en este orden, el token nuevo no puede ser la víctima.
		 */
		$tokens = self::podar( self::leer( $user->ID ), $ahora, self::MAX_DISPOSITIVOS - 1 );

		$tokens[ $jti ] = [
			'hash'   => hash( 'sha256', $secreto ),
			'pass'   => self::huella( $user->user_pass ),
			'nombre' => self::limpiar_nombre( $dispositivo ),
			'creado' => $ahora,
			'usado'  => $ahora,
		];

		self::guardar( $user->ID, $tokens );

		return $user->ID . '.' . $jti . '.' . $secreto;
	}

	/* ----------------------------------------------------------- validación */

	/**
	 * ¿De quién es este token?
	 *
	 * @return int ID del usuario, o 0 si no vale.
	 */
	public static function validar( $token ) {
		$partes = self::partir( $token );
		if ( ! $partes ) {
			return 0;
		}
		list( $user_id, $jti, $secreto ) = $partes;

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return 0;
		}

		$tokens = self::leer( $user_id );
		if ( ! isset( $tokens[ $jti ] ) ) {
			return 0;
		}
		$entrada = $tokens[ $jti ];

		// Comparación en tiempo constante: el token es una credencial.
		if ( ! hash_equals( (string) ( $entrada['hash'] ?? '' ), hash( 'sha256', $secreto ) ) ) {
			return 0;
		}

		$ahora = time();
		if ( self::caducado( $entrada, $ahora ) ) {
			self::revocar_jti( $user_id, $jti );
			return 0;
		}

		/*
		 * La contraseña cambió desde que se emitió este token. Es el caso que
		 * justifica todo el archivo: acá el token se muere solo, sin que nadie
		 * se tenga que acordar de ir a revocarlo.
		 */
		if ( ! hash_equals( (string) ( $entrada['pass'] ?? '' ), self::huella( $user->user_pass ) ) ) {
			self::revocar_jti( $user_id, $jti );
			return 0;
		}

		self::tocar( $user_id, $jti, $ahora );

		return $user_id;
	}

	/**
	 * Parte un token en sus tres pedazos, o devuelve null si no tiene la forma
	 * esperada. Pura a propósito: es la única parte del formato que hay que
	 * poder probar sin base de datos.
	 *
	 * @return array{0:int,1:string,2:string}|null
	 */
	public static function partir( $token ) {
		if ( ! is_string( $token ) ) {
			return null;
		}
		$partes = explode( '.', trim( $token ) );
		if ( 3 !== count( $partes ) ) {
			return null;
		}
		list( $uid, $jti, $secreto ) = $partes;

		if ( ! ctype_digit( $uid ) || (int) $uid <= 0 ) {
			return null;
		}
		if ( 12 !== strlen( $jti ) || ! ctype_xdigit( $jti ) ) {
			return null;
		}
		if ( 64 !== strlen( $secreto ) || ! ctype_xdigit( $secreto ) ) {
			return null;
		}
		return [ (int) $uid, $jti, $secreto ];
	}

	/**
	 * ¿Esta entrada ya venció? Pura, para poder probar el vencimiento sin
	 * esperar sesenta días.
	 */
	public static function caducado( $entrada, $ahora ) {
		$usado = (int) ( $entrada['usado'] ?? 0 );
		if ( $usado <= 0 ) {
			return true;
		}
		return ( $ahora - $usado ) > self::VIDA_SEG;
	}

	/**
	 * El pedazo del hash de la contraseña que ata el token a la contraseña
	 * vigente. Es el mismo rango que usa WordPress para firmar sus cookies de
	 * sesión: cambia con cada contraseña nueva y no alcanza para reconstruir
	 * nada del hash original.
	 */
	public static function huella( $user_pass ) {
		return substr( (string) $user_pass, 8, 4 );
	}

	/* ---------------------------------------------------------- revocación */

	/** Saca un dispositivo. Devuelve si había algo que sacar. */
	public static function revocar_jti( $user_id, $jti ) {
		$tokens = self::leer( $user_id );
		if ( ! isset( $tokens[ $jti ] ) ) {
			return false;
		}
		unset( $tokens[ $jti ] );
		self::guardar( $user_id, $tokens );
		return true;
	}

	/** Cierra la sesión del token que vino en la petición. */
	public static function revocar( $token ) {
		$partes = self::partir( $token );
		if ( ! $partes ) {
			return false;
		}
		return self::revocar_jti( $partes[0], $partes[1] );
	}

	/** Echa a todos los dispositivos de una persona. */
	public static function revocar_todo( $user_id ) {
		delete_user_meta( (int) $user_id, self::META );
	}

	/* -------------------------------------------------------------- listado */

	/**
	 * Los dispositivos activos de una persona, sin nada secreto adentro: es lo
	 * que la app muestra en «sesiones abiertas».
	 */
	public static function sesiones( $user_id ) {
		$out = [];
		foreach ( self::leer( $user_id ) as $jti => $e ) {
			$out[] = [
				'id'     => (string) $jti,
				'nombre' => (string) ( $e['nombre'] ?? '' ),
				'creado' => (int) ( $e['creado'] ?? 0 ),
				'usado'  => (int) ( $e['usado'] ?? 0 ),
			];
		}
		usort( $out, static function ( $a, $b ) {
			return $b['usado'] <=> $a['usado'];
		} );
		return $out;
	}

	/* ------------------------------------------------------------- interno */

	protected static function leer( $user_id ) {
		$tokens = get_user_meta( (int) $user_id, self::META, true );
		return is_array( $tokens ) ? $tokens : [];
	}

	protected static function guardar( $user_id, $tokens ) {
		if ( ! $tokens ) {
			delete_user_meta( (int) $user_id, self::META );
			return;
		}
		update_user_meta( (int) $user_id, self::META, $tokens );
	}

	/**
	 * Marca el token como usado recién.
	 *
	 * Se escribe solo si pasó al menos una hora desde la última vez. Sin ese
	 * freno, cada pantalla que abre la app sería un `UPDATE` a `usermeta`: el
	 * dato que se busca es «este dispositivo sigue vivo», y para eso la
	 * precisión de una hora sobra.
	 */
	protected static function tocar( $user_id, $jti, $ahora ) {
		$tokens = self::leer( $user_id );
		if ( ! isset( $tokens[ $jti ] ) ) {
			return;
		}
		if ( ( $ahora - (int) ( $tokens[ $jti ]['usado'] ?? 0 ) ) < HOUR_IN_SECONDS ) {
			return;
		}
		$tokens[ $jti ]['usado'] = $ahora;
		self::guardar( $user_id, $tokens );
	}

	/**
	 * Saca los vencidos y, si aún así hay de más, los menos usados.
	 *
	 * @param int      $ahora
	 * @param int|null $tope Cuántos dejar. Por defecto, el máximo.
	 * @return array
	 */
	public static function podar( $tokens, $ahora, $tope = null ) {
		$tope = null === $tope ? self::MAX_DISPOSITIVOS : max( 0, (int) $tope );

		foreach ( $tokens as $jti => $e ) {
			if ( self::caducado( $e, $ahora ) ) {
				unset( $tokens[ $jti ] );
			}
		}
		if ( count( $tokens ) <= $tope ) {
			return $tokens;
		}
		// Desempata por fecha de alta: entre dos que se usaron en el mismo
		// segundo, se queda el más nuevo.
		uasort( $tokens, static function ( $a, $b ) {
			return [ (int) ( $b['usado'] ?? 0 ), (int) ( $b['creado'] ?? 0 ) ]
				<=> [ (int) ( $a['usado'] ?? 0 ), (int) ( $a['creado'] ?? 0 ) ];
		} );
		return array_slice( $tokens, 0, $tope, true );
	}

	protected static function limpiar_nombre( $nombre ) {
		$nombre = trim( wp_strip_all_tags( (string) $nombre ) );
		if ( '' === $nombre ) {
			$nombre = __( 'Dispositivo', 'cead-acad' );
		}
		return mb_substr( $nombre, 0, 60 );
	}
}
