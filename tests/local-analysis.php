<?php

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'GLOBAL360_AI_USE_MOCK_PROVIDER', true );

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $wp_load ) ) { fwrite( STDERR, "wp-load.php not found\n" ); exit( 1 ); }
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/template.php';

use Global360\AI\Admin\ContentOpportunitiesPage;
use Global360\AI\AI\ProviderFactory;
use Global360\AI\AI\MockProvider;
use Global360\AI\AI\ProviderInterface;
use Global360\AI\AI\AnalysisResponse;
use Global360\AI\Analysis\AnalysisContextBuilder;
use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Analysis\OpportunityValidator;
use Global360\AI\Analysis\OpportunityValidationException;
use Global360\AI\Context\AnalysisContentReader;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Generation\DraftResponse;

final class InsufficientCandidateProvider implements ProviderInterface {
	public static $calls = 0;
	private $opportunities;
	public function __construct( array $opportunities ) { $this->opportunities = $opportunities; }
	public function name(): string { return 'insufficient-fixture'; }
	public function model(): string { return 'eight-candidate-fixture'; }
	public function is_configured(): bool { return true; }
	public function analyze( array $context ): AnalysisResponse { self::$calls++; return new AnalysisResponse( $this->opportunities, $this->name(), $this->model(), array( 'input_tokens' => 80, 'output_tokens' => 40, 'total_tokens' => 120 ) ); }
	public function generate_draft( array $context ): DraftResponse { throw new RuntimeException( 'Draft generation is outside this fixture.' ); }
}

final class InsufficientCandidateFactory extends ProviderFactory {
	private $provider;
	public function __construct( ProviderInterface $provider ) { $this->provider = $provider; }
	public function create(): ProviderInterface { return $this->provider; }
}

function ai_analysis_integration_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
}

global $wpdb;
$history_option = 'global360_ai_analysis_history';
$original_history = get_option( $history_option, array() );
register_shutdown_function( static function () use ( $history_option, $original_history ) { update_option( $history_option, $original_history, false ); } );
$before_counts = array( 'page' => (int) wp_count_posts( 'page' )->publish, 'post' => (int) wp_count_posts( 'post' )->publish );
$entity_ids = get_posts( array( 'post_type' => array( 'clinic', 'doctor' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$before_entity_meta = array();
foreach ( $entity_ids as $id ) { $before_entity_meta[ $id ] = get_post_meta( $id ); }
$before_seopress_options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%seopress%' ORDER BY option_name", ARRAY_A );
$before_seopress_meta = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '_seopress_%' ORDER BY meta_id", ARRAY_A );

$adapter = new CoreAdapter();
$site_builder = new SiteContextBuilder( $adapter, new ContentInventory() );
$context_builder = new AnalysisContextBuilder( $site_builder, new AnalysisContentReader(), $adapter );
$repository = new AnalysisRepository();
$keys = new ApiKeyManager();
$service = new OpportunityService( $context_builder, new ProviderFactory( $keys ), new OpportunityValidator(), $repository );

MockProvider::$analysis_calls = 0;
$before_queries = get_num_queries();
$record = $service->analyze();
$analysis_queries = get_num_queries() - $before_queries;

ai_analysis_integration_expect( 'mock' === $record['provider'], 'local end-to-end analysis uses the explicit MockProvider' );
ai_analysis_integration_expect( 5 === count( $record['opportunities'] ), 'local analysis returns exactly five recommendations' );
ai_analysis_integration_expect( 'success' === $record['status'] && ! empty( $record['context_hash'] ), 'validated analysis stores success provenance and context hash' );
ai_analysis_integration_expect( 5 === count( $repository->latest()['opportunities'] ), 'analysis history stores exactly five validated opportunities' );
ai_analysis_integration_expect( 1 === MockProvider::$analysis_calls, 'one analysis performs exactly one provider request with no retry' );
ai_analysis_integration_expect( 8 === $record['diagnostics']['candidates_received'] && 5 === $record['diagnostics']['candidates_selected'] && 3 === $record['diagnostics']['candidates_rejected'] && 3 === $record['diagnostics']['rejection_rules']['not_selected_after_limit'], 'successful history records bounded eight-to-five selection diagnostics' );
ai_analysis_integration_expect( false === strpos( serialize( $record ), 'mock-6' ), 'unselected candidate payloads are not persisted in successful history' );

$after_counts = array( 'page' => (int) wp_count_posts( 'page' )->publish, 'post' => (int) wp_count_posts( 'post' )->publish );
ai_analysis_integration_expect( $before_counts === $after_counts, 'analysis creates no Pages or Posts' );
foreach ( $entity_ids as $id ) { ai_analysis_integration_expect( $before_entity_meta[ $id ] === get_post_meta( $id ), 'analysis leaves entity metadata unchanged for post ' . $id ); }
ai_analysis_integration_expect( $before_seopress_options === $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%seopress%' ORDER BY option_name", ARRAY_A ), 'analysis leaves SEOPress options unchanged' );
ai_analysis_integration_expect( $before_seopress_meta === $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '_seopress_%' ORDER BY meta_id", ARRAY_A ), 'analysis leaves SEOPress metadata unchanged' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
ai_analysis_integration_expect( ! empty( $admins ), 'administrator exists for Content Opportunities rendering' );
wp_set_current_user( (int) $admins[0] );
$page = new ContentOpportunitiesPage( $service, $repository, $keys );
ob_start(); $page->render(); $html = ob_get_clean();
$active_records = array_filter( $page->records_for_view( 'active' ), static function ( $entry ) use ( $record ) { return ( $record['analysis_id'] ?? '' ) === ( $entry['analysis_id'] ?? '' ); } );
ai_analysis_integration_expect( 5 === count( $active_records ) && 5 <= substr_count( $html, '<div class="card">' ), 'Content Opportunities Active queue includes all five recommendations from the new analysis' );
foreach ( $record['opportunities'] as $opportunity ) { ai_analysis_integration_expect( false !== strpos( $html, esc_html( $opportunity['proposed_title'] ) ), 'Active queue renders recommendation ' . $opportunity['id'] ); }
ai_analysis_integration_expect( false === strpos( $html, 'secret-value' ) && false !== strpos( $html, 'type="password"' ), 'admin UI never renders an API key and uses a password field' );
ai_analysis_integration_expect( false === strpos( $html, 'Generate Draft' ), 'Content Opportunities UI exposes no draft-generation action' );

$insufficient_candidates = ( new MockProvider() )->analyze( $context_builder->build() )->opportunities;
foreach ( array( 0, 1, 2, 3 ) as $index ) { $insufficient_candidates[ $index ]['category'] = 'doorway'; }
InsufficientCandidateProvider::$calls = 0;
$insufficient_service = new OpportunityService( $context_builder, new InsufficientCandidateFactory( new InsufficientCandidateProvider( $insufficient_candidates ) ), new OpportunityValidator(), $repository );
try {
	$insufficient_service->analyze();
	ai_analysis_integration_expect( false, 'fewer than five valid candidates fail the complete analysis' );
} catch ( OpportunityValidationException $error ) {
	ai_analysis_integration_expect( true, 'fewer than five valid candidates fail the complete analysis' );
}
$failed_record = $repository->latest();
ai_analysis_integration_expect( 1 === InsufficientCandidateProvider::$calls, 'insufficient candidate selection performs no provider retry' );
ai_analysis_integration_expect( 'failed' === $failed_record['status'] && array() === $failed_record['opportunities'], 'insufficient candidate selection persists zero recommendations' );
ai_analysis_integration_expect( 4 === $failed_record['diagnostics']['candidates_selected'] && 4 === $failed_record['diagnostics']['candidates_rejected'] && 4 === $failed_record['diagnostics']['rejection_rules']['invalid_category'], 'failed history retains bounded survivor and rejection diagnostics' );
$active_after_failure = array_filter( $page->records_for_view( 'active' ), static function ( $entry ) use ( $record ) { return ( $record['analysis_id'] ?? '' ) === ( $entry['analysis_id'] ?? '' ); } );
ai_analysis_integration_expect( 5 === count( $active_after_failure ), 'failed candidate selection does not supersede the previous successful opportunity set' );

echo 'Analysis query delta: ' . $analysis_queries . PHP_EOL;
echo 'Provider key source: ' . $keys->source() . PHP_EOL;
echo "360 AI local MockProvider end-to-end tests passed.\n";
