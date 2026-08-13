<?php
/**
 * Generación de imágenes: flyers y fotos para las notas del sitio.
 *
 * ---------------------------------------------------------------------------
 * POR QUÉ ESTÁ SEPARADO DE LA IA DE TEXTO
 *
 * El proveedor de chat que usa el colegio (DeepSeek, por defecto) NO genera
 * imágenes. Ninguna cantidad de configuración lo va a hacer: no tiene ese
 * endpoint. Así que esto lleva su propio endpoint, su propio modelo y su propia
 * clave, y viene APAGADO. Si se colgara de la configuración de chat, prenderlo
 * daría un 404 del proveedor y parecería un error del plugin.
 *
 * La otra razón es la plata: cada imagen se cobra. Por eso NUNCA se genera sola
 * —hace falta que alguien la pida y después la confirme— y cada generación
 * queda en el registro de auditoría con quién la pidió.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Images {

	/** OpenAI es el único que hoy da esto con calidad de flyer, así que es el default. */
	const ENDPOINT_DEFAULT = 'https://api.openai.com/v1/images/generations';
	const MODEL_DEFAULT    = 'gpt-image-1';
	const SIZE_DEFAULT     = '1024x1024';

	/**
	 * Cuánto se espera al proveedor.
	 *
	 * Generar una imagen tarda bastante más que contestar un texto: entre diez
	 * segundos y un minuto según el modelo. Por eso se avisa «la estoy haciendo»
	 * ANTES de arrancar, y no se comparte el presupuesto de tiempo del turno de
	 * chat — esto corre en su propio turno, después de que la persona confirmó.
	 */
	const TIMEOUT = 90;

	/** Los tamaños que aceptan los modelos actuales. Otro valor da 400. */
	public static function sizes() {
		return [
			'1024x1024' => __( 'Cuadrada (1024×1024) — para Instagram', 'cead-acad' ),
			'1024x1536' => __( 'Vertical (1024×1536) — para historias y afiches', 'cead-acad' ),
			'1536x1024' => __( 'Apaisada (1536×1024) — para la portada de una nota', 'cead-acad' ),
		];
	}

	/* ------------------------------------------------------------- config */

	public static function enabled() {
		return (bool) get_option( 'cead_acad_wa_img_enabled', 0 ) && self::key() !== '';
	}

	/**
	 * La clave. Si no hay una propia, se usa la del chat.
	 *
	 * Varios proveedores compatibles con OpenAI sirven texto e imagen con la
	 * misma clave, y hacer que la carguen dos veces solo invita a que una de las
	 * dos quede vieja. Quien use proveedores distintos carga la propia.
	 */
	public static function key() {
		$k = trim( (string) get_option( 'cead_acad_wa_img_key', '' ) );
		if ( '' !== $k ) { return $k; }
		return class_exists( 'Cead_Acad_WA_AI' ) ? Cead_Acad_WA_AI::key() : '';
	}

	public static function endpoint() {
		$e = trim( (string) get_option( 'cead_acad_wa_img_endpoint', '' ) );
		return '' !== $e ? $e : self::ENDPOINT_DEFAULT;
	}

	public static function model() {
		return (string) ( get_option( 'cead_acad_wa_img_model', '' ) ?: self::MODEL_DEFAULT );
	}

	public static function size() {
		$s = (string) get_option( 'cead_acad_wa_img_size', '' );
		return isset( self::sizes()[ $s ] ) ? $s : self::SIZE_DEFAULT;
	}

	/** Guía visual del colegio: qué tiene que parecer todo lo que se genere. */
	public static function estilo() {
		$e = trim( (string) get_option( 'cead_acad_wa_img_estilo', '' ) );
		return $e !== '' ? $e : self::default_estilo();
	}

	public static function default_estilo() {
		return 'Estilo gráfico institucional de un colegio secundario paraguayo: limpio, con mucho aire, '
			. 'composición geométrica y recta (sin esquinas redondeadas ni degradados suaves). '
			. 'Paleta: rojo intenso (#E93B3C) como color principal, negro y blanco, con amarillo (#EDDF58) '
			. 'y celeste (#49A3C8) solo como acentos. Tipografía de palo seco condensada en mayúsculas para los títulos. '
			. 'Serio y prolijo, nunca infantil ni caricaturesco. Sin marcas de agua, sin logos inventados, '
			. 'sin rostros de personas reconocibles.';
	}

	/* ---------------------------------------------------------- generación */

	/**
	 * Genera una imagen y la deja en la biblioteca de medios.
	 *
	 * @param string $descripcion Qué tiene que mostrar.
	 * @param string $texto       Texto que va DENTRO de la imagen (para un flyer).
	 * @return array{attachment_id:int,url:string,mime:string,base64:string}|WP_Error
	 */
	public static function generar( $descripcion, $texto = '' ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'cead_img_off', __( 'La generación de imágenes está apagada o sin clave.', 'cead-acad' ) );
		}
		$descripcion = trim( (string) $descripcion );
		if ( '' === $descripcion ) {
			return new WP_Error( 'cead_img_vacio', __( 'Falta la descripción de la imagen.', 'cead-acad' ) );
		}

		$r = wp_remote_post( self::endpoint(), [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Bearer ' . self::key(),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'  => self::model(),
				'prompt' => self::componer_prompt( $descripcion, $texto ),
				'size'   => self::size(),
				'n'      => 1,
			] ),
		] );
		if ( is_wp_error( $r ) ) { return $r; }

		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code ) {
			$detalle = (string) ( $body['error']['message'] ?? ( 'HTTP ' . $code ) );
			return new WP_Error( 'cead_img_http', $detalle );
		}

		$item = $body['data'][0] ?? [];

		/*
		 * Dos formas de respuesta según el modelo: los nuevos devuelven la imagen
		 * en base64 y los viejos una URL temporal. Se aceptan las dos — atarse a
		 * una sola significa que cambiar de modelo rompe esto sin decir por qué.
		 */
		$bytes = '';
		if ( ! empty( $item['b64_json'] ) ) {
			$bytes = base64_decode( (string) $item['b64_json'], true ) ?: '';
		} elseif ( ! empty( $item['url'] ) ) {
			$img = wp_remote_get( (string) $item['url'], [ 'timeout' => 30 ] );
			if ( is_wp_error( $img ) ) { return $img; }
			$bytes = (string) wp_remote_retrieve_body( $img );
		}
		if ( '' === $bytes ) {
			return new WP_Error( 'cead_img_vacia', __( 'El proveedor no devolvió ninguna imagen.', 'cead-acad' ) );
		}

		return self::guardar( $bytes, $descripcion );
	}

	/**
	 * Arma el prompt final: lo que se pidió + la guía visual del colegio.
	 *
	 * El texto del flyer se pasa entrecomillado y con una instrucción aparte
	 * porque los modelos de imagen escriben mal cuando el texto viene mezclado
	 * con la descripción de la escena: sale con letras de más o palabras
	 * inventadas, y un flyer con una falta de ortografía es peor que no tenerlo.
	 */
	protected static function componer_prompt( $descripcion, $texto ) {
		$p = $descripcion;
		$texto = trim( (string) $texto );
		if ( '' !== $texto ) {
			$p .= "\n\nEl único texto visible en la imagen tiene que ser exactamente este, "
				. "bien escrito, en español y sin faltas de ortografía:\n«" . $texto . "»\n"
				. 'No agregues ninguna otra palabra, ni firmas, ni fechas que no estén en ese texto.';
		} else {
			$p .= "\n\nSin texto dentro de la imagen.";
		}
		$estilo = trim( self::estilo() );
		if ( '' !== $estilo ) { $p .= "\n\n" . $estilo; }
		return $p;
	}

	/**
	 * Deja los bytes en la biblioteca de medios.
	 *
	 * @return array{attachment_id:int,url:string,mime:string,base64:string}|WP_Error
	 */
	protected static function guardar( $bytes, $descripcion ) {
		$nombre = 'ceadi-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.png';
		$upload = wp_upload_bits( $nombre, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'cead_img_disco', (string) $upload['error'] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$aid = wp_insert_attachment( [
			'post_mime_type' => 'image/png',
			// El título es la descripción con la que se pidió: dentro de un mes,
			// en una biblioteca con doscientas imágenes, «ceadi-20260813-...» no
			// le dice nada a nadie.
			'post_title'     => mb_substr( sanitize_text_field( $descripcion ), 0, 100 ),
			'post_status'    => 'inherit',
		], $upload['file'] );
		if ( is_wp_error( $aid ) || ! $aid ) {
			return new WP_Error( 'cead_img_adjunto', __( 'No se pudo registrar la imagen en la biblioteca.', 'cead-acad' ) );
		}
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $upload['file'] ) );

		return [
			'attachment_id' => (int) $aid,
			'url'           => (string) $upload['url'],
			'mime'          => 'image/png',
			'base64'        => base64_encode( $bytes ),
		];
	}
}
