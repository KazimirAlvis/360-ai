<?php

namespace Global360\AI\AI;

final class OpenAIProvider implements ProviderInterface {
	private $api_key;
	private $model;

	public function __construct( string $api_key, string $model = 'gpt-5-mini' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	public function name(): string { return 'openai'; }
	public function model(): string { return $this->model; }
	public function is_configured(): bool { return '' !== $this->api_key; }

	public function analyze( array $context ): AnalysisResponse {
		if ( ! $this->is_configured() ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'OpenAI is not configured.' );
		}

		$payload = array(
			'model' => $this->model(),
			'store' => false,
			'instructions' => OpportunityRequest::instructions(),
			'input' => wp_json_encode( $context ),
			'text'  => array( 'format' => self::format() ),
		);
		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 60,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'The provider request failed.' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 > $status || 300 <= $status || ! is_array( $body ) ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'The provider returned an HTTP error.' );
		}
		$text = $this->output_text( $body );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) || ! isset( $data['opportunities'] ) || ! is_array( $data['opportunities'] ) ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'The provider returned invalid structured output.' );
		}
		$usage = (array) ( $body['usage'] ?? array() );
		return new AnalysisResponse(
			$data['opportunities'],
			$this->name(),
			(string) ( $body['model'] ?? $this->model() ),
			array(
				'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
				'total_tokens'  => (int) ( $usage['total_tokens'] ?? 0 ),
			)
		);
	}

	/** @return array<string,mixed> */
	public static function format(): array {
		return array(
			'type' => 'json_schema', 'name' => 'content_opportunities', 'strict' => true,
			'schema' => OpportunityRequest::schema(),
		);
	}

	private function output_text( array $body ): string {
		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				if ( 'output_text' === ( $part['type'] ?? '' ) ) { return (string) ( $part['text'] ?? '' ); }
			}
		}
		return '';
	}
}
