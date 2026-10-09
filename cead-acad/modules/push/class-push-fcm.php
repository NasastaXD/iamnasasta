<?php
/**
 * El envío a Firebase Cloud Messaging (FCM), sin dependencias.
 *
 * FCM ya no acepta la clave de servidor de antes: pide un token OAuth que se
 * saca firmando un JWT con la clave privada de una cuenta de servicio. Eso son
 * unas treinta líneas con `openssl_sign`, así que se hace acá y no se suma una
 * librería entera al plugin del colegio.
 *
 * Las credenciales son la clave privada de la cuenta de servicio: dan permiso
 * de mandar notificaciones a nombre del colegio. Por eso NO se guardan en la
 * base (donde las ve cualquier plugin y cualquier copia de seguridad) sino en
 * un archivo fuera de lo público, cuya ruta se declara en `wp-config.php`:
 *
 *     define( 'CEAD_ACAD_FCM_CREDENTIALS', '/ruta/fuera/de/public_html/cead-fcm.json' );
 *
 * Sin eso, `disponible()` es falso y el resto del sistema de avisos sigue
 * andando sin mandar nada: la app funciona igual, solo que sin timbre.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Push_Fcm {

	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';
	const CACHE_KEY = 'cead_acad_fcm_access';

	/** Resultados posibles de un envío. */
	const OK         = 'ok';
	const DESCARTAR  = 'descartar';  // El token ya no existe: sacarlo.
	const REINTENTAR = 'reintentar'; // Falló algo nuestro o de Google: dejarlo.

	/** @var callable|null Transporte inyectable: fn( $url, array $args ): array|WP_Error con code y body. */
	protected $transporte;
	/** @var array|null */
	protected $credenciales;

	public function __construct( $transporte = null, $credenciales = null ) {
		$this->transporte   = $transporte;
		$this->credenciales = $credenciales;
	}

	/* ------------------------------------------------------- credenciales */

	/**
	 * La cuenta de servicio, o null si no hay o está mal armada.
	 *
	 * @return array{project_id:string,client_email:string,private_key:string}|null
	 */
	public function credenciales() {
		if ( null !== $this->credenciales ) {
			return self::validar_credenciales( $this->credenciales );
		}
		$datos = apply_filters( 'cead_acad_fcm_credentials', null );
		if ( ! is_array( $datos ) && defined( 'CEAD_ACAD_FCM_CREDENTIALS' ) ) {
			$ruta = (string) CEAD_ACAD_FCM_CREDENTIALS;
			if ( '' !== $ruta && is_readable( $ruta ) ) {
				$datos = json_decode( (string) file_get_contents( $ruta ), true );
			}
		}
		return self::validar_credenciales( $datos );
	}

	/** Pura, para probarla: ¿tiene lo mínimo para firmar y para saber a qué proyecto mandar? */
	public static function validar_credenciales( $datos ) {
		if ( ! is_array( $datos ) ) {
			return null;
		}
		foreach ( [ 'project_id', 'client_email', 'private_key' ] as $k ) {
			if ( empty( $datos[ $k ] ) || ! is_string( $datos[ $k ] ) ) {
				return null;
			}
		}
		if ( false === strpos( $datos['private_key'], 'PRIVATE KEY' ) ) {
			return null;
		}
		return [
			'project_id'   => $datos['project_id'],
			'client_email' => $datos['client_email'],
			'private_key'  => $datos['private_key'],
		];
	}

	public function disponible() {
		return null !== $this->credenciales();
	}

	/* ----------------------------------------------------------- OAuth JWT */

	/**
	 * El JWT que se cambia por un token de acceso.
	 *
	 * @param array $cred Salida de `credenciales()`.
	 * @return string|null Null si no se pudo firmar (clave ilegible).
	 */
	public static function armar_jwt( array $cred, $ahora ) {
		$b64 = static function ( $s ) {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		};
		$cabecera = $b64( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$cuerpo   = $b64( wp_json_encode( [
			'iss'   => $cred['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => (int) $ahora,
			'exp'   => (int) $ahora + 3600,
		] ) );
		$firma = '';
		$clave = openssl_pkey_get_private( $cred['private_key'] );
		if ( ! $clave || ! openssl_sign( "{$cabecera}.{$cuerpo}", $firma, $clave, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		return "{$cabecera}.{$cuerpo}." . $b64( $firma );
	}

	/** El token de acceso, de la caché si sigue vigente. */
	public function token_acceso() {
		$cred = $this->credenciales();
		if ( ! $cred ) {
			return null;
		}
		$guardado = get_transient( self::CACHE_KEY );
		if ( is_string( $guardado ) && '' !== $guardado ) {
			return $guardado;
		}

		$jwt = self::armar_jwt( $cred, time() );
		if ( ! $jwt ) {
			return null;
		}
		$r = $this->pedir( self::TOKEN_URL, [
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => [ 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ],
		] );
		if ( is_wp_error( $r ) || 200 !== $r['code'] ) {
			return null;
		}
		$d = json_decode( $r['body'], true );
		if ( ! is_array( $d ) || empty( $d['access_token'] ) ) {
			return null;
		}
		// Se renueva cinco minutos antes de que venza, para no mandar con uno
		// que se muere a mitad de un lote.
		set_transient( self::CACHE_KEY, (string) $d['access_token'], max( 60, (int) ( $d['expires_in'] ?? 3600 ) - 300 ) );
		return (string) $d['access_token'];
	}

	/* -------------------------------------------------------------- envío */

	/**
	 * Manda una notificación a un dispositivo.
	 *
	 * @param string $token Token de registro de FCM.
	 * @param array  $aviso titulo, cuerpo, datos (mapa de strings).
	 * @return string `OK`, `DESCARTAR` o `REINTENTAR`.
	 */
	public function enviar( $token, array $aviso ) {
		$cred = $this->credenciales();
		if ( ! $cred ) {
			return self::REINTENTAR;
		}

		for ( $intento = 0; $intento < 2; $intento++ ) {
			$acceso = $this->token_acceso();
			if ( ! $acceso ) {
				return self::REINTENTAR;
			}
			$r = $this->pedir(
				'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $cred['project_id'] ) . '/messages:send',
				[
					'headers' => [ 'Authorization' => 'Bearer ' . $acceso, 'Content-Type' => 'application/json; charset=utf-8' ],
					'body'    => wp_json_encode( [ 'message' => self::mensaje( $token, $aviso ) ] ),
				]
			);
			if ( is_wp_error( $r ) ) {
				return self::REINTENTAR;
			}
			// El token de acceso se venció o lo revocaron: se pide otro y se
			// prueba una vez más. Una segunda vez no: sería un error de verdad.
			if ( 401 === $r['code'] && 0 === $intento ) {
				delete_transient( self::CACHE_KEY );
				continue;
			}
			return self::clasificar( $r['code'], $r['body'] );
		}
		return self::REINTENTAR;
	}

	/**
	 * El mensaje de FCM. Pura.
	 *
	 * Todo valor de `data` tiene que ser un string: FCM rechaza el mensaje
	 * entero si hay un número o un null, y el error no dice cuál.
	 */
	public static function mensaje( $token, array $aviso ) {
		$datos = [];
		foreach ( (array) ( $aviso['datos'] ?? [] ) as $k => $v ) {
			$datos[ (string) $k ] = (string) $v;
		}
		return [
			'token'        => (string) $token,
			'notification' => [
				'title' => (string) ( $aviso['titulo'] ?? '' ),
				'body'  => (string) ( $aviso['cuerpo'] ?? '' ),
			],
			'data'         => $datos ?: new stdClass(),
			'android'      => [
				'priority'     => 'high',
				'notification' => [ 'channel_id' => 'cead_avisos' ],
			],
			'apns'         => [ 'payload' => [ 'aps' => [ 'sound' => 'default' ] ] ],
		];
	}

	/**
	 * Qué hacer según lo que contestó FCM. Pura.
	 *
	 * Solo se descarta el token cuando FCM dice que ESE token ya no existe
	 * (app desinstalada, datos borrados). Un error de formato del mensaje, de
	 * permisos o de cuota no es culpa del dispositivo: descartarlo dejaría a
	 * todos sin avisos por un error nuestro.
	 */
	public static function clasificar( $codigo, $cuerpo ) {
		if ( $codigo >= 200 && $codigo < 300 ) {
			return self::OK;
		}
		$d       = json_decode( (string) $cuerpo, true );
		$detalle = is_array( $d ) ? ( $d['error'] ?? [] ) : [];
		$codigos = [ (string) ( $detalle['status'] ?? '' ) ];
		foreach ( (array) ( $detalle['details'] ?? [] ) as $x ) {
			if ( is_array( $x ) && isset( $x['errorCode'] ) ) {
				$codigos[] = (string) $x['errorCode'];
			}
		}
		if ( in_array( 'UNREGISTERED', $codigos, true ) ) {
			return self::DESCARTAR;
		}
		if ( 404 === (int) $codigo && in_array( 'NOT_FOUND', $codigos, true ) ) {
			return self::DESCARTAR;
		}
		return self::REINTENTAR;
	}

	/* ----------------------------------------------------------- transporte */

	/** @return array{code:int,body:string}|WP_Error */
	protected function pedir( $url, array $args ) {
		if ( $this->transporte ) {
			return call_user_func( $this->transporte, $url, $args );
		}
		$r = wp_remote_post( $url, $args + [ 'timeout' => 10 ] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return [ 'code' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) ];
	}
}
