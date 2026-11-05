<?php

namespace Global360\AI\AI;

use Global360\AI\Generation\DraftRequest;
use Global360\AI\Generation\DraftResponse;

final class AnthropicProvider implements ProviderInterface {
	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION = '2023-06-01';
	private const UNSUPPORTED_SCHEMA_KEYWORDS = array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems', 'minLength', 'maxLength' );
	private $api_key;
	private $model;

	public function __construct( string $api_key, string $model = 'claude-sonnet-5' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	public function name(): string { return 'anthropic'; }
	public function model(): string { return $this->model; }
	public function is_configured(): bool { return '' !== $this->api_key; }

	public function analyze( array $context ): AnalysisResponse {
		if ( ! $this->is_configured() ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'Anthropic is selected but no Anthropic API key is configured.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body' => wp_json_encode( array(
					'model'       => $this->model(),
					'max_tokens'  => 8192,
					'system'      => OpportunityRequest::instructions(),
					'messages'    => array( array( 'role' => 'user', 'content' => wp_json_encode( $context ) ) ),
					'output_config' => array( 'format' => array( 'type' => 'json_schema', 'schema' => self::schema() ) ),
				) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'The Anthropic request failed.', $this->transport_diagnostics( $response ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 > $status || 300 <= $status ) {
			$diagnostics = $this->error_diagnostics( $response, $body, $status );
			$type = '' !== $diagnostics['provider_error_type'] ? ': ' . $diagnostics['provider_error_type'] : '';
			return AnalysisResponse::failure( $this->name(), $this->model(), sprintf( 'Anthropic request failed (HTTP %d%s).', $status, $type ), $diagnostics );
		}
		if ( ! is_array( $body ) ) {
			return AnalysisResponse::failure( $this->name(), $this->model(), 'Anthropic returned invalid structured output.' );
		}

		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) ) { $text = (string) ( $part['text'] ?? '' ); break; }
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) || ! isset( $data['opportunities'] ) || ! is_array( $data['opportunities'] ) ) {
			return AnalysisResponse::failure( $this->name(), (string) ( $body['model'] ?? $this->model() ), 'Anthropic returned invalid structured output.' );
		}

		$usage  = (array) ( $body['usage'] ?? array() );
		$input  = (int) ( $usage['input_tokens'] ?? 0 );
		$output = (int) ( $usage['output_tokens'] ?? 0 );
		return new AnalysisResponse(
			$data['opportunities'],
			$this->name(),
			(string) ( $body['model'] ?? $this->model() ),
			array( 'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output )
		);
	}

	public function generate_draft( array $context ): DraftResponse {
		if ( ! $this->is_configured() ) { return DraftResponse::failure( $this->name(), $this->model(), 'Anthropic is selected but no Anthropic API key is configured.' ); }
		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 60,
			'headers' => array( 'x-api-key' => $this->api_key, 'anthropic-version' => self::API_VERSION, 'content-type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'model' => $this->model(), 'max_tokens' => 8192, 'system' => DraftRequest::instructions(),
				'messages' => array( array( 'role' => 'user', 'content' => wp_json_encode( $context ) ) ),
				'output_config' => array( 'format' => array( 'type' => 'json_schema', 'schema' => self::draft_schema() ) ),
			) ),
		) );
		if ( is_wp_error( $response ) ) { return DraftResponse::failure( $this->name(), $this->model(), 'The Anthropic draft request failed.', $this->transport_diagnostics( $response ) ); }
		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 > $status || 300 <= $status ) { return DraftResponse::failure( $this->name(), $this->model(), 'Anthropic draft request failed.', $this->error_diagnostics( $response, $body, $status ) ); }
		if ( ! is_array( $body ) ) { return DraftResponse::failure( $this->name(), $this->model(), 'Anthropic returned invalid draft output.' ); }
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $part ) { if ( 'text' === ( $part['type'] ?? '' ) ) { $text = (string) ( $part['text'] ?? '' ); break; } }
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) { return DraftResponse::failure( $this->name(), (string) ( $body['model'] ?? $this->model() ), 'Anthropic returned invalid draft output.' ); }
		$usage = (array) ( $body['usage'] ?? array() ); $input = (int) ( $usage['input_tokens'] ?? 0 ); $output = (int) ( $usage['output_tokens'] ?? 0 );
		return new DraftResponse( $data, $this->name(), (string) ( $body['model'] ?? $this->model() ), array( 'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output ), '', array(), $this->completion_metadata( $body ) );
	}

	/** @return array<string,mixed> */
	private function completion_metadata( array $body ): array {
		$reason = substr( sanitize_key( (string) ( $body['stop_reason'] ?? '' ) ), 0, 64 );
		$limited = 'max_tokens' === $reason;
		return array(
			'completion_status' => $limited ? 'incomplete' : ( 'refusal' === $reason ? 'refused' : 'completed' ),
			'stop_reason' => $reason,
			'output_limit_reached' => $limited,
		);
	}

	/** @return array<string,mixed> */
	public static function schema(): array {
		return self::sanitize_schema( OpportunityRequest::schema() );
	}

	/** @return array<string,mixed> */
	public static function draft_schema(): array { return self::sanitize_schema( DraftRequest::schema() ); }

	/** @param mixed $value @return mixed */
	private static function sanitize_schema( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		$clean = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( $key, self::UNSUPPORTED_SCHEMA_KEYWORDS, true ) ) { continue; }
			$clean[ $key ] = self::sanitize_schema( $item );
		}
		return $clean;
	}

	/** @return array<string,mixed> */
	private function error_diagnostics( $response, $body, int $status ): array {
		$error = is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
		$request_id = is_array( $body ) ? (string) ( $body['request_id'] ?? '' ) : '';
		if ( '' === $request_id ) { $request_id = (string) wp_remote_retrieve_header( $response, 'request-id' ); }
		return array(
			'http_status'            => $status,
			'provider_error_type'    => substr( sanitize_key( (string) ( $error['type'] ?? '' ) ), 0, 64 ),
			'provider_error_message' => substr( sanitize_text_field( (string) ( $error['message'] ?? '' ) ), 0, 300 ),
			'provider_request_id'    => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $request_id ), 0, 128 ),
		);
	}

	/** @return array<string,string> */
	private function transport_diagnostics( $error ): array {
		$code    = method_exists( $error, 'get_error_code' ) ? (string) $error->get_error_code() : '';
		$message = method_exists( $error, 'get_error_message' ) ? (string) $error->get_error_message() : '';
		$message = preg_replace( '/sk-ant-[A-Za-z0-9_-]+/', '[redacted]', $message );
		$message = preg_replace( '/Bearer\s+\S+/i', 'Bearer [redacted]', $message );

		return array(
			'failure_stage'          => 'transport',
			'transport_error_code'   => substr( sanitize_key( $code ), 0, 64 ),
			'transport_error_message' => substr( sanitize_text_field( $message ), 0, 300 ),
		);
	}
}
