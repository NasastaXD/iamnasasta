<?php
/**
 * Wiki pública del proyecto, servida en /wiki (y /wiki/tecnica).
 *
 * Es SOLO LECTURA a propósito: el contenido vive como Markdown empaquetado en
 * el plugin (docs/WIKI-*.md), no como post de WordPress, así no es editable
 * desde wp-admin por dirección/secretaría. Para cambiarla se edita el .md y se
 * publica una versión nueva del plugin. (Más adelante puede migrarse a un post
 * de WP si se quiere edición in situ.)
 *
 * El render usa el Parsedown que ya viene empaquetado con plugin-update-checker,
 * para no sumar dependencias. Si no estuviera, cae a un <pre> legible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Wiki {

	/** Páginas disponibles: slug => [archivo, etiqueta de la pestaña]. */
	public static function pages() {
		return [
			'usuario' => [ 'file' => 'docs/WIKI-USUARIO.md', 'tab' => 'Guía del usuario' ],
			'tecnica' => [ 'file' => 'docs/WIKI-TECNICA.md', 'tab' => 'Documentación técnica' ],
		];
	}

	/** Renderiza la página de wiki y termina la request. */
	public static function render( $which = 'usuario' ) {
		$pages = self::pages();
		$which = isset( $pages[ $which ] ) ? $which : 'usuario';

		$path = CEAD_ACAD_DIR . $pages[ $which ]['file'];
		$md   = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		// Reescribir los enlaces cruzados entre wikis a URLs reales del sitio.
		$md = str_replace(
			[ 'WIKI-USUARIO.md', 'WIKI-TECNICA.md' ],
			[ home_url( '/wiki' ), home_url( '/wiki/tecnica' ) ],
			$md
		);

		$body = self::markdown_to_html( $md );

		// Las imágenes del Markdown se referencian relativas a docs/ (ej. img/foo.svg);
		// las resolvemos a la URL real del plugin para que carguen en /wiki.
		$body = str_replace( 'src="img/', 'src="' . esc_url( CEAD_ACAD_URL . 'docs/img/' ), $body );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		// Cacheable: el contenido solo cambia al actualizar el plugin.
		header( 'Cache-Control: public, max-age=3600' );

		$title    = 'usuario' === $which ? __( 'Wiki — Guía del usuario', 'cead-acad' ) : __( 'Wiki — Documentación técnica', 'cead-acad' );
		$site     = get_bloginfo( 'name' );
		$url_user = home_url( '/wiki' );
		$url_tech = home_url( '/wiki/tecnica' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="index, follow">
	<title><?php echo esc_html( $title . ' · ' . $site ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,400&family=Mulish:wght@400;500;600;700;800&display=swap">
	<style>
		/* Tokens tomados del tema CEAD (cead/assets/css/styles.css). */
		:root {
			--brand:#E93B3C; --brand-deep:#B82A2B; --brand-tint:#FCE7E7;
			--acc-blue:#49A3C8; --acc-yellow:#EDDF58; --acc-orange:#F4B74C;
			--ink:#0B0B0B; --ink-soft:#1F1F1F; --text-secondary:#4A4A4A; --text-muted:#8E8E8E;
			--canvas:#F7F5F0; --card:#fff; --border:#E4E1DA; --code:#1F1F1F;
			--font-display:'Anton',system-ui,sans-serif;
			--font-serif:'Cormorant Garamond',Georgia,serif;
			--font-body:'Mulish',system-ui,sans-serif;
		}
		* { box-sizing:border-box; }
		body { margin:0; background:var(--canvas); color:var(--ink-soft); font-family:var(--font-body); line-height:1.7; -webkit-font-smoothing:antialiased; }
		::selection { background:var(--brand-tint); }

		.wiki-top { position:sticky; top:0; z-index:10; background:rgba(247,245,240,.9); backdrop-filter:saturate(180%) blur(10px); border-bottom:1px solid var(--border); }
		.wiki-top-in { max-width:940px; margin:0 auto; padding:14px 20px; display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
		.wiki-brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:var(--ink); }
		.wiki-brand-title { font-family:var(--font-display); font-size:1.5rem; letter-spacing:.01em; line-height:1; }
		.wiki-brand-tag { font-family:var(--font-serif); font-style:italic; font-size:1rem; color:var(--text-muted); }
		.wiki-marks { display:inline-flex; gap:4px; }
		.wiki-marks i { width:11px; height:11px; border-radius:50%; display:block; }
		.wiki-marks i:nth-child(1){ background:var(--brand); }
		.wiki-marks i:nth-child(2){ background:var(--acc-blue); }
		.wiki-marks i:nth-child(3){ background:var(--acc-yellow); }
		.wiki-marks i:nth-child(4){ background:var(--acc-orange); }
		.wiki-tabs { margin-left:auto; display:flex; gap:8px; }
		.wiki-tab { padding:8px 16px; border-radius:999px; border:1px solid var(--border); background:var(--card); color:var(--text-secondary); text-decoration:none; font-size:.85rem; font-weight:700; letter-spacing:.01em; transition:all .15s; }
		.wiki-tab:hover { border-color:var(--brand); color:var(--brand); }
		.wiki-tab.is-active { background:var(--brand); color:#fff; border-color:var(--brand); }

		.wiki-wrap { max-width:940px; margin:0 auto; padding:40px 20px 90px; }
		.wiki-doc { background:var(--card); border:1px solid var(--border); border-radius:18px; padding:clamp(24px,5vw,56px); box-shadow:0 1px 0 rgba(11,11,11,.02), 0 24px 48px -32px rgba(11,11,11,.25); }
		.wiki-doc h1, .wiki-doc h2 { font-family:var(--font-display); font-weight:400; letter-spacing:.005em; line-height:1.08; text-transform:uppercase; }
		.wiki-doc h1 { font-size:clamp(2rem,5vw,2.8rem); margin:.1em 0 .35em; }
		.wiki-doc h2 { font-size:clamp(1.4rem,3.2vw,1.9rem); margin:1.7em 0 .5em; padding-top:.7em; border-top:2px solid var(--border); color:var(--brand-deep); }
		.wiki-doc h3 { font-family:var(--font-body); font-weight:800; font-size:1.12rem; margin:1.5em 0 .4em; color:var(--ink); }
		.wiki-doc h1, .wiki-doc h2, .wiki-doc h3 { scroll-margin-top:90px; }
		.wiki-doc p, .wiki-doc li { color:var(--text-secondary); }
		.wiki-doc a { color:var(--brand); text-underline-offset:2px; }
		.wiki-doc a:hover { color:var(--brand-deep); }
		.wiki-doc strong { color:var(--ink); }
		.wiki-doc code { background:var(--canvas); border:1px solid var(--border); padding:.1em .42em; border-radius:6px; font-size:.86em; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; color:var(--brand-deep); }
		.wiki-doc pre { background:var(--code); color:#f5f3ee; padding:18px 20px; border-radius:14px; overflow:auto; font-size:.8rem; line-height:1.55; }
		.wiki-doc pre code { background:none; border:0; padding:0; color:inherit; }
		.wiki-doc blockquote { margin:1.3em 0; padding:.4em 1.1em; border-left:4px solid var(--brand); background:linear-gradient(90deg,var(--brand-tint),transparent); color:var(--ink-soft); border-radius:0 10px 10px 0; font-family:var(--font-serif); font-size:1.12rem; }
		.wiki-doc blockquote p { color:var(--ink-soft); margin:.4em 0; }
		.wiki-doc table { border-collapse:collapse; width:100%; margin:1.3em 0; font-size:.9rem; display:block; overflow-x:auto; }
		.wiki-doc th, .wiki-doc td { border:1px solid var(--border); padding:9px 13px; text-align:left; vertical-align:top; }
		.wiki-doc th { background:var(--canvas); font-family:var(--font-body); font-weight:800; color:var(--ink); text-transform:uppercase; font-size:.78rem; letter-spacing:.03em; }
		.wiki-doc tr:nth-child(even) td { background:#fbfaf7; }
		.wiki-doc hr { border:0; border-top:1px solid var(--border); margin:2.2em 0; }
		/* Ilustraciones del panel (SVG). */
		.wiki-doc img { display:block; max-width:100%; height:auto; margin:1.4em auto .4em; border:1px solid var(--border); border-radius:14px; background:var(--card); }
		.wiki-doc img[alt] { box-shadow:0 18px 40px -28px rgba(11,11,11,.4); }
		.wiki-doc p > em:only-child { display:block; text-align:center; color:var(--text-muted); font-family:var(--font-serif); font-size:1.02rem; margin-top:-.2em; }

		.wiki-foot { max-width:940px; margin:0 auto; padding:0 20px 60px; color:var(--text-muted); font-size:.85rem; text-align:center; }
		.wiki-foot .wiki-marks { justify-content:center; margin-bottom:10px; }
		@media print {
			.wiki-top, .wiki-foot { display:none; }
			body { background:#fff; }
			.wiki-doc { border:0; border-radius:0; padding:0; box-shadow:none; }
		}
	</style>
</head>
<body>
	<header class="wiki-top">
		<div class="wiki-top-in">
			<a class="wiki-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="wiki-marks" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
				<span class="wiki-brand-title">CEAD</span>
				<span class="wiki-brand-tag">Wiki</span>
			</a>
			<nav class="wiki-tabs">
				<a class="wiki-tab <?php echo 'usuario' === $which ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url_user ); ?>"><?php esc_html_e( 'Guía del usuario', 'cead-acad' ); ?></a>
				<a class="wiki-tab <?php echo 'tecnica' === $which ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url_tech ); ?>"><?php esc_html_e( 'Documentación técnica', 'cead-acad' ); ?></a>
			</nav>
		</div>
	</header>

	<main class="wiki-wrap">
		<article class="wiki-doc">
			<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — HTML generado por Parsedown desde Markdown de confianza (empaquetado en el plugin). ?>
		</article>
	</main>

	<footer class="wiki-foot">
		<span class="wiki-marks" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
		<p>
			<?php echo esc_html( $site ); ?> ·
			<?php
			/* translators: %s: versión del plugin */
			printf( esc_html__( 'Sistema CEAD Académico v%s', 'cead-acad' ), esc_html( CEAD_ACAD_VERSION ) );
			?>
		</p>
	</footer>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Convierte Markdown a HTML usando el Parsedown empaquetado con
	 * plugin-update-checker. Fallback a <pre> si no estuviera disponible.
	 */
	protected static function markdown_to_html( $md ) {
		$parsedown = CEAD_ACAD_DIR . 'vendor/plugin-update-checker/vendor/Parsedown.php';
		if ( file_exists( $parsedown ) ) {
			require_once $parsedown;
		}
		if ( class_exists( 'Parsedown' ) ) {
			$pd = new Parsedown();
			// El Markdown es de confianza, pero escapamos HTML embebido por las dudas.
			if ( method_exists( $pd, 'setMarkupEscaped' ) ) {
				$pd->setMarkupEscaped( true );
			}
			if ( method_exists( $pd, 'setSafeMode' ) ) {
				$pd->setSafeMode( true );
			}
			return $pd->text( $md );
		}
		// Fallback mínimo: mostrar el Markdown crudo, legible y escapado.
		return '<pre>' . esc_html( $md ) . '</pre>';
	}
}
