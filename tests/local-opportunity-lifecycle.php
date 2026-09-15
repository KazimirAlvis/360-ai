<?php

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'GLOBAL360_AI_USE_MOCK_PROVIDER', true );
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use Global360\AI\Admin\ContentOpportunitiesPage;
use Global360\AI\AI\MockProvider;
use Global360\AI\AI\ProviderFactory;
use Global360\AI\Analysis\AnalysisContextBuilder;
use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Analysis\OpportunityValidator;
use Global360\AI\Context\AnalysisContentReader;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Storage\WorkflowRepository;

function lifecycle_expect( $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
function lifecycle_count( array $records, array $analysis_ids ): int { return count( array_filter( $records, static function ( $record ) use ( $analysis_ids ) { return in_array( (string) ( $record['analysis_id'] ?? '' ), $analysis_ids, true ); } ) ); }
function lifecycle_opportunities( string $prefix ): array {
	$items = array();
	for ( $index = 1; $index <= 5; $index++ ) {
		$items[] = array(
			'id' => $prefix . '-' . $index, 'proposed_title' => strtoupper( $prefix ) . ' Opportunity ' . $index,
			'suggested_slug' => $prefix . '-opportunity-' . $index, 'content_type' => 'post', 'category' => 'informational',
			'target_topic' => $prefix . ' topic ' . $index, 'search_intent' => 'informational', 'rationale' => 'Deterministic local lifecycle fixture.',
			'audience' => 'Editors', 'geo_scope' => 'national', 'relevant_location_ids' => array(), 'relevant_clinic_ids' => array(),
			'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array(), 'priority' => 50, 'confidence' => 0.9,
		);
	}
	return $items;
}

global $wpdb;
$history_option = 'global360_ai_analysis_history';
$workflow_option = 'global360_ai_opportunity_workflow';
$original_history = get_option( $history_option, array() );
$original_workflow = get_option( $workflow_option, array() );
$before_pages = (int) wp_count_posts( 'page' )->publish;
$before_posts = (int) wp_count_posts( 'post' )->publish;
$created = array();

try {
	$repository = new AnalysisRepository();
	$workflow = new WorkflowRepository( $repository );
	$core = new CoreAdapter();
	$inventory = new ContentInventory();
	$context = new AnalysisContextBuilder( new SiteContextBuilder( $core, $inventory ), new AnalysisContentReader(), $core );
	$keys = new ApiKeyManager();
	$service = new OpportunityService( $context, new ProviderFactory( $keys ), new OpportunityValidator(), $repository );
	$page = new ContentOpportunitiesPage( $service, $repository, $keys, $workflow );
	$provider_calls = MockProvider::$draft_calls;

	$first = $repository->save( array( 'provider' => 'mock', 'model' => 'first-model', 'context_hash' => 'first-context', 'status' => 'success', 'opportunities' => lifecycle_opportunities( 'first' ), 'usage' => array( 'total_tokens' => 11 ), 'diagnostics' => array( 'fixture' => 'first' ) ) );
	lifecycle_expect( 5 === lifecycle_count( $page->records_for_view( 'active' ), array( $first ) ), 'first successful analysis produces five Active opportunities' );

	$workflow->transition( $first, 'first-1', 'approved' );
	$workflow->transition( $first, 'first-2', 'approved' );
	$draft_id = wp_insert_post( array( 'post_type' => 'g360_content', 'post_status' => 'draft', 'post_title' => 'Lifecycle Draft Fixture' ), true );
	lifecycle_expect( ! is_wp_error( $draft_id ), 'generated Content fixture is created safely' ); $created[] = (int) $draft_id;
	$workflow->transition( $first, 'first-2', 'draft_created', (int) $draft_id );
	$workflow->transition( $first, 'first-3', 'rejected' );
	$workflow->transition( $first, 'first-4', 'approved' );
	$published_id = wp_insert_post( array( 'post_type' => 'g360_content', 'post_status' => 'draft', 'post_title' => 'Lifecycle Published Fixture' ), true );
	lifecycle_expect( ! is_wp_error( $published_id ), 'published Content fixture is created safely' ); $created[] = (int) $published_id;
	update_post_meta( (int) $published_id, '_global360_ai_promoted_at', gmdate( 'c' ) );
	update_post_meta( (int) $published_id, '_global360_ai_final_slug', 'lifecycle-published-fixture' );
	wp_update_post( array( 'ID' => (int) $published_id, 'post_name' => 'lifecycle-published-fixture', 'post_status' => 'publish' ) );
	lifecycle_expect( 'publish' === get_post_status( (int) $published_id ), 'published Content fixture reaches published status' );
	$workflow->transition( $first, 'first-4', 'draft_created', (int) $published_id );

	$second = $repository->save( array( 'provider' => 'mock', 'model' => 'second-model', 'context_hash' => 'second-context', 'status' => 'success', 'opportunities' => lifecycle_opportunities( 'second' ), 'usage' => array( 'total_tokens' => 22 ), 'diagnostics' => array( 'fixture' => 'second' ) ) );
	$two = array( $first, $second );
	lifecycle_expect( 7 === lifecycle_count( $page->records_for_view( 'active' ), $two ), 'second analysis keeps five current plus two older in-progress opportunities Active' );
	lifecycle_expect( 1 === lifecycle_count( $page->records_for_view( 'superseded' ), $two ), 'second analysis supersedes the older untouched Pending opportunity' );
	lifecycle_expect( 1 === lifecycle_count( $page->records_for_view( 'published' ), $two ) && 1 === lifecycle_count( $page->records_for_view( 'rejected' ), $two ), 'Published and Rejected states outrank supersession' );
	update_post_meta( (int) $draft_id, '_global360_ai_promoted_at', gmdate( 'c' ) );
	lifecycle_expect( 2 === lifecycle_count( array_filter( $page->records_for_view( 'active' ), static function ( $entry ) use ( $first ) { return $first === ( $entry['analysis_id'] ?? '' ); } ), array( $first ) ), 'generated and promoted-but-unpublished older Content remain Active' );

	$third = $repository->save( array( 'provider' => 'mock', 'model' => 'third-model', 'context_hash' => 'third-context', 'status' => 'success', 'opportunities' => lifecycle_opportunities( 'third' ), 'usage' => array( 'total_tokens' => 33 ), 'diagnostics' => array( 'fixture' => 'third' ) ) );
	$ids = array( $first, $second, $third );
	lifecycle_expect( 7 === lifecycle_count( $page->records_for_view( 'active' ), $ids ), 'third successful analysis does not grow the actionable queue to fifteen' );
	lifecycle_expect( 6 === lifecycle_count( $page->records_for_view( 'superseded' ), $ids ), 'all older untouched recommendations classify as Superseded' );

	$counts_before_failure = array_map( static function ( $view ) use ( $page, $ids ) { return lifecycle_count( $page->records_for_view( $view ), $ids ); }, array( 'active', 'published', 'rejected', 'superseded', 'all' ) );
	$repository->save( array( 'provider' => 'mock', 'model' => 'failed-model', 'context_hash' => 'failed-context', 'status' => 'failed', 'opportunities' => array(), 'usage' => array( 'total_tokens' => 44 ), 'error' => 'Fixture provider failure.' ) );
	$repository->save( array( 'provider' => 'mock', 'model' => 'validation-model', 'context_hash' => 'validation-context', 'status' => 'failed', 'opportunities' => array(), 'usage' => array( 'total_tokens' => 55 ), 'error' => 'Fixture validation failure.', 'diagnostics' => array( 'failure_stage' => 'validation', 'validation_rule' => 'invalid_slug' ) ) );
	$counts_after_failure = array_map( static function ( $view ) use ( $page, $ids ) { return lifecycle_count( $page->records_for_view( $view ), $ids ); }, array( 'active', 'published', 'rejected', 'superseded', 'all' ) );
	lifecycle_expect( $counts_before_failure === $counts_after_failure, 'provider and validation failures do not supersede the current successful set' );

	$fixture_history = array_values( array_filter( $repository->all(), static function ( $record ) use ( $ids ) { return in_array( (string) ( $record['analysis_id'] ?? '' ), $ids, true ); } ) );
	$history_hash = hash( 'sha256', serialize( $fixture_history ) );
	$workflow_hash = hash( 'sha256', serialize( get_option( $workflow_option, array() ) ) );
	$page->records_for_view( 'active' ); $page->records_for_view( 'superseded' ); $page->records_for_view( 'all' );
	$fixture_history_after = array_values( array_filter( $repository->all(), static function ( $record ) use ( $ids ) { return in_array( (string) ( $record['analysis_id'] ?? '' ), $ids, true ); } ) );
	lifecycle_expect( $history_hash === hash( 'sha256', serialize( $fixture_history_after ) ) && $workflow_hash === hash( 'sha256', serialize( get_option( $workflow_option, array() ) ) ), 'derived classification is idempotent and preserves payload, provider, model, usage, diagnostics, and workflow relationships' );
	lifecycle_expect( 15 === array_sum( array_slice( $counts_after_failure, 0, 4 ) ) && 15 === $counts_after_failure[4], 'Active, Published, Rejected, and Superseded are mutually exclusive and sum to All' );

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) ); wp_set_current_user( (int) $admins[0] );
	$_GET['opportunity_view'] = 'superseded'; ob_start(); $page->render(); $html = ob_get_clean(); unset( $_GET['opportunity_view'] );
	lifecycle_expect( false !== strpos( $html, 'Superseded' ) && false === strpos( $html, '>Approve<' ) && false === strpos( $html, '>Generate Draft<' ) && false === strpos( $html, '>Move to Content<' ), 'Superseded view is read-only and exposes no workflow controls' );
	lifecycle_expect( false === strpos( serialize( $inventory->items( 'g360_content', 100 ) ), 'FIRST Opportunity 5' ), 'superseded recommendation text does not enter published ContentInventory' );
	lifecycle_expect( $provider_calls === MockProvider::$draft_calls, 'existing-data classification makes no provider request' );

	wp_update_post( array( 'ID' => (int) $published_id, 'post_status' => 'draft' ) );
	lifecycle_expect( 3 === lifecycle_count( array_filter( $page->records_for_view( 'active' ), static function ( $entry ) use ( $first ) { return $first === ( $entry['analysis_id'] ?? '' ); } ), array( $first ) ), 'unpublishing older Content returns meaningful work to Active rather than Superseded' );
} finally {
	foreach ( $created as $post_id ) { wp_delete_post( $post_id, true ); }
	update_option( $history_option, $original_history, false );
	update_option( $workflow_option, $original_workflow, false );
}

lifecycle_expect( $before_pages === (int) wp_count_posts( 'page' )->publish && $before_posts === (int) wp_count_posts( 'post' )->publish, 'normal published Pages and Posts remain unchanged' );
$summary = ( new CoreAdapter() )->platform_summary();
lifecycle_expect( 14 === (int) $summary['clinics'] && 24 === (int) $summary['doctors'] && 24 === (int) $summary['relationships'], 'baseline remains 14 Clinics, 24 Doctors, and 24 relationships' );
echo "360 AI local opportunity lifecycle tests passed.\n";
