<?php

namespace Global360\AI\Analysis;

final class OpportunityValidator {
	public const CATEGORIES = array( 'informational', 'treatment', 'symptom', 'comparison', 'local', 'faq_aeo' );
	private const TYPES = array( 'page', 'post' );

	/** @return array<int,array<string,mixed>> */
	public function validate( array $opportunities, array $context ): array {
		if ( 5 !== count( $opportunities ) ) {
			throw new \InvalidArgumentException( 'Analysis must contain exactly five opportunities.' );
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

		foreach ( $opportunities as $index => $item ) {
			if ( ! is_array( $item ) ) { throw new \InvalidArgumentException( 'Opportunity is malformed.' ); }
			$title = sanitize_text_field( (string) ( $item['proposed_title'] ?? '' ) );
			$slug  = sanitize_title( (string) ( $item['suggested_slug'] ?? '' ) );
			$type  = (string) ( $item['content_type'] ?? '' );
			$category = (string) ( $item['category'] ?? '' );
			if ( '' === $title || '' === $slug || ! in_array( $type, self::TYPES, true ) || ! in_array( $category, self::CATEGORIES, true ) ) {
				throw new \InvalidArgumentException( 'Opportunity contains an invalid title, slug, type, or category.' );
			}
			$title_key = $this->normalize( $title );
			if ( isset( $titles[ $title_key ] ) || isset( $slugs[ $slug ] ) ) {
				throw new \InvalidArgumentException( 'Analysis contains duplicate recommendation titles or slugs.' );
			}
			foreach ( $existing as $existing_item ) {
				if ( $this->overlaps( $title, $slug, (string) ( $existing_item['title'] ?? '' ), (string) ( $existing_item['slug'] ?? '' ) ) ) {
					throw new \InvalidArgumentException( 'A recommendation substantially overlaps existing content.' );
				}
			}
			$titles[ $title_key ] = true;
			$slugs[ $slug ] = true;

			$locations = $this->validated_strings( $item['relevant_location_ids'] ?? array(), $location_ids, 'location' );
			$clinics = $this->validated_ints( $item['relevant_clinic_ids'] ?? array(), $clinic_ids, 'Clinic' );
			$doctors = $this->validated_ints( $item['relevant_doctor_ids'] ?? array(), $doctor_ids, 'Doctor' );
			$links = $this->validated_ints( $item['suggested_internal_link_ids'] ?? array(), $content_ids, 'internal-link Post' );
			$geo_scope = sanitize_text_field( (string) ( $item['geo_scope'] ?? 'national' ) );
			if ( ( 'local' === $category || 'national' !== strtolower( $geo_scope ) ) && ( empty( $locations ) || empty( $clinics ) ) ) {
				throw new \InvalidArgumentException( 'Local recommendations require valid location and Clinic grounding.' );
			}
			if ( 'local' === $category || 'national' !== strtolower( $geo_scope ) ) {
				$grounded_clinics = array();
				foreach ( $locations as $location_id ) { $grounded_clinics = array_merge( $grounded_clinics, $location_clinics[ $location_id ] ?? array() ); }
				if ( array_diff( $clinics, array_unique( $grounded_clinics ) ) ) {
					throw new \InvalidArgumentException( 'A local recommendation references a Clinic outside its selected location.' );
				}
			}

			$clean[] = array(
				'id' => sanitize_key( (string) ( $item['id'] ?? 'opportunity-' . ( $index + 1 ) ) ),
				'proposed_title' => $title, 'suggested_slug' => $slug, 'content_type' => $type, 'category' => $category,
				'target_topic' => sanitize_text_field( (string) ( $item['target_topic'] ?? '' ) ),
				'search_intent' => sanitize_text_field( (string) ( $item['search_intent'] ?? '' ) ),
				'rationale' => sanitize_textarea_field( (string) ( $item['rationale'] ?? '' ) ),
				'audience' => sanitize_text_field( (string) ( $item['audience'] ?? '' ) ), 'geo_scope' => $geo_scope,
				'relevant_location_ids' => $locations, 'relevant_clinic_ids' => $clinics, 'relevant_doctor_ids' => $doctors,
				'suggested_internal_link_ids' => $links,
				'priority' => max( 0, min( 100, (int) ( $item['priority'] ?? 0 ) ) ),
				'confidence' => max( 0, min( 1, (float) ( $item['confidence'] ?? 0 ) ) ),
			);
		}
		return $clean;
	}

	private function validated_ints( $values, array $allowed, string $label ): array {
		$values = array_values( array_unique( array_map( 'intval', (array) $values ) ) );
		if ( array_diff( $values, $allowed ) ) { throw new \InvalidArgumentException( 'Analysis contains an invalid ' . $label . ' reference.' ); }
		return $values;
	}

	private function validated_strings( $values, array $allowed, string $label ): array {
		$values = array_values( array_unique( array_map( 'sanitize_text_field', (array) $values ) ) );
		if ( array_diff( $values, $allowed ) ) { throw new \InvalidArgumentException( 'Analysis contains an invalid ' . $label . ' reference.' ); }
		return $values;
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
