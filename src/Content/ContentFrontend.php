<?php

namespace Global360\AI\Content;

final class ContentFrontend {
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
	}

	public function enqueue_assets(): void {
		if ( ! is_singular( ContentDraftPostType::POST_TYPE ) ) { return; }
		$path = GLOBAL360_AI_PATH . 'assets/content.css';
		wp_enqueue_style( 'global360-ai-content', plugins_url( 'assets/content.css', GLOBAL360_AI_FILE ), array(), is_file( $path ) ? (string) filemtime( $path ) : GLOBAL360_AI_VERSION );
	}
}
