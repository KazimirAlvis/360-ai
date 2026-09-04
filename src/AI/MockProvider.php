<?php

namespace Global360\AI\AI;

use Global360\AI\Generation\DraftResponse;

final class MockProvider implements ProviderInterface {
	public static $draft_calls = 0;
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

	public function generate_draft( array $context ): DraftResponse {
		self::$draft_calls++;
		$opportunity = (array) ( $context['opportunity'] ?? array() );
		$links = array_map( 'absint', array_column( (array) ( $context['allowed_internal_links'] ?? array() ), 'id' ) );
		$content = '<p>This educational draft introduces the approved topic in clear language for readers preparing to discuss general questions with a qualified healthcare professional. It does not diagnose a condition or recommend a particular treatment.</p><h2>Understanding the topic</h2><p>People can use this overview to organize questions, learn common terminology, and prepare for a careful conversation with their own care team. Individual circumstances vary, and clinical decisions require professional evaluation.</p><h2>Questions to consider</h2><ul><li>What information should I bring to an appointment?</li><li>Which general options may be discussed?</li><li>What follow-up questions should I ask?</li></ul><p>This draft requires human editorial and medical review before publication.</p>';
		return new DraftResponse( array(
			'title' => (string) ( $opportunity['proposed_title'] ?? '' ),
			'slug' => (string) ( $opportunity['suggested_slug'] ?? '' ),
			'excerpt' => 'An educational overview of the approved topic, prepared for human editorial and medical review.',
			'content' => $content,
			'seo_title' => (string) ( $opportunity['proposed_title'] ?? '' ),
			'meta_description' => 'Review general educational information and questions to discuss with a qualified healthcare professional.',
			'suggested_internal_link_ids' => $links,
		), $this->name(), $this->model(), array( 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0 ) );
	}
}
