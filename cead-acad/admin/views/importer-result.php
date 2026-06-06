<?php
/**
 * Paso final: resultado del commit.
 * Variables esperadas: $job, $importer, $report
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php printf( esc_html__( 'Importación #%1$d — completada', 'cead-acad' ), (int) $job['id'] ); ?></h1>
	<div class="notice notice-success">
		<p>
			<?php
			/* translators: %1$d filas ok, %2$s tipo */
			printf(
				esc_html__( '%1$d filas importadas (%2$s).', 'cead-acad' ),
				(int) $job['rows_ok'],
				esc_html( $importer->label() )
			);
			?>
		</p>
	</div>

	<p>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-importers' ) ); ?>">← <?php esc_html_e( 'Volver al hub', 'cead-acad' ); ?></a>
		<?php if ( ! empty( ( $report['rows'] ?? [] ) ) ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_import_errors_csv&job=' . (int) $job['id'] ), 'cead_acad_import_errors_csv' ) ); ?>">
				<?php esc_html_e( 'Descargar reporte (CSV)', 'cead-acad' ); ?>
			</a>
		<?php endif; ?>
	</p>
</div>
