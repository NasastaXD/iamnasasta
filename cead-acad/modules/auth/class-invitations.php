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
	 * Crea N invitaciones. Devuelve array de tokens en plano (la única oportunidad de verlos).
	 *
	 * @param array{role:string,course_id:?int,email:?string,expires_days:int,count:int} $args
	 * @return array<int,string> tokens en plano
	 */
	public static function create( $args ) {
		global $wpdb;
		$args = wp_parse_args( $args, [
			'role'         => 'cead_acad_student',
			'course_id'    => null,
			'email'        => null,
			'expires_days' => 14,
			'count'        => 1,
		] );

		$role  = self::sanitize_role( $args['role'] );
		$count = max( 1, min( 200, (int) $args['count'] ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( (int) $args['expires_days'] * DAY_IN_SECONDS ) );
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
			// de bajo riesgo (single-use, expiran, su propósito es compartirse).
			$wpdb->insert(
				$table,
				[
					'token_hash' => cead_acad_hash_token( $token ),
					'email'      => $email,
					'role'       => $role,
					'course_id'  => $args['course_id'] ? (int) $args['course_id'] : null,
					'invited_by' => $user_id,
					'expires_at' => $expires,
					'created_at' => $now,
					'metadata'   => wp_json_encode( [ 'token' => $token ] ),
				],
				[ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
			);
			$invitation_id = (int) $wpdb->insert_id;

			// Enviar email si se proveyó una dirección.
			if ( $email ) {
				self::send_email( $email, $token, $role );
			}
		}

		Cead_Acad_Audit::log( 'invitation_created', [
			'entity_type' => 'invitation',
			'payload'     => array_filter( [
				'count'     => $count,
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
		$message  = sprintf( __( "Te invitamos a sumarte a %s.\n\n", 'cead-acad' ), $site );
		if ( $role_label ) {
			/* translators: %s: rol asignado al invitado */
			$message .= sprintf( __( "Rol asignado: %s\n\n", 'cead-acad' ), $role_label );
		}
		$message .= __( "Para crear tu cuenta, entrá a este link:\n", 'cead-acad' );
		$message .= $url . "\n\n";
		$message .= __( "El link es de un solo uso y vence pronto.\n\n", 'cead-acad' );
		/* translators: %s: nombre del sitio (firma del email) */
		$message .= sprintf( __( '— %s', 'cead-acad' ), $site );

		return wp_mail( $email, $subject, $message );
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
	 */
	public static function status( $row ) {
		if ( ! $row ) {
			return 'invalid';
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return 'revoked';
		}
		if ( ! empty( $row['used_at'] ) ) {
			return 'used';
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return 'expired';
		}
		return 'valid';
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
