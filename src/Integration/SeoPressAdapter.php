<?php

namespace Global360\AI\Integration;

class SeoPressAdapter {
	public const TITLE_META = '_seopress_titles_title';
	public const DESCRIPTION_META = '_seopress_titles_desc';
	public const TARGET_KEYWORDS_META = '_seopress_analysis_target_kw';
	public const FACEBOOK_TITLE_META = '_seopress_social_fb_title';
	public const FACEBOOK_DESCRIPTION_META = '_seopress_social_fb_desc';
	public const TWITTER_TITLE_META = '_seopress_social_twitter_title';
	public const TWITTER_DESCRIPTION_META = '_seopress_social_twitter_desc';

	public function available(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	public function initialize( int $post_id, string $title, string $description, array $target_keyphrases = array() ): void {
		$values = array(
			self::TITLE_META => sanitize_text_field( $title ),
			self::DESCRIPTION_META => sanitize_textarea_field( $description ),
			self::FACEBOOK_TITLE_META => sanitize_text_field( $title ),
			self::FACEBOOK_DESCRIPTION_META => sanitize_textarea_field( $description ),
			self::TWITTER_TITLE_META => sanitize_text_field( $title ),
			self::TWITTER_DESCRIPTION_META => sanitize_textarea_field( $description ),
		);
		$keyphrases = array_values( array_filter( array_map( 'sanitize_text_field', $target_keyphrases ) ) );
		if ( $keyphrases ) { $values[ self::TARGET_KEYWORDS_META ] = implode( ',', array_slice( $keyphrases, 0, 5 ) ); }
		foreach ( $values as $key => $value ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) { update_post_meta( $post_id, $key, $value ); }
		}
	}
}
