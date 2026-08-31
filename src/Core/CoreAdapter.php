<?php

namespace Global360\AI\Core;

final class CoreAdapter {
	/** @var callable|null */
	private $resolver;

	/** @var object|null */
	private $platform;

	/** @var bool */
	private $resolved = false;

	public function __construct( callable $resolver = null ) {
		$this->resolver = $resolver;
	}

	public function is_available(): bool {
		return null !== $this->platform();
	}

	public function version(): string {
		return $this->is_available() && defined( 'GLOBAL360_PLATFORM_VERSION' )
			? (string) GLOBAL360_PLATFORM_VERSION
			: '';
	}

	/** @return array<string,mixed> */
	public function site_context(): array {
		$platform = $this->platform();
		if ( null === $platform || ! method_exists( $platform, 'site_context' ) ) {
			return array();
		}

		$context = $platform->site_context();

		return array(
			'site_name'         => $context->site_name(),
			'primary_condition' => $context->primary_condition(),
			'primary_treatment' => $context->primary_treatment(),
			'brand_colors'      => $context->brand_colors(),
			'assessment'        => $context->assessment(),
			'contact'           => $context->contact(),
			'schema'            => $context->schema(),
		);
	}

	/**
	 * Build a request-local summary from normalized Core entity views.
	 *
	 * @return array<string,mixed>
	 */
	public function platform_summary(): array {
		$platform = $this->platform();
		if ( null === $platform ) {
			return array(
				'clinics'      => null,
				'doctors'      => null,
				'relationships'=> null,
				'states'       => array(),
				'cities'       => array(),
			);
		}

		$clinic_ids = $this->published_ids( 'clinic' );
		$doctor_ids = $this->published_ids( 'doctor' );
		$states     = array();
		$cities     = array();
		$relations  = array();

		foreach ( $clinic_ids as $clinic_id ) {
			$clinic = $platform->clinics()->get( $clinic_id );
			if ( ! is_array( $clinic ) ) {
				continue;
			}
			foreach ( (array) ( $clinic['state_codes'] ?? array() ) as $state_code ) {
				$state_code = sanitize_text_field( (string) $state_code );
				if ( '' !== $state_code ) {
					$states[ $state_code ] = $platform->states()->name( $state_code );
				}
			}
			foreach ( (array) ( $clinic['addresses'] ?? array() ) as $address ) {
				$city = is_array( $address ) ? sanitize_text_field( (string) ( $address['city'] ?? '' ) ) : '';
				if ( '' !== $city ) {
					$cities[ strtolower( $city ) ] = $city;
				}
			}
		}

		foreach ( $doctor_ids as $doctor_id ) {
			$doctor = $platform->doctors()->get( $doctor_id );
			if ( ! is_array( $doctor ) ) {
				continue;
			}
			foreach ( (array) ( $doctor['clinic_ids'] ?? array() ) as $clinic_id ) {
				$clinic_id = absint( $clinic_id );
				if ( $clinic_id > 0 ) {
					$relations[ $doctor_id . ':' . $clinic_id ] = true;
				}
			}
		}

		ksort( $states );
		natcasesort( $cities );

		return array(
			'clinics'       => count( $clinic_ids ),
			'doctors'       => count( $doctor_ids ),
			'relationships' => count( $relations ),
			'states'        => array_values( array_filter( $states ) ),
			'cities'        => array_values( $cities ),
		);
	}

	/**
	 * Return stable IDs and normalized locations allowed in analysis output.
	 *
	 * @return array<string,mixed>
	 */
	public function analysis_entities(): array {
		$platform = $this->platform();
		if ( null === $platform ) {
			return array( 'clinic_ids' => array(), 'doctor_ids' => array(), 'locations' => array() );
		}

		$clinic_ids = $this->published_ids( 'clinic' );
		$doctor_ids = $this->published_ids( 'doctor' );
		$locations  = array();

		foreach ( $clinic_ids as $clinic_id ) {
			$clinic = $platform->clinics()->get( $clinic_id );
			if ( ! is_array( $clinic ) ) {
				continue;
			}
			foreach ( (array) ( $clinic['state_codes'] ?? array() ) as $code ) {
				$code = strtoupper( sanitize_text_field( (string) $code ) );
				if ( '' !== $code ) {
					$locations[ 'state:' . $code ] = array(
						'id'         => 'state:' . $code,
						'name'       => (string) $platform->states()->name( $code ),
						'clinic_ids' => array( $clinic_id ),
					);
				}
			}
			foreach ( (array) ( $clinic['addresses'] ?? array() ) as $address ) {
				$city = is_array( $address ) ? sanitize_text_field( (string) ( $address['city'] ?? '' ) ) : '';
				if ( '' === $city ) {
					continue;
				}
				$state = is_array( $address ) ? strtoupper( sanitize_text_field( (string) ( $address['state'] ?? '' ) ) ) : '';
				$id = 'city:' . ( '' !== $state ? strtolower( $state ) . ':' : '' ) . sanitize_title( $city );
				if ( ! isset( $locations[ $id ] ) ) {
					$locations[ $id ] = array( 'id' => $id, 'name' => $city, 'clinic_ids' => array() );
				}
				$locations[ $id ]['clinic_ids'][] = $clinic_id;
			}
		}

		foreach ( $locations as &$location ) {
			$location['clinic_ids'] = array_values( array_unique( array_map( 'absint', $location['clinic_ids'] ) ) );
		}
		unset( $location );

		return array(
			'clinic_ids' => $clinic_ids,
			'doctor_ids' => $doctor_ids,
			'locations'  => array_values( $locations ),
		);
	}

	/** @return object|null */
	private function platform() {
		if ( $this->resolved ) {
			return $this->platform;
		}

		$this->resolved = true;
		if ( null !== $this->resolver ) {
			$candidate = call_user_func( $this->resolver );
			$this->platform = is_object( $candidate ) ? $candidate : null;
			return $this->platform;
		}

		if ( function_exists( 'global360_platform' ) ) {
			$candidate = global360_platform();
			$this->platform = is_object( $candidate ) ? $candidate : null;
		}

		return $this->platform;
	}

	/** @return array<int,int> */
	private function published_ids( string $post_type ): array {
		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_map( 'absint', $ids ) );
	}
}
