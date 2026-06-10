<?php
/**
 * Paso 3: revisión + commit.
 * Variables esperadas: $job, $importer, $headers, $rows, $mapping, $report, $fields
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$summary = $report['summary'] ?? [ 'ok' => 0, 'failed' => 0, 'total' => 0 ];
$issues  = (array) ( $report['rows'] ?? [] );
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php
		printf( esc_html__( 'Importación #%1$d — %2$s', 'cead-acad' ), (int) $job['id'], esc_html( $importer->label() ) );
	?></h1>
	<p><strong><?php esc_html_e( 'Paso 3 de 3: validar y confirmar', 'cead-acad' ); ?></strong></p>

	<div class="notice <?php echo $summary['failed'] ? 'notice-warning' : 'notice-success'; ?>">
		<p>
			<strong><?php echo (int) $summary['ok']; ?></strong> <?php esc_html_e( 'filas válidas', 'cead-acad' ); ?> ·
			<strong><?php echo (int) $summary['failed']; ?></strong> <?php esc_html_e( 'con error', 'cead-acad' ); ?> ·
			<?php esc_html_e( 'Total leídas:', 'cead-acad' ); ?> <strong><?php echo (int) $summary['total']; ?></strong>
		</p>
	</div>

	<?php if ( $issues ) :
		$errors = array_filter( $issues, fn( $r ) => $r['level'] === 'error' );
		$warns  = array_filter( $issues, fn( $r ) => $r['level'] === 'warn' );
	?>
		<?php if ( $errors ) : ?>
			<h2><?php esc_html_e( 'Errores', 'cead-acad' ); ?> (<?php echo count( $errors ); ?>)</h2>
			<table class="wp-list-table widefat striped">
				<thead><tr><th><?php esc_html_e( 'Fila', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Mensaje', 'cead-acad' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $errors, 0, 50 ) as $e ) : ?>
						<tr><td><?php echo (int) $e['n']; ?></td><td><?php echo esc_html( $e['message'] ); ?></td></tr>
					<?php endforeach; ?>
					<?php if ( count( $errors ) > 50 ) : ?>
						<tr><td colspan="2"><em><?php /* translators: %d: cantidad de errores adicionales no listados */ printf( esc_html__( '... y %d más. Descargá el CSV de errores para ver todos.', 'cead-acad' ), count( $errors ) - 50 ); ?></em></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $warns ) : ?>
			<h2><?php esc_html_e( 'Advertencias', 'cead-acad' ); ?> (<?php echo count( $warns ); ?>)</h2>
			<table class="wp-list-table widefat striped">
				<thead><tr><th><?php esc_html_e( 'Fila', 'cead-acad' ); ?></th><th><?php esc_html_e( 'Mensaje', 'cead-acad' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $warns, 0, 20 ) as $w ) : ?>
						<tr><td><?php echo (int) $w['n']; ?></td><td><?php echo esc_html( $w['message'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_import_errors_csv&job=' . (int) $job['id'] ), 'cead_acad_import_errors_csv' ) ); ?>">
				<?php esc_html_e( 'Descargar reporte completo (CSV)', 'cead-acad' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<p><em><?php esc_html_e( 'Las filas con error se omiten. El commit es idempotente: si ya existe el registro, se actualiza.', 'cead-acad' ); ?></em></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '¿Confirmar la importación de los registros válidos?', 'cead-acad' ) ); ?>');">
		<?php wp_nonce_field( 'cead_acad_import_commit' ); ?>
		<input type="hidden" name="action" value="cead_acad_import_commit">
		<input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ); ?>"><?php esc_html_e( 'Cancelar', 'cead-acad' ); ?></a>
			<button class="button button-primary"><?php
				/* translators: %d: cantidad de filas a importar */
				printf( esc_html__( 'Confirmar e importar %d filas', 'cead-acad' ), (int) $summary['ok'] );
			?></button>
		</p>
	</form>
</div>
