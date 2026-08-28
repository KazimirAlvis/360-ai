<?php

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $wp_load ) ) { fwrite( STDERR, "wp-load.php not found\n" ); exit( 1 ); }
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Global360\AI\Admin\AdminPage;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;

function ai_integration_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
}

ai_integration_expect( is_plugin_active( '360-ai/360-ai.php' ), '360 AI is active locally' );
ai_integration_expect( function_exists( 'global360_platform' ), 'Platform Core is available' );
ai_integration_expect( class_exists( CoreAdapter::class ) && class_exists( SiteContextBuilder::class ), '360 AI classes load during WordPress bootstrap' );
$admin_hook_found = false;
global $wp_filter;
foreach ( (array) ( $wp_filter['admin_menu']->callbacks ?? array() ) as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		$function = $callback['function'] ?? null;
		if ( is_array( $function ) && isset( $function[0] ) && $function[0] instanceof AdminPage ) { $admin_hook_found = true; }
	}
}
ai_integration_expect( $admin_hook_found, '360 AI admin menu callback is registered' );

$clinic_ids = get_posts( array( 'post_type' => 'clinic', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$doctor_ids = get_posts( array( 'post_type' => 'doctor', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$entity_ids = array_merge( $clinic_ids, $doctor_ids );
$before_meta = array();
foreach ( $entity_ids as $entity_id ) { $before_meta[ $entity_id ] = get_post_meta( $entity_id ); }

$before_queries = get_num_queries();
$builder = new SiteContextBuilder( new CoreAdapter(), new ContentInventory() );
$context = $builder->build();
$dashboard_queries = get_num_queries() - $before_queries;

ai_integration_expect( ! empty( $context['platform']['connected'] ), 'dashboard reports Platform Core connected' );
ai_integration_expect( GLOBAL360_PLATFORM_VERSION === $context['platform']['core_version'], 'dashboard reports the current Core version' );
ai_integration_expect( GLOBAL360_AI_VERSION === $context['platform']['ai_version'], 'dashboard reports the current 360 AI version' );
ai_integration_expect( count( $clinic_ids ) === $context['platform_data']['clinics'], 'Clinic count comes through Core-backed context' );
ai_integration_expect( count( $doctor_ids ) === $context['platform_data']['doctors'], 'Doctor count comes through Core-backed context' );
ai_integration_expect( isset( $context['content_summary']['pages'], $context['content_summary']['posts'] ), 'Page/Post inventory summary is available' );
ai_integration_expect( ! empty( $context['locations']['states'] ) && ! empty( $context['locations']['cities'] ), 'Core locations populate state and city summaries' );
$inventory_items = ( new ContentInventory() )->items( 'page', 2 );
ai_integration_expect( empty( $inventory_items ) || ( isset( $inventory_items[0]['id'], $inventory_items[0]['title'], $inventory_items[0]['permalink'] ) && ! isset( $inventory_items[0]['content'] ) ), 'local Page inventory is lightweight and excludes post bodies' );

foreach ( $entity_ids as $entity_id ) {
	ai_integration_expect( $before_meta[ $entity_id ] === get_post_meta( $entity_id ), 'entity metadata remains unchanged for post ' . $entity_id );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
ai_integration_expect( ! empty( $admins ), 'local administrator is available for admin rendering' );
wp_set_current_user( (int) $admins[0] );
$admin = new AdminPage( $builder );
ob_start(); $admin->render(); $html = ob_get_clean();
ai_integration_expect( false !== strpos( $html, 'Platform Core' ) && false !== strpos( $html, 'Connected' ), 'authenticated dashboard renders connected status' );
ai_integration_expect( false !== strpos( $html, esc_html( GLOBAL360_PLATFORM_VERSION ) ), 'authenticated dashboard displays Core version' );

echo 'Dashboard query delta: ' . $dashboard_queries . PHP_EOL;
echo 'Dataset: ' . count( $clinic_ids ) . ' Clinics, ' . count( $doctor_ids ) . ' Doctors, ' . (int) $context['platform_data']['relationships'] . ' relationships' . PHP_EOL;
echo "360 AI local integration tests passed.\n";
