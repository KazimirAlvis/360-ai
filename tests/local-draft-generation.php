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
$before_pages = (int) wp_count_posts( 'page' )->publish; $before_posts = (int) wp_count_posts( 'post' )->publish;
$created = array();

try {
	$link_ids = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'posts_per_page' => 2, 'fields' => 'ids' ) );
	$opportunities = array(
		array( 'id' => 'draft-e2e-one', 'proposed_title' => 'Mock Draft Generation Safety Review', 'suggested_slug' => 'mock-draft-generation-safety-review', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'safe draft testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'priority' => 50, 'confidence' => 0.9 ),
		array( 'id' => 'draft-e2e-lock', 'proposed_title' => 'Mock Concurrent Draft Safety Review', 'suggested_slug' => 'mock-concurrent-draft-safety-review', 'content_type' => 'page', 'category' => 'informational', 'target_topic' => 'lock testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'priority' => 40, 'confidence' => 0.9 ),
		array( 'id' => 'draft-e2e-provider-failure', 'proposed_title' => 'Mock Provider Failure Safety Review', 'suggested_slug' => 'mock-provider-failure-safety-review', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'provider failure testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'priority' => 30, 'confidence' => 0.9, 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array() ),
		array( 'id' => 'draft-e2e-validation-failure', 'proposed_title' => 'Mock Validation Failure Safety Review', 'suggested_slug' => 'mock-validation-failure-safety-review', 'content_type' => 'post', 'category' => 'informational', 'target_topic' => 'validation failure testing', 'search_intent' => 'informational', 'rationale' => 'Local test only.', 'audience' => 'Editors', 'geo_scope' => 'national', 'priority' => 20, 'confidence' => 0.9, 'suggested_internal_link_ids' => array_map( 'absint', $link_ids ), 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array() ),
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
	ob_start(); $page->render(); $pending_html = ob_get_clean();
	local_draft_expect( false !== strpos( $pending_html, 'Approve' ) && false !== strpos( $pending_html, 'Reject' ), 'pending admin workflow controls render' );

	$workflow->transition( $analysis_id, 'draft-e2e-one', 'approved' );
	$workflow->record_failure( $analysis_id, 'draft-e2e-one', 'Prior fixture failure.', array( 'validation_field' => 'content', 'validation_rule' => 'incomplete_content', 'completion_status' => 'incomplete', 'stop_reason' => 'max_tokens', 'output_limit_reached' => true ) );
	$workflow->record_generation_attempt( $analysis_id, 'draft-e2e-one', array( 'provider' => 'fixture-prior', 'model' => 'fixture', 'status' => 'validation_failed', 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30 ), 'diagnostics' => array( 'validation_field' => 'content', 'validation_rule' => 'incomplete_content', 'completion_status' => 'incomplete', 'stop_reason' => 'max_tokens', 'output_limit_reached' => true ) ) );
	$post_id = $drafts->generate( $analysis_id, 'draft-e2e-one', (int) $admins[0] ); $created[] = $post_id;
	$post = get_post( $post_id );
	local_draft_expect( 1 === MockProvider::$draft_calls, 'only approved opportunity reaches provider' );
	local_draft_expect( $post instanceof WP_Post && 'draft' === $post->post_status && 'post' === $post->post_type, 'exactly one WordPress draft is created with application-owned status and type' );
	local_draft_expect( 'Mock Draft Generation Safety Review' === $post->post_title && 'mock-draft-generation-safety-review' === $post->post_name && '' !== $post->post_excerpt && '' !== $post->post_content, 'validated title, slug, excerpt, and content are written' );
	foreach ( array( '_global360_ai_analysis_id', '_global360_ai_opportunity_id', '_global360_ai_provider', '_global360_ai_model', '_global360_ai_generated_at', '_global360_ai_plugin_version', '_global360_ai_seo_title', '_global360_ai_meta_description', '_global360_ai_suggested_internal_link_ids' ) as $key ) { local_draft_expect( metadata_exists( 'post', $post_id, $key ), 'draft provenance contains ' . $key ); }
	local_draft_expect( ! metadata_exists( 'post', $post_id, '_seopress_titles_title' ) && ! metadata_exists( 'post', $post_id, '_seopress_titles_desc' ), 'generated SEO suggestions remain in private AI metadata' );
	$created_state = $workflow->get( $analysis_id, 'draft-e2e-one' );
	local_draft_expect( 'draft_created' === $created_state['status'], 'workflow transitions to draft_created with post relationship' );
	local_draft_expect( ! isset( $created_state['generation_error'], $created_state['generation_diagnostics'] ), 'successful generation clears stale top-level failure state' );
	$created_attempts = $created_state['generation_attempts']; $latest_created_attempt = end( $created_attempts );
	local_draft_expect( 'validation_failed' === $created_attempts[0]['status'] && 'draft_created' === $latest_created_attempt['status'], 'successful generation preserves prior failure history and appends success history' );
	$again = $drafts->generate( $analysis_id, 'draft-e2e-one', (int) $admins[0] );
	local_draft_expect( $post_id === $again && 1 === MockProvider::$draft_calls, 'existing draft prevents a second provider call and duplicate post' );

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
	local_draft_expect( false !== strpos( $created_html, 'Draft Created' ) && false !== strpos( $created_html, 'Edit Draft' ), 'draft-created admin controls render an edit link' );
} finally {
	foreach ( $created as $post_id ) { wp_delete_post( $post_id, true ); }
	update_option( $history_option, $original_history, false ); update_option( $workflow_option, $original_workflow, false );
}

$summary = ( new CoreAdapter() )->platform_summary();
local_draft_expect( 14 === (int) $summary['clinics'] && 24 === (int) $summary['doctors'] && 24 === (int) $summary['relationships'], 'baseline remains 14 Clinics, 24 Doctors, and 24 relationships' );
local_draft_expect( $before_meta === local_draft_meta( $entity_ids ), 'all Clinic and Doctor metadata remains unchanged' );
local_draft_expect( $before_pages === (int) wp_count_posts( 'page' )->publish && $before_posts === (int) wp_count_posts( 'post' )->publish, 'no published Page or Post is created' );
local_draft_expect( 0 === count( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'draft', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_global360_ai_analysis_id', 'meta_value' => $analysis_id ) ) ), 'no permanent test draft remains' );
$after_seopress = hash( 'sha256', serialize( array( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%seopress%' ORDER BY option_name", ARRAY_A ), $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '_seopress_%' ORDER BY meta_id", ARRAY_A ) ) ) );
local_draft_expect( $before_seopress === $after_seopress, 'SEOPress options and metadata remain unchanged' );

echo "360 AI local draft-generation end-to-end tests passed.\n";
