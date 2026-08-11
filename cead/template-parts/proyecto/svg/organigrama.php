<?php
/**
 * El organigrama: quién habla con quién.
 *
 * Se arma solo al entrar en pantalla (las líneas se dibujan con
 * `stroke-dashoffset`), que es la forma más clara de mostrar una dependencia:
 * primero las cajas, después las flechas.
 *
 * Acá el SVG NO va `aria-hidden`: es información, no decoración. Lleva `role="img"`
 * y una descripción que cuenta lo mismo en una frase.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$desc_id = 'proy-org-desc';
?>
<div class="reveal proy-org">
	<svg viewBox="0 0 360 250" fill="none" role="img" aria-describedby="<?php echo esc_attr( $desc_id ); ?>" focusable="false">
		<title><?php esc_attr_e( 'Cómo se conectan las partes del sistema', 'cead' ); ?></title>
		<desc id="<?php echo esc_attr( $desc_id ); ?>"><?php esc_attr_e( 'Hay tres formas de entrar: navegador, aplicación y WhatsApp. El navegador y la aplicación se conectan directamente con el sistema principal; WhatsApp lo hace a través de un puente. Los datos se guardan únicamente en la aplicación.', 'cead' ); ?></desc>

		<?php // Las tres puertas ?>
		<g class="proy-org-caja">
			<rect x="10" y="14" width="92" height="42" fill="#fff" stroke="var(--ink)" stroke-width="3"/>
			<text x="56" y="33" text-anchor="middle" class="proy-org-t"><?php esc_html_e( 'Navegador', 'cead' ); ?></text>
			<text x="56" y="47" text-anchor="middle" class="proy-org-s"><?php esc_html_e( 'la web', 'cead' ); ?></text>
		</g>
		<g class="proy-org-caja proy-org-caja--2">
			<rect x="134" y="14" width="92" height="42" fill="#fff" stroke="var(--ink)" stroke-width="3"/>
			<text x="180" y="33" text-anchor="middle" class="proy-org-t"><?php esc_html_e( 'Aplicación', 'cead' ); ?></text>
			<text x="180" y="47" text-anchor="middle" class="proy-org-s"><?php esc_html_e( 'el celular', 'cead' ); ?></text>
		</g>
		<g class="proy-org-caja proy-org-caja--3">
			<rect x="258" y="14" width="92" height="42" fill="#fff" stroke="var(--ink)" stroke-width="3"/>
			<text x="304" y="33" text-anchor="middle" class="proy-org-t"><?php esc_html_e( 'WhatsApp', 'cead' ); ?></text>
			<text x="304" y="47" text-anchor="middle" class="proy-org-s"><?php esc_html_e( 'CEADI', 'cead' ); ?></text>
		</g>

		<?php // El puente, solo en el camino de WhatsApp ?>
		<g class="proy-org-caja proy-org-caja--4">
			<rect x="258" y="98" width="92" height="36" fill="var(--acc-orange)" stroke="var(--ink)" stroke-width="3"/>
			<text x="304" y="121" text-anchor="middle" class="proy-org-t"><?php esc_html_e( 'El puente', 'cead' ); ?></text>
		</g>

		<?php // El corazón ?>
		<g class="proy-org-caja proy-org-caja--5">
			<rect x="46" y="160" width="268" height="52" fill="var(--brand)" stroke="var(--ink)" stroke-width="3"/>
			<text x="180" y="182" text-anchor="middle" class="proy-org-t proy-org-t--inv"><?php esc_html_e( 'La aplicación', 'cead' ); ?></text>
			<text x="180" y="199" text-anchor="middle" class="proy-org-s proy-org-s--inv"><?php esc_html_e( 'usuarios · permisos · notas · comunicados · datos', 'cead' ); ?></text>
		</g>

		<?php // Las líneas, que se dibujan solas ?>
		<g class="proy-org-lineas" stroke="var(--ink)" stroke-width="3" stroke-linecap="round">
			<path class="proy-org-linea" d="M56 56v104"/>
			<path class="proy-org-linea proy-org-linea--2" d="M180 56v104"/>
			<path class="proy-org-linea proy-org-linea--3" d="M304 56v42"/>
			<path class="proy-org-linea proy-org-linea--4" d="M304 134v26"/>
		</g>

		<text x="180" y="236" text-anchor="middle" class="proy-org-pie"><?php esc_html_e( 'Todo en el servidor del colegio', 'cead' ); ?></text>
	</svg>
</div>
