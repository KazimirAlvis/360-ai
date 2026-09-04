<?php

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'GLOBAL360_AI_USE_MOCK_PROVIDER', true );

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $wp_load ) ) { fwrite( STDERR, "wp-load.php not found\n" ); exit( 1 ); }
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/template.php';

use Global360\AI\Admin\ContentOpportunitiesPage;
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

$before_queries = get_num_queries();
$record = $service->analyze();
$analysis_queries = get_num_queries() - $before_queries;

ai_analysis_integration_expect( 'mock' === $record['provider'], 'local end-to-end analysis uses the explicit MockProvider' );
ai_analysis_integration_expect( 5 === count( $record['opportunities'] ), 'local analysis returns exactly five recommendations' );
ai_analysis_integration_expect( 'success' === $record['status'] && ! empty( $record['context_hash'] ), 'validated analysis stores success provenance and context hash' );
ai_analysis_integration_expect( 5 === count( $repository->latest()['opportunities'] ), 'analysis history stores exactly five validated opportunities' );

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
ai_analysis_integration_expect( 5 === substr_count( $html, '<div class="card">' ), 'Content Opportunities UI displays exactly five cards' );
ai_analysis_integration_expect( false === strpos( $html, 'secret-value' ) && false !== strpos( $html, 'type="password"' ), 'admin UI never renders an API key and uses a password field' );
ai_analysis_integration_expect( false === strpos( $html, 'Generate Draft' ), 'Content Opportunities UI exposes no draft-generation action' );

echo 'Analysis query delta: ' . $analysis_queries . PHP_EOL;
echo 'Provider key source: ' . $keys->source() . PHP_EOL;
echo "360 AI local MockProvider end-to-end tests passed.\n";
