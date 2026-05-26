<?php
/**
 * Paso 2: mapear columnas del archivo a fields del importador.
 * Variables esperadas: $job, $importer, $headers, $preview_rows, $suggested, $fields
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php
		/* translators: %1$d: id job, %2$s: tipo */
		printf( esc_html__( 'Importación #%1$d — %2$s', 'cead-acad' ), (int) $job['id'], esc_html( $importer->label() ) );
	?></h1>
	<p><strong><?php esc_html_e( 'Paso 2 de 3: mapear columnas', 'cead-acad' ); ?></strong></p>
	<p class="description"><?php esc_html_e( 'Asigná cada columna de tu archivo a un campo del sistema. Las columnas que dejes en "ignorar" no se importan.', 'cead-acad' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cead_acad_import_map' ); ?>
		<input type="hidden" name="action" value="cead_acad_import_map">
		<input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:35%"><?php esc_html_e( 'Columna del archivo', 'cead-acad' ); ?></th>
					<th style="width:30%"><?php esc_html_e( 'Mapear a', 'cead-acad' ); ?></th>
					<th><?php esc_html_e( 'Vista previa', 'cead-acad' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $headers as $i => $h ) :
					$current = $suggested[ (string) $i ] ?? '';
				?>
				<tr>
					<td><code><?php echo esc_html( $h ); ?></code></td>
					<td>
						<select name="mapping[<?php echo (int) $i; ?>]">
							<option value=""><?php esc_html_e( '— ignorar —', 'cead-acad' ); ?></option>
							<?php foreach ( $fields as $key => $cfg ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
									<?php echo esc_html( $cfg['label'] ); ?><?php if ( ! empty( $cfg['required'] ) ) : ?> *<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<?php
						$samples = [];
						foreach ( $preview_rows as $row ) {
							$samples[] = (string) ( $row[ $i ] ?? '' );
						}
						$samples = array_filter( $samples, fn( $s ) => $s !== '' );
						echo esc_html( implode( ' · ', array_slice( $samples, 0, 3 ) ) );
						?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button( __( 'Continuar a validación', 'cead-acad' ) ); ?>
	</form>
</div>
