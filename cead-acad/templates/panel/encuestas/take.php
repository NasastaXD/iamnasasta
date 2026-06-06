<?php
/**
 * Tomar encuesta: /panel/encuestas/{id}
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$survey_id = isset( $survey_id ) ? (int) $survey_id : 0;
$user      = wp_get_current_user();
$post      = $survey_id ? get_post( $survey_id ) : null;

$err  = isset( $_GET['err']  ) ? sanitize_key( $_GET['err'] )  : '';
$done = isset( $_GET['done'] );

$can_view = $post && Cead_Acad_Surveys_CPT::POST_TYPE === $post->post_type && in_array( (int) $survey_id, Cead_Acad_Audiences::subjects_for_user( 'survey', $user->ID ), true );

if ( ! $can_view ) {
	status_header( 404 );
	$page_title = __( 'No encontrada', 'cead-acad' );
	$body = function () {
		?>
		<section class="cead-acad-panel-section">
			<span class="cead-acad-eyebrow"><?php esc_html_e( 'Error', 'cead-acad' ); ?></span>
			<h2 class="cead-acad-panel-h"><?php esc_html_e( 'Encuesta no disponible', 'cead-acad' ); ?></h2>
			<p><a class="cead-acad-btn cead-acad-btn--ghost" href="<?php echo esc_url( cead_acad_url( 'panel/encuestas' ) ); ?>">← <?php esc_html_e( 'Volver', 'cead-acad' ); ?></a></p>
		</section>
		<?php
	};
	include CEAD_ACAD_DIR . 'templates/panel/shell.php';
	return;
}

$is_open      = Cead_Acad_Surveys_CPT::is_open( $survey_id );
$is_anon      = Cead_Acad_Surveys_CPT::is_anonymous( $survey_id );
$already      = ! $is_anon && Cead_Acad_Surveys_Responses::user_already_responded( $survey_id, $user->ID );
$questions    = Cead_Acad_Surveys_Questions::for_survey( $survey_id );

$page_title = get_the_title( $post );

$body = function () use ( $post, $questions, $is_open, $is_anon, $already, $done, $err ) {
	?>
	<article class="cead-acad-panel-section cead-acad-bcast">
		<p class="cead-acad-bcast-back">
			<a href="<?php echo esc_url( cead_acad_url( 'panel/encuestas' ) ); ?>">← <?php esc_html_e( 'Encuestas', 'cead-acad' ); ?></a>
		</p>
		<header class="cead-acad-bcast-head">
			<span class="cead-acad-eyebrow">
				<?php echo $is_anon ? esc_html__( 'Anónima', 'cead-acad' ) : esc_html__( 'Nominal', 'cead-acad' ); ?>
				<?php
				$closes = (string) get_post_meta( $post->ID, '_cead_acad_survey_closes_at', true );
				if ( $closes ) : ?> · <?php printf( esc_html__( 'Cierra el %s', 'cead-acad' ), esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $closes ) ) ); ?>
				<?php endif; ?>
			</span>
			<h1 class="cead-acad-bcast-title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
		</header>

		<?php if ( $post->post_content ) : ?>
			<div class="cead-acad-bcast-body">
				<?php echo apply_filters( 'the_content', $post->post_content ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $done ) : ?>
			<div class="cead-acad-msg cead-acad-msg--ok" style="margin-top:1.5rem"><?php esc_html_e( '¡Gracias! Tu respuesta fue registrada.', 'cead-acad' ); ?></div>
		<?php endif; ?>

		<?php if ( $err ) :
			$labels = [
				'closed'           => __( 'La encuesta está cerrada.', 'cead-acad' ),
				'forbidden'        => __( 'No tenés permiso para responder esta encuesta.', 'cead-acad' ),
				'already_responded'=> __( 'Ya respondiste esta encuesta.', 'cead-acad' ),
				'required_missing' => __( 'Faltan respuestas obligatorias.', 'cead-acad' ),
				'invalid'          => __( 'Solicitud inválida.', 'cead-acad' ),
				'no_questions'     => __( 'La encuesta no tiene preguntas configuradas.', 'cead-acad' ),
			];
			?>
			<div class="cead-acad-msg cead-acad-msg--err" style="margin-top:1.5rem"><?php echo esc_html( $labels[ $err ] ?? __( 'Error.', 'cead-acad' ) ); ?></div>
		<?php endif; ?>

		<?php if ( ! $is_open ) : ?>
			<div class="cead-acad-msg" style="margin-top:1.5rem"><?php esc_html_e( 'La encuesta está cerrada y no acepta más respuestas.', 'cead-acad' ); ?></div>
		<?php elseif ( $already ) : ?>
			<div class="cead-acad-msg cead-acad-msg--ok" style="margin-top:1.5rem"><?php esc_html_e( 'Ya respondiste esta encuesta. ¡Gracias!', 'cead-acad' ); ?></div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cead-acad-form" style="margin-top:2rem">
				<?php wp_nonce_field( 'cead_acad_survey_submit' ); ?>
				<input type="hidden" name="action" value="cead_acad_survey_submit">
				<input type="hidden" name="survey_id" value="<?php echo (int) $post->ID; ?>">

				<?php foreach ( $questions as $i => $q ) : ?>
					<fieldset class="cead-acad-field cead-acad-question">
						<legend class="cead-acad-label">
							<?php echo esc_html( ( $i + 1 ) . '. ' . $q['text'] ); ?>
							<?php if ( $q['required'] ) : ?><span style="color:var(--cead-acad-brand)">*</span><?php endif; ?>
						</legend>

						<?php
						$name = 'answers[' . (int) $q['id'] . ']';
						switch ( $q['type'] ) :
							case 'radio': ?>
								<?php foreach ( (array) ( $q['config']['options'] ?? [] ) as $opt ) : ?>
									<label class="cead-acad-checkbox">
										<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo $q['required'] ? 'required' : ''; ?>>
										<span><?php echo esc_html( $opt ); ?></span>
									</label>
								<?php endforeach; ?>
								<?php break;

							case 'checkbox': ?>
								<?php foreach ( (array) ( $q['config']['options'] ?? [] ) as $opt ) : ?>
									<label class="cead-acad-checkbox">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $opt ); ?>">
										<span><?php echo esc_html( $opt ); ?></span>
									</label>
								<?php endforeach; ?>
								<?php break;

							case 'text': ?>
								<input type="text" name="<?php echo esc_attr( $name ); ?>" <?php echo $q['required'] ? 'required' : ''; ?>>
								<?php break;

							case 'long_text': ?>
								<textarea name="<?php echo esc_attr( $name ); ?>" rows="4" <?php echo $q['required'] ? 'required' : ''; ?> style="font-family:var(--cead-acad-font-body);font-size:1rem;padding:0.85rem 1rem;border:1px solid var(--cead-acad-border)"></textarea>
								<?php break;

							case 'scale':
								$min = (int) ( $q['config']['min'] ?? 1 );
								$max = (int) ( $q['config']['max'] ?? 5 );
								?>
								<div style="display:flex;gap:8px;flex-wrap:wrap">
									<?php for ( $v = $min; $v <= $max; $v++ ) : ?>
										<label class="cead-acad-checkbox" style="border:1px solid var(--cead-acad-border);padding:0.4rem 0.7rem">
											<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $v; ?>" <?php echo $q['required'] ? 'required' : ''; ?>>
											<span><?php echo (int) $v; ?></span>
										</label>
									<?php endfor; ?>
								</div>
								<?php break;

							case 'date': ?>
								<input type="date" name="<?php echo esc_attr( $name ); ?>" <?php echo $q['required'] ? 'required' : ''; ?>>
								<?php break;
						endswitch; ?>
					</fieldset>
				<?php endforeach; ?>

				<button type="submit" class="cead-acad-btn cead-acad-btn--primary" style="margin-top:1.5rem"><?php esc_html_e( 'Enviar respuestas', 'cead-acad' ); ?></button>
			</form>
		<?php endif; ?>
	</article>
	<?php
};

include CEAD_ACAD_DIR . 'templates/panel/shell.php';
