<?php
/**
 * Instagram → borrador de nota.
 *
 * Revisa cada tanto la cuenta del colegio y, cuando aparece una publicación
 * nueva, arma la nota entera y se la ofrece al encargado para que la apruebe.
 *
 * El protocolo:
 *
 *   1. El extractor trae la publicación con hasta DOS imágenes.
 *   2. CEADI la redacta y DECIDE la categoría y la maqueta.
 *   3. Las imágenes se suben a la biblioteca: la primera queda de destacada, la
 *      segunda va adentro del cuerpo.
 *   4. Todo eso se guarda como BORRADOR de WordPress, ya armado.
 *   5. Recién ahí se avisa por WhatsApp: aprobar, editar o dejar en borrador.
 *
 * Antes el paso 3 y el 4 no existían: llegaba un texto suelto por WhatsApp que
 * había que copiar, pegar, buscarle la foto en Instagram, bajarla, subirla y
 * elegirle la categoría a mano. O sea, la nota la terminaba armando la persona
 * igual, y lo único que ahorraba era el primer borrador de la redacción.
 *
 * ---------------------------------------------------------------------------
 * ADVERTENCIA HONESTA SOBRE DE DÓNDE SALEN LOS DATOS
 *
 * Instagram no deja leer una cuenta sin autenticación. No hay endpoint público
 * estable: los que circulaban se cerraron, y raspar el HTML da bloqueos por IP
 * y deja de andar sin aviso. Cualquiera que prometa lo contrario está
 * describiendo algo que va a romperse.
 *
 * Por eso la lectura está separada del resto en `traer_publicaciones()`, con
 * tres orígenes posibles:
 *
 *   1. `graph`     — API oficial de Instagram. Es la única confiable. Necesita
 *                    acceso a la cuenta del colegio, que todavía no hay.
 *   2. `proveedor` — un servicio externo que devuelva JSON (hay varios pagos
 *                    que hacen justo esto). Se configura URL y clave.
 *   3. `off`       — apagado (por defecto).
 *
 * Todo lo demás —detectar que algo es nuevo, redactar con CEADI, mandarlo al
 * director— ya funciona y no cambia según el origen. Cuando consigan el acceso
 * a la cuenta, se completa el token de `graph` en Ajustes y listo: no hay que
 * tocar código.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_WA_Instagram {

	const EVENTO      = 'cead_acad_wa_instagram';
	/** Publicaciones ya avisadas, para no repetir. */
	const OPT_VISTAS  = 'cead_acad_wa_ig_vistas';
	const OPT_ERROR   = 'cead_acad_wa_ig_error';
	/** Cuántos ids recordar. Alcanza de sobra y no deja crecer la opción sin fin. */
	const RECORDAR    = 60;
	/**
	 * Cuántas imágenes se traen de cada publicación.
	 *
	 * Dos: una para destacada y otra para el cuerpo. Un carrusel de Instagram
	 * puede tener diez, y bajarlas todas llenaría la biblioteca de fotos que
	 * nadie va a usar — la nota del sitio no es el carrusel.
	 */
	const IMAGENES    = 2;
	/** De qué publicación de Instagram salió cada borrador. */
	const META_ORIGEN = '_cead_acad_ig_origen';

	public function boot() {
		add_action( self::EVENTO, [ $this, 'revisar' ] );
		add_action( 'init', [ $this, 'programar' ] );
	}

	public function programar() {
		if ( self::modo() === 'off' ) {
			$t = wp_next_scheduled( self::EVENTO );
			if ( $t ) { wp_unschedule_event( $t, self::EVENTO ); }
			return;
		}
		if ( ! wp_next_scheduled( self::EVENTO ) ) {
			// Cada media hora: una cuenta de colegio no publica más seguido que
			// eso, y consultar de más solo gasta cuota del proveedor.
			wp_schedule_event( time() + 300, 'cead_acad_wa_30min', self::EVENTO );
		}
	}

	/* ------------------------------------------------------------ config */

	public static function modo() {
		$m = (string) get_option( 'cead_acad_wa_ig_modo', 'off' );
		return in_array( $m, [ 'graph', 'proveedor' ], true ) ? $m : 'off';
	}
	public static function usuario() {
		return (string) get_option( 'cead_acad_wa_ig_usuario', 'cead_felix_de_guarania' );
	}
	public static function ultimo_error() {
		return (string) get_option( self::OPT_ERROR, '' );
	}

	protected static function anotar_error( $msg ) {
		update_option( self::OPT_ERROR, $msg ? current_time( 'mysql' ) . ' — ' . $msg : '', false );
		if ( $msg ) { error_log( '[CeadAcadWA][IG] ' . $msg ); }
	}

	/* ------------------------------------------------------------- ciclo */

	/** Corre en el cron: mira si hay algo nuevo y avisa. */
	public function revisar() {
		if ( self::modo() === 'off' ) { return; }

		$posts = self::traer_publicaciones();
		if ( is_wp_error( $posts ) ) {
			self::anotar_error( $posts->get_error_message() );
			return;
		}
		self::anotar_error( '' );
		if ( ! $posts ) { return; }

		$vistas = (array) get_option( self::OPT_VISTAS, [] );
		$estado = self::separar_nuevas( $posts, $vistas );

		if ( $estado['primera'] ) {
			update_option( self::OPT_VISTAS, $estado['vistas'], false );
			error_log( '[CeadAcadWA][IG] primera corrida: ' . count( $estado['vistas'] ) . ' publicaciones tomadas como ya vistas.' );
			return;
		}

		/*
		 * Se anota como vista SOLO la publicación que efectivamente salió.
		 *
		 * Antes se guardaba la lista entera antes de mandar nada: si el bridge
		 * estaba caído, o si todavía no había número de dirección cargado, el
		 * borrador se perdía para siempre —la corrida siguiente ya veía esos ids
		 * como conocidos y los salteaba—. Guardando después de cada envío, lo que
		 * no salió se vuelve a intentar dentro de media hora.
		 */
		$enviadas = 0;
		foreach ( $estado['nuevas'] as $p ) {
			if ( ! $this->avisar( $p ) ) { break; }
			$vistas[] = (string) ( $p['id'] ?? '' );
			$enviadas++;
		}

		if ( $enviadas ) {
			if ( count( $vistas ) > self::RECORDAR ) {
				$vistas = array_slice( $vistas, -self::RECORDAR );
			}
			update_option( self::OPT_VISTAS, array_values( $vistas ), false );
		}
	}

	/**
	 * Decide qué es nuevo y qué ya se avisó.
	 *
	 * Lo importante acá es la PRIMERA corrida: cuando todavía no hay nada
	 * anotado, no se avisa nada — solo se toma nota de lo que ya existe. Sin
	 * eso, activar la función le dispararía al director doce borradores de
	 * publicaciones viejas de un saque, que es la peor forma posible de
	 * estrenar la herramienta.
	 *
	 * Las nuevas se devuelven de más vieja a más nueva: Instagram las entrega
	 * al revés, y llegando así se leen en el orden en que pasaron.
	 *
	 * @return array{nuevas:array,vistas:array,primera:bool}
	 */
	public static function separar_nuevas( array $posts, array $vistas ) {
		$primera = empty( $vistas );
		$nuevas  = [];

		foreach ( $posts as $p ) {
			$id = (string) ( $p['id'] ?? '' );
			if ( '' === $id || in_array( $id, $vistas, true ) ) { continue; }
			$vistas[] = $id;
			if ( ! $primera ) { $nuevas[] = $p; }
		}

		if ( count( $vistas ) > self::RECORDAR ) {
			$vistas = array_slice( $vistas, -self::RECORDAR );
		}

		return [
			'nuevas'  => array_reverse( $nuevas ),
			'vistas'  => array_values( $vistas ),
			'primera' => $primera,
		];
	}

	/**
	 * Arma el borrador completo y se lo manda al encargado.
	 *
	 * El protocolo, de punta a punta:
	 *
	 *   1. El extractor trae la publicación con hasta DOS imágenes.
	 *   2. CEADI redacta la nota y DECIDE la categoría y la maqueta.
	 *   3. Las imágenes se suben a la biblioteca: la primera es la destacada,
	 *      la segunda va adentro del cuerpo.
	 *   4. Todo eso se guarda como BORRADOR de WordPress, ya armado.
	 *   5. Recién ahí se le avisa al encargado, que aprueba, edita o descarta.
	 *
	 * Por qué borrador y no un texto suelto por WhatsApp, que es como estaba:
	 * el mensaje se perdía. Había que copiarlo, pegarlo, buscar la foto en
	 * Instagram, bajarla, subirla y elegir la categoría a mano — o sea, la nota
	 * la terminaba escribiendo la persona igual. Guardado como borrador, aprobar
	 * es cambiarle el estado a una nota que YA está completa.
	 *
	 * Y hay una razón más dura: el estado de la conversación de WhatsApp caduca
	 * a los diez minutos. Un borrador que llega a las tres de la mañana tiene que
	 * seguir existiendo a las siete. Con el atajo numérico solo se pierde el
	 * atajo; la nota sigue en el sitio, entera, esperando.
	 *
	 * @return bool true si el aviso realmente salió.
	 */
	protected function avisar( $post ) {
		$telefono = (string) get_option( 'cead_acad_wa_director_phone', '' );
		if ( '' === trim( $telefono ) ) {
			self::anotar_error( 'Hay publicaciones nuevas pero no está configurado el número de dirección.' );
			return false;
		}

		$identidad = Cead_Acad_WA_Identity::resolve( $telefono );
		$autor     = (int) ( $identidad['user_id'] ?? 0 );

		/*
		 * El encargado tiene que poder publicar lo que se le propone.
		 *
		 * Si el número configurado no corresponde a nadie con permiso, armar el
		 * borrador igual sería dejar una nota a medio hacer que ese número no va
		 * a poder aprobar nunca. Mejor decirlo en el registro de errores, que es
		 * donde se mira cuando esto «no anda».
		 */
		if ( ! $autor || ! Cead_Acad_WA_Identity::can( $autor, 'cead_acad_manage_articles' ) ) {
			self::anotar_error( 'El número de dirección no corresponde a un usuario que pueda publicar artículos.' );
			return false;
		}

		$ficha = self::redactar( $post );
		if ( ! $ficha ) {
			self::anotar_error( 'CEADI no pudo redactar el borrador; se reintenta en la próxima corrida.' );
			return false;
		}

		$adjuntas = self::subir_imagenes( $post, $ficha['epigrafe'] );
		$pid      = self::crear_borrador( $ficha, $adjuntas, $post, $autor );
		if ( ! $pid ) {
			self::anotar_error( 'No se pudo guardar el borrador en el sitio; se reintenta en la próxima corrida.' );
			return false;
		}

		$ok = Cead_Acad_WA_Module::notify( $telefono, self::mensaje_propuesta( $ficha, $adjuntas, $post, $pid ) );
		if ( ! $ok ) {
			/*
			 * El aviso no salió, pero el borrador ya existe. Se borra para que la
			 * próxima corrida —que va a reintentar esta misma publicación, porque
			 * no quedó anotada como vista— no deje dos borradores iguales.
			 */
			wp_delete_post( $pid, true );
			self::anotar_error( 'No se pudo avisar al número de dirección; se reintenta en la próxima corrida.' );
			return false;
		}

		/*
		 * El atajo para aprobar por chat. Se pone SOLO si la conversación está
		 * quieta: si el encargado está en medio de cargar una nota o de armar un
		 * comunicado, pisarle el estado le rompe lo que está haciendo. En ese
		 * caso el borrador igual quedó guardado y el mensaje ya salió con el
		 * enlace, así que no se pierde nada.
		 */
		$store  = new Cead_Acad_WA_Store();
		$actual = (string) ( $store->get_state( preg_replace( '/[^0-9]/', '', $telefono ) )['state'] ?? 'idle' );
		if ( in_array( $actual, [ 'idle', 'ia_home' ], true ) ) {
			$store->set_state( preg_replace( '/[^0-9]/', '', $telefono ), 'ia_staff_confirm', [
				'kind'    => 'ig_borrador',
				'post_id' => $pid,
			] );
		}

		Cead_Acad_Audit::log( 'ig_draft_created', [
			'user_id'     => $autor,
			'entity_type' => 'post',
			'entity_id'   => $pid,
			'payload'     => [
				'ig_id'     => (string) ( $post['id'] ?? '' ),
				'categoria' => $ficha['categoria'] ?: null,
				'formato'   => $ficha['formato'] ?: null,
				'imagenes'  => count( $adjuntas ),
			],
		] );

		return true;
	}

	/** El mensaje que ve el encargado. */
	protected static function mensaje_propuesta( array $ficha, array $adjuntas, array $post, $pid ) {
		$extra = '';
		if ( $ficha['categoria'] ) {
			$cats   = Cead_Acad_Article_Categories::listar();
			$extra .= "\n🏷️ " . sprintf( __( 'Categoría: %s', 'cead-acad' ), $cats[ $ficha['categoria'] ] ?? '' );
		}
		if ( $ficha['formato'] && class_exists( 'Cead_Acad_Article_Kind' ) ) {
			$extra .= "\n🧩 " . sprintf( __( 'Maqueta: %s', 'cead-acad' ), Cead_Acad_Article_Kind::label( $ficha['formato'] ) );
		}
		if ( $adjuntas ) {
			$extra .= "\n📎 " . sprintf(
				/* translators: %d: cantidad de imágenes traídas de Instagram */
				_n( '%d imagen traída de Instagram.', '%d imágenes traídas de Instagram.', count( $adjuntas ), 'cead-acad' ),
				count( $adjuntas )
			);
		}

		$msg  = "📸 *Publicación nueva en Instagram*\n";
		if ( ! empty( $post['url'] ) ) { $msg .= $post['url'] . "\n"; }
		$msg .= "\n" . sprintf(
			/* translators: 1: título propuesto, 2: extracto del cuerpo, 3: datos extra */
			__( "📝 *%1\$s*\n────────\n%2\$s\n────────%3\$s", 'cead-acad' ),
			$ficha['titulo'],
			mb_substr( $ficha['contenido'], 0, 420 ) . ( mb_strlen( $ficha['contenido'] ) > 420 ? '…' : '' ),
			$extra
		);
		$msg .= "\n\n" . __( 'Está guardado como *borrador* en el sitio, ya armado.', 'cead-acad' );
		$msg .= "\n\n*1.* ✅ Publicar\n*2.* ✏️ Editar (decime el cambio)\n*3.* ❌ Dejarlo en borrador";
		$msg .= "\n\n" . sprintf(
			/* translators: %s: enlace para editarlo en wp-admin */
			__( 'También lo podés abrir y editar acá: %s', 'cead-acad' ),
			admin_url( 'post.php?post=' . (int) $pid . '&action=edit' )
		);

		return $msg;
	}

	/**
	 * Le pide a CEADI la nota YA DECIDIDA: título, cuerpo, categoría y maqueta.
	 *
	 * Se pide JSON en vez de texto corrido porque lo que vuelve tiene que
	 * entrar en campos distintos del post. Antes volvía «título, línea en
	 * blanco, cuerpo» y todo lo demás —categoría, maqueta, epígrafe— no existía:
	 * lo ponía la persona a mano o no lo ponía nadie.
	 *
	 * Si el modelo igual contesta en prosa, se lee como venía antes. Es
	 * preferible un borrador sin categoría que ningún borrador.
	 *
	 * @return array{titulo:string,contenido:string,categoria:int,formato:string,epigrafe:string}|null
	 */
	protected static function redactar( array $post ) {
		$pie    = trim( (string) ( $post['caption'] ?? '' ) );
		$enlace = (string) ( $post['url'] ?? '' );

		if ( '' === $pie ) {
			// Sin texto no hay nota. Se avisa igual —la publicación existe— pero
			// no se inventa contenido a partir de nada.
			return [
				'titulo'    => __( 'Publicación de Instagram sin texto', 'cead-acad' ),
				'contenido' => sprintf(
					/* translators: %s: enlace a la publicación */
					__( 'La publicación no trae pie de foto. Está acá: %s', 'cead-acad' ),
					$enlace
				),
				'categoria' => 0,
				'formato'   => '',
				'epigrafe'  => '',
			];
		}

		if ( ! class_exists( 'Cead_Acad_WA_AI' ) || ! Cead_Acad_WA_AI::enabled() ) {
			return [
				'titulo'    => mb_substr( trim( explode( "\n", $pie )[0] ), 0, 90 ),
				'contenido' => $pie,
				'categoria' => 0,
				'formato'   => '',
				'epigrafe'  => '',
			];
		}

		$cats     = Cead_Acad_Article_Categories::listar();
		$maquetas = class_exists( 'Cead_Acad_Article_Kind' ) ? Cead_Acad_Article_Kind::catalogo() : [];

		$prompt = "Se publicó esto en el Instagram del colegio. Convertilo en una nota para el sitio web.\n\n"
			. "Contestá SOLO con un objeto JSON, sin explicaciones ni bloques de código, con estas claves:\n"
			. '{"titulo": "...", "contenido": "...", "categoria": "...", "formato": "...", "epigrafe": "..."}' . "\n\n"
			. "- titulo: una línea, sin markdown, sin hashtags ni emojis.\n"
			. "- contenido: el cuerpo en MARKDOWN, dos o tres párrafos, tercera persona, tono institucional. "
			. "El PRIMER párrafo es la bajada y tiene que resumir lo importante solo. No repitas el título adentro.\n"
			. ( $cats ? "- categoria: una de estas, la que mejor corresponda al tema: " . implode( ', ', $cats ) . ". Si ninguna encaja, dejala vacía.\n" : '' )
			. ( $maquetas ? '- formato: ' . self::pistas_de_maqueta( $maquetas ) . "\n" : '' )
			. "- epigrafe: una línea describiendo qué se ve en las fotos, para el pie de imagen.\n\n"
			. "NO inventes datos que no estén en el texto (fechas, nombres, resultados, cantidades). "
			. "Si un dato falta, escribí que está a confirmar. No copies hashtags ni emojis."
			// La misma guía de redacción que usa el resto del sistema: una nota
			// que entra por Instagram tiene que sonar igual que una escrita a mano.
			. Cead_Acad_WA_AI::estilo_bloque()
			. "\n\nTexto de la publicación:\n" . mb_substr( $pie, 0, 1500 );

		$r     = Cead_Acad_WA_AI::route( $prompt, '', '', [], 'Estás preparando un borrador de nota para el sitio del colegio.' );
		$texto = is_array( $r ) ? trim( (string) ( $r['reply'] ?? '' ) ) : '';
		if ( '' === $texto ) { return null; }

		return self::interpretar( $texto, $pie );
	}

	/** Las pistas de cada maqueta, en una línea, para meterlas en el prompt. */
	protected static function pistas_de_maqueta( array $maquetas ) {
		$partes = [];
		foreach ( $maquetas as $slug => $cfg ) {
			$partes[] = '«' . $slug . '» ' . ( $cfg['pista'] ?? '' );
		}
		return 'con qué maqueta se dibuja. ' . implode( ' ', $partes )
			. ' Elegí por lo que ES el texto, no por el tema. Ante la duda, «noticia».';
	}

	/**
	 * Lee la respuesta del modelo.
	 *
	 * Acepta JSON pelado o envuelto en un bloque de código, que es como lo
	 * devuelven la mitad de los modelos por más que se les pida lo contrario. Si
	 * no hay JSON válido, cae a la forma vieja —primera línea es el título,
	 * el resto el cuerpo— antes que descartar una redacción que puede estar bien.
	 */
	public static function interpretar_publico( $texto, $pie ) {
		$f = self::interpretar( $texto, $pie );
		// Una reescritura que devuelve el mismo título y el pie crudo como cuerpo
		// es la señal de que el JSON no vino: para una CORRECCIÓN eso no sirve —
		// devolvería el borrador sin el cambio y diciendo que lo aplicó.
		return ( $f && $f['contenido'] !== $pie ) ? $f : null;
	}

	protected static function interpretar( $texto, $pie ) {
		$json = $texto;
		if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $texto, $m ) ) { $json = $m[1]; }
		// Un modelo charlatán antepone una frase antes del objeto.
		$ini = strpos( $json, '{' );
		$fin = strrpos( $json, '}' );
		if ( false !== $ini && false !== $fin && $fin > $ini ) {
			$json = substr( $json, $ini, $fin - $ini + 1 );
		}

		$d = json_decode( $json, true );
		if ( is_array( $d ) && '' !== trim( (string) ( $d['titulo'] ?? '' ) ) && '' !== trim( (string) ( $d['contenido'] ?? '' ) ) ) {
			$formato = '';
			if ( class_exists( 'Cead_Acad_Article_Kind' ) ) {
				// Sin datos de evento: un «evento» propuesto acá se cae solo a
				// noticia, que es lo correcto — de un pie de Instagram no sale
				// una fecha confiable.
				$formato = Cead_Acad_Article_Kind::resolver( (string) ( $d['formato'] ?? '' ) );
			}
			return [
				'titulo'    => sanitize_text_field( (string) $d['titulo'] ),
				'contenido' => trim( (string) $d['contenido'] ),
				'categoria' => Cead_Acad_Article_Categories::resolver( (string) ( $d['categoria'] ?? '' ) ),
				'formato'   => $formato,
				'epigrafe'  => sanitize_text_field( (string) ( $d['epigrafe'] ?? '' ) ),
			];
		}

		$lineas = preg_split( '/\r?\n/', trim( $texto ) );
		$titulo = trim( (string) array_shift( $lineas ) );
		$cuerpo = trim( implode( "\n", $lineas ) );

		return [
			'titulo'    => sanitize_text_field( $titulo !== '' ? $titulo : mb_substr( $pie, 0, 90 ) ),
			'contenido' => $cuerpo !== '' ? $cuerpo : $pie,
			'categoria' => 0,
			'formato'   => '',
			'epigrafe'  => '',
		];
	}

	/**
	 * Baja las imágenes de la publicación y las sube a la biblioteca.
	 *
	 * Se bajan AHORA y no al aprobar porque las URLs de Instagram están firmadas
	 * y vencen: un borrador aprobado dos días después se publicaría sin foto y
	 * sin que nadie entienda por qué.
	 *
	 * @return int[] IDs de adjunto, en orden. Vacío si no se pudo ninguna.
	 */
	protected static function subir_imagenes( array $post, $epigrafe = '' ) {
		$urls = array_values( array_filter( array_map( 'strval', (array) ( $post['imagenes'] ?? [] ) ) ) );
		if ( ! $urls ) { return []; }

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$out = [];
		foreach ( array_slice( $urls, 0, self::IMAGENES ) as $url ) {
			// `0` como post padre: todavía no existe el borrador. Se adjuntan al
			// crearlo, unas líneas más adelante.
			$id = media_sideload_image( $url, 0, $epigrafe ?: null, 'id' );
			if ( is_wp_error( $id ) ) {
				self::anotar_error( 'No se pudo bajar una imagen de Instagram: ' . $id->get_error_message() );
				continue;
			}
			$out[] = (int) $id;
		}
		return $out;
	}

	/**
	 * Guarda el borrador ya armado: cuerpo, categoría, maqueta e imágenes.
	 *
	 * @return int post_id, o 0 si falló.
	 */
	protected static function crear_borrador( array $ficha, array $adjuntas, array $post, $autor ) {
		$html = class_exists( 'Cead_Acad_Article_Format' )
			? Cead_Acad_Article_Format::to_html( $ficha['contenido'] )
			: wpautop( $ficha['contenido'] );

		// La segunda imagen va adentro del cuerpo: como destacada solo entra una,
		// y dejarla suelta en la biblioteca es tirarla.
		if ( isset( $adjuntas[1] ) ) {
			$html .= "\n" . self::figura( $adjuntas[1], $ficha['epigrafe'] );
		}

		$pid = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $ficha['titulo'],
			'post_content' => $html,
			'post_author'  => (int) $autor,
		], true );
		if ( is_wp_error( $pid ) || ! $pid ) { return 0; }

		if ( isset( $adjuntas[0] ) ) {
			set_post_thumbnail( $pid, (int) $adjuntas[0] );
		}
		foreach ( $adjuntas as $aid ) {
			wp_update_post( [ 'ID' => (int) $aid, 'post_parent' => (int) $pid ] );
		}

		if ( $ficha['categoria'] ) {
			wp_set_post_categories( $pid, [ (int) $ficha['categoria'] ], true );
		}
		if ( $ficha['formato'] && class_exists( 'Cead_Acad_Article_Kind' ) ) {
			Cead_Acad_Article_Kind::guardar( $pid, $ficha['formato'] );
		}

		// De dónde salió, para poder rastrearlo después.
		update_post_meta( $pid, self::META_ORIGEN, (string) ( $post['url'] ?? '' ) );

		return (int) $pid;
	}

	/** Una figura con epígrafe, en el formato de bloques de WordPress. */
	protected static function figura( $attachment_id, $epigrafe ) {
		$src = wp_get_attachment_image_url( (int) $attachment_id, 'large' );
		if ( ! $src ) { return ''; }
		$img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $epigrafe ) . '" class="wp-image-' . (int) $attachment_id . '"/>';
		$cap = $epigrafe ? '<figcaption class="wp-element-caption">' . esc_html( $epigrafe ) . '</figcaption>' : '';
		return '<figure class="wp-block-image size-large">' . $img . $cap . '</figure>';
	}

	/* --------------------------------------------------------- la lectura */

	/**
	 * Trae las publicaciones recientes. Devuelve una lista de
	 * [ id, caption, url, imagen ] o un WP_Error.
	 *
	 * Es la única parte que depende de Instagram, y está aislada acá a
	 * propósito: cuando cambie la forma de leer (o cuando llegue el acceso a la
	 * cuenta) se toca solo esta función.
	 *
	 * @return array<int,array<string,string>>|WP_Error
	 */
	public static function traer_publicaciones() {
		switch ( self::modo() ) {
			case 'graph':     return self::desde_graph();
			case 'proveedor': return self::desde_proveedor();
		}
		return [];
	}

	/**
	 * API oficial. Es el camino bueno: estable, permitido y sin bloqueos. Pide
	 * un token de acceso de larga duración de la cuenta de empresa vinculada.
	 */
	protected static function desde_graph() {
		$token = (string) get_option( 'cead_acad_wa_ig_token', '' );
		$cuenta = (string) get_option( 'cead_acad_wa_ig_cuenta_id', '' );
		if ( '' === $token || '' === $cuenta ) {
			return new WP_Error( 'cead_ig_config', 'Falta el token o el ID de cuenta de Instagram.' );
		}

		/*
		 * `children{media_url}` trae las fotos de un carrusel. Sin eso, de una
		 * publicación de cinco fotos llegaba `media_url` a secas —que en un
		 * carrusel viene vacío—, y la nota se armaba sin ninguna imagen.
		 */
		$url = add_query_arg( [
			'fields'       => 'id,caption,permalink,media_url,media_type,timestamp,children{media_url}',
			'limit'        => 10,
			'access_token' => $token,
		], 'https://graph.facebook.com/v21.0/' . rawurlencode( $cuenta ) . '/media' );

		$r = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code ) {
			$detalle = (string) ( $body['error']['message'] ?? ( 'HTTP ' . $code ) );
			return new WP_Error( 'cead_ig_http', 'Instagram respondió: ' . $detalle );
		}

		$out = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $m ) {
			$imgs = [];
			foreach ( (array) ( $m['children']['data'] ?? [] ) as $hijo ) {
				if ( ! empty( $hijo['media_url'] ) ) { $imgs[] = (string) $hijo['media_url']; }
			}
			if ( ! $imgs && ! empty( $m['media_url'] ) ) { $imgs[] = (string) $m['media_url']; }

			$out[] = [
				'id'       => (string) ( $m['id'] ?? '' ),
				'caption'  => (string) ( $m['caption'] ?? '' ),
				'url'      => (string) ( $m['permalink'] ?? '' ),
				'imagenes' => array_slice( $imgs, 0, self::IMAGENES ),
			];
		}
		return $out;
	}

	/**
	 * Servicio externo que devuelve JSON. Pensado para los proveedores que
	 * hacen esta lectura por vos mientras no haya acceso a la cuenta.
	 *
	 * Se espera una lista de objetos con `id`/`caption`/`url`; se aceptan
	 * también los nombres alternativos más comunes (`shortcode`, `text`,
	 * `permalink`) porque cada proveedor los llama distinto y no vale la pena
	 * atarse a uno solo.
	 */
	protected static function desde_proveedor() {
		$url = trim( (string) get_option( 'cead_acad_wa_ig_proveedor_url', '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'cead_ig_config', 'Falta la URL del proveedor de Instagram.' );
		}
		$url = str_replace( '{usuario}', rawurlencode( self::usuario() ), $url );

		$headers = [];
		$clave   = trim( (string) get_option( 'cead_acad_wa_ig_proveedor_key', '' ) );
		if ( '' !== $clave ) {
			$headers['Authorization'] = 'Bearer ' . $clave;
			// Varios servicios de RapidAPI esperan la clave con este nombre.
			$headers['x-api-key']     = $clave;
		}

		$r = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => $headers ] );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 200 !== $code ) {
			return new WP_Error( 'cead_ig_http', 'El proveedor respondió HTTP ' . $code . '.' );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'cead_ig_json', 'El proveedor no devolvió JSON válido.' );
		}
		// La lista puede venir suelta o adentro de `data` / `items` / `posts`.
		$lista = $body;
		foreach ( [ 'data', 'items', 'posts', 'result' ] as $k ) {
			if ( isset( $body[ $k ] ) && is_array( $body[ $k ] ) ) { $lista = $body[ $k ]; break; }
		}

		$out = [];
		foreach ( (array) $lista as $m ) {
			if ( ! is_array( $m ) ) { continue; }
			$id = (string) ( $m['id'] ?? $m['shortcode'] ?? $m['code'] ?? '' );
			if ( '' === $id ) { continue; }
			$out[] = [
				'id'       => $id,
				'caption'  => (string) ( $m['caption'] ?? $m['text'] ?? $m['description'] ?? '' ),
				'url'      => (string) ( $m['url'] ?? $m['permalink'] ?? $m['link'] ?? '' ),
				'imagenes' => self::imagenes_del_proveedor( $m ),
			];
		}
		return $out;
	}

	/**
	 * Saca las imágenes de un item del proveedor.
	 *
	 * Cada servicio las llama distinto y algunos devuelven la lista del carrusel
	 * y otros una sola URL, así que se aceptan las dos formas y los nombres más
	 * comunes. Lo que no se hace es adivinar: si no hay ninguna clave conocida,
	 * la nota sale sin foto y se ve, en vez de quedar con una URL rota.
	 *
	 * @return string[]
	 */
	protected static function imagenes_del_proveedor( array $m ) {
		$urls = [];

		foreach ( [ 'images', 'medias', 'carousel', 'children' ] as $k ) {
			foreach ( (array) ( $m[ $k ] ?? [] ) as $hijo ) {
				if ( is_string( $hijo ) ) { $urls[] = $hijo; continue; }
				if ( ! is_array( $hijo ) ) { continue; }
				$u = (string) ( $hijo['media_url'] ?? $hijo['url'] ?? $hijo['image'] ?? '' );
				if ( '' !== $u ) { $urls[] = $u; }
			}
		}
		foreach ( [ 'media_url', 'image', 'thumbnail', 'display_url' ] as $k ) {
			if ( ! empty( $m[ $k ] ) && is_string( $m[ $k ] ) ) { $urls[] = (string) $m[ $k ]; }
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return array_slice( $urls, 0, self::IMAGENES );
	}
}
