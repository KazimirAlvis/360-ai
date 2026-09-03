<?php

namespace Global360\AI\AI;

final class OpportunityRequest {
	public static function instructions(): string {
		return 'Identify exactly five genuine, non-duplicative educational content gaps for this medical site. Account for existing content and avoid duplicate or cannibalizing recommendations. Use only supplied Clinic, Doctor, location, and internal-link IDs. Any local or GEO recommendation must be grounded in supplied locations and Clinics. Do not invent medical facts, studies, efficacy claims, diagnoses, personalized medical advice, providers, clinics, locations, or unsupported local targeting. Return exactly five opportunities for human review.';
	}

	/** @return array<string,mixed> */
	public static function schema(): array {
		$string = array( 'type' => 'string' );
		$ids = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		return array(
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
		);
	}
}
