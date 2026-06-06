<?php
/**
 * Clase base abstracta para importadores específicos.
 * Cada concreto define:
 *   - type (slug)
 *   - label (display)
 *   - fields() : campos destino con label + required
 *   - validate_row( $mapped_row ) : devuelve [ 'level'=>'ok|warn|error', 'message'=>?, 'data'=>? ]
 *   - commit_row( $mapped_row, $job_id ) : ejecuta el insert/update
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Cead_Acad_Importer_Base {

	abstract public function type();
	abstract public function label();

	/** @return array<string,array{label:string,required:bool}> */
	abstract public function fields();

	/**
	 * @param array<string,string> $row Row mapeada por field key.
	 * @return array{level:string,message:?string,data:?array}
	 */
	abstract public function validate_row( $row );

	/**
	 * @param array<string,string> $row Row mapeada por field key.
	 * @param int $job_id
	 * @return true|WP_Error
	 */
	abstract public function commit_row( $row, $job_id );

	/**
	 * Sugerencia de mapping basada en similitud de headers ↔ field keys.
	 *
	 * @return array<string,string> headers[idx] => field_key
	 */
	public function suggest_mapping( $headers ) {
		$fields = array_keys( $this->fields() );
		$out = [];
		foreach ( $headers as $i => $h ) {
			$best = '';
			$best_score = 0;
			$hn = mb_strtolower( $this->normalize( $h ) );
			foreach ( $fields as $f ) {
				similar_text( $hn, $f, $score );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best = $f;
				}
			}
			if ( $best_score >= 65 ) {
				$out[ (string) $i ] = $best;
			}
		}
		return $out;
	}

	protected function normalize( $s ) {
		$s = remove_accents( (string) $s );
		$s = preg_replace( '/[^a-z0-9_]+/i', '_', $s );
		return trim( strtolower( $s ), '_' );
	}

	/**
	 * Mapea una fila cruda usando el mapping (idx_header => field_key).
	 *
	 * @return array<string,string>
	 */
	public function apply_mapping( $row, $mapping ) {
		$out = [];
		foreach ( $mapping as $idx => $field ) {
			if ( $field === '' ) { continue; }
			$out[ $field ] = (string) ( $row[ (int) $idx ] ?? '' );
		}
		return $out;
	}

	/**
	 * Procesa todas las filas validando. Devuelve el reporte y los counts.
	 */
	public function validate_all( $rows, $mapping ) {
		$report = [ 'rows' => [], 'summary' => [] ];
		$ok = 0;
		$failed = 0;
		foreach ( $rows as $i => $row ) {
			$mapped = $this->apply_mapping( $row, $mapping );
			$res    = $this->validate_row( $mapped );
			if ( $res['level'] === 'error' ) {
				$failed++;
				$report['rows'][] = [
					'n'       => $i + 2, // +1 por header, +1 base 1
					'level'   => 'error',
					'message' => $res['message'] ?? '',
				];
			} elseif ( $res['level'] === 'warn' ) {
				$ok++;
				$report['rows'][] = [
					'n'       => $i + 2,
					'level'   => 'warn',
					'message' => $res['message'] ?? '',
				];
			} else {
				$ok++;
			}
		}
		$report['summary'] = [ 'ok' => $ok, 'failed' => $failed, 'total' => count( $rows ) ];
		return $report;
	}

	/**
	 * Commit. Salta filas con error.
	 *
	 * @return int rows_ok efectivamente comiteadas
	 */
	public function commit_all( $rows, $mapping, $job_id ) {
		$ok = 0;
		foreach ( $rows as $row ) {
			$mapped = $this->apply_mapping( $row, $mapping );
			$res    = $this->validate_row( $mapped );
			if ( $res['level'] === 'error' ) { continue; }
			$r = $this->commit_row( $mapped, $job_id );
			if ( ! is_wp_error( $r ) ) {
				$ok++;
			}
		}
		return $ok;
	}

	/**
	 * Asegura que el directorio de uploads existe y tiene .htaccess.
	 */
	public static function ensure_upload_dir( $base ) {
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "deny from all\n" );
		}
		$index = $base . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
	}
}
