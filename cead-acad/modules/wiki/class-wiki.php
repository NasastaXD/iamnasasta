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
	<style>
		:root { --bg:#F7F5F0; --ink:#1c1b1a; --muted:#5b5953; --accent:#E93B3C; --card:#fff; --line:#e7e3da; --code:#2b2a28; }
		* { box-sizing:border-box; }
		body { margin:0; background:var(--bg); color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.65; }
		.wiki-top { position:sticky; top:0; z-index:10; background:rgba(247,245,240,.92); backdrop-filter:saturate(180%) blur(8px); border-bottom:1px solid var(--line); }
		.wiki-top-in { max-width:920px; margin:0 auto; padding:12px 20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
		.wiki-brand { font-weight:800; letter-spacing:.02em; color:var(--accent); text-decoration:none; font-size:1.05rem; }
		.wiki-brand span { color:var(--ink); }
		.wiki-tabs { margin-left:auto; display:flex; gap:8px; }
		.wiki-tab { padding:7px 14px; border-radius:999px; border:1px solid var(--line); background:var(--card); color:var(--muted); text-decoration:none; font-size:.9rem; font-weight:600; }
		.wiki-tab.is-active { background:var(--accent); color:#fff; border-color:var(--accent); }
		.wiki-wrap { max-width:920px; margin:0 auto; padding:32px 20px 80px; }
		.wiki-doc { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:32px clamp(20px,4vw,48px); }
		.wiki-doc h1 { font-size:1.9rem; line-height:1.2; margin:.2em 0 .5em; }
		.wiki-doc h2 { font-size:1.4rem; margin:1.8em 0 .5em; padding-top:.4em; border-top:1px solid var(--line); }
		.wiki-doc h3 { font-size:1.12rem; margin:1.4em 0 .4em; }
		.wiki-doc h1, .wiki-doc h2, .wiki-doc h3 { scroll-margin-top:80px; }
		.wiki-doc a { color:var(--accent); }
		.wiki-doc code { background:#f1ede4; padding:.12em .4em; border-radius:6px; font-size:.88em; }
		.wiki-doc pre { background:var(--code); color:#f5f3ee; padding:16px 18px; border-radius:12px; overflow:auto; font-size:.82rem; line-height:1.5; }
		.wiki-doc pre code { background:none; padding:0; color:inherit; }
		.wiki-doc blockquote { margin:1em 0; padding:.6em 1em; border-left:4px solid var(--accent); background:#faf8f3; color:var(--muted); border-radius:0 8px 8px 0; }
		.wiki-doc table { border-collapse:collapse; width:100%; margin:1.2em 0; font-size:.92rem; display:block; overflow-x:auto; }
		.wiki-doc th, .wiki-doc td { border:1px solid var(--line); padding:8px 12px; text-align:left; vertical-align:top; }
		.wiki-doc th { background:#f1ede4; }
		.wiki-doc hr { border:0; border-top:1px solid var(--line); margin:2em 0; }
		.wiki-doc img { max-width:100%; height:auto; }
		.wiki-foot { max-width:920px; margin:0 auto; padding:0 20px 60px; color:var(--muted); font-size:.85rem; text-align:center; }
		.wiki-foot a { color:var(--accent); }
		@media print {
			.wiki-top, .wiki-foot { display:none; }
			body { background:#fff; }
			.wiki-doc { border:0; border-radius:0; padding:0; }
		}
	</style>
</head>
<body>
	<header class="wiki-top">
		<div class="wiki-top-in">
			<a class="wiki-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">CEAD <span>· Wiki</span></a>
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
