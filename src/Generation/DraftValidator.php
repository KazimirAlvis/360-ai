<?php

namespace Global360\AI\Generation;

final class DraftValidator {
	private const MIN_CONTENT = 200;
	private const MAX_CONTENT = 20000;
	private const ALLOWED_KEYS = array( 'title', 'slug', 'excerpt', 'content', 'seo_title', 'meta_description', 'suggested_internal_link_ids' );

	/** @return array<string,mixed> */
	public function validate( array $draft, array $opportunity, array $context ): array {
		if ( array_diff( self::ALLOWED_KEYS, array_keys( $draft ) ) ) { throw new \InvalidArgumentException( 'Draft output is missing required fields.' ); }
		if ( array_diff( array_keys( $draft ), self::ALLOWED_KEYS ) ) { throw new \InvalidArgumentException( 'Draft output contains unexpected fields.' ); }
		$title = sanitize_text_field( (string) ( $draft['title'] ?? '' ) );
		$raw_slug = (string) ( $draft['slug'] ?? '' );
		$slug = sanitize_title( $raw_slug );
		$excerpt = sanitize_textarea_field( (string) ( $draft['excerpt'] ?? '' ) );
		$content = trim( (string) ( $draft['content'] ?? '' ) );
		$seo_title = sanitize_text_field( (string) ( $draft['seo_title'] ?? '' ) );
		$meta_description = sanitize_textarea_field( (string) ( $draft['meta_description'] ?? '' ) );
		if ( '' === $title ) { throw new \InvalidArgumentException( 'Draft title is required.' ); }
		if ( '' === $raw_slug || $slug !== $raw_slug ) { throw new \InvalidArgumentException( 'Draft slug is invalid.' ); }
		if ( '' === $excerpt ) { throw new \InvalidArgumentException( 'Draft excerpt is required.' ); }
		if ( '' === $content ) { throw new \InvalidArgumentException( 'Draft content is required.' ); }
		if ( '' === $seo_title || '' === $meta_description ) { throw new \InvalidArgumentException( 'Draft SEO title and meta description are required.' ); }
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( wp_strip_all_tags( $content ) ) : strlen( wp_strip_all_tags( $content ) );
		if ( self::MIN_CONTENT > $length || self::MAX_CONTENT < $length ) { throw new \InvalidArgumentException( 'Draft content length is outside the allowed range.' ); }
		if ( ! in_array( (string) ( $opportunity['content_type'] ?? '' ), array( 'page', 'post' ), true ) ) { throw new \InvalidArgumentException( 'Approved opportunity has an unexpected post type.' ); }
		if ( sanitize_title( (string) ( $opportunity['suggested_slug'] ?? '' ) ) !== $slug || ! $this->titles_correspond( $title, (string) ( $opportunity['proposed_title'] ?? '' ) ) ) { throw new \InvalidArgumentException( 'Generated title or slug does not correspond to the approved opportunity.' ); }
		if ( preg_match( '/<(script|iframe|form)\b|\son[a-z]+\s*=|javascript\s*:/i', $content ) ) { throw new \InvalidArgumentException( 'Draft content contains prohibited active content.' ); }
		if ( preg_match( '/```|\[[^\]]+\]/', $content ) ) { throw new \InvalidArgumentException( 'Draft content contains a prohibited Markdown fence or shortcode.' ); }
		$allowed_html = array(
			'p' => array(), 'h2' => array(), 'h3' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
			'strong' => array(), 'em' => array(),
		);
		$clean_content = wp_kses( $content, $allowed_html );
		if ( $clean_content !== $content ) { throw new \InvalidArgumentException( 'Draft content contains unsupported or unsafe HTML.' ); }
		$allowed_ids = array_map( 'absint', array_column( (array) ( $context['allowed_internal_links'] ?? array() ), 'id' ) );
		$link_ids = array_values( array_unique( array_map( 'absint', (array) ( $draft['suggested_internal_link_ids'] ?? array() ) ) ) );
		if ( array_diff( $link_ids, $allowed_ids ) ) { throw new \InvalidArgumentException( 'Draft contains an invalid internal-link target.' ); }
		return array(
			'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'content' => $clean_content,
			'seo_title' => substr( $seo_title, 0, 200 ),
			'meta_description' => substr( $meta_description, 0, 320 ),
			'suggested_internal_link_ids' => $link_ids,
		);
	}

	private function titles_correspond( string $generated, string $approved ): bool {
		$a = array_values( array_unique( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( $generated ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) );
		$b = array_values( array_unique( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( $approved ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) );
		return ! empty( $a ) && ! empty( $b ) && count( array_intersect( $a, $b ) ) / max( 1, min( count( $a ), count( $b ) ) ) >= 0.5;
	}
}
