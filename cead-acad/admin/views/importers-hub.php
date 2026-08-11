<?php
/**
 * Hub principal: paso 1 (subir archivo) + lista de jobs recientes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$importers = Cead_Acad_Importer_Admin::importers();
$jobs      = Cead_Acad_Importer_Job::recent( 20 );
?>
<div class="wrap cead-acad-admin-wrap">
	<h1><?php esc_html_e( 'Importadores', 'cead-acad' ); ?></h1>
	<?php $xlsx_ok = Cead_Acad_Importer_Xlsx_Reader::available(); ?>
	<p class="description"><?php esc_html_e( 'Subí un archivo CSV o Excel (.xlsx) y, guiado paso a paso, lo mapeás, validás e importás. Descargá una plantilla para ver el formato esperado.', 'cead-acad' ); ?></p>

	<h2 class="title"><?php esc_html_e( 'Nuevo trabajo', 'cead-acad' ); ?></h2>
	<?php
	/*
	 * Acá había un bloqueo por `wp_is_mobile()`: desde el teléfono no se
	 * mostraba el formulario y solo se veía un cartel diciendo que usaras una
	 * PC. Era una suposición sobre el dispositivo, no una limitación real — y
	 * `wp_is_mobile()` decide por user-agent, así que también dejaba afuera
	 * tablets y navegadores de escritorio en modo responsive.
	 *
	 * El formulario se muestra siempre. Lo que sí hacía falta era que la tabla
	 * de mapeo se pueda usar en pantalla chica, y eso se resuelve con scroll
	 * horizontal (ver `importer-mapping.php`), no prohibiendo el acceso.
	 */
	?>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cead_acad_import_upload' ); ?>
		<input type="hidden" name="action" value="cead_acad_import_upload">
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="import_type"><?php esc_html_e( 'Tipo de importación', 'cead-acad' ); ?></label></th>
				<td>
					<select name="import_type" id="import_type">
						<?php foreach ( $importers as $type => $impl ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $impl->label() ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( '¿Necesitás un modelo de archivo?', 'cead-acad' ); ?>
						<?php foreach ( $importers as $type => $impl ) :
							$url = wp_nonce_url( admin_url( 'admin-post.php?action=cead_acad_import_download_template&type=' . $type ), 'cead_acad_import_template' );
						?>
							<a href="<?php echo esc_url( $url ); ?>"><?php /* translators: %s: nombre del importador */ printf( esc_html__( 'plantilla %s', 'cead-acad' ), esc_html( $impl->label() ) ); ?></a>
						<?php endforeach; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="csv_file"><?php esc_html_e( 'Archivo', 'cead-acad' ); ?></label></th>
				<td>
					<input type="file" name="csv_file" id="csv_file" required style="display:block;max-width:100%;font-size:16px;line-height:1.7;padding:6px 0">
					<p class="description">
						<?php
						echo $xlsx_ok
							? esc_html__( 'Máx 10MB. Admite .csv y .xlsx. Para columnas de fecha en XLSX, escribilas como texto (ej. 2026-12-10 08:00).', 'cead-acad' )
							: esc_html__( 'Máx 10MB. Admite .csv (en este servidor no está disponible la lectura de .xlsx).', 'cead-acad' );
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Subir y continuar', 'cead-acad' ) ); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Importaciones recientes', 'cead-acad' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Tipo', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Archivo', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'OK', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Errores', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'cead-acad' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'cead-acad' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $jobs ) : ?>
			<tr><td colspan="8"><em><?php esc_html_e( 'Sin trabajos previos.', 'cead-acad' ); ?></em></td></tr>
		<?php else : foreach ( $jobs as $j ) : ?>
			<tr>
				<td><?php echo (int) $j['id']; ?></td>
				<td><?php echo esc_html( ucfirst( $j['type'] ) ); ?></td>
				<td><?php echo esc_html( $j['original_filename'] ); ?></td>
				<td><?php echo esc_html( $j['status'] ); ?></td>
				<td><?php echo (int) $j['rows_ok']; ?></td>
				<td><?php echo (int) $j['rows_failed']; ?></td>
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $j['created_at'] ) ); ?></td>
				<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=cead-acad-importers&job=' . (int) $j['id'] ) ); ?>"><?php esc_html_e( 'Abrir', 'cead-acad' ); ?></a></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
