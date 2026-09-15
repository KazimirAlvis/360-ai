<?php

namespace Global360\AI\Content;

use Global360\AI\Admin\AdminPage;
use Global360\AI\Integration\SeoPressAdapter;

final class ContentDraftPostType {
	public const POST_TYPE = 'g360_content';
	public const TAXONOMY = 'g360_content_tag';
	public const PROMOTED_META = '_global360_ai_promoted_at';
	private const EDIT_NONCE = 'global360_ai_content_draft_fields';
	private $seo;
	private $promotion_form_post_id = 0;
	private static $publication_collision = array();
	private static $promotion_required = array();

	public function __construct( SeoPressAdapter $seo = null ) { $this->seo = $seo ?: new SeoPressAdapter(); }

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_workspaces' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'filter_admin_workspace' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_editorial_fields' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'record_collision_state' ), 20, 2 );
		add_action( 'admin_post_global360_promote_content', array( $this, 'handle_promotion' ) );
		add_action( 'admin_footer-post.php', array( $this, 'render_promotion_form' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'validate_publication' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'root_permalink' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'resolve_root_request' ) );
		add_filter( 'option_seopress_xml_sitemap_option_name', array( $this, 'include_in_seopress_sitemap' ) );
		add_filter( 'seopress_sitemaps_index_post_types_query', array( $this, 'filter_seopress_sitemap_query' ), 10, 2 );
		add_filter( 'seopress_sitemaps_single_query', array( $this, 'filter_seopress_sitemap_query' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_value' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_tag_filter' ) );
	}

	public function register_taxonomy(): void {
		register_taxonomy( self::TAXONOMY, array( self::POST_TYPE ), array(
			'labels' => array( 'name' => 'Content Tags', 'singular_name' => 'Content Tag', 'search_items' => 'Search Content Tags', 'all_items' => 'All Content Tags', 'edit_item' => 'Edit Content Tag', 'update_item' => 'Update Content Tag', 'add_new_item' => 'Add New Content Tag', 'new_item_name' => 'New Content Tag Name', 'menu_name' => 'Content Tags' ),
			'public' => false, 'publicly_queryable' => false, 'show_ui' => true, 'show_in_menu' => false,
			'show_in_nav_menus' => false, 'show_in_rest' => true, 'show_tagcloud' => false,
			'show_admin_column' => false, 'hierarchical' => false, 'query_var' => false, 'rewrite' => false,
			'capabilities' => array( 'manage_terms' => 'manage_options', 'edit_terms' => 'manage_options', 'delete_terms' => 'manage_options', 'assign_terms' => 'manage_options' ),
		) );
	}

	public function register_post_type(): void {
		$drafts = $this->is_draft_workspace();
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name' => $drafts ? 'Content Drafts' : 'Content', 'singular_name' => $drafts ? 'Content Draft' : 'Content', 'menu_name' => $drafts ? 'Content Drafts' : 'Content',
				'edit_item' => $drafts ? 'Edit Content Draft' : 'Edit Content', 'view_item' => 'View Content', 'search_items' => $drafts ? 'Search Content Drafts' : 'Search Content',
				'not_found' => $drafts ? 'No content drafts found.' : 'No content found.', 'all_items' => $drafts ? 'Content Drafts' : 'Content',
			),
			'public' => true, 'publicly_queryable' => true, 'exclude_from_search' => false,
			'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => true,
			'query_var' => self::POST_TYPE, 'rewrite' => false, 'has_archive' => false, 'show_in_nav_menus' => true,
			'supports' => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail' ),
			'map_meta_cap' => false,
			'capabilities' => array(
				'edit_post' => 'manage_options', 'read_post' => 'manage_options', 'delete_post' => 'manage_options',
				'edit_posts' => 'manage_options', 'edit_others_posts' => 'manage_options', 'delete_posts' => 'manage_options',
				'delete_private_posts' => 'manage_options', 'delete_published_posts' => 'manage_options',
				'edit_private_posts' => 'manage_options', 'edit_published_posts' => 'manage_options',
				'publish_posts' => 'manage_options', 'read_private_posts' => 'manage_options', 'create_posts' => 'do_not_allow',
			),
		) );
	}

	public function register_admin_workspaces(): void {
		add_submenu_page( AdminPage::MENU_SLUG, 'Content Drafts', 'Content Drafts', AdminPage::CAPABILITY, 'edit.php?post_type=' . self::POST_TYPE . '&g360_library=drafts' );
		add_submenu_page( AdminPage::MENU_SLUG, 'Content', 'Content', AdminPage::CAPABILITY, 'edit.php?post_type=' . self::POST_TYPE . '&g360_library=content' );
		add_submenu_page( AdminPage::MENU_SLUG, 'Content Tags', 'Content Tags', AdminPage::CAPABILITY, 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=' . self::POST_TYPE );
	}

	public function filter_admin_workspace( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) { return; }
		$workspace = isset( $_GET['g360_library'] ) ? sanitize_key( wp_unslash( $_GET['g360_library'] ) ) : 'drafts';
		$query->set( 'meta_query', array( array( 'key' => self::PROMOTED_META, 'compare' => 'content' === $workspace ? 'EXISTS' : 'NOT EXISTS' ) ) );
		$term_id = isset( $_GET[ self::TAXONOMY ] ) ? absint( $_GET[ self::TAXONOMY ] ) : 0;
		if ( $term_id ) { $query->set( 'tax_query', array( array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => array( $term_id ) ) ) ); }
	}

	public function register_meta_boxes( \WP_Post $post ): void {
		if ( $this->is_promoted( $post->ID ) ) {
			add_meta_box( 'global360-content-provenance', 'Content Details', array( $this, 'render_provenance' ), self::POST_TYPE, 'side', 'default' );
			return;
		}
		add_meta_box( 'global360-content-draft-details', 'Editorial Details', array( $this, 'render_editorial_details' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'global360-content-promotion', 'Move to Content', array( $this, 'render_promotion' ), self::POST_TYPE, 'side', 'high' );
	}

	public function render_editorial_details( \WP_Post $post ): void {
		wp_nonce_field( self::EDIT_NONCE, 'global360_ai_content_draft_nonce' );
		$slug = (string) get_post_meta( $post->ID, '_global360_ai_final_slug', true );
		$title = (string) get_post_meta( $post->ID, '_global360_ai_seo_title', true );
		$description = (string) get_post_meta( $post->ID, '_global360_ai_meta_description', true );
		$keyphrases = (array) get_post_meta( $post->ID, '_global360_ai_target_keyphrases', true );
		echo '<p><label for="global360-ai-final-slug"><strong>Intended final slug</strong></label><br><input class="widefat" id="global360-ai-final-slug" name="global360_ai_final_slug" type="text" value="' . esc_attr( $slug ) . '"></p>';
		echo '<p><label for="global360-ai-seo-title"><strong>SEO title</strong></label><br><input class="widefat" id="global360-ai-seo-title" name="global360_ai_seo_title" type="text" value="' . esc_attr( $title ) . '"></p>';
		echo '<p><label for="global360-ai-meta-description"><strong>Meta description</strong></label><br><textarea class="widefat" rows="4" id="global360-ai-meta-description" name="global360_ai_meta_description">' . esc_textarea( $description ) . '</textarea></p>';
		echo '<p><label for="global360-ai-target-keyphrases"><strong>Target keyphrases</strong></label><br><textarea class="widefat" rows="3" id="global360-ai-target-keyphrases" name="global360_ai_target_keyphrases">' . esc_textarea( implode( "\n", $keyphrases ) ) . '</textarea><br><span class="description">One reviewed phrase per line; the first is primary.</span></p>';
		echo '<p>These draft values populate SEOPress when this item is moved into Content.</p>';
	}

	public function render_promotion( \WP_Post $post ): void {
		$this->promotion_form_post_id = $post->ID;
		echo '<p>Move this reviewed draft into the permanent 360 Content library. This does not publish it and does not call an AI provider.</p>';
		echo '<button class="button button-primary" type="submit" form="global360-ai-promote-form-' . esc_attr( (string) $post->ID ) . '">Move to Content</button>';
	}

	public function render_promotion_form(): void {
		$post_id = (int) $this->promotion_form_post_id;
		if ( 1 > $post_id ) { return; }
		echo '<form id="global360-ai-promote-form-' . esc_attr( (string) $post_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="global360_promote_content"><input type="hidden" name="content_draft_id" value="' . esc_attr( (string) $post_id ) . '">';
		wp_nonce_field( 'global360_ai_promote_content_' . $post_id );
		echo '</form>';
	}

	public function render_provenance( \WP_Post $post ): void {
		$type = (string) get_post_meta( $post->ID, '_global360_ai_intended_post_type', true );
		$opportunity = (string) get_post_meta( $post->ID, '_global360_ai_opportunity_id', true );
		echo '<p><strong>Original classification:</strong> ' . esc_html( ucfirst( $type ) ) . '</p>';
		if ( '' !== $opportunity ) { echo '<p><strong>Opportunity:</strong> ' . esc_html( $opportunity ) . '</p>'; }
		echo '<p><strong>Moved to Content:</strong> ' . esc_html( (string) get_post_meta( $post->ID, self::PROMOTED_META, true ) ) . '</p>';
	}

	public function save_editorial_fields( int $post_id, \WP_Post $post ): void {
		if ( self::POST_TYPE !== $post->post_type || $this->is_promoted( $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
		if ( ! isset( $_POST['global360_ai_content_draft_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['global360_ai_content_draft_nonce'] ) ), self::EDIT_NONCE ) ) { return; }
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) { return; }
		if ( isset( $_POST['global360_ai_final_slug'] ) ) { update_post_meta( $post_id, '_global360_ai_final_slug', sanitize_title( wp_unslash( $_POST['global360_ai_final_slug'] ) ) ); }
		if ( isset( $_POST['global360_ai_seo_title'] ) ) { update_post_meta( $post_id, '_global360_ai_seo_title', sanitize_text_field( wp_unslash( $_POST['global360_ai_seo_title'] ) ) ); }
		if ( isset( $_POST['global360_ai_meta_description'] ) ) { update_post_meta( $post_id, '_global360_ai_meta_description', sanitize_textarea_field( wp_unslash( $_POST['global360_ai_meta_description'] ) ) ); }
		if ( isset( $_POST['global360_ai_target_keyphrases'] ) ) {
			$phrases = preg_split( '/[\r\n,]+/', (string) wp_unslash( $_POST['global360_ai_target_keyphrases'] ) );
			$phrases = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $phrases ?: array() ) ) ), 0, 5 );
			update_post_meta( $post_id, '_global360_ai_target_keyphrases', $phrases );
		}
	}

	public function handle_promotion(): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) { wp_die( 'You are not allowed to move Content Drafts.', '', array( 'response' => 403 ) ); }
		$post_id = isset( $_POST['content_draft_id'] ) ? absint( $_POST['content_draft_id'] ) : 0;
		check_admin_referer( 'global360_ai_promote_content_' . $post_id );
		try { $result = $this->promote( $post_id, get_current_user_id() ); }
		catch ( \DomainException $error ) { $this->redirect_after_promotion( $post_id, 'collision' ); }
		catch ( \Throwable $error ) { $this->redirect_after_promotion( $post_id, 'failed' ); }
		$this->redirect_after_promotion( $post_id, ! empty( $result['promoted'] ) ? 'moved' : 'already' );
	}

	/** @return array{post_id:int,promoted:bool} */
	public function promote( int $post_id, int $actor_id ): array {
		if ( ! user_can( $actor_id, AdminPage::CAPABILITY ) ) { throw new \DomainException( 'You are not allowed to move Content Drafts.' ); }
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) { throw new \InvalidArgumentException( 'Invalid Content Draft.' ); }
		if ( $this->is_promoted( $post_id ) ) { return array( 'post_id' => $post_id, 'promoted' => false ); }
		$slug = sanitize_title( (string) get_post_meta( $post_id, '_global360_ai_final_slug', true ) );
		if ( '' === $slug ) { $slug = sanitize_title( $post->post_title ); }
		if ( '' === $slug || $this->has_public_slug_collision( $slug, $post_id ) ) { throw new \DomainException( 'The intended final slug is already reserved.' ); }
		$result = wp_update_post( array( 'ID' => $post_id, 'post_name' => $slug, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) ) { throw new \RuntimeException( 'WordPress could not move the draft into Content.' ); }
		$this->seo->initialize( $post_id, (string) get_post_meta( $post_id, '_global360_ai_seo_title', true ), (string) get_post_meta( $post_id, '_global360_ai_meta_description', true ), (array) get_post_meta( $post_id, '_global360_ai_target_keyphrases', true ) );
		update_post_meta( $post_id, self::PROMOTED_META, gmdate( 'c' ) );
		update_post_meta( $post_id, '_global360_ai_promoted_by', $actor_id );
		return array( 'post_id' => $post_id, 'promoted' => true );
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $postarr @return array<string,mixed> */
	public function validate_publication( array $data, array $postarr ): array {
		if ( self::POST_TYPE !== ( $data['post_type'] ?? '' ) || 'publish' !== ( $data['post_status'] ?? '' ) ) { return $data; }
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $this->is_promoted( $post_id ) ) { self::$promotion_required[ $post_id ] = true; $data['post_status'] = 'draft'; return $data; }
		$slug = sanitize_title( (string) ( $data['post_name'] ?? '' ) );
		if ( '' === $slug || $this->has_public_slug_collision( $slug, $post_id ) ) { self::$publication_collision[ $post_id ] = true; $data['post_status'] = 'draft'; }
		return $data;
	}

	public function record_collision_state( int $post_id, \WP_Post $post ): void {
		if ( isset( self::$publication_collision[ $post_id ] ) ) { update_post_meta( $post_id, '_global360_ai_slug_collision', 1 ); }
		elseif ( $this->is_promoted( $post_id ) && ! $this->has_public_slug_collision( (string) $post->post_name, $post_id ) ) { delete_post_meta( $post_id, '_global360_ai_slug_collision' ); }
		if ( isset( self::$promotion_required[ $post_id ] ) ) { update_post_meta( $post_id, '_global360_ai_promotion_required', 1 ); }
		elseif ( $this->is_promoted( $post_id ) ) { delete_post_meta( $post_id, '_global360_ai_promotion_required' ); }
	}

	public function root_permalink( $url, \WP_Post $post ): string {
		if ( self::POST_TYPE !== $post->post_type || ! $this->is_promoted( $post->ID ) || '' === (string) $post->post_name ) { return (string) $url; }
		return home_url( user_trailingslashit( $post->post_name ) );
	}

	public function resolve_root_request( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) { return; }
		$slug = sanitize_title( (string) $query->get( 'name' ) ); $post_type = $query->get( 'post_type' );
		if ( '' === $slug || ( $post_type && 'post' !== $post_type ) || get_page_by_path( $slug, OBJECT, 'post' ) ) { return; }
		$query->set( 'post_type', self::POST_TYPE );
		$query->set( 'meta_query', array( array( 'key' => self::PROMOTED_META, 'compare' => 'EXISTS' ) ) );
	}

	/** @param mixed $options @return mixed */
	public function include_in_seopress_sitemap( $options ) {
		if ( ! is_array( $options ) ) { return $options; }
		$options['seopress_xml_sitemap_post_types_list'][ self::POST_TYPE ]['include'] = '1'; return $options;
	}

	/** @param array<string,mixed> $args @return array<string,mixed> */
	public function filter_seopress_sitemap_query( array $args, string $post_type ): array {
		if ( self::POST_TYPE === $post_type ) { $args['meta_query'][] = array( 'key' => self::PROMOTED_META, 'compare' => 'EXISTS' ); }
		return $args;
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public function columns( array $columns ): array { return array( 'cb' => $columns['cb'] ?? '<input type="checkbox">', 'title' => 'Title', 'content_tags' => 'Content Tags', 'status' => 'Status', 'workflow' => 'Workflow', 'date' => 'Date' ); }
	public function column_value( string $column, int $post_id ): void {
		if ( 'content_tags' === $column ) { $tags = wp_get_post_terms( $post_id, self::TAXONOMY, array( 'fields' => 'names' ) ); echo esc_html( is_wp_error( $tags ) || empty( $tags ) ? '—' : implode( ', ', $tags ) ); }
		if ( 'status' === $column ) { echo esc_html( ucfirst( (string) get_post_status( $post_id ) ) ); }
		if ( 'workflow' === $column ) { echo esc_html( $this->is_promoted( $post_id ) ? 'Content' : 'Editorial draft' ); }
	}

	public function render_tag_filter(): void {
		global $typenow;
		if ( self::POST_TYPE !== $typenow ) { return; }
		wp_dropdown_categories( array( 'show_option_all' => 'All Content Tags', 'taxonomy' => self::TAXONOMY, 'name' => self::TAXONOMY, 'orderby' => 'name', 'selected' => isset( $_GET[ self::TAXONOMY ] ) ? absint( $_GET[ self::TAXONOMY ] ) : 0, 'hierarchical' => false, 'hide_empty' => false, 'value_field' => 'term_id' ) );
	}

	/** @param array<string,string> $actions @return array<string,string> */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( self::POST_TYPE === $post->post_type ) { unset( $actions['inline hide-if-no-js'] ); if ( ! $this->is_promoted( $post->ID ) ) { unset( $actions['view'] ); } }
		return $actions;
	}

	public function render_admin_notice(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$status = isset( $_GET['global360_ai_promotion'] ) ? sanitize_key( wp_unslash( $_GET['global360_ai_promotion'] ) ) : '';
		if ( 'moved' === $status ) { echo '<div class="notice notice-success is-dismissible"><p>Content Draft moved to the permanent Content library. It remains unpublished.</p></div>'; }
		if ( 'already' === $status ) { echo '<div class="notice notice-info is-dismissible"><p>This item is already in Content; no duplicate was created.</p></div>'; }
		if ( 'collision' === $status || ( $post_id && get_post_meta( $post_id, '_global360_ai_slug_collision', true ) ) ) { echo '<div class="notice notice-error"><p>This item cannot move to or publish in Content because its root URL slug is already reserved by existing public content. Choose a unique intended final slug.</p></div>'; }
		if ( 'failed' === $status ) { echo '<div class="notice notice-error"><p>Content promotion failed safely. No content was published.</p></div>'; }
		if ( $post_id && get_post_meta( $post_id, '_global360_ai_promotion_required', true ) ) { echo '<div class="notice notice-error"><p>This editorial draft must be moved to Content before it can be published.</p></div>'; }
	}

	private function redirect_after_promotion( int $post_id, string $status ): void { wp_safe_redirect( add_query_arg( 'global360_ai_promotion', $status, admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) ); exit; }
	private function is_promoted( int $post_id ): bool { return 0 < $post_id && metadata_exists( 'post', $post_id, self::PROMOTED_META ); }
	private function is_draft_workspace(): bool {
		if ( ! is_admin() ) { return false; }
		if ( isset( $_GET['g360_library'] ) ) { return 'drafts' === sanitize_key( wp_unslash( $_GET['g360_library'] ) ); }
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		return $post_id ? ! $this->is_promoted( $post_id ) : false;
	}

	private function has_public_slug_collision( string $slug, int $content_id ): bool {
		global $wpdb;
		$slug = sanitize_title( $slug ); if ( '' === $slug ) { return true; }
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND ID != %d AND post_status NOT IN ('trash','auto-draft','inherit')", $slug, $content_id ) );
		foreach ( (array) $candidates as $candidate ) {
			if ( self::POST_TYPE === $candidate->post_type ) { return true; }
			$object = get_post_type_object( $candidate->post_type ); if ( $object && ( $object->public || $object->publicly_queryable ) ) { return true; }
		}
		$resolved_id = url_to_postid( home_url( '/' . $slug . '/' ) );
		if ( $resolved_id && $content_id !== $resolved_id ) { return true; }
		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			if ( self::POST_TYPE === $post_type->name || ( ! $post_type->public && ! $post_type->publicly_queryable ) || ! is_array( $post_type->rewrite ) ) { continue; }
			$rewrite_slug = trim( (string) ( $post_type->rewrite['slug'] ?? '' ), '/' );
			if ( '' !== $rewrite_slug && false === strpos( $rewrite_slug, '/' ) && $slug === $rewrite_slug ) { return true; }
		}
		$path = trim( (string) wp_parse_url( home_url( '/' . $slug . '/' ), PHP_URL_PATH ), '/' );
		return (bool) apply_filters( 'global360_ai_reserved_root_slug', false, $slug, $path );
	}
}
