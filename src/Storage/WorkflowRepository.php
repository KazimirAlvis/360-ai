<?php

namespace Global360\AI\Storage;

final class WorkflowRepository {
	private const OPTION = 'global360_ai_opportunity_workflow';
	private const STATES = array( 'pending', 'approved', 'rejected', 'draft_created' );
	private $analyses;

	public function __construct( AnalysisRepository $analyses ) { $this->analyses = $analyses; }

	/** @return array<string,mixed> */
	public function get( string $analysis_id, string $opportunity_id ): array {
		$this->opportunity( $analysis_id, $opportunity_id );
		$records = $this->records();
		$key = $this->key( $analysis_id, $opportunity_id );
		return isset( $records[ $key ] ) && is_array( $records[ $key ] ) ? $records[ $key ] : array(
			'analysis_id' => $analysis_id, 'opportunity_id' => $opportunity_id, 'status' => 'pending',
			'created_at' => '', 'updated_at' => '', 'draft_post_id' => 0,
		);
	}

	/** @return array<string,mixed> */
	public function transition( string $analysis_id, string $opportunity_id, string $to, int $draft_post_id = 0 ): array {
		if ( ! in_array( $to, self::STATES, true ) ) { throw new \InvalidArgumentException( 'Unknown workflow state.' ); }
		$current = $this->get( $analysis_id, $opportunity_id );
		$allowed = array(
			'pending' => array( 'approved', 'rejected' ),
			'approved' => array( 'pending', 'draft_created' ),
			'rejected' => array( 'pending' ),
			'draft_created' => array(),
		);
		if ( ! in_array( $to, $allowed[ $current['status'] ] ?? array(), true ) ) { throw new \DomainException( 'Workflow transition is not allowed.' ); }
		if ( 'draft_created' === $to && 1 > $draft_post_id ) { throw new \InvalidArgumentException( 'Draft creation requires a valid post ID.' ); }
		$now = gmdate( 'c' );
		$current['status'] = $to;
		$current['created_at'] = $current['created_at'] ?: $now;
		$current['updated_at'] = $now;
		if ( 'draft_created' === $to ) {
			$current['draft_post_id'] = $draft_post_id;
			unset( $current['generation_error'], $current['generation_diagnostics'] );
		}
		$records = $this->records();
		$records[ $this->key( $analysis_id, $opportunity_id ) ] = $current;
		update_option( self::OPTION, $records, false );
		return $current;
	}

	public function record_failure( string $analysis_id, string $opportunity_id, string $message, array $diagnostics = array() ): void {
		$current = $this->get( $analysis_id, $opportunity_id );
		$current['generation_error'] = substr( sanitize_text_field( $message ), 0, 300 );
		$current['generation_diagnostics'] = $this->sanitize_diagnostics( $diagnostics );
		$current['updated_at'] = gmdate( 'c' );
		$records = $this->records();
		$records[ $this->key( $analysis_id, $opportunity_id ) ] = $current;
		update_option( self::OPTION, $records, false );
	}

	/** @return array<string,mixed> */
	public function record_conversion( string $analysis_id, string $opportunity_id, int $content_draft_id, int $final_post_id, string $final_post_type ): array {
		if ( 1 > $content_draft_id || 1 > $final_post_id || ! in_array( $final_post_type, array( 'page', 'post' ), true ) ) {
			throw new \InvalidArgumentException( 'Conversion requires valid content and final draft details.' );
		}
		$current = $this->get( $analysis_id, $opportunity_id );
		if ( 'draft_created' !== ( $current['status'] ?? '' ) || $content_draft_id !== (int) ( $current['draft_post_id'] ?? 0 ) ) {
			throw new \DomainException( 'Only the workflow Content Draft can be converted.' );
		}
		if ( ! empty( $current['final_post_id'] ) ) {
			if ( $final_post_id !== (int) $current['final_post_id'] || $final_post_type !== (string) ( $current['final_post_type'] ?? '' ) ) {
				throw new \DomainException( 'The workflow is already linked to a different final draft.' );
			}
			return $current;
		}
		$current['final_post_id'] = $final_post_id;
		$current['final_post_type'] = $final_post_type;
		$current['converted_at'] = gmdate( 'c' );
		$current['updated_at'] = $current['converted_at'];
		$records = $this->records();
		$records[ $this->key( $analysis_id, $opportunity_id ) ] = $current;
		update_option( self::OPTION, $records, false );
		return $current;
	}

	/** @param array<string,mixed> $attempt */
	public function record_generation_attempt( string $analysis_id, string $opportunity_id, array $attempt ): void {
		$current = $this->get( $analysis_id, $opportunity_id );
		$attempts = isset( $current['generation_attempts'] ) && is_array( $current['generation_attempts'] ) ? $current['generation_attempts'] : array();
		$usage = isset( $attempt['usage'] ) && is_array( $attempt['usage'] ) ? $attempt['usage'] : array();
		$attempts[] = array(
			'analysis_id' => substr( sanitize_text_field( $analysis_id ), 0, 128 ),
			'opportunity_id' => substr( sanitize_text_field( $opportunity_id ), 0, 128 ),
			'timestamp' => gmdate( 'c' ),
			'provider' => substr( sanitize_key( (string) ( $attempt['provider'] ?? '' ) ), 0, 64 ),
			'model' => substr( sanitize_text_field( (string) ( $attempt['model'] ?? '' ) ), 0, 128 ),
			'status' => in_array( (string) ( $attempt['status'] ?? '' ), array( 'provider_failed', 'validation_failed', 'draft_created' ), true ) ? (string) $attempt['status'] : 'provider_failed',
			'usage' => array( 'input_tokens' => max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ), 'output_tokens' => max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ), 'total_tokens' => max( 0, (int) ( $usage['total_tokens'] ?? 0 ) ) ),
			'diagnostics' => $this->sanitize_diagnostics( (array) ( $attempt['diagnostics'] ?? array() ) ),
			'plugin_version' => substr( sanitize_text_field( defined( 'GLOBAL360_AI_VERSION' ) ? GLOBAL360_AI_VERSION : '' ), 0, 32 ),
			'draft_post_id' => max( 0, (int) ( $attempt['draft_post_id'] ?? 0 ) ),
		);
		$current['generation_attempts'] = array_slice( $attempts, -20 );
		$current['updated_at'] = gmdate( 'c' );
		$records = $this->records(); $records[ $this->key( $analysis_id, $opportunity_id ) ] = $current;
		update_option( self::OPTION, $records, false );
	}

	/** @return array<string,mixed> */
	private function sanitize_diagnostics( array $diagnostics ): array {
		$result = array(
			'http_status' => max( 0, min( 599, (int) ( $diagnostics['http_status'] ?? 0 ) ) ),
			'provider_error_type' => substr( sanitize_key( (string) ( $diagnostics['provider_error_type'] ?? '' ) ), 0, 64 ),
			'provider_error_message' => substr( sanitize_text_field( (string) ( $diagnostics['provider_error_message'] ?? '' ) ), 0, 300 ),
			'provider_request_id' => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $diagnostics['provider_request_id'] ?? '' ) ), 0, 128 ),
			'validation_field' => substr( sanitize_key( (string) ( $diagnostics['validation_field'] ?? '' ) ), 0, 64 ),
			'validation_rule' => substr( sanitize_key( (string) ( $diagnostics['validation_rule'] ?? '' ) ), 0, 64 ),
			'completion_status' => substr( sanitize_key( (string) ( $diagnostics['completion_status'] ?? '' ) ), 0, 64 ),
			'stop_reason' => substr( sanitize_key( (string) ( $diagnostics['stop_reason'] ?? '' ) ), 0, 64 ),
			'output_limit_reached' => ! empty( $diagnostics['output_limit_reached'] ),
		);
		foreach ( array( 'unsupported_tags' => 10, 'unsupported_attributes' => 20, 'unsafe_tags' => 10, 'unsafe_attributes' => 20 ) as $key => $limit ) {
			$tokens = array(); foreach ( (array) ( $diagnostics[ $key ] ?? array() ) as $token ) { $token = substr( preg_replace( '/[^a-z0-9:_-]/', '', strtolower( (string) $token ) ), 0, 64 ); if ( '' !== $token ) { $tokens[] = $token; } }
			$tokens = array_values( array_unique( $tokens ) ); sort( $tokens, SORT_STRING ); $result[ $key ] = array_slice( $tokens, 0, $limit );
		}
		return $result;
	}

	/** @return array<string,mixed> */
	public function opportunity( string $analysis_id, string $opportunity_id ): array {
		foreach ( $this->analyses->all() as $analysis ) {
			if ( ! is_array( $analysis ) || $analysis_id !== (string) ( $analysis['analysis_id'] ?? '' ) ) { continue; }
			foreach ( (array) ( $analysis['opportunities'] ?? array() ) as $opportunity ) {
				if ( is_array( $opportunity ) && $opportunity_id === (string) ( $opportunity['id'] ?? '' ) ) { return $opportunity; }
			}
			throw new \InvalidArgumentException( 'Unknown opportunity ID.' );
		}
		throw new \InvalidArgumentException( 'Unknown analysis ID.' );
	}

	/** @return array<string,mixed> */
	private function records(): array { $value = get_option( self::OPTION, array() ); return is_array( $value ) ? $value : array(); }
	private function key( string $analysis_id, string $opportunity_id ): string { return hash( 'sha256', $analysis_id . ':' . $opportunity_id ); }
}
