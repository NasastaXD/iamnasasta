<?php
defined( 'ABSPATH' ) || exit;

class Caaguazu_Database {

    private string $t_messages;
    private string $t_numbers;
    private string $t_logs;
    private string $t_session;
    private string $t_state;

    public function __construct() {
        global $wpdb;
        $this->t_messages = $wpdb->prefix . 'caag_bot_messages';
        $this->t_numbers  = $wpdb->prefix . 'caag_registered_numbers';
        $this->t_logs     = $wpdb->prefix . 'caag_conversation_logs';
        $this->t_session  = $wpdb->prefix . 'caag_session';
        $this->t_state    = $wpdb->prefix . 'caag_user_state';
    }

    // -----------------------------------------------------------------------
    // Mensajes del bot
    // -----------------------------------------------------------------------

    public function get_message( string $key ): string {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT content FROM `{$this->t_messages}` WHERE msg_key = %s", $key )
        );
        return $row ? (string) $row->content : '';
    }

    public function update_message( string $key, string $content ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $this->t_messages,
            [ 'content' => $content ],
            [ 'msg_key' => $key ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    public function get_all_messages(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM `{$this->t_messages}` ORDER BY msg_key ASC"
        ) ?: [];
    }

    // -----------------------------------------------------------------------
    // Números registrados
    // -----------------------------------------------------------------------

    public function get_number( string $phone ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$this->t_numbers}` WHERE phone = %s", $phone )
        ) ?: null;
    }

    /** INSERT con UPDATE en caso de duplicado de phone. */
    public function upsert_number( string $phone, array $data ): bool {
        global $wpdb;
        $existing = $this->get_number( $phone );
        if ( $existing ) {
            return (bool) $wpdb->update( $this->t_numbers, $data, [ 'phone' => $phone ] );
        }
        $data['phone'] = $phone;
        return (bool) $wpdb->insert( $this->t_numbers, $data );
    }

    public function set_opt_out( string $phone, bool $status ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $this->t_numbers,
            [ 'opt_out' => $status ? 1 : 0 ],
            [ 'phone'   => $phone ]
        );
    }

    public function update_last_seen( string $phone ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $this->t_numbers,
            [ 'last_seen' => current_time( 'mysql' ) ],
            [ 'phone'     => $phone ]
        );
    }

    public function get_numbers_by_role( string $role ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$this->t_numbers}` WHERE role = %s AND opt_out = 0",
                $role
            )
        ) ?: [];
    }

    public function get_all_numbers(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM `{$this->t_numbers}` ORDER BY registered_at DESC" ) ?: [];
    }

    /** Todos los números que NO se dieron de baja (para broadcasts a "todos"). */
    public function get_active_numbers(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM `{$this->t_numbers}` WHERE opt_out = 0 ORDER BY registered_at DESC" ) ?: [];
    }

    public function is_admin_phone( string $phone ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->t_numbers}` WHERE phone = %s AND role = 'admin' AND opt_out = 0",
                $phone
            )
        );
        return $count > 0;
    }

    public function is_opted_out( string $phone ): bool {
        global $wpdb;
        $row = $this->get_number( $phone );
        return $row && (int) $row->opt_out === 1;
    }

    // -----------------------------------------------------------------------
    // Suscripciones a categorías
    // Se guardan como string delimitado por comas (",1,5,9,") para poder
    // filtrar con LIKE de forma segura.
    // -----------------------------------------------------------------------

    public function get_subscriptions( string $phone ): array {
        $row = $this->get_number( $phone );
        if ( ! $row || empty( $row->subscriptions ) ) {
            return [];
        }
        $ids = array_filter( explode( ',', trim( (string) $row->subscriptions, ',' ) ), 'strlen' );
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }

    public function set_subscriptions( string $phone, array $cat_ids ): bool {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $cat_ids ), fn( $i ) => $i > 0 ) ) );
        sort( $ids );
        $value = empty( $ids ) ? '' : ',' . implode( ',', $ids ) . ',';
        return $this->upsert_number( $phone, [ 'subscriptions' => $value ] );
    }

    /** Activa/desactiva una categoría y devuelve la nueva lista de IDs. */
    public function toggle_subscription( string $phone, int $cat_id ): array {
        $subs = $this->get_subscriptions( $phone );
        if ( in_array( $cat_id, $subs, true ) ) {
            $subs = array_values( array_diff( $subs, [ $cat_id ] ) );
        } else {
            $subs[] = $cat_id;
        }
        $this->set_subscriptions( $phone, $subs );
        return $subs;
    }

    public function get_subscribers_by_category( int $cat_id ): array {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( ',' . $cat_id . ',' ) . '%';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$this->t_numbers}` WHERE opt_out = 0 AND subscriptions LIKE %s",
                $like
            )
        ) ?: [];
    }

    // -----------------------------------------------------------------------
    // Logs de conversación
    // -----------------------------------------------------------------------

    public function log_message( string $phone, string $direction, string $body, string $action = '' ): bool {
        global $wpdb;
        return (bool) $wpdb->insert(
            $this->t_logs,
            [
                'phone'            => $phone,
                'direction'        => $direction,
                'message_body'     => $body,
                'processed_action' => $action,
                'timestamp'        => current_time( 'mysql' ),
            ]
        );
    }

    public function get_logs( string $phone = '', int $limit = 50 ): array {
        global $wpdb;
        if ( $phone !== '' ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$this->t_logs}` WHERE phone = %s ORDER BY timestamp DESC LIMIT %d",
                    $phone, $limit
                )
            ) ?: [];
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$this->t_logs}` ORDER BY timestamp DESC LIMIT %d",
                $limit
            )
        ) ?: [];
    }

    public function cleanup_old_logs( int $days = 90 ): int {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$this->t_logs}` WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        return (int) $wpdb->rows_affected;
    }

    // -----------------------------------------------------------------------
    // Estadísticas (solo lectura)
    // -----------------------------------------------------------------------

    /** Cuenta mensajes. $direction: '' = todos, 'in', 'out'. $days: 0 = sin límite. */
    public function count_messages( string $direction = '', int $days = 0 ): int {
        global $wpdb;
        $where  = [];
        $params = [];
        if ( $direction !== '' ) {
            $where[]  = 'direction = %s';
            $params[] = $direction;
        }
        if ( $days > 0 ) {
            $where[]  = 'timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $params[] = $days;
        }
        $sql = "SELECT COUNT(*) FROM `{$this->t_logs}`";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        return (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_var( $sql ) );
    }

    public function count_unique_users( int $days = 0 ): int {
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT phone) FROM `{$this->t_logs}`";
        if ( $days > 0 ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare( $sql . ' WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days )
            );
        }
        return (int) $wpdb->get_var( $sql );
    }

    /** Devuelve [ 'admin' => n, 'reader' => n ] de números no dados de baja. */
    public function count_numbers_by_role(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT role, COUNT(*) AS total FROM `{$this->t_numbers}` WHERE opt_out = 0 GROUP BY role"
        ) ?: [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (string) $r->role ] = (int) $r->total;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Sesión del bridge
    // -----------------------------------------------------------------------

    public function get_session(): ?object {
        global $wpdb;
        return $wpdb->get_row( "SELECT * FROM `{$this->t_session}` WHERE id = 1" ) ?: null;
    }

    public function update_session( array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( $this->t_session, $data, [ 'id' => 1 ] );
    }

    public function get_bridge_url(): string {
        return (string) ( $this->get_session()->bridge_url ?? '' );
    }

    public function get_shared_token(): string {
        return (string) ( $this->get_session()->shared_token ?? '' );
    }

    public function get_connection_status(): string {
        return (string) ( $this->get_session()->connection_status ?? 'disconnected' );
    }

    public function update_heartbeat(): bool {
        return $this->update_session( [ 'last_heartbeat' => current_time( 'mysql' ) ] );
    }

    // -----------------------------------------------------------------------
    // Estado de la máquina de estados por usuario
    // -----------------------------------------------------------------------

    /** Retorna el estado actual; si expiró el timeout de 10 min, resetea a 'idle'. */
    public function get_user_state( string $phone ): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$this->t_state}` WHERE phone = %s", $phone )
        );

        if ( ! $row ) {
            return [ 'state' => 'idle', 'context' => [], 'updated_at' => '' ];
        }

        // Timeout de 10 minutos
        if ( strtotime( $row->updated_at ) < ( time() - 600 ) ) {
            $this->reset_user_state( $phone );
            return [ 'state' => 'idle', 'context' => [], 'updated_at' => current_time( 'mysql' ) ];
        }

        $context = [];
        if ( $row->context_data ) {
            $decoded = json_decode( $row->context_data, true );
            if ( is_array( $decoded ) ) {
                $context = $decoded;
            }
        }

        return [
            'state'      => (string) $row->current_state,
            'context'    => $context,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    public function set_user_state( string $phone, string $state, array $context = [] ): bool {
        global $wpdb;
        $json = wp_json_encode( $context );
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$this->t_state}` WHERE phone = %s", $phone )
        );

        if ( $exists ) {
            return (bool) $wpdb->update(
                $this->t_state,
                [ 'current_state' => $state, 'context_data' => $json ],
                [ 'phone' => $phone ]
            );
        }

        return (bool) $wpdb->insert(
            $this->t_state,
            [ 'phone' => $phone, 'current_state' => $state, 'context_data' => $json ]
        );
    }

    public function reset_user_state( string $phone ): bool {
        return $this->set_user_state( $phone, 'idle', [] );
    }

    public function cleanup_stale_states( int $minutes = 60 ): int {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$this->t_state}` WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
                $minutes
            )
        );
        return (int) $wpdb->rows_affected;
    }
}
