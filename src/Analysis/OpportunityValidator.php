<?php

namespace Global360\AI\Analysis;

final class OpportunityValidator {
	public const CATEGORIES = array( 'informational', 'treatment', 'symptom', 'comparison', 'local', 'faq_aeo' );
	private const TYPES = array( 'page', 'post' );
	private $selection_diagnostics = array();

	/** @return array<int,array<string,mixed>> */
	public function validate( array $opportunities, array $context ): array {
		$this->selection_diagnostics = array();
		if ( 8 !== count( $opportunities ) ) {
			$this->fail( 'Analysis must contain exactly eight candidate opportunities.', 'candidate_count', -1, '', 'count', count( $opportunities ) );
		}
		$entities = (array) ( $context['allowed_entities'] ?? array() );
		$clinic_ids = array_map( 'intval', (array) ( $entities['clinic_ids'] ?? array() ) );
		$doctor_ids = array_map( 'intval', (array) ( $entities['doctor_ids'] ?? array() ) );
		$location_ids = array_values( array_filter( array_map( static function ( $location ) { return is_array( $location ) ? (string) ( $location['id'] ?? '' ) : ''; }, (array) ( $entities['locations'] ?? array() ) ) ) );
		$location_clinics = array();
		foreach ( (array) ( $entities['locations'] ?? array() ) as $location ) {
			if ( is_array( $location ) && ! empty( $location['id'] ) ) {
				$location_clinics[ (string) $location['id'] ] = array_map( 'intval', (array) ( $location['clinic_ids'] ?? array() ) );
			}
		}
		$content_ids = array_map( 'intval', array_column( (array) ( $context['existing_content'] ?? array() ), 'id' ) );
		$existing = (array) ( $context['existing_content'] ?? array() );
		$titles = array();
		$slugs = array();
		$clean = array();
		$rejection_rules = array();

		foreach ( $opportunities as $index => $item ) {
			if ( ! is_array( $item ) ) { $this->fail( 'Opportunity candidate is structurally malformed.', 'malformed_opportunity', $index ); }
			try {
			$opportunity_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$title = sanitize_text_field( (string) ( $item['proposed_title'] ?? '' ) );
			$slug  = sanitize_title( (string) ( $item['suggested_slug'] ?? '' ) );
			$type  = (string) ( $item['content_type'] ?? '' );
			$category = (string) ( $item['category'] ?? '' );
			if ( '' === $title ) { $this->fail( 'Opportunity contains an invalid title.', 'invalid_title', $index, $opportunity_id, 'proposed_title', gettype( $item['proposed_title'] ?? null ) ); }
			if ( '' === $slug ) { $this->fail( 'Opportunity contains an invalid slug.', 'invalid_slug', $index, $opportunity_id, 'suggested_slug', gettype( $item['suggested_slug'] ?? null ) ); }
			if ( ! in_array( $type, self::TYPES, true ) ) { $this->fail( 'Opportunity contains an invalid content type.', 'invalid_content_type', $index, $opportunity_id, 'content_type', $type ); }
			if ( ! in_array( $category, self::CATEGORIES, true ) ) { $this->fail( 'Opportunity contains an invalid category.', 'invalid_category', $index, $opportunity_id, 'category', $category ); }
			$title_key = $this->normalize( $title );
			if ( isset( $titles[ $title_key ] ) ) { $this->fail( 'Analysis contains a duplicate recommendation title.', 'duplicate_title', $index, $opportunity_id, 'proposed_title' ); }
			if ( isset( $slugs[ $slug ] ) ) { $this->fail( 'Analysis contains a duplicate recommendation slug.', 'duplicate_slug', $index, $opportunity_id, 'suggested_slug', $slug ); }
			foreach ( $existing as $existing_item ) {
				if ( $this->overlaps( $title, $slug, (string) ( $existing_item['title'] ?? '' ), (string) ( $existing_item['slug'] ?? '' ) ) ) {
					$this->fail( 'A recommendation substantially overlaps existing content.', 'existing_content_overlap', $index, $opportunity_id, 'existing_content_id', (int) ( $existing_item['id'] ?? 0 ) );
				}
			}
			$locations = $this->validated_strings( $item['relevant_location_ids'] ?? array(), $location_ids, 'location', 'invalid_location_id', $index, $opportunity_id );
			$clinics = $this->validated_ints( $item['relevant_clinic_ids'] ?? array(), $clinic_ids, 'Clinic', 'invalid_clinic_id', $index, $opportunity_id );
			$doctors = $this->validated_ints( $item['relevant_doctor_ids'] ?? array(), $doctor_ids, 'Doctor', 'invalid_doctor_id', $index, $opportunity_id );
			$links = $this->validated_ints( $item['suggested_internal_link_ids'] ?? array(), $content_ids, 'internal-link Post', 'invalid_internal_link_id', $index, $opportunity_id );
			$geo_scope = sanitize_text_field( (string) ( $item['geo_scope'] ?? 'national' ) );
			if ( ( 'local' === $category || 'national' !== strtolower( $geo_scope ) ) && ( empty( $locations ) || empty( $clinics ) ) ) {
				$this->fail( 'Local recommendations require valid location and Clinic grounding.', 'missing_local_grounding', $index, $opportunity_id, empty( $locations ) ? 'relevant_location_ids' : 'relevant_clinic_ids', 'empty' );
			}
			if ( 'local' === $category || 'national' !== strtolower( $geo_scope ) ) {
				$grounded_clinics = array();
				foreach ( $locations as $location_id ) { $grounded_clinics = array_merge( $grounded_clinics, $location_clinics[ $location_id ] ?? array() ); }
				$outside_clinics = array_values( array_diff( $clinics, array_unique( $grounded_clinics ) ) );
				if ( $outside_clinics ) {
					$this->fail( 'A local recommendation references a Clinic outside its selected location.', 'clinic_location_mismatch', $index, $opportunity_id, 'relevant_clinic_ids', (int) $outside_clinics[0] );
				}
			}
			$priority = (int) ( $item['priority'] ?? 0 );
			$confidence = (float) ( $item['confidence'] ?? 0 );
			if ( 0 > $priority || 100 < $priority ) { $this->fail( 'Opportunity priority must be between 0 and 100.', 'invalid_priority', $index, $opportunity_id, 'priority', $priority ); }
			if ( 0 > $confidence || 1 < $confidence ) { $this->fail( 'Opportunity confidence must be between 0 and 1.', 'invalid_confidence', $index, $opportunity_id, 'confidence', $confidence ); }

			$titles[ $title_key ] = true;
			$slugs[ $slug ] = true;
			$clean[] = array(
				'id' => sanitize_key( (string) ( $item['id'] ?? 'opportunity-' . ( $index + 1 ) ) ),
				'proposed_title' => $title, 'suggested_slug' => $slug, 'content_type' => $type, 'category' => $category,
				'target_topic' => sanitize_text_field( (string) ( $item['target_topic'] ?? '' ) ),
				'search_intent' => sanitize_text_field( (string) ( $item['search_intent'] ?? '' ) ),
				'rationale' => sanitize_textarea_field( (string) ( $item['rationale'] ?? '' ) ),
				'audience' => sanitize_text_field( (string) ( $item['audience'] ?? '' ) ), 'geo_scope' => $geo_scope,
				'relevant_location_ids' => $locations, 'relevant_clinic_ids' => $clinics, 'relevant_doctor_ids' => $doctors,
				'suggested_internal_link_ids' => $links,
				'priority' => $priority,
				'confidence' => $confidence,
			);
			} catch ( OpportunityValidationException $error ) {
				$rule = sanitize_key( (string) ( $error->diagnostics()['validation_rule'] ?? 'candidate_validation' ) );
				$rejection_rules[ $rule ] = min( 8, (int) ( $rejection_rules[ $rule ] ?? 0 ) + 1 );
			}
		}
		$not_selected = max( 0, count( $clean ) - 5 );
		if ( 0 < $not_selected ) {
			$rejection_rules['not_selected_after_limit'] = $not_selected;
		}
		ksort( $rejection_rules );
		$this->selection_diagnostics = array(
			'candidates_received' => 8,
			'candidates_selected' => min( 5, count( $clean ) ),
			'candidates_rejected' => 8 - min( 5, count( $clean ) ),
			'rejection_rules' => array_slice( $rejection_rules, 0, 8, true ),
		);
		if ( 5 > count( $clean ) ) {
			throw new OpportunityValidationException( 'Fewer than five candidate opportunities passed deterministic validation.', array_merge( array( 'failure_stage' => 'validation', 'validation_rule' => 'insufficient_valid_candidates' ), $this->selection_diagnostics ) );
		}
		return array_slice( $clean, 0, 5 );
	}

	/** @return array<string,mixed> */
	public function selection_diagnostics(): array { return $this->selection_diagnostics; }

	private function validated_ints( $values, array $allowed, string $label, string $rule, int $index, string $opportunity_id ): array {
		$values = array_values( array_unique( array_map( 'intval', (array) $values ) ) );
		$invalid = array_values( array_diff( $values, $allowed ) );
		if ( $invalid ) { $this->fail( 'Analysis contains an invalid ' . $label . ' reference.', $rule, $index, $opportunity_id, strtolower( str_replace( ' ', '_', $label ) ) . '_id', (int) $invalid[0] ); }
		return $values;
	}

	private function validated_strings( $values, array $allowed, string $label, string $rule, int $index, string $opportunity_id ): array {
		$values = array_values( array_unique( array_map( 'sanitize_text_field', (array) $values ) ) );
		$invalid = array_values( array_diff( $values, $allowed ) );
		if ( $invalid ) { $this->fail( 'Analysis contains an invalid ' . $label . ' reference.', $rule, $index, $opportunity_id, $label . '_id', $invalid[0] ); }
		return $values;
	}

	private function fail( string $message, string $rule, int $index, string $opportunity_id = '', string $field = '', $value = null ): void {
		$diagnostics = array( 'failure_stage' => 'validation', 'validation_rule' => $rule );
		if ( 0 <= $index ) { $diagnostics['opportunity_index'] = $index + 1; }
		if ( '' !== $opportunity_id ) { $diagnostics['opportunity_id'] = substr( sanitize_key( $opportunity_id ), 0, 64 ); }
		if ( '' !== $field ) { $diagnostics['offending_field'] = substr( sanitize_key( $field ), 0, 64 ); }
		if ( null !== $value ) {
			$primitive = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_scalar( $value ) ? (string) $value : gettype( $value ) );
			$diagnostics['offending_value'] = substr( preg_replace( '/[^A-Za-z0-9:._-]/', '', $primitive ), 0, 96 );
		}
		throw new OpportunityValidationException( $message, $diagnostics );
	}

	private function overlaps( string $title, string $slug, string $existing_title, string $existing_slug ): bool {
		if ( '' !== $slug && $slug === sanitize_title( $existing_slug ) ) { return true; }
		$a = $this->tokens( $title ); $b = $this->tokens( $existing_title );
		if ( empty( $a ) || empty( $b ) ) { return false; }
		if ( min( count( $a ), count( $b ) ) < 2 ) { return $this->normalize( $title ) === $this->normalize( $existing_title ); }
		$intersection = count( array_intersect( $a, $b ) );
		return $intersection / max( 1, min( count( $a ), count( $b ) ) ) >= 0.75;
	}

	private function normalize( string $value ): string { return implode( ' ', $this->tokens( $value ) ); }
	private function tokens( string $value ): array {
		$value = strtolower( remove_accents( $value ) );
		$tokens = preg_split( '/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		$stop = array( 'a', 'an', 'and', 'of', 'the', 'to', 'for', 'in', 'your' );
		$tokens = array_map( static function ( $token ) { return strlen( $token ) > 3 ? rtrim( $token, 's' ) : $token; }, $tokens ?: array() );
		return array_values( array_unique( array_diff( $tokens, $stop ) ) );
	}
}
