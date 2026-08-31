<?php

namespace Global360\AI\AI;

final class MockProvider implements ProviderInterface {
	public function name(): string { return 'mock'; }
	public function model(): string { return 'deterministic-fixture'; }
	public function is_configured(): bool { return true; }

	public function analyze( array $context ): AnalysisResponse {
		$content = (array) ( $context['existing_content'] ?? array() );
		$link_ids = array_slice( array_values( array_map( 'intval', array_column( $content, 'id' ) ) ), 0, 2 );
		$topics = array(
			array( 'Questions to Ask Before a Treatment Consultation', 'treatment-consultation-questions', 'page', 'treatment', 'treatment consultation preparation', 'commercial_investigation' ),
			array( 'How to Track Symptoms Before Seeing a Specialist', 'track-symptoms-before-specialist', 'post', 'symptom', 'symptom tracking', 'informational' ),
			array( 'Treatment Options: Questions, Benefits, and Tradeoffs', 'treatment-options-questions-benefits-tradeoffs', 'post', 'comparison', 'treatment option comparison', 'informational' ),
			array( 'Frequently Asked Questions About Specialist Care', 'specialist-care-frequently-asked-questions', 'page', 'faq_aeo', 'specialist care FAQ', 'informational' ),
			array( 'Preparing for Your First Specialist Appointment', 'prepare-first-specialist-appointment', 'post', 'informational', 'appointment preparation', 'informational' ),
		);
		$items = array();
		foreach ( $topics as $index => $topic ) {
			$items[] = array(
				'id'                          => 'mock-' . ( $index + 1 ),
				'proposed_title'              => $topic[0],
				'suggested_slug'              => $topic[1],
				'content_type'                => $topic[2],
				'category'                    => $topic[3],
				'target_topic'                => $topic[4],
				'search_intent'               => $topic[5],
				'rationale'                   => 'Test fixture demonstrating a grounded educational content-gap recommendation for human review.',
				'audience'                    => 'People researching specialist care',
				'geo_scope'                   => 'national',
				'relevant_location_ids'       => array(),
				'relevant_clinic_ids'         => array(),
				'relevant_doctor_ids'         => array(),
				'suggested_internal_link_ids' => $link_ids,
				'priority'                    => 100 - ( $index * 5 ),
				'confidence'                  => 0.9,
			);
		}
		return new AnalysisResponse( $items, $this->name(), $this->model(), array( 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0 ) );
	}
}
