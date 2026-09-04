<?php

namespace Global360\AI\Generation;

use Global360\AI\AI\ProviderFactory;
use Global360\AI\Storage\WorkflowRepository;

final class DraftService {
	private $workflow;
	private $context;
	private $providers;
	private $validator;
	private $lock;

	public function __construct( WorkflowRepository $workflow, DraftGenerationContextBuilder $context, ProviderFactory $providers, DraftValidator $validator, GenerationLock $lock ) {
		$this->workflow = $workflow; $this->context = $context; $this->providers = $providers; $this->validator = $validator; $this->lock = $lock;
	}

	public function generate( string $analysis_id, string $opportunity_id, int $author_id ): int {
		$opportunity = $this->workflow->opportunity( $analysis_id, $opportunity_id );
		$state = $this->workflow->get( $analysis_id, $opportunity_id );
		$existing = $this->existing_draft( $analysis_id, $opportunity_id );
		if ( $existing ) {
			if ( 'approved' === $state['status'] ) { $this->workflow->transition( $analysis_id, $opportunity_id, 'draft_created', $existing ); }
			return $existing;
		}
		if ( 'approved' !== $state['status'] ) { throw new \DomainException( 'Only an approved opportunity can generate a draft.' ); }
		if ( ! $this->lock->acquire( $analysis_id, $opportunity_id ) ) { throw new \RuntimeException( 'Draft generation is already in progress.' ); }
		try {
			$existing = $this->existing_draft( $analysis_id, $opportunity_id );
			if ( $existing ) { $this->workflow->transition( $analysis_id, $opportunity_id, 'draft_created', $existing ); return $existing; }
			$context = $this->context->build( $opportunity );
			$provider = $this->providers->create();
			if ( ! $provider->is_configured() ) { throw new \RuntimeException( 'The selected provider is not configured.' ); }
			$response = $provider->generate_draft( $context );
			if ( ! $response->is_success() ) {
				$this->workflow->record_failure( $analysis_id, $opportunity_id, $response->error, $response->diagnostics );
				$this->workflow->record_generation_attempt( $analysis_id, $opportunity_id, array( 'provider' => $response->provider, 'model' => $response->model, 'status' => 'provider_failed', 'usage' => $response->usage, 'diagnostics' => $response->diagnostics ) );
				throw new \RuntimeException( $response->error );
			}
			try { $draft = $this->validator->validate( $response->draft, $opportunity, $context ); }
			catch ( \InvalidArgumentException $error ) {
				$diagnostics = $error instanceof DraftValidationException ? $error->diagnostics() : array( 'validation_field' => 'draft', 'validation_rule' => 'deterministic_validation' );
				$this->workflow->record_failure( $analysis_id, $opportunity_id, 'Generated draft failed deterministic validation.', $diagnostics );
				$this->workflow->record_generation_attempt( $analysis_id, $opportunity_id, array( 'provider' => $response->provider, 'model' => $response->model, 'status' => 'validation_failed', 'usage' => $response->usage, 'diagnostics' => $diagnostics ) );
				throw $error;
			}
			$post_id = wp_insert_post( array(
				'post_status' => 'draft', 'post_type' => (string) $opportunity['content_type'], 'post_author' => $author_id,
				'post_title' => $draft['title'], 'post_name' => $draft['slug'], 'post_excerpt' => $draft['excerpt'], 'post_content' => $draft['content'],
			), true );
			if ( is_wp_error( $post_id ) || 1 > (int) $post_id ) { throw new \RuntimeException( 'WordPress could not create the draft.' ); }
			$meta = array(
				'_global360_ai_analysis_id' => $analysis_id,
				'_global360_ai_opportunity_id' => $opportunity_id,
				'_global360_ai_provider' => $response->provider,
				'_global360_ai_model' => $response->model,
				'_global360_ai_generated_at' => gmdate( 'c' ),
				'_global360_ai_plugin_version' => defined( 'GLOBAL360_AI_VERSION' ) ? GLOBAL360_AI_VERSION : '',
				'_global360_ai_seo_title' => $draft['seo_title'],
				'_global360_ai_meta_description' => $draft['meta_description'],
				'_global360_ai_suggested_internal_link_ids' => $draft['suggested_internal_link_ids'],
				'_global360_ai_generation_usage' => $response->usage,
			);
			foreach ( $meta as $key => $value ) { update_post_meta( (int) $post_id, $key, $value ); }
			try { $this->workflow->transition( $analysis_id, $opportunity_id, 'draft_created', (int) $post_id ); }
			catch ( \Throwable $error ) { wp_delete_post( (int) $post_id, true ); throw $error; }
			$this->workflow->record_generation_attempt( $analysis_id, $opportunity_id, array( 'provider' => $response->provider, 'model' => $response->model, 'status' => 'draft_created', 'usage' => $response->usage, 'draft_post_id' => (int) $post_id ) );
			return (int) $post_id;
		} finally {
			$this->lock->release( $analysis_id, $opportunity_id );
		}
	}

	private function existing_draft( string $analysis_id, string $opportunity_id ): int {
		$ids = get_posts( array(
			'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_global360_ai_analysis_id', 'value' => $analysis_id ),
				array( 'key' => '_global360_ai_opportunity_id', 'value' => $opportunity_id ),
			),
		) );
		return isset( $ids[0] ) ? absint( $ids[0] ) : 0;
	}
}
