<?php

namespace Global360\AI\AI;

final class OpportunityRequest {
	public static function instructions(): string {
		return 'Identify exactly eight ordered candidate opportunities for genuine educational content gaps on this medical site. Existing published Pages, Posts, and Content are topics already covered: do not merely rewrite an existing title or propose substantially the same reader question or search intent with different wording. Look for genuine gaps and useful adjacent intents; a related subject is allowed when its reader intent or question is materially different. Use only supplied Clinic, Doctor, location, and internal-link IDs. Any local or GEO recommendation must be grounded in supplied locations and Clinics. Do not invent medical facts, studies, efficacy claims, diagnoses, personalized medical advice, providers, clinics, locations, or unsupported local targeting. Return exactly eight candidates in priority order; deterministic validation will select the first five valid, distinct opportunities for human review.';
	}

	/** @return array<string,mixed> */
	public static function schema(): array {
		$string = array( 'type' => 'string' );
		$ids = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		return array(
			'type' => 'object', 'additionalProperties' => false, 'required' => array( 'opportunities' ),
			'properties' => array( 'opportunities' => array(
				'type' => 'array', 'minItems' => 8, 'maxItems' => 8,
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
		);
	}
}
