<?php
/**
 * Controller frontend: submit de respuestas vía admin-post.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cead_Acad_Surveys_Frontend {

	public function boot() {
		add_action( 'admin_post_cead_acad_survey_submit', [ $this, 'handle_submit' ] );
		// Permitimos submit anónimo cuando la encuesta lo es; en ese caso requerimos sesión igual
		// para evitar abuso (mantener acceso al panel).
	}

	public function handle_submit() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( cead_acad_url( 'login' ) );
			exit;
		}
		check_admin_referer( 'cead_acad_survey_submit' );

		$survey_id = (int) ( $_POST['survey_id'] ?? 0 );
		if ( ! $survey_id ) {
			$this->bail( 0, 'invalid' );
		}

		$user = wp_get_current_user();

		$visible_ids = Cead_Acad_Audiences::subjects_for_user( 'survey', $user->ID );
		if ( ! $visible_ids || ! in_array( $survey_id, $visible_ids, true ) ) {
			$this->bail( $survey_id, 'forbidden' );
		}
		if ( ! Cead_Acad_Surveys_CPT::is_open( $survey_id ) ) {
			$this->bail( $survey_id, 'closed' );
		}

		$answers = (array) ( $_POST['answers'] ?? [] );
		// Sanitización ya se hace en submit/format_answer_value.

		$result = Cead_Acad_Surveys_Responses::submit( $survey_id, $user->ID, $answers );
		if ( is_wp_error( $result ) ) {
			$this->bail( $survey_id, $result->get_error_code() );
		}

		wp_safe_redirect( add_query_arg( 'done', 1, cead_acad_url( 'panel/encuestas/' . $survey_id ) ) );
		exit;
	}

	protected function bail( $survey_id, $code ) {
		$dest = $survey_id ? cead_acad_url( 'panel/encuestas/' . $survey_id ) : cead_acad_url( 'panel/encuestas' );
		wp_safe_redirect( add_query_arg( 'err', $code, $dest ) );
		exit;
	}
}
