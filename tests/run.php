<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'GLOBAL360_AI_VERSION', '0.1.0' );
define( 'GLOBAL360_PLATFORM_VERSION', '1.0.1' );

$test_posts = array();
$test_counts = array( 'page' => 3, 'post' => 4 );
$test_can_manage = true;
$test_menu = array();
$test_inventory_query = '';

class WP_Post {
	public $ID;
	public $post_type;
	public $post_name;
	public $post_modified_gmt;
	public $post_title;
	public function __construct( $id, $type, $title ) {
		$this->ID = $id; $this->post_type = $type; $this->post_title = $title;
		$this->post_name = strtolower( str_replace( ' ', '-', $title ) ); $this->post_modified_gmt = '2026-08-27 12:00:00';
	}
}

final class TestWpdb {
	public $posts = 'wp_posts';
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_results( $query ) {
		global $test_inventory_query, $test_posts;
		$test_inventory_query = $query;
		$type = false !== strpos( $query, "'page'" ) ? 'page' : 'post';
		return $test_posts[ $type ] ?? array();
	}
}
$wpdb = new TestWpdb();

function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function get_posts( $args ) { global $test_posts; return $test_posts[ $args['post_type'] ] ?? array(); }
function wp_count_posts( $type ) { global $test_counts; return (object) array( 'publish' => $test_counts[ $type ] ?? 0 ); }
function get_bloginfo( $key ) { return 'name' === $key ? 'Test <Site>' : ''; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_the_title( $post ) { return $post->post_title; }
function get_permalink( $post ) { $id = $post instanceof WP_Post ? $post->ID : (int) $post; return 'https://example.test/?p=' . $id; }
function add_action( $hook, $callback ) { global $test_actions; $test_actions[ $hook ][] = $callback; }
function add_menu_page( $page, $menu, $capability, $slug ) { global $test_menu; $test_menu = compact( 'page', 'menu', 'capability', 'slug' ); }
function add_submenu_page() { return ''; }
function current_user_can() { global $test_can_manage; return $test_can_manage; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value ) { return esc_html( $value ); }
function __( $value ) { return $value; }

require_once dirname( __DIR__ ) . '/src/Core/CoreAdapter.php';
require_once dirname( __DIR__ ) . '/src/Context/ContentInventory.php';
require_once dirname( __DIR__ ) . '/src/Context/SiteContextBuilder.php';
require_once dirname( __DIR__ ) . '/src/Admin/AdminPage.php';
require_once dirname( __DIR__ ) . '/src/Plugin.php';

use Global360\AI\Admin\AdminPage;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;

function ai_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
}

final class FakeSiteContext {
	public function site_name() { return '<script>Brand</script>'; }
	public function primary_condition() { return 'Condition'; }
	public function primary_treatment() { return 'Treatment'; }
	public function brand_colors() { return array( 'primary' => '#000' ); }
	public function assessment() { return array( 'id' => 'assessment' ); }
	public function contact() { return array( 'email' => 'test@example.test' ); }
	public function schema() { return array( 'medical_specialty' => 'Specialty' ); }
}
final class FakeClinicRepository {
	public function get( $id ) { return array( 'state_codes' => array( 'TX' ), 'addresses' => array( array( 'city' => 10 === $id ? 'Austin' : 'Dallas' ) ) ); }
}
final class FakeDoctorRepository {
	public function get( $id ) { return array( 'clinic_ids' => array( 10, 11 ) ); }
}
final class FakeStates {
	public function name( $code ) { return 'TX' === $code ? 'Texas' : ''; }
}
final class FakePlatform {
	public function site_context() { return new FakeSiteContext(); }
	public function clinics() { return new FakeClinicRepository(); }
	public function doctors() { return new FakeDoctorRepository(); }
	public function states() { return new FakeStates(); }
}

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/360-ai.php' );
ai_expect( false !== strpos( $bootstrap, 'Plugin Name: 360 AI' ) && false !== strpos( $bootstrap, "'src/Plugin.php'" ), 'plugin bootstrap declares identity and loads modular Plugin class' );
ai_expect( false !== strpos( $bootstrap, 'Version: 0.4.0' ) && false !== strpos( $bootstrap, "define( 'GLOBAL360_AI_VERSION', '0.4.0' )" ), '0.4.0 plugin header and authoritative version constant are synchronized' );

$missing = new CoreAdapter( static function () { return null; } );
ai_expect( ! $missing->is_available() && '' === $missing->version(), 'Core unavailability is graceful' );

$test_posts['clinic'] = array( 10, 11 );
$test_posts['doctor'] = array( 20 );
$test_posts['page'] = array( new WP_Post( 1, 'page', 'Example Page' ) );
$test_posts['post'] = array( new WP_Post( 2, 'post', 'Example Post' ) );

$core = new CoreAdapter( static function () { return new FakePlatform(); } );
ai_expect( $core->is_available() && '1.0.1' === $core->version(), 'Core detection reports the connected version' );
$site_context = $core->site_context();
ai_expect( 'Condition' === $site_context['primary_condition'] && 'Treatment' === $site_context['primary_treatment'], 'Core SiteContext values are retrieved' );
$platform = $core->platform_summary();
ai_expect( 2 === $platform['clinics'], 'Clinic count is built from Core entity views' );
ai_expect( 1 === $platform['doctors'] && 2 === $platform['relationships'], 'Doctor and relationship counts use Core entity views' );
ai_expect( array( 'Texas' ) === $platform['states'] && array( 'Austin', 'Dallas' ) === $platform['cities'], 'normalized Core locations are summarized' );

$inventory = new ContentInventory();
ai_expect( array( 'pages' => 3, 'posts' => 4 ) === $inventory->summary(), 'published Page/Post counts are lightweight' );
$page_items = $inventory->items( 'page', 500 );
ai_expect( 1 === count( $page_items ) && ! isset( $page_items[0]['content'] ) && 'example-page' === $page_items[0]['slug'], 'bounded inventory excludes post bodies' );
ai_expect( false === stripos( $test_inventory_query, 'post_content' ) && false !== stripos( $test_inventory_query, 'LIMIT 100' ), 'inventory query selects lightweight columns and enforces its upper bound' );
ai_expect( array() === $inventory->items( 'clinic' ), 'inventory rejects unsupported domain post types' );

$builder = new SiteContextBuilder( $core, $inventory );
$built = $builder->build();
ai_expect( isset( $built['site'], $built['platform'], $built['content_summary'], $built['locations'] ) || isset( $built['platform_data'] ), 'SiteContextBuilder returns reusable structured context' );

$admin = new AdminPage( $builder );
$admin->register_menu();
ai_expect( 'manage_options' === $test_menu['capability'] && '360-ai' === $test_menu['slug'], 'admin menu requires manage_options' );
$test_can_manage = false;
try { $admin->render(); ai_expect( false, 'unauthorized admin render is blocked' ); } catch ( RuntimeException $error ) { ai_expect( true, 'unauthorized admin render is blocked' ); }
$test_can_manage = true;
ob_start(); $admin->render(); $output = ob_get_clean();
ai_expect( false === strpos( $output, '<script>Brand</script>' ) && false !== strpos( $output, '&lt;script&gt;Brand&lt;/script&gt;' ), 'admin output escapes Core-provided values' );

$domain_sources = file_get_contents( dirname( __DIR__ ) . '/src/Core/CoreAdapter.php' ) . file_get_contents( dirname( __DIR__ ) . '/src/Analysis/OpportunityService.php' );
ai_expect( ! preg_match( '/\b(?:add|update|delete)_post_meta\s*\(/', $domain_sources ), 'analysis and Core adapters perform no Clinic/Doctor metadata writes' );
ai_expect( false === strpos( $domain_sources, 'get_post_meta(' ), 'analysis and Core adapters do not read legacy Clinic/Doctor meta directly' );
$draft_source = file_get_contents( dirname( __DIR__ ) . '/src/Generation/DraftService.php' );
ai_expect( false === strpos( $draft_source, "'post_type' => 'clinic'" ) && false === strpos( $draft_source, "'post_type' => 'doctor'" ), 'draft generation cannot target Clinic or Doctor post types' );
$content_source = file_get_contents( dirname( __DIR__ ) . '/src/Content/ContentDraftPostType.php' );
$conversion_source = file_get_contents( dirname( __DIR__ ) . '/src/Generation/ConversionService.php' );
ai_expect( false !== strpos( $content_source, "'public' => false" ) && false !== strpos( $content_source, "'publicly_queryable' => false" ) && false !== strpos( $content_source, "'show_in_rest' => false" ) && false !== strpos( $content_source, "'rewrite' => false" ), 'Content Draft contract is private with no REST or rewrite exposure' );
ai_expect( false !== strpos( $content_source, "current_user_can( 'manage_options' )" ) && false !== strpos( $content_source, 'check_admin_referer(' ), 'conversion action requires capability and a post-bound nonce' );
ai_expect( false !== strpos( $conversion_source, "array( 'page', 'post' )" ) && false !== strpos( $conversion_source, "'post_status' => 'draft'" ), 'conversion accepts only Page/Post and can create only a draft' );
ai_expect( false === strpos( $conversion_source, 'generate_draft(' ), 'conversion makes no provider request' );

echo "360 AI focused tests passed.\n";
