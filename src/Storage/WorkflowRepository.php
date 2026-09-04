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
		if ( 'draft_created' === $to ) { $current['draft_post_id'] = $draft_post_id; }
		$records = $this->records();
		$records[ $this->key( $analysis_id, $opportunity_id ) ] = $current;
		update_option( self::OPTION, $records, false );
		return $current;
	}

	public function record_failure( string $analysis_id, string $opportunity_id, string $message, array $diagnostics = array() ): void {
		$current = $this->get( $analysis_id, $opportunity_id );
		$current['generation_error'] = substr( sanitize_text_field( $message ), 0, 300 );
		$current['generation_diagnostics'] = array(
			'http_status' => max( 0, min( 599, (int) ( $diagnostics['http_status'] ?? 0 ) ) ),
			'provider_error_type' => substr( sanitize_key( (string) ( $diagnostics['provider_error_type'] ?? '' ) ), 0, 64 ),
			'provider_error_message' => substr( sanitize_text_field( (string) ( $diagnostics['provider_error_message'] ?? '' ) ), 0, 300 ),
			'provider_request_id' => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $diagnostics['provider_request_id'] ?? '' ) ), 0, 128 ),
		);
		$current['updated_at'] = gmdate( 'c' );
		$records = $this->records();
		$records[ $this->key( $analysis_id, $opportunity_id ) ] = $current;
		update_option( self::OPTION, $records, false );
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
