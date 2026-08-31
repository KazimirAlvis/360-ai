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
			$this->save_failure( $provider->name(), $provider->model(), $context_hash, 'No analysis provider is configured.' );
			throw new \RuntimeException( 'No analysis provider is configured.' );
		}
		$response = $provider->analyze( $context );
		if ( ! $response->is_success() ) {
			$this->save_failure( $response->provider, $response->model, $context_hash, $response->error ?: 'The analysis provider failed.' );
			throw new \RuntimeException( $response->error ?: 'The analysis provider failed.' );
		}
		try {
			$opportunities = $this->validator->validate( $response->opportunities, $context );
		} catch ( \InvalidArgumentException $error ) {
			$this->save_failure( $response->provider, $response->model, $context_hash, 'Structured response failed deterministic validation.' );
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
		);
		$record['analysis_id'] = $this->repository->save( $record );
		return $record;
	}

	private function save_failure( string $provider, string $model, string $context_hash, string $error ): void {
		$this->repository->save( array(
			'provider' => $provider, 'model' => $model, 'context_hash' => $context_hash,
			'status' => 'failed', 'opportunities' => array(),
			'usage' => array( 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0 ),
			'error' => sanitize_text_field( $error ),
		) );
	}
}
