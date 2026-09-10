<?php

namespace Global360\AI\Generation;

final class DraftValidator {
	private const MIN_CONTENT = 200;
	private const MIN_PAGE_WORDS = 450;
	private const MAX_CONTENT = 20000;
	private const ALLOWED_KEYS = array( 'title', 'slug', 'excerpt', 'content', 'seo_title', 'meta_description', 'suggested_internal_link_ids' );
	private const ALLOWED_HTML = array( 'p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em' );
	private const UNSAFE_HTML = array( 'script', 'iframe', 'form', 'input', 'button', 'object', 'embed', 'style', 'svg', 'video', 'audio', 'canvas' );

	/** @return array<string,mixed> */
	public function validate( array $draft, array $opportunity, array $context, array $completion = array() ): array {
		if ( array_diff( self::ALLOWED_KEYS, array_keys( $draft ) ) ) { throw new \InvalidArgumentException( 'Draft output is missing required fields.' ); }
		if ( array_diff( array_keys( $draft ), self::ALLOWED_KEYS ) ) { throw new \InvalidArgumentException( 'Draft output contains unexpected fields.' ); }
		$title = sanitize_text_field( $this->normalize_plain_text( (string) ( $draft['title'] ?? '' ) ) );
		$raw_slug = (string) ( $draft['slug'] ?? '' );
		$slug = sanitize_title( $raw_slug );
		$excerpt = sanitize_textarea_field( $this->normalize_plain_text( (string) ( $draft['excerpt'] ?? '' ) ) );
		$content = trim( (string) ( $draft['content'] ?? '' ) );
		$seo_title = sanitize_text_field( $this->normalize_plain_text( (string) ( $draft['seo_title'] ?? '' ) ) );
		$meta_description = sanitize_textarea_field( $this->normalize_plain_text( (string) ( $draft['meta_description'] ?? '' ) ) );
		if ( '' === $title ) { throw new \InvalidArgumentException( 'Draft title is required.' ); }
		if ( '' === $raw_slug || $slug !== $raw_slug ) { throw new \InvalidArgumentException( 'Draft slug is invalid.' ); }
		if ( '' === $excerpt ) { throw new \InvalidArgumentException( 'Draft excerpt is required.' ); }
		if ( '' === $content ) { throw new \InvalidArgumentException( 'Draft content is required.' ); }
		if ( '' === $seo_title || '' === $meta_description ) { throw new \InvalidArgumentException( 'Draft SEO title and meta description are required.' ); }
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( wp_strip_all_tags( $content ) ) : strlen( wp_strip_all_tags( $content ) );
		if ( self::MIN_CONTENT > $length || self::MAX_CONTENT < $length ) { throw new \InvalidArgumentException( 'Draft content length is outside the allowed range.' ); }
		if ( ! in_array( (string) ( $opportunity['content_type'] ?? '' ), array( 'page', 'post' ), true ) ) { throw new \InvalidArgumentException( 'Approved opportunity has an unexpected post type.' ); }
		if ( 'page' === (string) $opportunity['content_type'] && self::MIN_PAGE_WORDS > $this->word_count( $content ) ) {
			throw new DraftValidationException( 'Generated Page content is too short to be a useful complete draft.', array( 'validation_field' => 'content', 'validation_rule' => 'minimum_content' ) );
		}
		if ( sanitize_title( (string) ( $opportunity['suggested_slug'] ?? '' ) ) !== $slug || ! $this->titles_correspond( $title, (string) ( $opportunity['proposed_title'] ?? '' ) ) ) { throw new \InvalidArgumentException( 'Generated title or slug does not correspond to the approved opportunity.' ); }
		if ( preg_match( '/```|\[[^\]]+\]/', $content ) ) { throw new \InvalidArgumentException( 'Draft content contains a prohibited Markdown fence or shortcode.' ); }
		$allowed_html = array_fill_keys( self::ALLOWED_HTML, array() );
		$diagnostics = $this->html_diagnostics( $content );
		if ( $diagnostics['unsupported_tags'] || $diagnostics['unsupported_attributes'] || $diagnostics['unsafe_tags'] || $diagnostics['unsafe_attributes'] ) {
			throw new DraftValidationException( 'Draft content contains unsupported or unsafe HTML.', $diagnostics );
		}
		$clean_content = wp_kses( $content, $allowed_html );
		if ( $clean_content !== $content ) {
			throw new DraftValidationException( 'Draft content contains unsupported or unsafe HTML.', $diagnostics );
		}
		$this->validate_completion( $clean_content, $completion );
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

	private function validate_completion( string $content, array $completion ): void {
		$status = sanitize_key( (string) ( $completion['completion_status'] ?? '' ) );
		$reason = sanitize_key( (string) ( $completion['stop_reason'] ?? '' ) );
		$limited = ! empty( $completion['output_limit_reached'] ) || in_array( $reason, array( 'max_tokens', 'max_output_tokens' ), true );
		$text = trim( html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( $limited || in_array( $status, array( 'incomplete', 'refused' ), true ) || '' === $text || ! preg_match( '/[.!?](?:[\x{2019}\x{201D}\"\x{27})\]])*$/u', $text ) ) {
			throw new DraftValidationException( 'Generated draft content appears incomplete.', array(
				'validation_field' => 'content', 'validation_rule' => 'incomplete_content',
				'completion_status' => $status,
				'stop_reason' => $reason, 'output_limit_reached' => $limited,
			) );
		}
	}

	private function word_count( string $content ): int {
		$text = trim( wp_strip_all_tags( preg_replace( '/<[^>]+>/', ' ', $content ) ) );
		if ( '' === $text ) { return 0; }
		$count = preg_match_all( '/[\p{L}\p{N}]+(?:[\x{2019}\x{27}-][\p{L}\p{N}]+)*/u', $text, $matches );
		return false === $count ? 0 : $count;
	}

	private function normalize_plain_text( string $value ): string {
		return preg_replace( '/(?<![A-Za-z0-9])(?:\\\\u0026|u0026)(?![A-Za-z0-9])/i', '&', $value );
	}

	/** @return array<string,mixed> */
	private function html_diagnostics( string $content ): array {
		$result = array(
			'validation_field' => 'content', 'validation_rule' => 'unsupported_markup',
			'unsupported_tags' => array(), 'unsupported_attributes' => array(),
			'unsafe_tags' => array(), 'unsafe_attributes' => array(),
		);
		if ( ! class_exists( '\\DOMDocument' ) ) { return $result; }
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors(); libxml_use_internal_errors( $previous );
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			$tag = $this->token( strtolower( $element->nodeName ) );
			if ( '' === $tag || 'div' === $tag && $element === $document->documentElement ) { continue; }
			if ( in_array( $tag, self::UNSAFE_HTML, true ) ) { $result['unsafe_tags'][] = $tag; }
			elseif ( ! in_array( $tag, self::ALLOWED_HTML, true ) ) { $result['unsupported_tags'][] = $tag; }
			if ( ! $element->hasAttributes() ) { continue; }
			foreach ( $element->attributes as $attribute ) {
				$name = $this->token( strtolower( $attribute->nodeName ) );
				if ( '' === $name ) { continue; }
				$value = ltrim( strtolower( html_entity_decode( (string) $attribute->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( 0 === strpos( $name, 'on' ) || ( in_array( $name, array( 'href', 'src', 'action', 'formaction' ), true ) && 0 === strpos( $value, 'javascript:' ) ) ) {
					$result['unsafe_attributes'][] = $name;
				} else { $result['unsupported_attributes'][] = $name; }
			}
		}
		foreach ( array( 'unsupported_tags' => 10, 'unsafe_tags' => 10, 'unsupported_attributes' => 20, 'unsafe_attributes' => 20 ) as $key => $limit ) {
			$result[ $key ] = array_values( array_unique( $result[ $key ] ) ); sort( $result[ $key ], SORT_STRING ); $result[ $key ] = array_slice( $result[ $key ], 0, $limit );
		}
		if ( $result['unsafe_tags'] || $result['unsafe_attributes'] ) { $result['validation_rule'] = 'unsafe_markup'; }
		return $result;
	}

	private function token( string $value ): string { return substr( preg_replace( '/[^a-z0-9:_-]/', '', $value ), 0, 64 ); }

	private function titles_correspond( string $generated, string $approved ): bool {
		$a = array_values( array_unique( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( $generated ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) );
		$b = array_values( array_unique( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( $approved ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) );
		return ! empty( $a ) && ! empty( $b ) && count( array_intersect( $a, $b ) ) / max( 1, min( count( $a ), count( $b ) ) ) >= 0.5;
	}
}
