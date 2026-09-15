<?php

namespace Global360\AI\Analysis;

use Global360\AI\AI\ProviderFactory;
use Global360\AI\Storage\AnalysisRepository;

final class OpportunityService {
	private $context;
	private $providers;
	private $validator;
	private $repository;

	public function __construct( AnalysisContextBuilder $context, ProviderFactory $providers, OpportunityValidator $validator, AnalysisRepository $repository ) {
		$this->context = $context; $this->providers = $providers; $this->validator = $validator; $this->repository = $repository;
	}

	/** @return array<string,mixed> */
	public function analyze(): array {
		$context = $this->context->build();
		$context_hash = $this->context->hash( $context );
		if ( empty( $context['platform']['connected'] ) ) {
			throw new \RuntimeException( '360 Platform Core is required for site analysis.' );
		}
		$provider = $this->providers->create();
		if ( ! $provider->is_configured() ) {
			$error = 'anthropic' === $provider->name() ? 'Anthropic is selected but no Anthropic API key is configured.' : 'OpenAI is selected but no OpenAI API key is configured.';
			$this->save_failure( $provider->name(), $provider->model(), $context_hash, $error );
			throw new \RuntimeException( $error );
		}
		$response = $provider->analyze( $context );
		if ( ! $response->is_success() ) {
			$this->save_failure( $response->provider, $response->model, $context_hash, $response->error ?: 'The analysis provider failed.', $response->diagnostics );
			throw new \RuntimeException( $response->error ?: 'The analysis provider failed.' );
		}
		try {
			$opportunities = $this->validator->validate( $response->opportunities, $context );
		} catch ( OpportunityValidationException $error ) {
			$this->save_failure( $response->provider, $response->model, $context_hash, 'Structured response failed deterministic validation.', $error->diagnostics(), $response->usage );
			throw $error;
		}
		$record = array(
			'provider' => $response->provider,
			'model' => $response->model,
			'context_hash' => $context_hash,
			'status' => 'success',
			'opportunities' => $opportunities,
			'usage' => array(
				'input_tokens' => (int) ( $response->usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $response->usage['output_tokens'] ?? 0 ),
				'total_tokens' => (int) ( $response->usage['total_tokens'] ?? 0 ),
			),
			'error' => '',
			'diagnostics' => $this->validator->selection_diagnostics(),
		);
		$record['analysis_id'] = $this->repository->save( $record );
		return $record;
	}

	private function save_failure( string $provider, string $model, string $context_hash, string $error, array $diagnostics = array(), array $usage = array() ): void {
		$safe_diagnostics = array();
		if ( $diagnostics ) {
			$safe_diagnostics = array(
				'http_status'            => max( 0, min( 599, (int) ( $diagnostics['http_status'] ?? 0 ) ) ),
				'provider_error_type'    => substr( sanitize_key( (string) ( $diagnostics['provider_error_type'] ?? '' ) ), 0, 64 ),
				'provider_error_message' => substr( sanitize_text_field( (string) ( $diagnostics['provider_error_message'] ?? '' ) ), 0, 300 ),
				'provider_request_id'    => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $diagnostics['provider_request_id'] ?? '' ) ), 0, 128 ),
				'failure_stage'          => substr( sanitize_key( (string) ( $diagnostics['failure_stage'] ?? '' ) ), 0, 32 ),
				'validation_rule'        => substr( sanitize_key( (string) ( $diagnostics['validation_rule'] ?? '' ) ), 0, 64 ),
				'opportunity_index'      => max( 0, min( 8, (int) ( $diagnostics['opportunity_index'] ?? 0 ) ) ),
				'opportunity_id'         => substr( sanitize_key( (string) ( $diagnostics['opportunity_id'] ?? '' ) ), 0, 64 ),
				'offending_field'        => substr( sanitize_key( (string) ( $diagnostics['offending_field'] ?? '' ) ), 0, 64 ),
				'offending_value'        => substr( preg_replace( '/[^A-Za-z0-9:._-]/', '', (string) ( $diagnostics['offending_value'] ?? '' ) ), 0, 96 ),
				'completion_status'      => substr( sanitize_key( (string) ( $diagnostics['completion_status'] ?? '' ) ), 0, 64 ),
				'stop_reason'            => substr( sanitize_key( (string) ( $diagnostics['stop_reason'] ?? '' ) ), 0, 64 ),
				'candidates_received'     => max( 0, min( 8, (int) ( $diagnostics['candidates_received'] ?? 0 ) ) ),
				'candidates_selected'     => max( 0, min( 5, (int) ( $diagnostics['candidates_selected'] ?? 0 ) ) ),
				'candidates_rejected'     => max( 0, min( 8, (int) ( $diagnostics['candidates_rejected'] ?? 0 ) ) ),
				'rejection_rules'         => $this->sanitize_rejection_rules( (array) ( $diagnostics['rejection_rules'] ?? array() ) ),
			);
		}
		$this->repository->save( array(
			'provider' => $provider, 'model' => $model, 'context_hash' => $context_hash,
			'status' => 'failed', 'opportunities' => array(),
			'usage' => array( 'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ), 'total_tokens' => (int) ( $usage['total_tokens'] ?? 0 ) ),
			'error' => sanitize_text_field( $error ),
			'diagnostics' => $safe_diagnostics,
		) );
	}

	/** @return array<string,int> */
	private function sanitize_rejection_rules( array $rules ): array {
		$clean = array();
		foreach ( array_slice( $rules, 0, 8, true ) as $rule => $count ) { $rule = substr( sanitize_key( (string) $rule ), 0, 64 ); if ( '' !== $rule ) { $clean[ $rule ] = max( 0, min( 8, (int) $count ) ); } }
		ksort( $clean ); return $clean;
	}
}
