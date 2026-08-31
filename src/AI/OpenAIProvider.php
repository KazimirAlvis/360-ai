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
			'instructions' => 'Identify five genuine, non-duplicative educational content gaps for this medical site. Do not invent facts, studies, efficacy, diagnoses, providers, clinics, or locations. Local recommendations must use supplied stable IDs. Return exactly five opportunities.',
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
		$string = array( 'type' => 'string' );
		$ids = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		return array(
			'type' => 'json_schema', 'name' => 'content_opportunities', 'strict' => true,
			'schema' => array(
				'type' => 'object', 'additionalProperties' => false, 'required' => array( 'opportunities' ),
				'properties' => array( 'opportunities' => array(
					'type' => 'array', 'minItems' => 5, 'maxItems' => 5,
					'items' => array(
						'type' => 'object', 'additionalProperties' => false,
						'required' => array( 'id', 'proposed_title', 'suggested_slug', 'content_type', 'category', 'target_topic', 'search_intent', 'rationale', 'audience', 'geo_scope', 'relevant_location_ids', 'relevant_clinic_ids', 'relevant_doctor_ids', 'suggested_internal_link_ids', 'priority', 'confidence' ),
						'properties' => array(
							'id' => $string, 'proposed_title' => $string, 'suggested_slug' => $string,
							'content_type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ) ),
							'category' => array( 'type' => 'string', 'enum' => array( 'informational', 'treatment', 'symptom', 'comparison', 'local', 'faq_aeo' ) ),
							'target_topic' => $string, 'search_intent' => $string, 'rationale' => $string, 'audience' => $string, 'geo_scope' => $string,
							'relevant_location_ids' => array( 'type' => 'array', 'items' => $string ),
							'relevant_clinic_ids' => $ids, 'relevant_doctor_ids' => $ids, 'suggested_internal_link_ids' => $ids,
							'priority' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
							'confidence' => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ),
						),
					),
				) ),
			),
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
