<?php

namespace Global360\AI\Context;

final class ContentInventory {
	/** @return array<string,int> */
	public function summary(): array {
		return array(
			'pages' => $this->published_count( 'page' ),
			'posts' => $this->published_count( 'post' ),
			'content' => $this->published_count( 'g360_content' ),
		);
	}

	/**
	 * Return a bounded lightweight inventory for future modules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items( string $post_type, int $limit = 50, int $offset = 0 ): array {
		if ( ! in_array( $post_type, array( 'page', 'post', 'g360_content' ), true ) ) {
			return array();
		}

		global $wpdb;
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_title, post_name, post_modified_gmt
				FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = 'publish'
				ORDER BY post_modified_gmt DESC, ID DESC
				LIMIT %d OFFSET %d",
				$post_type,
				max( 1, min( 100, $limit ) ),
				max( 0, $offset )
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}
			$item = array(
				'id'       => (int) $post->ID,
				'post_type'=> (string) $post->post_type,
				'title'    => (string) $post->post_title,
				'slug'     => (string) $post->post_name,
				'permalink'=> get_permalink( (int) $post->ID ),
				'modified' => (string) $post->post_modified_gmt,
			);
			if ( 'g360_content' === $post_type ) { $terms = wp_get_post_terms( (int) $post->ID, 'g360_content_tag', array( 'fields' => 'names' ) ); $item['tags'] = is_wp_error( $terms ) ? array() : array_slice( array_values( array_map( 'sanitize_text_field', $terms ) ), 0, 6 ); }
			$items[] = $item;
		}

		return $items;
	}

	private function published_count( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
}
