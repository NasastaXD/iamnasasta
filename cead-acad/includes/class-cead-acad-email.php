<?php
/**
 * Emails HTML con branding institucional. Plantilla única (header con color
 * de marca, cuerpo, botón CTA y footer) con fallback de texto plano implícito:
 * el contenido es simple y los clientes que no rendericen HTML muestran el link.
 *
 * Colores: toma los theme mods del tema cead si están, con los mismos
 * defaults institucionales.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Email {

	/**
	 * Envía un email con la plantilla institucional.
	 *
	 * @param string $to      Destinatario.
	 * @param string $subject Asunto.
	 * @param array  $args    {
	 *     @type string   $title      Título grande dentro del email.
	 *     @type string[] $paragraphs Párrafos del cuerpo (texto plano, se escapa).
	 *     @type string   $cta_label  Etiqueta del botón (opcional).
	 *     @type string   $cta_url    URL del botón (opcional).
	 *     @type string   $footnote   Nota chica al pie del cuerpo (opcional).
	 * }
	 * @return bool
	 */
	public static function send( $to, $subject, array $args = [] ) {
		$html    = self::render( $subject, $args );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		return wp_mail( $to, $subject, $html, $headers );
	}

	public static function render( $subject, array $args = [] ) {
		$brand      = cead_acad_sanitize_hex( get_theme_mod( 'cead_color_brand', '#E93B3C' ) ) ?: '#E93B3C';
		$brand_deep = cead_acad_sanitize_hex( get_theme_mod( 'cead_color_brand_deep', '#B82A2B' ) ) ?: '#B82A2B';
		$site       = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title      = $args['title'] ?? $subject;
		$paragraphs = (array) ( $args['paragraphs'] ?? [] );
		$cta_label  = $args['cta_label'] ?? '';
		$cta_url    = $args['cta_url'] ?? '';
		$footnote   = $args['footnote'] ?? '';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:24px 12px">
		<tr><td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e0e0e0">
				<tr>
					<td style="background:<?php echo esc_attr( $brand ); ?>;padding:20px 28px">
						<span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:.02em"><?php echo esc_html( $site ); ?></span>
					</td>
				</tr>
				<tr>
					<td style="padding:28px">
						<h1 style="margin:0 0 16px;font-size:20px;color:#1d2327"><?php echo esc_html( $title ); ?></h1>
						<?php foreach ( $paragraphs as $p ) : ?>
							<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3c434a"><?php echo wp_kses( $p, [ 'strong' => [], 'em' => [], 'br' => [], 'code' => [] ] ); ?></p>
						<?php endforeach; ?>
						<?php if ( $cta_label && $cta_url ) : ?>
							<p style="margin:24px 0;text-align:center">
								<a href="<?php echo esc_url( $cta_url ); ?>" style="background:<?php echo esc_attr( $brand ); ?>;border:1px solid <?php echo esc_attr( $brand_deep ); ?>;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 28px;border-radius:6px;display:inline-block"><?php echo esc_html( $cta_label ); ?></a>
							</p>
							<p style="margin:0 0 14px;font-size:12px;line-height:1.5;color:#646970">
								<?php esc_html_e( 'Si el botón no funciona, copiá y pegá este link en tu navegador:', 'cead-acad' ); ?><br>
								<a href="<?php echo esc_url( $cta_url ); ?>" style="color:<?php echo esc_attr( $brand_deep ); ?>;word-break:break-all"><?php echo esc_html( $cta_url ); ?></a>
							</p>
						<?php endif; ?>
						<?php if ( $footnote ) : ?>
							<p style="margin:18px 0 0;font-size:12px;line-height:1.5;color:#646970"><?php echo esc_html( $footnote ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 28px;background:#f6f7f7;border-top:1px solid #e0e0e0">
						<p style="margin:0;font-size:12px;color:#646970">— <?php echo esc_html( $site ); ?></p>
					</td>
				</tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
