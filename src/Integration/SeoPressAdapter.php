<?php

namespace Global360\AI\Integration;

class SeoPressAdapter {
	public const TITLE_META = '_seopress_titles_title';
	public const DESCRIPTION_META = '_seopress_titles_desc';

	public function available(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	public function transfer( int $post_id, string $title, string $description ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		update_post_meta( $post_id, self::TITLE_META, sanitize_text_field( $title ) );
		update_post_meta( $post_id, self::DESCRIPTION_META, sanitize_textarea_field( $description ) );
		return true;
	}
}
