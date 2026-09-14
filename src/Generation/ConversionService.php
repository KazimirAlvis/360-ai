<?php

namespace Global360\AI\Generation;

use Global360\AI\Content\ContentDraftPostType;
use Global360\AI\Integration\SeoPressAdapter;
use Global360\AI\Storage\WorkflowRepository;

final class ConversionService {
	private $workflow;
	private $seo;

	public function __construct( WorkflowRepository $workflow, SeoPressAdapter $seo ) {
		$this->workflow = $workflow;
		$this->seo = $seo;
	}

	/** @return array<string,mixed> */
	public function convert( int $content_draft_id, int $current_user_id ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \DomainException( 'You are not allowed to convert Content Drafts.' );
		}
		$content_draft = get_post( $content_draft_id );
		if ( ! $content_draft || ContentDraftPostType::POST_TYPE !== $content_draft->post_type ) {
			throw new \InvalidArgumentException( 'A valid Content Draft is required.' );
		}

		$intended_type = (string) get_post_meta( $content_draft_id, '_global360_ai_intended_post_type', true );
		if ( ! in_array( $intended_type, array( 'page', 'post' ), true ) ) {
			throw new \DomainException( 'The Content Draft has no valid intended content type.' );
		}

		$analysis_id = (string) get_post_meta( $content_draft_id, '_global360_ai_analysis_id', true );
		$opportunity_id = (string) get_post_meta( $content_draft_id, '_global360_ai_opportunity_id', true );
		if ( '' === $analysis_id || '' === $opportunity_id ) {
			throw new \DomainException( 'The Content Draft is missing its workflow provenance.' );
		}

		$state = $this->workflow->get( $analysis_id, $opportunity_id );
		if ( 'draft_created' !== ( $state['status'] ?? '' ) || $content_draft_id !== (int) ( $state['draft_post_id'] ?? 0 ) ) {
			throw new \DomainException( 'The Content Draft does not match its workflow record.' );
		}

		$existing = $this->existing_final( $content_draft_id );
		if ( $existing ) {
			return array( 'post_id' => $existing, 'post_type' => get_post_type( $existing ), 'created' => false, 'seo_transferred' => (bool) get_post_meta( $content_draft_id, '_global360_ai_seopress_transferred', true ) );
		}

		$lock = wp_generate_uuid4();
		if ( ! add_post_meta( $content_draft_id, '_global360_ai_conversion_lock', $lock, true ) ) {
			throw new \RuntimeException( 'Content Draft conversion is already in progress.' );
		}

		$final_id = 0;
		try {
			$existing = $this->existing_final( $content_draft_id );
			if ( $existing ) {
				return array( 'post_id' => $existing, 'post_type' => get_post_type( $existing ), 'created' => false, 'seo_transferred' => (bool) get_post_meta( $content_draft_id, '_global360_ai_seopress_transferred', true ) );
			}

			$author_id = (int) $content_draft->post_author;
			if ( 1 > $author_id ) {
				$author_id = $current_user_id;
			}
			$final_slug = sanitize_title( (string) get_post_meta( $content_draft_id, '_global360_ai_final_slug', true ) );
			$final_id = wp_insert_post( array(
				'post_status' => 'draft',
				'post_type' => $intended_type,
				'post_author' => $author_id,
				'post_title' => $content_draft->post_title,
				'post_name' => $final_slug,
				'post_excerpt' => $content_draft->post_excerpt,
				'post_content' => $content_draft->post_content,
			), true );
			if ( is_wp_error( $final_id ) || 1 > (int) $final_id ) {
				throw new \RuntimeException( 'WordPress could not create the final draft.' );
			}
			$final_id = (int) $final_id;

			$copy_keys = array(
				'_global360_ai_analysis_id', '_global360_ai_opportunity_id', '_global360_ai_provider',
				'_global360_ai_model', '_global360_ai_generated_at', '_global360_ai_plugin_version',
				'_global360_ai_seo_title', '_global360_ai_meta_description',
				'_global360_ai_suggested_internal_link_ids', '_global360_ai_generation_usage',
			);
			foreach ( $copy_keys as $key ) {
				$value = get_post_meta( $content_draft_id, $key, true );
				if ( '' !== $value && array() !== $value ) {
					update_post_meta( $final_id, $key, $value );
				}
			}
			update_post_meta( $final_id, '_global360_ai_content_draft_id', $content_draft_id );
			update_post_meta( $final_id, '_global360_ai_converted_at', gmdate( 'c' ) );

			$seo_transferred = $this->seo->transfer(
				$final_id,
				(string) get_post_meta( $content_draft_id, '_global360_ai_seo_title', true ),
				(string) get_post_meta( $content_draft_id, '_global360_ai_meta_description', true )
			);
			update_post_meta( $content_draft_id, '_global360_ai_final_post_id', $final_id );
			update_post_meta( $content_draft_id, '_global360_ai_conversion_type', $intended_type );
			update_post_meta( $content_draft_id, '_global360_ai_converted_at', gmdate( 'c' ) );
			update_post_meta( $content_draft_id, '_global360_ai_seopress_transferred', $seo_transferred ? 1 : 0 );

			try {
				$this->workflow->record_conversion( $analysis_id, $opportunity_id, $content_draft_id, $final_id, $intended_type );
			} catch ( \Throwable $error ) {
				foreach ( array( '_global360_ai_final_post_id', '_global360_ai_conversion_type', '_global360_ai_converted_at', '_global360_ai_seopress_transferred' ) as $key ) {
					delete_post_meta( $content_draft_id, $key );
				}
				wp_delete_post( $final_id, true );
				throw $error;
			}

			return array( 'post_id' => $final_id, 'post_type' => $intended_type, 'created' => true, 'seo_transferred' => $seo_transferred );
		} finally {
			delete_post_meta( $content_draft_id, '_global360_ai_conversion_lock', $lock );
		}
	}

	private function existing_final( int $content_draft_id ): int {
		$stored = (int) get_post_meta( $content_draft_id, '_global360_ai_final_post_id', true );
		if ( $stored && in_array( get_post_type( $stored ), array( 'page', 'post' ), true ) ) {
			return $stored;
		}
		$ids = get_posts( array(
			'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'posts_per_page' => 1,
			'fields' => 'ids', 'no_found_rows' => true,
			'meta_key' => '_global360_ai_content_draft_id', 'meta_value' => $content_draft_id,
		) );
		return isset( $ids[0] ) ? absint( $ids[0] ) : 0;
	}
}
