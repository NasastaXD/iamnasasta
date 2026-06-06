<?php
/**
 * /panel/buzon: gestión de reportes y sugerencias (responder, aceptar, negar,
 * marcar "no es un reporte", papelera). Reemplaza las bandejas del bot.
 *
 * Visibilidad: Dirección/Secretaría (cead_acad_manage_reports) ven todo;
 * el Consejo (cead_acad_manage_suggestions) solo ve sugerencias de su categoría.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$can_reports     = current_user_can( 'cead_acad_manage_reports' );
$can_suggestions = current_user_can( 'cead_acad_manage_suggestions' );
if ( ! $can_reports && ! $can_suggestions ) {
	wp_die( esc_html__( 'No tenés permiso para ver el buzón.', 'cead-acad' ), 403 );
}

// El Consejo (sin manage_reports) solo ve la categoría "consejo".
$council_only = $can_suggestions && ! $can_reports;

$store = new Cead_Acad_WA_Store();

if ( isset( $_POST['cead_acad_buzon'] ) && check_admin_referer( 'cead_acad_buzon' ) ) {
	$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
	$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
	$id   = (int) ( $_POST['id'] ?? 0 );
	$resp = sanitize_textarea_field( wp_unslash( $_POST['response'] ?? '' ) );

	if ( $kind === 'report' && $can_reports && $id ) {
		$r = $store->get_report( $id );
		if ( $r ) {
			switch ( $do ) {
				case 'respond':
					$store->respond_report( $id, $resp, 'in_review' );
					if ( $r->type === 'confidential' && $resp !== '' && ! empty( $r->phone ) ) {
						Cead_Acad_WA_Module::notify( $r->phone, "💬 Respuesta a tu reporte {$r->ref_code}:\n\n{$resp}" );
					}
					break;
				case 'accept':
					$store->respond_report( $id, $resp, 'accepted' );
					if ( $r->type === 'confidential' && ! empty( $r->phone ) ) {
						Cead_Acad_WA_Module::notify( $r->phone, "✅ Tu reporte {$r->ref_code} fue recibido y aceptado." . ( $resp !== '' ? "\n\n{$resp}" : '' ) );
					}
					break;
				case 'not_report': $store->mark_not_report( $id ); break;
				case 'trash':      $store->soft_delete_report( $id ); break;
				case 'restore':    $store->restore_report( $id ); break;
				case 'purge':      $store->purge_report( $id ); break;
			}
		}
	} elseif ( $kind === 'suggestion' && ( $can_reports || $can_suggestions ) && $id ) {
		$s = $store->get_suggestion( $id );
		// El Consejo solo puede actuar sobre su categoría.
		if ( $s && ( ! $council_only || $s->category === 'consejo' ) ) {
			switch ( $do ) {
				case 'respond':
					$store->respond_suggestion( $id, $resp, 'in_review' );
					if ( $resp !== '' && ! empty( $s->phone ) ) {
						Cead_Acad_WA_Module::notify( $s->phone, "💬 Respuesta a tu sugerencia:\n\n{$resp}" );
					}
					break;
				case 'accept':
					$store->respond_suggestion( $id, $resp, 'accepted' );
					if ( ! empty( $s->phone ) ) {
						Cead_Acad_WA_Module::notify( $s->phone, "✅ Tu sugerencia fue aceptada." . ( $resp !== '' ? "\n\n{$resp}" : '' ) );
					}
					break;
				case 'deny':
					$store->respond_suggestion( $id, $resp, 'denied' );
					if ( ! empty( $s->phone ) ) {
						Cead_Acad_WA_Module::notify( $s->phone, "❌ Tu sugerencia fue rechazada." . ( $resp !== '' ? "\n\n{$resp}" : '' ) );
					}
					break;
				case 'trash':   $store->soft_delete_suggestion( $id ); break;
				case 'restore': $store->restore_suggestion( $id ); break;
				case 'purge':   $store->purge_suggestion( $id ); break;
			}
		}
	}

	// Patrón POST→Redirect→GET: volvemos al MISMO ítem (ancla) para no recargar
	// saltando al tope de la página. Mucho más rápido y cómodo en móvil.
	$bz_anchor = in_array( $do, [ 'trash', 'purge' ], true ) ? 'sec-papelera' : ( ( $kind && $id ) ? "b-{$kind}-{$id}" : 'buzon-top' );
	wp_safe_redirect( cead_acad_url( 'panel/buzon' ) . '?bz=' . rawurlencode( $do ) . '#' . $bz_anchor );
	exit;
}

// Aviso (desde el redirect).
$notice = '';
if ( isset( $_GET['bz'] ) ) {
	$bzmap = [
		'respond'    => __( 'Respuesta guardada.', 'cead-acad' ),
		'accept'     => __( 'Aceptado.', 'cead-acad' ),
		'deny'       => __( 'Rechazado.', 'cead-acad' ),
		'not_report' => __( 'Marcado como "No es un reporte".', 'cead-acad' ),
		'trash'      => __( 'Movido a la papelera.', 'cead-acad' ),
		'restore'    => __( 'Restaurado.', 'cead-acad' ),
		'purge'      => __( 'Borrado definitivamente.', 'cead-acad' ),
	];
	$notice = $bzmap[ sanitize_key( wp_unslash( $_GET['bz'] ) ) ] ?? '';
}

// Datos a mostrar.
$reports     = $can_reports ? $store->reports_by_status( '', 100 ) : [];
$suggestions = $council_only ? $store->suggestions_by_category( 'consejo', 100 ) : $store->suggestions_by_category( '', 100 );
$trash_reports = $can_reports ? $store->reports_trashed( 50 ) : [];
$trash_sugg    = $can_reports ? $store->suggestions_trashed( 50 ) : [];

$status_label = static function ( $s ) {
	$map = [ 'new' => '🆕 Nuevo', 'in_review' => '🔎 En revisión', 'accepted' => '✅ Aceptado', 'resolved' => '✅ Resuelto', 'denied' => '❌ Rechazado', 'not_report' => '🃏 No es un reporte' ];
	return $map[ $s ] ?? $s;
};
$cat_label = static function ( $c ) {
	$map = [ 'consejo' => __( 'Consejo', 'cead-acad' ), 'direccion' => __( 'Dirección', 'cead-acad' ), 'administracion' => __( 'Administración', 'cead-acad' ) ];
	return $map[ $c ] ?? __( 'Administración', 'cead-acad' );
};

$page_title = __( 'Buzón', 'cead-acad' );

$body = function () use ( $reports, $suggestions, $trash_reports, $trash_sugg, $can_reports, $council_only, $notice, $status_label, $cat_label ) {
	$nonce = wp_create_nonce( 'cead_acad_buzon' );
	$self  = esc_url( cead_acad_url( 'panel/buzon' ) );
	?>
	<section class="cead-acad-panel-section" id="buzon-top">
		<span class="cead-acad-eyebrow"><?php esc_html_e( 'Coordinación', 'cead-acad' ); ?></span>
		<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Buzón', 'cead-acad' ); ?></h2>
		<p class="cead-acad-panel-sub"><?php esc_html_e( 'Reportes y sugerencias del alumnado. Las respuestas se avisan por WhatsApp si dejaron número.', 'cead-acad' ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="cead-acad-msg cead-acad-msg--ok" style="margin:1rem 0"><?php echo esc_html( $notice ); ?></div>
		<?php endif; ?>

		<h3 class="cead-acad-section-h" style="margin-top:1.5rem"><?php esc_html_e( 'Sugerencias', 'cead-acad' ); ?></h3>
		<?php if ( ! $suggestions ) : ?>
			<p class="cead-acad-panel-sub"><?php esc_html_e( 'No hay sugerencias.', 'cead-acad' ); ?></p>
		<?php else : foreach ( $suggestions as $s ) : ?>
			<div class="cead-acad-card cead-acad-buzon-item" id="b-suggestion-<?php echo (int) $s->id; ?>" style="margin-bottom:1rem">
				<p class="cead-acad-buzon-meta">
					<strong><?php echo esc_html( $cat_label( $s->category ) ); ?></strong>
					· <?php echo esc_html( $status_label( $s->status ) ); ?>
					· <?php echo esc_html( $s->created_at ); ?>
					<?php if ( ! empty( $s->phone ) ) : ?> · +<?php echo esc_html( $s->phone ); ?><?php endif; ?>
				</p>
				<blockquote class="cead-acad-buzon-quote" style="border-left-color:#2563eb"><?php echo nl2br( esc_html( (string) $s->body ) ); ?></blockquote>
				<?php if ( trim( (string) $s->response ) !== '' ) : ?>
					<p><strong><?php esc_html_e( 'Respuesta:', 'cead-acad' ); ?></strong><br><?php echo nl2br( esc_html( (string) $s->response ) ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo $self; ?>" class="cead-acad-buzon-reply">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="cead_acad_buzon" value="1">
					<input type="hidden" name="kind" value="suggestion">
					<input type="hidden" name="id" value="<?php echo (int) $s->id; ?>">
					<textarea name="response" rows="3" class="cead-acad-input" placeholder="<?php esc_attr_e( 'Respuesta (opcional)…', 'cead-acad' ); ?>"></textarea>
					<div class="cead-acad-buzon-actions">
						<button class="cead-acad-btn" name="do" value="respond"><?php esc_html_e( 'Responder', 'cead-acad' ); ?></button>
						<button class="cead-acad-btn cead-acad-btn--primary" name="do" value="accept"><?php esc_html_e( 'Aceptar', 'cead-acad' ); ?></button>
						<button class="cead-acad-btn cead-acad-btn--ghost" name="do" value="deny"><?php esc_html_e( 'Negar', 'cead-acad' ); ?></button>
						<button class="cead-acad-btn cead-acad-btn--ghost" name="do" value="trash" onclick="return confirm('<?php echo esc_js( __( '¿Mover a la papelera?', 'cead-acad' ) ); ?>');"><?php esc_html_e( 'Borrar', 'cead-acad' ); ?></button>
					</div>
				</form>
			</div>
		<?php endforeach; endif; ?>

		<?php if ( $can_reports ) : ?>
			<h3 class="cead-acad-section-h" style="margin-top:2rem"><?php esc_html_e( 'Reportes', 'cead-acad' ); ?></h3>
			<?php if ( ! $reports ) : ?>
				<p class="cead-acad-panel-sub"><?php esc_html_e( 'No hay reportes.', 'cead-acad' ); ?></p>
			<?php else : foreach ( $reports as $r ) :
				$decoded = Cead_Acad_WA_Crypto::decrypt( (string) $r->body_enc );
			?>
				<div class="cead-acad-card cead-acad-buzon-item" id="b-report-<?php echo (int) $r->id; ?>" style="margin-bottom:1rem">
					<p class="cead-acad-buzon-meta">
						<strong><?php echo esc_html( $r->ref_code ); ?></strong>
						· <?php echo esc_html( $r->type === 'confidential' ? __( 'Confidencial', 'cead-acad' ) : __( 'Anónimo', 'cead-acad' ) ); ?>
						· <?php echo esc_html( $status_label( $r->status ) ); ?>
						· <?php echo esc_html( $r->category ?: '—' ); ?>
						· <?php echo esc_html( $r->created_at ); ?>
						<?php if ( $r->type === 'confidential' && ! empty( $r->phone ) ) : ?> · +<?php echo esc_html( $r->phone ); ?><?php endif; ?>
					</p>
					<blockquote class="cead-acad-buzon-quote" style="border-left-color:#E93B3C"><?php echo nl2br( esc_html( $decoded !== null ? $decoded : '⚠️ No se pudo descifrar.' ) ); ?></blockquote>
					<?php if ( trim( (string) $r->note ) !== '' ) : ?>
						<p><strong><?php esc_html_e( 'Notas:', 'cead-acad' ); ?></strong><br><?php echo nl2br( esc_html( (string) $r->note ) ); ?></p>
					<?php endif; ?>
					<?php if ( trim( (string) $r->response ) !== '' ) : ?>
						<p><strong><?php esc_html_e( 'Respuesta:', 'cead-acad' ); ?></strong><br><?php echo nl2br( esc_html( (string) $r->response ) ); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo $self; ?>" class="cead-acad-buzon-reply">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
						<input type="hidden" name="cead_acad_buzon" value="1">
						<input type="hidden" name="kind" value="report">
						<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
						<textarea name="response" rows="3" class="cead-acad-input" placeholder="<?php echo $r->type === 'confidential' ? esc_attr__( 'Respuesta (se envía por WhatsApp)…', 'cead-acad' ) : esc_attr__( 'Nota interna (anónimo: no se puede responder)…', 'cead-acad' ); ?>"></textarea>
						<div class="cead-acad-buzon-actions">
							<button class="cead-acad-btn" name="do" value="respond"><?php esc_html_e( 'Responder', 'cead-acad' ); ?></button>
							<button class="cead-acad-btn cead-acad-btn--primary" name="do" value="accept"><?php esc_html_e( 'Aceptar', 'cead-acad' ); ?></button>
							<button class="cead-acad-btn cead-acad-btn--ghost" name="do" value="not_report"><?php esc_html_e( 'No es un reporte', 'cead-acad' ); ?></button>
							<button class="cead-acad-btn cead-acad-btn--ghost" name="do" value="trash" onclick="return confirm('<?php echo esc_js( __( '¿Mover a la papelera?', 'cead-acad' ) ); ?>');"><?php esc_html_e( 'Borrar', 'cead-acad' ); ?></button>
						</div>
					</form>
				</div>
			<?php endforeach; endif; ?>

			<?php if ( $trash_reports || $trash_sugg ) : ?>
				<h3 class="cead-acad-section-h" style="margin-top:2rem" id="sec-papelera"><?php esc_html_e( 'Papelera', 'cead-acad' ); ?></h3>
				<p class="cead-acad-panel-sub"><?php esc_html_e( 'Borrados temporalmente. Podés restaurarlos o borrarlos definitivamente.', 'cead-acad' ); ?></p>
				<?php
				$trash = [];
				foreach ( $trash_reports as $t ) { $trash[] = [ 'kind' => 'report', 'label' => $t->ref_code . ' (reporte)', 'id' => (int) $t->id ]; }
				foreach ( $trash_sugg as $t ) { $trash[] = [ 'kind' => 'suggestion', 'label' => mb_substr( (string) $t->body, 0, 40 ) . '… (sugerencia)', 'id' => (int) $t->id ]; }
				foreach ( $trash as $t ) : ?>
					<div class="cead-acad-card" style="margin-bottom:.6rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap">
						<span><?php echo esc_html( $t['label'] ); ?></span>
						<form method="post" action="<?php echo $self; ?>" style="display:flex;gap:.5rem">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
							<input type="hidden" name="cead_acad_buzon" value="1">
							<input type="hidden" name="kind" value="<?php echo esc_attr( $t['kind'] ); ?>">
							<input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
							<button class="cead-acad-btn cead-acad-btn--ghost" name="do" value="restore"><?php esc_html_e( 'Restaurar', 'cead-acad' ); ?></button>
							<button class="cead-acad-btn cead-acad-btn--ghost" name="do" value="purge" onclick="return confirm('<?php echo esc_js( __( '¿Borrar para siempre?', 'cead-acad' ) ); ?>');"><?php esc_html_e( 'Borrar definitivo', 'cead-acad' ); ?></button>
						</form>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
