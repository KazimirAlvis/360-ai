<?php

namespace Global360\AI\Generation;

use Global360\AI\Analysis\AnalysisContextBuilder;
use Global360\AI\Core\CoreAdapter;

final class DraftGenerationContextBuilder {
	private $analysis;
	private $core;

	public function __construct( AnalysisContextBuilder $analysis, CoreAdapter $core ) { $this->analysis = $analysis; $this->core = $core; }

	/** @return array<string,mixed> */
	public function build( array $opportunity ): array {
		$base = $this->analysis->build();
		$allowed_link_ids = array_values( array_unique( array_map( 'absint', (array) ( $opportunity['suggested_internal_link_ids'] ?? array() ) ) ) );
		$existing = array_values( array_filter( (array) ( $base['existing_content'] ?? array() ), static function ( $item ) use ( $allowed_link_ids ) {
			return is_array( $item ) && in_array( (int) ( $item['id'] ?? 0 ), $allowed_link_ids, true );
		} ) );
		return array(
			'opportunity' => $opportunity,
			'site' => (array) ( $base['site'] ?? array() ),
			'site_context' => (array) ( $base['site_context'] ?? array() ),
			'existing_content' => $existing,
			'allowed_internal_links' => $existing,
			'relevant_entities' => $this->core->generation_entities(
				(array) ( $opportunity['relevant_clinic_ids'] ?? array() ),
				(array) ( $opportunity['relevant_doctor_ids'] ?? array() ),
				(array) ( $opportunity['relevant_location_ids'] ?? array() )
			),
			'editorial_rules' => array(
				'status' => 'draft',
				'human_review_required' => true,
				'ymyl' => 'Educational only; omit unsupported clinical or entity claims and never fabricate citations.',
			),
		);
	}
}
