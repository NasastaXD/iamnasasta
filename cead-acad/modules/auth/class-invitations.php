<?php
/**
 * CRUD de invitaciones. Tokens hasheados (no almacenamos plano).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Invitations {

	public function boot() {
		// Solo wiring básico; el admin UI lo monta Cead_Acad_Admin_Menu.
	}

	/**
	 * Días de validez por defecto según el rol. Delegado/a rota cada ciclo lectivo,
	 * así que su link dura 1 año; el resto ~3 años.
	 */
	public static function default_expires_days( $role ) {
		return ( 'cead_acad_delegate' === $role ) ? 365 : 1095;
	}

	/**
	 * Crea invitaciones y devuelve los tokens en plano (única oportunidad de verlos).
	 *
	 * Multiuso: con `max_uses` > 1 se crea **un solo link** reutilizable esa cantidad
	 * de veces. Con `max_uses` = 1 (default) se comporta como antes y respeta `count`
	 * (N links de un solo uso). `expires_days` null = default según el rol.
	 *
	 * @param array{role:string,course_id:?int,email:?string,expires_days:?int,count:int,max_uses:int} $args
	 * @return array<int,string> tokens en plano
	 */
	public static function create( $args ) {
		global $wpdb;
		$args = wp_parse_args( $args, [
			'role'         => 'cead_acad_student',
			'course_id'    => null,
			'email'        => null,
			'expires_days' => null,
			'count'        => 1,
			'max_uses'     => 1,
		] );

		$role     = self::sanitize_role( $args['role'] );
		$max_uses = max( 1, min( 1000, (int) $args['max_uses'] ) );
		// Un link multiuso es uno solo; el modo de N links sueltos solo aplica a single-use.
		$count    = ( $max_uses > 1 ) ? 1 : max( 1, min( 200, (int) $args['count'] ) );

		$days = ( null === $args['expires_days'] ) ? self::default_expires_days( $role ) : (int) $args['expires_days'];
		$days = max( 1, min( 3650, $days ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );

		$now   = current_time( 'mysql', 1 );
		$user_id = get_current_user_id();
		$table = cead_acad_table( 'invitations' );

		$email = $args['email'] ? sanitize_email( $args['email'] ) : null;

		$plain_tokens = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$token = cead_acad_generate_token();
			$plain_tokens[] = $token;
			// Guardamos el token en claro en metadata para poder mostrar/copiar
			// el link y reenviar email cuando haga falta. Las invitaciones son
			// de bajo riesgo (con tope de usos, expiran, su propósito es compartirse).
			$wpdb->insert(
				$table,
				[
					'token_hash' => cead_acad_hash_token( $token ),
					'email'      => $email,
					'role'       => $role,
					'course_id'  => $args['course_id'] ? (int) $args['course_id'] : null,
					'invited_by' => $user_id,
					'expires_at' => $expires,
					'max_uses'   => $max_uses,
					'used_count' => 0,
					'created_at' => $now,
					'metadata'   => wp_json_encode( [ 'token' => $token ] ),
				],
				[ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
			);
			$invitation_id = (int) $wpdb->insert_id;

			// Enviar email si se proveyó una dirección.
			if ( $email && ! self::send_email( $email, $token, $role ) ) {
				// El link ya quedó creado (se puede copiar/reenviar desde el
				// panel); solo dejamos rastro de que el correo no salió, para
				// que no sea un "se envió" silencioso que nunca llegó.
				Cead_Acad_Audit::log( 'invitation_email_failed', [
					'entity_type' => 'invitation',
					'entity_id'   => $invitation_id,
					'payload'     => [ 'email' => $email ],
				] );
			}
		}

		Cead_Acad_Audit::log( 'invitation_created', [
			'entity_type' => 'invitation',
			'payload'     => array_filter( [
				'count'     => $count,
				'max_uses'  => $max_uses,
				'role'      => $role,
				'course_id' => $args['course_id'] ? (int) $args['course_id'] : null,
				'email'     => $email,
			] ),
		] );

		return $plain_tokens;
	}

	/**
	 * Token en claro almacenado en metadata (si existe).
	 */
	public static function plain_token( $row ) {
		if ( empty( $row['metadata'] ) ) {
			return '';
		}
		$meta = json_decode( $row['metadata'], true );
		return is_array( $meta ) && ! empty( $meta['token'] ) ? (string) $meta['token'] : '';
	}

	/**
	 * Envía el email de invitación con el link de registro.
	 */
	public static function send_email( $email, $token, $role = '' ) {
		$roles = Cead_Acad_Capabilities::roles();
		$role_label = $roles[ $role ]['display'] ?? '';
		$url = self::registration_url( $token );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: nombre del sitio */
		$subject = sprintf( __( 'Invitación a %s', 'cead-acad' ), $site );

		/* translators: %s: nombre del sitio */
		$paragraphs = [ sprintf( __( 'Te invitamos a sumarte a %s.', 'cead-acad' ), '<strong>' . esc_html( $site ) . '</strong>' ) ];
		if ( $role_label ) {
			/* translators: %s: rol asignado al invitado */
			$paragraphs[] = sprintf( __( 'Rol asignado: %s.', 'cead-acad' ), '<strong>' . esc_html( $role_label ) . '</strong>' );
		}

		return Cead_Acad_Email::send( $email, $subject, [
			'title'      => __( '¡Te invitamos a sumarte!', 'cead-acad' ),
			'paragraphs' => $paragraphs,
			'cta_label'  => __( 'Crear mi cuenta', 'cead-acad' ),
			'cta_url'    => $url,
			'footnote'   => __( 'El link es de un solo uso. Si no esperabas esta invitación, ignorá este mensaje.', 'cead-acad' ),
		] );
	}

	/**
	 * Busca una invitación por ID. Devuelve la fila o null.
	 */
	public static function find_by_id( $id ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Busca una invitación por token plano. Devuelve la fila o null.
	 */
	public static function find_by_token( $token ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$hash  = cead_acad_hash_token( $token );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $hash ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Estado calculado: valid|used|expired|revoked|invalid.
	 * "used" = se alcanzó el tope de usos (max_uses).
	 */
	public static function status( $row ) {
		if ( ! $row ) {
			return 'invalid';
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return 'revoked';
		}
		$max  = isset( $row['max_uses'] ) ? max( 1, (int) $row['max_uses'] ) : 1;
		$used = isset( $row['used_count'] ) ? (int) $row['used_count'] : 0;
		// Compatibilidad: las invitaciones single-use viejas marcaban solo used_at.
		if ( $used < 1 && ! empty( $row['used_at'] ) ) {
			$used = 1;
		}
		if ( $used >= $max ) {
			return 'used';
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return 'expired';
		}
		return 'valid';
	}

	/** Usos restantes de una invitación (0 si agotada). */
	public static function uses_left( $row ) {
		$max  = isset( $row['max_uses'] ) ? max( 1, (int) $row['max_uses'] ) : 1;
		$used = isset( $row['used_count'] ) ? (int) $row['used_count'] : 0;
		if ( $used < 1 && ! empty( $row['used_at'] ) ) { $used = 1; }
		return max( 0, $max - $used );
	}

	/**
	 * Consume (reserva) un uso de forma atómica antes de crear el usuario, para no
	 * pasarse del tope aunque varios se registren a la vez. Devuelve true si quedaba
	 * cupo y se tomó, false si no. La trazabilidad de quién usó qué invitación queda
	 * en el user meta `_cead_acad_invited_via`.
	 */
	public static function consume( $invitation_id ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$now   = current_time( 'mysql', 1 );
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			    SET used_count = used_count + 1,
			        used_at = COALESCE(used_at, %s)
			  WHERE id = %d
			    AND revoked_at IS NULL
			    AND expires_at > %s
			    AND used_count < GREATEST(max_uses, 1)",
			$now,
			(int) $invitation_id,
			$now
		) );
		if ( $affected ) {
			Cead_Acad_Audit::log( 'invitation_used', [
				'entity_type' => 'invitation',
				'entity_id'   => (int) $invitation_id,
			] );
		}
		return (bool) $affected;
	}

	/** Devuelve un uso (p. ej. si falla la creación del usuario tras consumir). */
	public static function refund( $invitation_id ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET used_count = GREATEST(used_count - 1, 0) WHERE id = %d",
			(int) $invitation_id
		) );
	}

	public static function mark_used( $invitation_id, $user_id ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$wpdb->update(
			$table,
			[
				'used_at'         => current_time( 'mysql', 1 ),
				'used_by_user_id' => (int) $user_id,
			],
			[ 'id' => (int) $invitation_id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);

		Cead_Acad_Audit::log( 'invitation_used', [
			'user_id'     => (int) $user_id,
			'entity_type' => 'invitation',
			'entity_id'   => (int) $invitation_id,
		] );
	}

	public static function revoke( $invitation_id ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$wpdb->update(
			$table,
			[ 'revoked_at' => current_time( 'mysql', 1 ) ],
			[ 'id' => (int) $invitation_id ],
			[ '%s' ],
			[ '%d' ]
		);

		Cead_Acad_Audit::log( 'invitation_revoked', [
			'entity_type' => 'invitation',
			'entity_id'   => (int) $invitation_id,
		] );
	}

	public static function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = cead_acad_table( 'invitations' );
		$limit = (int) $limit;
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
	}

	protected static function sanitize_role( $role ) {
		$valid = array_keys( Cead_Acad_Capabilities::roles() );
		return in_array( $role, $valid, true ) ? $role : 'cead_acad_student';
	}

	/**
	 * URL final de registro con el token. Usa el link corto /i/<token>; la ruta
	 * larga /registro?t=<token> sigue funcionando para links viejos.
	 */
	public static function registration_url( $token ) {
		return home_url( '/i/' . rawurlencode( $token ) );
	}
}
