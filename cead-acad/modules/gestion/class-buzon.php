<?php
/**
 * El buzón: reportes y sugerencias del alumnado, y la respuesta de coordinación.
 *
 * Toda esta lógica vivía adentro de la plantilla de la página del panel, y el
 * aviso al alumno de que su reporte tuvo respuesta salía por WhatsApp, escrito
 * ahí mismo. Con la app y sin el bot eso no alcanza: la app necesita las mismas
 * acciones, y el aviso tiene que poder llegar por otro lado.
 *
 * Así que la lógica pasa acá —la plantilla y la API llaman a lo mismo— y el
 * aviso deja de estar atado a un canal: al responder se dispara
 * `cead_acad_buzon_respuesta`, y quien quiera avisar se cuelga de ahí. Hoy lo
 * escucha el módulo de WhatsApp, igual que antes; mañana, las notificaciones
 * de la app.
 *
 * Quién ve qué, como siempre: Dirección y Secretaría (`manage_reports`) ven
 * todo; el Consejo Estudiantil (`manage_suggestions` sin lo otro) ve y responde
 * solo las sugerencias dirigidas al Consejo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Buzon {

	const ACCIONES_REPORTE    = [ 'respond', 'accept', 'not_report', 'trash', 'restore', 'purge' ];
	const ACCIONES_SUGERENCIA = [ 'respond', 'accept', 'deny', 'trash', 'restore', 'purge' ];

	/** Las acciones que le cambian algo a quien escribió, y por eso le avisan. */
	const ACCIONES_CON_AVISO = [ 'respond', 'accept', 'deny' ];

	/** Consultas de estado por persona y por minuto: el código es la única llave. */
	const CONSULTAS_MINUTO = 10;

	/** @var Cead_Acad_WA_Store */
	protected $store;

	public function __construct( $store = null ) {
		$this->store = $store ?: new Cead_Acad_WA_Store();
	}

	/* ------------------------------------------------------------- alcance */

	/**
	 * Qué parte del buzón ve esta persona.
	 *
	 * @return string `todo`, `consejo` o `` (nada).
	 */
	public static function alcance( $user_id ) {
		if ( user_can( $user_id, 'cead_acad_manage_reports' ) ) {
			return 'todo';
		}
		if ( user_can( $user_id, 'cead_acad_manage_suggestions' ) ) {
			return 'consejo';
		}
		return '';
	}

	/**
	 * ¿Puede hacer esta acción sobre este ítem? Pura, para probarla.
	 *
	 * @param string $alcance   `todo` / `consejo` / ``.
	 * @param string $tipo      `reporte` / `sugerencia`.
	 * @param string $categoria Categoría de la sugerencia (solo importa para el Consejo).
	 */
	public static function puede( $alcance, $tipo, $accion, $categoria = '' ) {
		if ( 'reporte' === $tipo ) {
			return 'todo' === $alcance && in_array( $accion, self::ACCIONES_REPORTE, true );
		}
		if ( 'sugerencia' === $tipo ) {
			if ( ! in_array( $accion, self::ACCIONES_SUGERENCIA, true ) ) {
				return false;
			}
			if ( 'todo' === $alcance ) {
				return true;
			}
			// El Consejo, solo lo suyo. Puede mandar a la papelera —el panel
			// siempre le mostró «Borrar»— pero no ve la papelera, así que ni
			// restaura ni borra para siempre.
			return 'consejo' === $alcance && 'consejo' === $categoria
				&& ! in_array( $accion, [ 'restore', 'purge' ], true );
		}
		return false;
	}

	/* -------------------------------------------------------------- listar */

	public function listar( $user_id ) {
		$alcance = self::alcance( $user_id );
		if ( '' === $alcance ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No tenés permiso para ver el buzón.', 'cead-acad' ), [ 'status' => 403 ] );
		}
		$todo = 'todo' === $alcance;

		return [
			'alcance'     => $alcance,
			'reportes'    => $todo ? array_map( [ $this, 'reporte' ], $this->store->reports_by_status( '', 100 ) ) : [],
			'sugerencias' => array_map( [ $this, 'sugerencia' ], $todo
				? $this->store->suggestions_by_category( '', 100 )
				: $this->store->suggestions_by_category( 'consejo', 100 ) ),
			'papelera'    => $todo ? [
				'reportes'    => array_map( [ $this, 'reporte' ], $this->store->reports_trashed( 50 ) ),
				'sugerencias' => array_map( [ $this, 'sugerencia' ], $this->store->suggestions_trashed( 50 ) ),
			] : null,
		];
	}

	public function reporte( $r ) {
		$texto = Cead_Acad_WA_Crypto::decrypt( (string) $r->body_enc );
		return [
			'id'        => (int) $r->id,
			'codigo'    => (string) $r->ref_code,
			'tipo'      => 'confidential' === $r->type ? 'confidencial' : 'anonimo',
			'categoria' => (string) $r->category,
			// Null si no se pudo descifrar (la clave cambió): la app lo dice,
			// en vez de mostrar un reporte vacío como si no tuviera texto.
			'texto'     => null !== $texto ? (string) $texto : null,
			'estado'    => (string) $r->status,
			'respuesta' => (string) ( $r->response ?? '' ),
			'notas'     => (string) ( $r->note ?? '' ),
			// El número solo en los confidenciales: en uno anónimo no se guarda.
			'telefono'  => 'confidential' === $r->type && ! empty( $r->phone ) ? (string) $r->phone : null,
			'creado'    => (string) $r->created_at,
		];
	}

	public function sugerencia( $s ) {
		return [
			'id'        => (int) $s->id,
			'categoria' => (string) $s->category,
			'texto'     => (string) $s->body,
			'estado'    => (string) $s->status,
			'respuesta' => (string) ( $s->response ?? '' ),
			'telefono'  => ! empty( $s->phone ) ? (string) $s->phone : null,
			'creado'    => (string) $s->created_at,
		];
	}

	/**
	 * Lo que mandó esta persona y cómo va: sus mensajes y sus reportes
	 * confidenciales. Los anónimos no, porque el servidor no sabe de quién son
	 * —la app los sigue por el código—.
	 */
	public function mios( $user_id ) {
		return [
			'mensajes' => array_map( static function ( $s ) {
				return [
					'id'        => (int) $s->id,
					'para'      => (string) $s->category,
					'texto'     => self::sin_firma( (string) $s->body ),
					'estado'    => (string) $s->status,
					'respuesta' => (string) ( $s->response ?? '' ),
					'creado'    => (string) $s->created_at,
				];
			}, $this->store->suggestions_by_user( $user_id ) ),
			'reportes' => array_map( static function ( $r ) {
				return [
					'codigo'      => (string) $r->ref_code,
					'categoria'   => (string) $r->category,
					'estado'      => (string) $r->status,
					'respuesta'   => (string) ( $r->response ?? '' ),
					'creado'      => (string) $r->created_at,
					'actualizado' => (string) $r->updated_at,
				];
			}, $this->store->reports_by_user( $user_id ) ),
		];
	}

	/**
	 * El mensaje sin la línea «✉️ De Nombre (Rol)» que le agrega
	 * `Cead_Acad_Account::enviar_mensaje()` para coordinación: a quien lo
	 * escribió no le hace falta que le digan quién es.
	 */
	public static function sin_firma( $cuerpo ) {
		return (string) preg_replace( '/^✉️ De [^\n]*\n\n/u', '', (string) $cuerpo, 1 );
	}

	/* -------------------------------------------------------------- actuar */

	/**
	 * Responde, acepta, rechaza, archiva o borra un ítem.
	 *
	 * @return true|WP_Error
	 */
	public function actuar( $user_id, $tipo, $id, $accion, $respuesta = '' ) {
		$id        = (int) $id;
		$accion    = sanitize_key( (string) $accion );
		$respuesta = sanitize_textarea_field( (string) $respuesta );
		$alcance   = self::alcance( $user_id );

		$fila = 'reporte' === $tipo ? $this->store->get_report( $id ) : ( 'sugerencia' === $tipo ? $this->store->get_suggestion( $id ) : null );
		if ( ! $fila ) {
			return new WP_Error( 'no_existe', __( 'Ese ítem del buzón no existe.', 'cead-acad' ), [ 'status' => 404 ] );
		}
		$categoria = 'sugerencia' === $tipo ? (string) $fila->category : '';
		if ( ! self::puede( $alcance, $tipo, $accion, $categoria ) ) {
			return new WP_Error( 'cead_api_sin_permiso', __( 'No podés hacer eso con este ítem.', 'cead-acad' ), [ 'status' => 403 ] );
		}

		if ( 'reporte' === $tipo ) {
			switch ( $accion ) {
				case 'respond':    $this->store->respond_report( $id, $respuesta, 'in_review' ); break;
				case 'accept':     $this->store->respond_report( $id, $respuesta, 'accepted' ); break;
				case 'not_report': $this->store->mark_not_report( $id ); break;
				case 'trash':      $this->store->soft_delete_report( $id ); break;
				case 'restore':    $this->store->restore_report( $id ); break;
				case 'purge':      $this->store->purge_report( $id ); break;
			}
		} else {
			switch ( $accion ) {
				case 'respond': $this->store->respond_suggestion( $id, $respuesta, 'in_review' ); break;
				case 'accept':  $this->store->respond_suggestion( $id, $respuesta, 'accepted' ); break;
				case 'deny':    $this->store->respond_suggestion( $id, $respuesta, 'denied' ); break;
				case 'trash':   $this->store->soft_delete_suggestion( $id ); break;
				case 'restore': $this->store->restore_suggestion( $id ); break;
				case 'purge':   $this->store->purge_suggestion( $id ); break;
			}
		}

		if ( in_array( $accion, self::ACCIONES_CON_AVISO, true ) ) {
			/**
			 * Coordinación le contestó a alguien. Quien sepa cómo avisarle, que
			 * le avise: el buzón no sabe —ni tiene por qué saber— por qué canal.
			 *
			 * @param string $tipo      `reporte` o `sugerencia`.
			 * @param object $fila      La fila como estaba ANTES de la acción.
			 * @param string $accion    `respond`, `accept` o `deny`.
			 * @param string $respuesta Lo que escribió coordinación.
			 */
			do_action( 'cead_acad_buzon_respuesta', $tipo, $fila, $accion, $respuesta );
		}
		return true;
	}

	/* --------------------------------------------- reportes desde la app */

	/** Las mismas que ofrecía el bot, que se editan en Ajustes de WhatsApp. */
	public static function categorias() {
		$c = get_option( 'cead_acad_wa_report_categories', [] );
		return is_array( $c ) && $c
			? array_values( array_map( 'strval', $c ) )
			: [ 'Bullying / acoso', 'Seguridad', 'Infraestructura', 'Otro' ];
	}

	/**
	 * Un reporte nuevo, mandado desde la app.
	 *
	 * @param string $tipo `anonimo` o `confidencial`.
	 * @return string|WP_Error El código de seguimiento.
	 */
	public function crear_reporte( $user_id, $tipo, $categoria, $texto ) {
		$texto = trim( sanitize_textarea_field( (string) $texto ) );
		if ( '' === $texto ) {
			return new WP_Error( 'vacio', __( 'Contá qué pasó.', 'cead-acad' ), [ 'status' => 400 ] );
		}
		$categoria = sanitize_text_field( (string) $categoria );
		if ( ! in_array( $categoria, self::categorias(), true ) ) {
			$categoria = 'Otro';
		}

		if ( 'confidencial' === $tipo ) {
			$quien    = $this->identidad( $user_id );
			$cuerpo   = self::cuerpo_confidencial( $quien['nombre'], $quien['rol'], $quien['curso'], $texto );
			$telefono = '' !== $quien['telefono'] ? $quien['telefono'] : null;
			$codigo   = $this->store->create_report( 'confidential', $telefono, $categoria, $cuerpo, (int) $user_id );
		} else {
			// Ni el id, ni el teléfono, ni nada que salga de la sesión.
			$codigo = $this->store->create_report( 'anonymous', null, $categoria, $texto );
		}

		/**
		 * Entró un reporte. Para avisarle a coordinación; no lleva quién lo
		 * mandó, porque en uno anónimo eso no se sabe y no tiene que saberse.
		 *
		 * @param string $codigo El código de seguimiento.
		 */
		do_action( 'cead_acad_buzon_reporte_nuevo', $codigo );
		return $codigo;
	}

	/**
	 * Nombre, rol, curso y teléfono de quien manda un reporte confidencial.
	 *
	 * @return array{nombre:string,rol:string,curso:string,telefono:string}
	 */
	protected function identidad( $user_id ) {
		$u     = get_userdata( $user_id );
		$roles = Cead_Acad_Capabilities::roles();
		$rol   = cead_acad_user_role( $user_id );
		$curso = cead_acad_curso_actual( $user_id );
		return [
			'nombre'   => $u ? (string) $u->display_name : '',
			'rol'      => (string) ( $roles[ $rol ]['display'] ?? $rol ),
			'curso'    => $curso ? (string) get_the_title( $curso ) : '',
			'telefono' => (string) get_user_meta( $user_id, Cead_Acad_Account::PHONE_META, true ),
		];
	}

	/**
	 * El texto de un reporte confidencial, con quién lo manda adentro.
	 *
	 * La tabla identifica a quien reporta por su número de WhatsApp, que era
	 * el único canal. Desde la app, alguien sin teléfono cargado mandaría un
	 * reporte «confidencial» que coordinación no podría atribuir a nadie: se
	 * convertiría en anónimo sin que nadie lo decida, que es exactamente lo
	 * contrario de lo que eligió esa persona. Así que el nombre va en el
	 * cuerpo, que se guarda cifrado igual que el resto.
	 */
	public static function cuerpo_confidencial( $nombre, $rol, $curso, $texto ) {
		$quien = trim( (string) $nombre );
		$datos = array_filter( [ trim( (string) $rol ), trim( (string) $curso ) ] );
		if ( $datos ) {
			$quien .= ' (' . implode( ', ', $datos ) . ')';
		}
		return '🔒 De: ' . ( '' !== $quien ? $quien : '—' ) . "\n\n" . (string) $texto;
	}

	/**
	 * Cómo va un reporte, por su código.
	 *
	 * El código es lo único que conecta un reporte anónimo con quien lo mandó:
	 * el servidor no sabe quién fue, así que la app guarda el código y pregunta
	 * por él. Por eso no se devuelve el texto —quien pregunta ya lo sabe, y
	 * quien adivina un código no tiene por qué leerlo— y las consultas tienen
	 * un tope por persona.
	 *
	 * @return array|WP_Error|null Null si no existe.
	 */
	public function estado_reporte( $user_id, $codigo ) {
		$clave = 'cead_acad_buzon_consulta_' . (int) $user_id . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::CONSULTAS_MINUTO ) {
			return new WP_Error( 'muchas', __( 'Esperá un momento antes de volver a consultar.', 'cead-acad' ), [ 'status' => 429 ] );
		}
		set_transient( $clave, $n + 1, 2 * MINUTE_IN_SECONDS );

		$codigo = strtoupper( trim( (string) $codigo ) );
		if ( ! preg_match( '/^RPT-[0-9A-F]{6}$/', $codigo ) ) {
			return null;
		}
		$r = $this->store->get_report_by_ref( $codigo );
		if ( ! $r || ! empty( $r->deleted_at ) ) {
			return null;
		}
		return [
			'codigo'      => (string) $r->ref_code,
			'estado'      => (string) $r->status,
			'respuesta'   => (string) ( $r->response ?? '' ),
			'actualizado' => (string) $r->updated_at,
		];
	}
}
