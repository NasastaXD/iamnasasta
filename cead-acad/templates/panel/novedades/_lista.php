<?php
/**
 * La lista de novedades. La usan el panel (/panel/novedades) y wp-admin
 * (CEAD Académico → Novedades): el mismo dibujo, para que lo que se ve en un
 * lado sea lo que se ve en el otro.
 *
 * Variables:
 *   $entradas      array   las entradas ya filtradas (Cead_Acad_Changelog::para)
 *   $mirada        string  'todos' | 'equipo' | 'admin'
 *   $visto         string  la última versión que vio la persona ('' si ninguna)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$entradas = $entradas ?? [];
$mirada   = $mirada ?? 'todos';
$visto    = $visto ?? '';
$es_admin = 'admin' === $mirada;
$frescas  = Cead_Acad_Changelog::frescas( $entradas, $visto );
$totales  = Cead_Acad_Changelog::contar_todas( $entradas );

// Los cuatro colores de la marca, uno por versión, para el hilo de tiempo.
$colores = [ 'brand', 'blue', 'yellow', 'orange' ];
?>
<div class="cead-acad-nov<?php echo $es_admin ? ' cead-acad-nov--admin' : ''; ?>">

<?php if ( ! $entradas ) : ?>
	<div class="cead-acad-card cead-acad-card--empty">
		<h3><?php esc_html_e( 'Todavía no hay novedades', 'cead-acad' ); ?></h3>
		<p><?php esc_html_e( 'Cuando el CEAD sume algo nuevo al panel o a la app, lo vas a ver acá.', 'cead-acad' ); ?></p>
	</div>
<?php else : ?>

	<?php
	/*
	 * Filtros SIN JavaScript: un grupo de radios arriba y la lista abajo, y el CSS
	 * oculta lo que no corresponde según cuál está marcado. Funciona aunque el
	 * script no cargue (red lenta, bloqueador) y se maneja con el teclado.
	 */
	$filtros = [ 'todo' => [ __( 'Todo', 'cead-acad' ), array_sum( $totales ) ] ];
	foreach ( Cead_Acad_Changelog::TIPOS as $slug => $_n ) {
		if ( ! empty( $totales[ $slug ] ) ) {
			$filtros[ $slug ] = [ Cead_Acad_Changelog::etiqueta( $slug, true ), $totales[ $slug ] ];
		}
	}
	?>
	<?php foreach ( $filtros as $slug => $_f ) : ?>
		<input class="cead-acad-nov-radio" type="radio" name="cead-nov-filtro" id="cead-nov-f-<?php echo esc_attr( $slug ); ?>" <?php checked( 'todo', $slug ); ?>>
	<?php endforeach; ?>

	<div class="cead-acad-nov-filtros" role="group" aria-label="<?php esc_attr_e( 'Filtrar las novedades', 'cead-acad' ); ?>">
		<?php foreach ( $filtros as $slug => $f ) : ?>
			<label class="cead-acad-nov-filtro cead-acad-nov-filtro--<?php echo esc_attr( $slug ); ?>" for="cead-nov-f-<?php echo esc_attr( $slug ); ?>">
				<?php echo esc_html( $f[0] ); ?> <span class="cead-acad-nov-filtro-n"><?php echo (int) $f[1]; ?></span>
			</label>
		<?php endforeach; ?>
	</div>

	<ol class="cead-acad-nov-linea">
		<?php foreach ( $entradas as $i => $e ) :
			$fresca = in_array( (string) $e['version'], $frescas, true );
			$color  = $colores[ $i % count( $colores ) ];
			$cuenta = Cead_Acad_Changelog::contar( $e );
		?>
			<li class="cead-acad-nov-release<?php echo 0 === $i ? ' cead-acad-nov-release--ultima' : ''; ?><?php echo $fresca ? ' is-fresca' : ''; ?>" id="<?php echo esc_attr( Cead_Acad_Changelog::ancla( $e['version'] ) ); ?>">
				<span class="cead-acad-nov-nodo cead-acad-nov-nodo--<?php echo esc_attr( $color ); ?>" aria-hidden="true"></span>

				<header class="cead-acad-nov-cab">
					<?php if ( 0 === $i ) : ?>
						<span class="cead-acad-nov-marcas" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
					<?php endif; ?>
					<div class="cead-acad-nov-meta">
						<span class="cead-acad-nov-ver"><?php /* translators: %s: número de versión */ printf( esc_html__( 'Versión %s', 'cead-acad' ), esc_html( $e['version'] ) ); ?></span>
						<time datetime="<?php echo esc_attr( $e['fecha'] ); ?>"><?php echo esc_html( Cead_Acad_Changelog::fecha_larga( $e['fecha'] ) ); ?></time>
						<?php if ( $fresca ) : ?>
							<span class="cead-acad-nov-pill"><?php esc_html_e( 'Nuevo', 'cead-acad' ); ?></span>
						<?php endif; ?>
					</div>
					<h3 class="cead-acad-nov-titulo"><?php echo esc_html( $e['titulo'] ); ?></h3>
					<p class="cead-acad-nov-resumen"><?php echo esc_html( $e['resumen'] ); ?></p>
					<?php if ( 0 === $i && $cuenta ) : ?>
						<p class="cead-acad-nov-cuenta">
							<?php foreach ( $cuenta as $tipo => $n ) : ?>
								<span class="cead-acad-nov-chip cead-acad-nov-chip--<?php echo esc_attr( $tipo ); ?>"><?php echo (int) $n; ?> <?php echo esc_html( mb_strtolower( Cead_Acad_Changelog::etiqueta( $tipo, $n > 1 ) ) ); ?></span>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				</header>

				<ul class="cead-acad-nov-items">
					<?php foreach ( $e['items'] as $item ) :
						$tipo = (string) $item['tipo'];
						$para = (string) ( $item['para'] ?? 'todos' );
					?>
						<li class="cead-acad-nov-item cead-acad-nov-item--<?php echo esc_attr( $tipo ); ?>" data-tipo="<?php echo esc_attr( $tipo ); ?>">
							<div class="cead-acad-nov-chips">
								<span class="cead-acad-nov-chip cead-acad-nov-chip--<?php echo esc_attr( $tipo ); ?>"><?php echo esc_html( Cead_Acad_Changelog::etiqueta( $tipo ) ); ?></span>
								<?php if ( ! empty( $item['area'] ) ) : ?>
									<span class="cead-acad-nov-chip cead-acad-nov-chip--area"><?php echo esc_html( $item['area'] ); ?></span>
								<?php endif; ?>
								<?php if ( 'equipo' === $para ) : ?>
									<span class="cead-acad-nov-chip cead-acad-nov-chip--equipo"><?php esc_html_e( 'Para el equipo', 'cead-acad' ); ?></span>
								<?php elseif ( 'admin' === $para ) : ?>
									<span class="cead-acad-nov-chip cead-acad-nov-chip--equipo"><?php esc_html_e( 'Solo wp-admin', 'cead-acad' ); ?></span>
								<?php endif; ?>
							</div>
							<h4 class="cead-acad-nov-item-titulo"><?php echo esc_html( $item['titulo'] ); ?></h4>
							<p class="cead-acad-nov-item-texto"><?php echo esc_html( $item['texto'] ); ?></p>
							<?php if ( $es_admin && ! empty( $item['admin'] ) ) : ?>
								<div class="cead-acad-nov-admin">
									<strong><?php esc_html_e( 'Para quien administra', 'cead-acad' ); ?></strong>
									<p><?php echo esc_html( $item['admin'] ); ?></p>
								</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</li>
		<?php endforeach; ?>
	</ol>

<?php endif; ?>
</div>
