<?php

namespace Global360\AI\Context;

final class AnalysisContentReader {
	private const MAX_ITEMS = 40;
	private const MAX_TEXT  = 900;

	/** @return array<int,array<string,mixed>> */
	public function read(): array {
		$ids = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_ITEMS,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
				continue;
			}
			$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true );
			$text = preg_replace( '/\s+/', ' ', $text );
			$items[] = array(
				'id'         => (int) $post->ID,
				'type'       => (string) $post->post_type,
				'title'      => get_the_title( $post ),
				'slug'       => (string) $post->post_name,
				'permalink'  => get_permalink( $post ),
				'modified'   => (string) $post->post_modified_gmt,
				'excerpt'    => wp_trim_words( (string) $post->post_excerpt, 35, '' ),
				'source_text'=> function_exists( 'mb_substr' ) ? mb_substr( trim( $text ), 0, self::MAX_TEXT ) : substr( trim( $text ), 0, self::MAX_TEXT ),
				'taxonomies' => $this->terms( (int) $post->ID ),
			);
		}

		return $items;
	}

	/** @return array<string,array<int,string>> */
	private function terms( int $post_id ): array {
		$result = array( 'categories' => array(), 'tags' => array() );
		foreach ( array( 'category' => 'categories', 'post_tag' => 'tags' ) as $taxonomy => $key ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
			$result[ $key ] = is_wp_error( $terms ) ? array() : array_values( array_map( 'sanitize_text_field', $terms ) );
		}
		return $result;
	}
}
