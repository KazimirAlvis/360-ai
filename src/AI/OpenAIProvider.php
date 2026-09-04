<?php

namespace Global360\AI\AI;

use Global360\AI\Generation\DraftRequest;
use Global360\AI\Generation\DraftResponse;

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

	public function generate_draft( array $context ): DraftResponse {
		if ( ! $this->is_configured() ) { return DraftResponse::failure( $this->name(), $this->model(), 'OpenAI is not configured.' ); }
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'model' => $this->model(), 'store' => false, 'instructions' => DraftRequest::instructions(),
				'input' => wp_json_encode( $context ),
				'text' => array( 'format' => self::draft_format() ),
			) ),
		) );
		if ( is_wp_error( $response ) ) { return DraftResponse::failure( $this->name(), $this->model(), 'The provider request failed.' ); }
		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 > $status || 300 <= $status ) { return DraftResponse::failure( $this->name(), $this->model(), 'OpenAI draft request failed.', $this->error_diagnostics( $response, $body, $status ) ); }
		if ( ! is_array( $body ) ) { return DraftResponse::failure( $this->name(), $this->model(), 'OpenAI returned invalid draft output.' ); }
		$data = json_decode( $this->output_text( $body ), true );
		if ( ! is_array( $data ) ) { return DraftResponse::failure( $this->name(), (string) ( $body['model'] ?? $this->model() ), 'OpenAI returned invalid draft output.' ); }
		$usage = (array) ( $body['usage'] ?? array() );
		return new DraftResponse( $data, $this->name(), (string) ( $body['model'] ?? $this->model() ), array(
			'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ), 'total_tokens' => (int) ( $usage['total_tokens'] ?? 0 ),
		) );
	}

	/** @return array<string,mixed> */
	public static function format(): array {
		return array(
			'type' => 'json_schema', 'name' => 'content_opportunities', 'strict' => true,
			'schema' => OpportunityRequest::schema(),
		);
	}

	/** @return array<string,mixed> */
	public static function draft_format(): array {
		return array( 'type' => 'json_schema', 'name' => 'content_draft', 'strict' => true, 'schema' => DraftRequest::schema() );
	}

	/** @return array<string,mixed> */
	private function error_diagnostics( $response, $body, int $status ): array {
		$error = is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
		$request_id = is_array( $body ) ? (string) ( $body['request_id'] ?? '' ) : '';
		if ( '' === $request_id ) { $request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' ); }
		return array(
			'http_status' => $status,
			'provider_error_type' => substr( sanitize_key( (string) ( $error['type'] ?? '' ) ), 0, 64 ),
			'provider_error_message' => substr( sanitize_text_field( (string) ( $error['message'] ?? '' ) ), 0, 300 ),
			'provider_request_id' => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $request_id ), 0, 128 ),
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
