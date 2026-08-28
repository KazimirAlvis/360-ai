<?php

namespace Global360\AI\Context;

final class ContentInventory {
	/** @return array<string,int> */
	public function summary(): array {
		return array(
			'pages' => $this->published_count( 'page' ),
			'posts' => $this->published_count( 'post' ),
		);
	}

	/**
	 * Return a bounded lightweight inventory for future modules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items( string $post_type, int $limit = 50, int $offset = 0 ): array {
		if ( ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
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
			$items[] = array(
				'id'       => (int) $post->ID,
				'post_type'=> (string) $post->post_type,
				'title'    => (string) $post->post_title,
				'slug'     => (string) $post->post_name,
				'permalink'=> get_permalink( (int) $post->ID ),
				'modified' => (string) $post->post_modified_gmt,
			);
		}

		return $items;
	}

	private function published_count( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
}
