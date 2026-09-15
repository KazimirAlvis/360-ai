<?php

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'GLOBAL360_AI_USE_MOCK_PROVIDER', true );
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use Global360\AI\Admin\ContentOpportunitiesPage;
use Global360\AI\AI\MockProvider;
use Global360\AI\AI\ProviderFactory;
use Global360\AI\AI\ProviderInterface;
use Global360\AI\AI\AnalysisResponse;
use Global360\AI\Analysis\AnalysisContextBuilder;
use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Analysis\OpportunityValidator;
use Global360\AI\Context\AnalysisContentReader;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;
use Global360\AI\Content\ContentDraftPostType;
use Global360\AI\Generation\DraftGenerationContextBuilder;
use Global360\AI\Generation\DraftService;
use Global360\AI\Generation\DraftValidator;
use Global360\AI\Generation\GenerationLock;
use Global360\AI\Generation\DraftResponse;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Storage\WorkflowRepository;

function local_draft_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
function local_draft_meta( array $ids ): string { $data = array(); foreach ( $ids as $id ) { $data[ $id ] = get_post_meta( $id ); } return hash( 'sha256', serialize( $data ) ); }
function local_draft_published_hash( $wpdb ): string { return hash( 'sha256', serialize( $wpdb->get_results( "SELECT ID, post_author, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') ORDER BY ID", ARRAY_A ) ) ); }
function local_draft_view_count( array $records, string $analysis_id ): int { return count( array_filter( $records, static function ( $record ) use ( $analysis_id ) { return $analysis_id === ( $record['analysis_id'] ?? '' ); } ) ); }
function local_draft_same_terms( array $left, array $right ): bool { sort( $left, SORT_NATURAL | SORT_FLAG_CASE ); sort( $right, SORT_NATURAL | SORT_FLAG_CASE ); return $left === $right; }

final class LocalFailingDraftProvider implements ProviderInterface {
	public function name(): string { return 'fixture-failure'; } public function model(): string { return 'fixture'; } public function is_configured(): bool { return true; }
	public function analyze( array $context ): AnalysisResponse { return AnalysisResponse::failure( $this->name(), $this->model(), 'Not used.' ); }
	public function generate_draft( array $context ): DraftResponse { return DraftResponse::failure( $this->name(), $this->model(), 'Fixture provider failure.', array( 'http_status' => 429, 'provider_error_type' => 'rate_limit_error', 'provider_error_message' => 'Fixture.', 'provider_request_id' => 'req_fixture' ) ); }
}
final class LocalInvalidDraftProvider implements ProviderInterface {
	public static $calls = 0;
	public function name(): string { return 'fixture-invalid'; } public function model(): string { return 'fixture'; } public function is_configured(): bool { return true; }
	public function analyze( array $context ): AnalysisResponse { return AnalysisResponse::failure( $this->name(), $this->model(), 'Not used.' ); }
	public function generate_draft( array $context ): DraftResponse { self::$calls++; $response = ( new MockProvider() )->generate_draft( $context ); $response->draft['content'] .= '<h4 class="private-value" onclick="privateValue()">Private generated article text must not persist.</h4>'; $response->usage = array( 'input_tokens' => 321, 'output_tokens' => 654, 'total_tokens' => 975 ); return $response; }
}
final class LocalProviderFactory extends ProviderFactory {
	private $fixture;
	public function __construct( ProviderInterface $fixture ) { $this->fixture = $fixture; }
	public function create(): ProviderInterface { return $this->fixture; }
}

global $wpdb;
$history_option = 'global360_ai_analysis_history';
$workflow_option = 'global360_ai_opportunity_workflow';
$original_history = get_option( $history_option, array() );
$original_workflow = get_option( $workflow_option, array() );
$entity_ids = get_posts( array( 'post_type' => array( 'clinic', 'doctor' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$before_meta = local_draft_meta( $entity_ids );
$before_seopress = hash( 'sha256', serialize( array(
	$wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%seopress%' ORDER BY option_name", ARRAY_A ),
	$wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '_seopress_%' ORDER BY meta_id", ARRAY_A ),
) ) );
$before_pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'" );
$before_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
$before_published_hash = local_draft_published_hash( $wpdb );
$historical_ids = array( 17526, 17528, 17530 ); $historical_before = array(); foreach ( $historical_ids as $historical_id ) { $historical_before[ $historical_id ] = get_post( $historical_id ) ? hash( 'sha256', serialize( array( get_post( $historical_id ), get_post_meta( $historical_id ) ) ) ) : ''; }
$existing_content_ids = get_posts( array( 'post_type' => ContentDraftPostType::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$existing_content_before = array(); foreach ( $existing_content_ids as $existing_content_id ) { $existing_content_before[ $existing_content_id ] = hash( 'sha256', serialize( array( get_post( $existing_content_id ), get_post_meta( $existing_content_id ) ) ) ); }
$existing_tag_ids = get_terms( array( 'taxonomy' => ContentDraftPostType::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ); if ( is_wp_error( $existing_tag_ids ) ) { $existing_tag_ids = array(); }
$created = array();

try {
	$link_ids = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'posts_per_page' => 2, 'fields' => 'ids' ) );
	$opportunities = array(
		array( 'id' => 'draft-e2e-one', 'proposed_title' => 'Mock Draft Generation Safety Review', 'suggested_slug' => 'mock-draft-generation-safety-review', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'safe draft testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'priority' => 50, 'confidence' => 0.9 ),
		array( 'id' => 'draft-e2e-lock', 'proposed_title' => 'Mock Concurrent Draft Safety Review', 'suggested_slug' => 'mock-concurrent-draft-safety-review', 'content_type' => 'page', 'category' => 'informational', 'target_topic' => 'lock testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'priority' => 40, 'confidence' => 0.9 ),
		array( 'id' => 'draft-e2e-provider-failure', 'proposed_title' => 'Mock Provider Failure Safety Review', 'suggested_slug' => 'mock-provider-failure-safety-review', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'provider failure testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'priority' => 30, 'confidence' => 0.9, 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array() ),
		array( 'id' => 'draft-e2e-validation-failure', 'proposed_title' => 'Mock Validation Failure Safety Review', 'suggested_slug' => 'mock-validation-failure-safety-review', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'validation failure testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'priority' => 20, 'confidence' => 0.9, 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array() ),
		array( 'id' => 'draft-e2e-untouched', 'proposed_title' => 'Mock Untouched Recommendation', 'suggested_slug' => 'mock-untouched-recommendation', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'untouched recommendation testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'priority' => 10, 'confidence' => 0.9, 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array() ),
	);
	$repository = new AnalysisRepository();
	$analysis_id = $repository->save( array( 'provider' => 'mock', 'model' => 'fixture', 'context_hash' => 'local-draft-e2e', 'status' => 'success', 'opportunities' => $opportunities, 'usage' => array() ) );
	$workflow = new WorkflowRepository( $repository );
	$adapter = new CoreAdapter(); $inventory = new ContentInventory(); $site = new SiteContextBuilder( $adapter, $inventory );
	$analysis = new AnalysisContextBuilder( $site, new AnalysisContentReader(), $adapter );
	$keys = new ApiKeyManager(); $providers = new ProviderFactory( $keys );
	$lock = new GenerationLock();
	$drafts = new DraftService( $workflow, new DraftGenerationContextBuilder( $analysis, $adapter ), $providers, new DraftValidator(), $lock );
	$analysis_service = new OpportunityService( $analysis, $providers, new OpportunityValidator(), $repository );

	MockProvider::$draft_calls = 0;
	try { $drafts->generate( $analysis_id, 'draft-e2e-one', 1 ); local_draft_expect( false, 'pending cannot generate' ); } catch ( DomainException $error ) { local_draft_expect( 0 === MockProvider::$draft_calls, 'pending opportunity cannot call provider' ); }
	$workflow->transition( $analysis_id, 'draft-e2e-one', 'rejected' );
	try { $drafts->generate( $analysis_id, 'draft-e2e-one', 1 ); local_draft_expect( false, 'rejected cannot generate' ); } catch ( DomainException $error ) { local_draft_expect( 0 === MockProvider::$draft_calls, 'rejected opportunity cannot call provider' ); }
	$workflow->transition( $analysis_id, 'draft-e2e-one', 'pending' );

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) ); wp_set_current_user( (int) $admins[0] );
	$page = new ContentOpportunitiesPage( $analysis_service, $repository, $keys, $workflow, $drafts );
	local_draft_expect( 5 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ) && 0 === local_draft_view_count( $page->records_for_view( 'rejected' ), $analysis_id ), 'pending opportunities appear in Active by default' );
	$workflow->transition( $analysis_id, 'draft-e2e-lock', 'rejected' );
	local_draft_expect( 4 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ) && 1 === local_draft_view_count( $page->records_for_view( 'rejected' ), $analysis_id ) && 5 === local_draft_view_count( $page->records_for_view( 'all' ), $analysis_id ), 'rejected opportunity is separated from Active without being deleted' );
	$workflow->transition( $analysis_id, 'draft-e2e-lock', 'pending' );
	ob_start(); $page->render(); $pending_html = ob_get_clean();
	local_draft_expect( false !== strpos( $pending_html, 'Approve' ) && false !== strpos( $pending_html, 'Reject' ), 'pending admin workflow controls render' );

	$workflow->transition( $analysis_id, 'draft-e2e-one', 'approved' );
	local_draft_expect( 5 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ), 'approved opportunity remains Active' );
	$workflow->record_failure( $analysis_id, 'draft-e2e-one', 'Prior fixture failure.', array( 'validation_field' => 'content', 'validation_rule' => 'incomplete_content', 'completion_status' => 'incomplete', 'stop_reason' => 'max_tokens', 'output_limit_reached' => true ) );
	$workflow->record_generation_attempt( $analysis_id, 'draft-e2e-one', array( 'provider' => 'fixture-prior', 'model' => 'fixture', 'status' => 'validation_failed', 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30 ), 'diagnostics' => array( 'validation_field' => 'content', 'validation_rule' => 'incomplete_content', 'completion_status' => 'incomplete', 'stop_reason' => 'max_tokens', 'output_limit_reached' => true ) ) );
	$post_id = $drafts->generate( $analysis_id, 'draft-e2e-one', (int) $admins[0] ); $created[] = $post_id;
	$post = get_post( $post_id );
	local_draft_expect( 1 === MockProvider::$draft_calls, 'only approved opportunity reaches provider' );
	local_draft_expect( $post instanceof WP_Post && 'draft' === $post->post_status && ContentDraftPostType::POST_TYPE === $post->post_type, 'exactly one Content draft is created in the permanent content type' );
	local_draft_expect( 'Mock Draft Generation Safety Review' === $post->post_title && '' === $post->post_name && '' !== $post->post_excerpt && '' !== $post->post_content, 'validated title, excerpt, and content are written without assigning a public slug' );
	local_draft_expect( 'post' === get_post_meta( $post_id, '_global360_ai_intended_post_type', true ), 'suggested Page/Post classification remains provenance only' );
	foreach ( array( '_global360_ai_analysis_id', '_global360_ai_opportunity_id', '_global360_ai_provider', '_global360_ai_model', '_global360_ai_generated_at', '_global360_ai_plugin_version', '_global360_ai_seo_title', '_global360_ai_meta_description', '_global360_ai_target_keyphrases', '_global360_ai_generated_target_keyphrases', '_global360_ai_generated_content_tags', '_global360_ai_suggested_internal_link_ids' ) as $key ) { local_draft_expect( metadata_exists( 'post', $post_id, $key ), 'draft provenance contains ' . $key ); }
	$generated_tags = wp_get_post_terms( $post_id, ContentDraftPostType::TAXONOMY, array( 'fields' => 'names' ) );
	local_draft_expect( 4 === count( $generated_tags ) && local_draft_same_terms( $generated_tags, get_post_meta( $post_id, '_global360_ai_generated_content_tags', true ) ), 'valid generated Content Tags are assigned and preserved as private provenance' );
	local_draft_expect( ! metadata_exists( 'post', $post_id, '_seopress_titles_title' ) && ! metadata_exists( 'post', $post_id, '_seopress_titles_desc' ), 'private generation does not initialize public SEOPress metadata' );
	local_draft_expect( ! metadata_exists( 'post', $post_id, '_seopress_analysis_target_kw' ) && ! metadata_exists( 'post', $post_id, '_seopress_social_fb_title' ) && ! metadata_exists( 'post', $post_id, '_seopress_social_twitter_title' ), 'generated keyphrases and social values remain private before promotion' );
	local_draft_expect( ! metadata_exists( 'post', $post_id, ContentDraftPostType::PROMOTED_META ), 'generated item starts only in Content Drafts' );
	local_draft_expect( 5 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ) && 0 === local_draft_view_count( $page->records_for_view( 'published' ), $analysis_id ), 'generated Content Draft opportunity remains Active' );
	$draft_context = $analysis->build();
	local_draft_expect( ! in_array( $post_id, array_map( 'intval', array_column( $draft_context['existing_content'], 'id' ) ), true ), 'unpublished Content is excluded from bounded future-analysis context' );
	$sitemap_visible_ids = get_posts( array( 'post_type' => ContentDraftPostType::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	local_draft_expect( ! in_array( $post_id, $sitemap_visible_ids, true ), 'draft Content is excluded from the published records used by the SEOPress XML sitemap' );
	$created_state = $workflow->get( $analysis_id, 'draft-e2e-one' );
	local_draft_expect( 'draft_created' === $created_state['status'], 'workflow transitions to draft_created with post relationship' );
	local_draft_expect( ! isset( $created_state['generation_error'], $created_state['generation_diagnostics'] ), 'successful generation clears stale top-level failure state' );
	$created_attempts = $created_state['generation_attempts']; $latest_created_attempt = end( $created_attempts );
	local_draft_expect( 'validation_failed' === $created_attempts[0]['status'] && 'draft_created' === $latest_created_attempt['status'], 'successful generation preserves prior failure history and appends success history' );
	$again = $drafts->generate( $analysis_id, 'draft-e2e-one', (int) $admins[0] );
	local_draft_expect( $post_id === $again && 1 === MockProvider::$draft_calls, 'existing draft prevents a second provider call and duplicate post' );

	$content_lifecycle = new ContentDraftPostType();
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Reviewed Safety Article', 'post_excerpt' => 'Reviewed excerpt.', 'post_content' => '<p>Reviewed editorial content.</p>' ) );
	update_post_meta( $post_id, '_global360_ai_final_slug', 'reviewed-safety-article' );
	update_post_meta( $post_id, '_global360_ai_seo_title', 'Reviewed SEO Title' );
	update_post_meta( $post_id, '_global360_ai_meta_description', 'Reviewed SEO description.' );
	update_post_meta( $post_id, '_global360_ai_target_keyphrases', array( 'spinal cord stimulation trial', 'SCS trial period', 'failed back surgery syndrome care' ) );
	$reviewed_tags = array( 'Spinal Cord Stimulation', 'Failed Back Surgery Syndrome', 'Neuromodulation' );
	wp_set_object_terms( $post_id, $reviewed_tags, ContentDraftPostType::TAXONOMY, false );
	local_draft_expect( local_draft_same_terms( $reviewed_tags, wp_get_post_terms( $post_id, ContentDraftPostType::TAXONOMY, array( 'fields' => 'names' ) ) ), 'human can replace generated tag assignments while Content is a draft' );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	local_draft_expect( 'draft' === get_post_status( $post_id ), 'unpromoted Content Draft cannot be published' );
	$promotion = $content_lifecycle->promote( $post_id, (int) $admins[0] );
	local_draft_expect( $post_id === $promotion['post_id'] && $promotion['promoted'] && 'draft' === get_post_status( $post_id ), 'promotion preserves the record ID and never auto-publishes' );
	local_draft_expect( metadata_exists( 'post', $post_id, ContentDraftPostType::PROMOTED_META ) && (int) $admins[0] === (int) get_post_meta( $post_id, '_global360_ai_promoted_by', true ), 'promotion provenance is recorded privately' );
	local_draft_expect( 'reviewed-safety-article' === get_post_field( 'post_name', $post_id ), 'promotion applies the reviewed final slug' );
	local_draft_expect( 'Reviewed SEO Title' === get_post_meta( $post_id, '_seopress_titles_title', true ) && 'Reviewed SEO description.' === get_post_meta( $post_id, '_seopress_titles_desc', true ), 'promotion initializes canonical SEOPress fields' );
	local_draft_expect( 'spinal cord stimulation trial,SCS trial period,failed back surgery syndrome care' === get_post_meta( $post_id, '_seopress_analysis_target_kw', true ), 'promotion initializes SEOPress Target Keywords in its canonical comma-separated storage' );
	local_draft_expect( 'Reviewed SEO Title' === get_post_meta( $post_id, '_seopress_social_fb_title', true ) && 'Reviewed SEO description.' === get_post_meta( $post_id, '_seopress_social_fb_desc', true ) && 'Reviewed SEO Title' === get_post_meta( $post_id, '_seopress_social_twitter_title', true ) && 'Reviewed SEO description.' === get_post_meta( $post_id, '_seopress_social_twitter_desc', true ), 'promotion initializes Open Graph and X metadata from reviewed SEO values' );
	local_draft_expect( local_draft_same_terms( $reviewed_tags, wp_get_post_terms( $post_id, ContentDraftPostType::TAXONOMY, array( 'fields' => 'names' ) ) ), 'Move to Content preserves human-reviewed Content Tags without regeneration' );
	local_draft_expect( 5 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ) && 0 === local_draft_view_count( $page->records_for_view( 'published' ), $analysis_id ), 'moved-to-Content but unpublished opportunity remains Active' );
	$again_promotion = $content_lifecycle->promote( $post_id, (int) $admins[0] );
	local_draft_expect( $post_id === $again_promotion['post_id'] && ! $again_promotion['promoted'] && 1 === MockProvider::$draft_calls, 'repeated promotion is idempotent and makes no provider request' );
	update_post_meta( $post_id, '_seopress_social_fb_title', 'Human-edited social title' );
	update_post_meta( $post_id, '_seopress_analysis_target_kw', 'human edited phrase' );
	$content_lifecycle->promote( $post_id, (int) $admins[0] );
	local_draft_expect( 'Human-edited social title' === get_post_meta( $post_id, '_seopress_social_fb_title', true ) && 'human edited phrase' === get_post_meta( $post_id, '_seopress_analysis_target_kw', true ), 'idempotent promotion never overwrites subsequent human SEOPress edits' );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$published = get_post( $post_id );
	local_draft_expect( $published instanceof WP_Post && ContentDraftPostType::POST_TYPE === $published->post_type && 'publish' === $published->post_status, 'publishing changes status while content remains g360_content' );
	local_draft_expect( 'Reviewed Safety Article' === $published->post_title && 'Reviewed excerpt.' === $published->post_excerpt && '<p>Reviewed editorial content.</p>' === $published->post_content, 'published Content retains current reviewed values' );
	local_draft_expect( home_url( '/reviewed-safety-article/' ) === get_permalink( $published ), 'published Content uses a clean root-level permalink without a CPT base' );
	local_draft_expect( 'Reviewed SEO Title' === get_post_meta( $post_id, '_seopress_titles_title', true ) && 'Reviewed SEO description.' === get_post_meta( $post_id, '_seopress_titles_desc', true ), 'reviewed SEOPress values remain canonical after publication' );
	local_draft_expect( local_draft_same_terms( $reviewed_tags, wp_get_post_terms( $post_id, ContentDraftPostType::TAXONOMY, array( 'fields' => 'names' ) ) ) && local_draft_same_terms( $generated_tags, get_post_meta( $post_id, '_global360_ai_generated_content_tags', true ) ), 'publication preserves current tags and original generated-tag provenance independently' );
	$provenance_before_status_cycles = get_post_meta( $post_id );
	local_draft_expect( 4 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ) && 1 === local_draft_view_count( $page->records_for_view( 'published' ), $analysis_id ) && 5 === local_draft_view_count( $page->records_for_view( 'all' ), $analysis_id ), 'published opportunity leaves Active but remains in Published and All' );
	$published_context = $analysis->build();
	local_draft_expect( in_array( $post_id, array_map( 'intval', array_column( $published_context['existing_content'], 'id' ) ), true ), 'published Content is included in bounded future-analysis context' );
	$published_context_item = current( array_filter( $published_context['existing_content'], static function ( $item ) use ( $post_id ) { return $post_id === (int) ( $item['id'] ?? 0 ); } ) );
	local_draft_expect( is_array( $published_context_item ) && local_draft_same_terms( $reviewed_tags, $published_context_item['taxonomies']['tags'] ), 'published Content contributes only its bounded reviewed Content Tags to analysis context' );
	$validation_fixture = array();
	for ( $index = 1; $index <= 5; $index++ ) {
		$validation_fixture[] = array( 'id' => 'published-context-' . $index, 'proposed_title' => 1 === $index ? 'Reviewed Safety Article' : 'Lifecycle Fixture 20260914 Topic ' . $index, 'suggested_slug' => 1 === $index ? 'reviewed-safety-article' : 'lifecycle-fixture-20260914-topic-' . $index, 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'bounded context test', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Patients', 'geo_scope' => 'national', 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array(), 'priority' => 50, 'confidence' => 0.9 );
	}
	try { ( new OpportunityValidator() )->validate( $validation_fixture, $published_context ); local_draft_expect( false, 'published Content overlap cannot validate' ); } catch ( \Global360\AI\Analysis\OpportunityValidationException $error ) { local_draft_expect( 'existing_content_overlap' === $error->diagnostics()['validation_rule'], 'published Content participates in deterministic duplicate/cannibalization checks' ); }
	$validation_fixture[0]['proposed_title'] = 'Lifecycle Fixture 20260914 Topic 1'; $validation_fixture[0]['suggested_slug'] = 'lifecycle-fixture-20260914-topic-1';
	local_draft_expect( 5 === count( ( new OpportunityValidator() )->validate( $validation_fixture, $published_context ) ), 'distinct related topics remain eligible after published Content is added to context' );
	local_draft_expect( 5 === count( ( new OpportunityValidator() )->validate( $validation_fixture, array_merge( $published_context, array( 'existing_content' => array_map( static function ( $item ) { $item['taxonomies']['tags'] = array( 'Spinal Cord Stimulation' ); return $item; }, $published_context['existing_content'] ) ) ) ) ), 'shared Content Tags alone do not trigger duplicate rejection' );
	$_GET['opportunity_view'] = 'published'; ob_start(); $page->render(); $published_html = ob_get_clean(); unset( $_GET['opportunity_view'] );
	local_draft_expect( false !== strpos( $published_html, 'Published' ) && false !== strpos( $published_html, 'Edit Content' ) && false !== strpos( $published_html, 'View Content' ) && false === strpos( $published_html, 'Generate Draft' ), 'Published view shows correct Content links without inappropriate generation controls' );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
	local_draft_expect( 0 === local_draft_view_count( $page->records_for_view( 'published' ), $analysis_id ) && 5 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ), 'unpublishing Content returns its opportunity to Active' );
	local_draft_expect( ! in_array( $post_id, array_map( 'intval', array_column( $analysis->build()['existing_content'], 'id' ) ), true ), 'unpublished Content leaves future-analysis context' );
	wp_trash_post( $post_id );
	local_draft_expect( 0 === local_draft_view_count( $page->records_for_view( 'published' ), $analysis_id ) && 5 === local_draft_view_count( $page->records_for_view( 'active' ), $analysis_id ), 'trashing associated Content cannot leave its opportunity falsely Published' );
	wp_untrash_post( $post_id ); wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	local_draft_expect( $provenance_before_status_cycles === get_post_meta( $post_id ), 'publication status cycles leave opportunity and generation provenance unchanged' );
	local_draft_expect( 0 === count( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_global360_ai_analysis_id', 'meta_value' => $analysis_id ) ) ), 'workflow creates no normal Page or Post' );

	$collision_page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Content Collision Fixture', 'post_name' => 'content-collision-fixture' ) ); $created[] = $collision_page_id;
	$collision_content_id = wp_insert_post( array( 'post_type' => ContentDraftPostType::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Colliding Content' ) ); $created[] = $collision_content_id;
	update_post_meta( $collision_content_id, '_global360_ai_final_slug', 'content-collision-fixture' );
	try { $content_lifecycle->promote( $collision_content_id, (int) $admins[0] ); local_draft_expect( false, 'Page slug collision cannot promote' ); } catch ( DomainException $error ) { local_draft_expect( true, 'promotion fails closed when an existing public Page owns the root slug' ); }
	local_draft_expect( $collision_page_id === url_to_postid( home_url( '/content-collision-fixture/' ) ), 'existing Page route wins a root-slug collision' );
	try { $content_lifecycle->promote( $collision_content_id, 0 ); local_draft_expect( false, 'unauthorized promotion cannot proceed' ); } catch ( DomainException $error ) { local_draft_expect( true, 'promotion requires the administrative capability' ); }
	$collision_post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Post Collision Fixture', 'post_name' => 'post-collision-fixture' ) ); $created[] = $collision_post_id;
	$post_collision_content_id = wp_insert_post( array( 'post_type' => ContentDraftPostType::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Post-colliding Content' ) ); $created[] = $post_collision_content_id;
	update_post_meta( $post_collision_content_id, '_global360_ai_final_slug', 'post-collision-fixture' );
	try { $content_lifecycle->promote( $post_collision_content_id, (int) $admins[0] ); local_draft_expect( false, 'Post slug collision cannot promote' ); } catch ( DomainException $error ) { local_draft_expect( true, 'promotion fails closed when an existing public Post owns the root slug' ); }
	$route_collision_content_id = wp_insert_post( array( 'post_type' => ContentDraftPostType::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Route-colliding Content' ) ); $created[] = $route_collision_content_id;
	update_post_meta( $route_collision_content_id, '_global360_ai_final_slug', 'doctors' );
	try { $content_lifecycle->promote( $route_collision_content_id, (int) $admins[0] ); local_draft_expect( false, 'registered CPT route collision cannot promote' ); } catch ( DomainException $error ) { local_draft_expect( true, 'promotion preserves a registered public CPT route base' ); }

	$workflow->transition( $analysis_id, 'draft-e2e-lock', 'approved' ); $lock->acquire( $analysis_id, 'draft-e2e-lock' );
	try { $drafts->generate( $analysis_id, 'draft-e2e-lock', (int) $admins[0] ); local_draft_expect( false, 'concurrent generation cannot proceed' ); } catch ( RuntimeException $error ) { local_draft_expect( 1 === MockProvider::$draft_calls, 'generation lock prevents concurrent provider call' ); }
	$lock->release( $analysis_id, 'draft-e2e-lock' );

	$workflow->transition( $analysis_id, 'draft-e2e-provider-failure', 'approved' );
	$failing = new DraftService( $workflow, new DraftGenerationContextBuilder( $analysis, $adapter ), new LocalProviderFactory( new LocalFailingDraftProvider() ), new DraftValidator(), $lock );
	try { $failing->generate( $analysis_id, 'draft-e2e-provider-failure', (int) $admins[0] ); local_draft_expect( false, 'provider failure cannot create draft' ); } catch ( RuntimeException $error ) { local_draft_expect( true, 'provider failure creates no draft and performs no retry' ); }
	$failed_state = $workflow->get( $analysis_id, 'draft-e2e-provider-failure' );
	local_draft_expect( 'approved' === $failed_state['status'] && 429 === $failed_state['generation_diagnostics']['http_status'], 'provider failure leaves opportunity approved with sanitized diagnostics' );
	local_draft_expect( $lock->acquire( $analysis_id, 'draft-e2e-provider-failure' ), 'generation lock clears after provider failure' ); $lock->release( $analysis_id, 'draft-e2e-provider-failure' );

	$workflow->transition( $analysis_id, 'draft-e2e-validation-failure', 'approved' );
	LocalInvalidDraftProvider::$calls = 0;
	$invalid = new DraftService( $workflow, new DraftGenerationContextBuilder( $analysis, $adapter ), new LocalProviderFactory( new LocalInvalidDraftProvider() ), new DraftValidator(), $lock );
	try { $invalid->generate( $analysis_id, 'draft-e2e-validation-failure', (int) $admins[0] ); local_draft_expect( false, 'invalid provider draft cannot be inserted' ); } catch ( InvalidArgumentException $error ) { local_draft_expect( true, 'validation failure creates no draft and performs no repair' ); }
	$invalid_state = $workflow->get( $analysis_id, 'draft-e2e-validation-failure' );
	local_draft_expect( 'approved' === $invalid_state['status'] && false !== strpos( $invalid_state['generation_error'], 'deterministic validation' ), 'validation failure leaves opportunity approved with sanitized failure' );
	$attempt = end( $invalid_state['generation_attempts'] );
	local_draft_expect( 1 === LocalInvalidDraftProvider::$calls && 'validation_failed' === $attempt['status'] && 975 === $attempt['usage']['total_tokens'], 'validation failure records one paid provider attempt and token usage' );
	local_draft_expect( 'content' === $attempt['diagnostics']['validation_field'] && 'unsafe_markup' === $attempt['diagnostics']['validation_rule'] && array( 'h4' ) === $attempt['diagnostics']['unsupported_tags'] && array( 'onclick' ) === $attempt['diagnostics']['unsafe_attributes'], 'validation attempt stores bounded structural diagnostics' );
	$serialized_failure = serialize( $invalid_state );
	foreach ( array( 'Private generated article text', 'private-value', 'privateValue()', 'mock-api-key', 'Authorization', 'allowed_internal_links' ) as $forbidden ) { local_draft_expect( false === strpos( $serialized_failure, $forbidden ), 'failure storage excludes sensitive/generated value: ' . $forbidden ); }
	local_draft_expect( $lock->acquire( $analysis_id, 'draft-e2e-validation-failure' ), 'generation lock clears after validation failure' ); $lock->release( $analysis_id, 'draft-e2e-validation-failure' );

	ob_start(); $page->render(); $created_html = ob_get_clean();
	local_draft_expect( false !== strpos( $created_html, 'Opportunity Queue' ) && false !== strpos( $created_html, 'Active') && false !== strpos( $created_html, 'Published' ) && false !== strpos( $created_html, 'Rejected' ), 'Content Opportunities renders the actionable queue and all lifecycle views' );
} finally {
	foreach ( $created as $post_id ) { wp_delete_post( $post_id, true ); }
	$current_tag_ids = get_terms( array( 'taxonomy' => ContentDraftPostType::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ); if ( ! is_wp_error( $current_tag_ids ) ) { foreach ( array_diff( $current_tag_ids, $existing_tag_ids ) as $term_id ) { wp_delete_term( (int) $term_id, ContentDraftPostType::TAXONOMY ); } }
	update_option( $history_option, $original_history, false ); update_option( $workflow_option, $original_workflow, false );
}

$summary = ( new CoreAdapter() )->platform_summary();
local_draft_expect( 14 === (int) $summary['clinics'] && 24 === (int) $summary['doctors'] && 24 === (int) $summary['relationships'], 'baseline remains 14 Clinics, 24 Doctors, and 24 relationships' );
local_draft_expect( $before_meta === local_draft_meta( $entity_ids ), 'all Clinic and Doctor metadata remains unchanged' );
$after_pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'" );
$after_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
local_draft_expect( $before_pages === $after_pages && $before_posts === $after_posts, 'no published Page or Post is created' );
local_draft_expect( $before_published_hash === local_draft_published_hash( $wpdb ), 'published Page/Post content hash remains unchanged' );
foreach ( $historical_ids as $historical_id ) { $historical_after = get_post( $historical_id ) ? hash( 'sha256', serialize( array( get_post( $historical_id ), get_post_meta( $historical_id ) ) ) ) : ''; local_draft_expect( $historical_before[ $historical_id ] === $historical_after, 'historical draft ' . $historical_id . ' remains unchanged' ); }
foreach ( $existing_content_before as $existing_content_id => $existing_hash ) { local_draft_expect( $existing_hash === hash( 'sha256', serialize( array( get_post( $existing_content_id ), get_post_meta( $existing_content_id ) ) ) ), 'existing 0.4 Content record ' . $existing_content_id . ' remains unchanged' ); }
local_draft_expect( 0 === count( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'draft', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_global360_ai_analysis_id', 'meta_value' => $analysis_id ) ) ), 'no permanent test draft remains' );
$after_seopress = hash( 'sha256', serialize( array( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%seopress%' ORDER BY option_name", ARRAY_A ), $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '_seopress_%' ORDER BY meta_id", ARRAY_A ) ) ) );
local_draft_expect( $before_seopress === $after_seopress, 'SEOPress options and metadata remain unchanged' );

echo "360 AI local draft-generation end-to-end tests passed.\n";
