<?php

namespace Global360\AI\Content;

use Global360\AI\Admin\AdminPage;
use Global360\AI\Generation\ConversionService;

final class ContentDraftPostType {
	public const POST_TYPE = 'g360_content';
	private const EDIT_NONCE = 'global360_ai_content_draft_fields';
	private $conversion;
	private $conversion_form_post_id = 0;

	public function __construct( ConversionService $conversion ) {
		$this->conversion = $conversion;
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_editorial_fields' ), 10, 2 );
		add_action( 'admin_post_global360_convert_content_draft', array( $this, 'handle_conversion' ) );
		add_action( 'admin_footer-post.php', array( $this, 'render_conversion_form' ) );
		add_action( 'admin_notices', array( $this, 'render_conversion_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_value' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'exclude_from_sitemaps' ) );
	}

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name' => 'Content Drafts', 'singular_name' => 'Content Draft', 'menu_name' => 'Content Drafts',
				'edit_item' => 'Edit Content Draft', 'view_item' => 'View Content Draft', 'search_items' => 'Search Content Drafts',
				'not_found' => 'No content drafts found.', 'all_items' => 'Content Drafts',
			),
			'public' => false,
			'publicly_queryable' => false,
			'exclude_from_search' => true,
			'show_ui' => true,
			'show_in_menu' => AdminPage::MENU_SLUG,
			'show_in_rest' => false,
			'query_var' => false,
			'rewrite' => false,
			'has_archive' => false,
			'supports' => array( 'title', 'editor', 'excerpt', 'author' ),
			'map_meta_cap' => false,
			'capabilities' => array(
				'edit_post' => 'manage_options', 'read_post' => 'manage_options', 'delete_post' => 'manage_options',
				'edit_posts' => 'manage_options', 'edit_others_posts' => 'manage_options', 'delete_posts' => 'manage_options',
				'delete_private_posts' => 'manage_options', 'delete_published_posts' => 'manage_options',
				'edit_private_posts' => 'manage_options', 'edit_published_posts' => 'manage_options',
				'publish_posts' => 'do_not_allow', 'read_private_posts' => 'manage_options', 'create_posts' => 'do_not_allow',
			),
		) );
	}

	public function register_meta_boxes(): void {
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
		add_meta_box( 'global360-content-draft-save', 'Review Status', array( $this, 'render_save_box' ), self::POST_TYPE, 'side', 'high' );
		add_meta_box( 'global360-content-draft-details', 'Content Details', array( $this, 'render_details' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'global360-content-draft-conversion', 'Create WordPress Draft', array( $this, 'render_conversion' ), self::POST_TYPE, 'side', 'high' );
	}

	public function render_save_box( \WP_Post $post ): void {
		echo '<p><strong>Private editorial draft</strong></p><p>This item has no public URL and cannot be published directly.</p>';
		echo '<div class="major-publishing-actions"><div class="delete-action"><a class="submitdelete deletion" href="' . esc_url( get_delete_post_link( $post->ID, '', true ) ) . '">Move to Trash</a></div><div class="publishing-action">';
		submit_button( 'Save Content Draft', 'primary', 'save', false );
		echo '</div><div class="clear"></div></div>';
	}

	public function render_details( \WP_Post $post ): void {
		wp_nonce_field( self::EDIT_NONCE, 'global360_ai_content_draft_nonce' );
		$slug = (string) get_post_meta( $post->ID, '_global360_ai_final_slug', true );
		$seo_title = (string) get_post_meta( $post->ID, '_global360_ai_seo_title', true );
		$description = (string) get_post_meta( $post->ID, '_global360_ai_meta_description', true );
		$type = (string) get_post_meta( $post->ID, '_global360_ai_intended_post_type', true );
		echo '<p><strong>Intended final type:</strong> ' . esc_html( ucfirst( $type ) ) . '</p>';
		echo '<p><label for="global360-ai-final-slug"><strong>Final slug</strong></label><br><input class="widefat" id="global360-ai-final-slug" name="global360_ai_final_slug" type="text" value="' . esc_attr( $slug ) . '"></p>';
		echo '<p><label for="global360-ai-seo-title"><strong>SEO title</strong></label><br><input class="widefat" id="global360-ai-seo-title" name="global360_ai_seo_title" type="text" value="' . esc_attr( $seo_title ) . '"></p>';
		echo '<p><label for="global360-ai-meta-description"><strong>Meta description</strong></label><br><textarea class="widefat" rows="4" id="global360-ai-meta-description" name="global360_ai_meta_description">' . esc_textarea( $description ) . '</textarea></p>';
	}

	public function render_conversion( \WP_Post $post ): void {
		$final_id = (int) get_post_meta( $post->ID, '_global360_ai_final_post_id', true );
		if ( $final_id && get_post( $final_id ) ) {
			$url = get_edit_post_link( $final_id, 'raw' );
			$type = 'page' === get_post_type( $final_id ) ? 'Page' : 'Post';
			echo '<p>Converted to ' . esc_html( $type ) . '.</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">Edit ' . esc_html( $type ) . '</a></p>';
			return;
		}
		$type = (string) get_post_meta( $post->ID, '_global360_ai_intended_post_type', true );
		$this->conversion_form_post_id = $post->ID;
		echo '<p>Save any review changes first, then convert the current Content Draft into one editable ' . esc_html( ucfirst( $type ) ) . ' draft. This does not publish content or call an AI provider.</p>';
		echo '<button class="button button-primary" type="submit" form="global360-ai-convert-form-' . esc_attr( (string) $post->ID ) . '">Convert to ' . esc_html( ucfirst( $type ) ) . '</button>';
	}

	public function render_conversion_form(): void {
		$post_id = (int) $this->conversion_form_post_id;
		if ( 1 > $post_id ) { return; }
		echo '<form id="global360-ai-convert-form-' . esc_attr( (string) $post_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="global360_convert_content_draft"><input type="hidden" name="content_draft_id" value="' . esc_attr( (string) $post_id ) . '">';
		wp_nonce_field( 'global360_ai_convert_content_draft_' . $post_id );
		echo '</form>';
	}

	public function save_editorial_fields( int $post_id, \WP_Post $post ): void {
		if ( self::POST_TYPE !== $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
		if ( ! isset( $_POST['global360_ai_content_draft_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['global360_ai_content_draft_nonce'] ) ), self::EDIT_NONCE ) ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( isset( $_POST['global360_ai_final_slug'] ) ) { update_post_meta( $post_id, '_global360_ai_final_slug', sanitize_title( wp_unslash( $_POST['global360_ai_final_slug'] ) ) ); }
		if ( isset( $_POST['global360_ai_seo_title'] ) ) { update_post_meta( $post_id, '_global360_ai_seo_title', sanitize_text_field( wp_unslash( $_POST['global360_ai_seo_title'] ) ) ); }
		if ( isset( $_POST['global360_ai_meta_description'] ) ) { update_post_meta( $post_id, '_global360_ai_meta_description', sanitize_textarea_field( wp_unslash( $_POST['global360_ai_meta_description'] ) ) ); }
	}

	public function handle_conversion(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You are not allowed to convert Content Drafts.', '', array( 'response' => 403 ) ); }
		$content_draft_id = isset( $_POST['content_draft_id'] ) ? absint( $_POST['content_draft_id'] ) : 0;
		check_admin_referer( 'global360_ai_convert_content_draft_' . $content_draft_id );
		try {
			$result = $this->conversion->convert( $content_draft_id, get_current_user_id() );
			$notice = ! empty( $result['created'] ) ? 'created' : 'exists';
		} catch ( \Throwable $error ) {
			$notice = 'failed';
		}
		wp_safe_redirect( add_query_arg( 'global360_ai_conversion', $notice, admin_url( 'post.php?post=' . $content_draft_id . '&action=edit' ) ) );
		exit;
	}

	public function render_conversion_notice(): void {
		if ( ! isset( $_GET['global360_ai_conversion'] ) ) { return; }
		$status = sanitize_key( wp_unslash( $_GET['global360_ai_conversion'] ) );
		if ( 'created' === $status ) { echo '<div class="notice notice-success is-dismissible"><p>WordPress draft created. Publication still requires a separate editorial action.</p></div>'; }
		if ( 'exists' === $status ) { echo '<div class="notice notice-info is-dismissible"><p>This Content Draft was already converted; no duplicate was created.</p></div>'; }
		if ( 'failed' === $status ) { echo '<div class="notice notice-error"><p>Conversion failed safely. No content was published.</p></div>'; }
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public function columns( array $columns ): array {
		return array( 'cb' => $columns['cb'] ?? '<input type="checkbox">', 'title' => 'Title', 'intended_type' => 'Final Type', 'opportunity' => 'Opportunity', 'provider' => 'Provider / Model', 'conversion' => 'Conversion', 'date' => 'Generated' );
	}

	public function column_value( string $column, int $post_id ): void {
		if ( 'intended_type' === $column ) { echo esc_html( ucfirst( (string) get_post_meta( $post_id, '_global360_ai_intended_post_type', true ) ) ); }
		if ( 'opportunity' === $column ) { echo esc_html( (string) get_post_meta( $post_id, '_global360_ai_opportunity_id', true ) ); }
		if ( 'provider' === $column ) { echo esc_html( trim( (string) get_post_meta( $post_id, '_global360_ai_provider', true ) . ' / ' . (string) get_post_meta( $post_id, '_global360_ai_model', true ), ' /' ) ); }
		if ( 'conversion' === $column ) {
			$final_id = (int) get_post_meta( $post_id, '_global360_ai_final_post_id', true );
			echo $final_id ? '<a href="' . esc_url( get_edit_post_link( $final_id, 'raw' ) ) . '">Draft #' . esc_html( (string) $final_id ) . '</a>' : 'Not converted';
		}
	}

	/** @param array<string,string> $actions @return array<string,string> */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( self::POST_TYPE === $post->post_type ) { unset( $actions['view'], $actions['inline hide-if-no-js'] ); }
		return $actions;
	}

	/** @param array<string,mixed> $post_types @return array<string,mixed> */
	public function exclude_from_sitemaps( array $post_types ): array { unset( $post_types[ self::POST_TYPE ] ); return $post_types; }
}
