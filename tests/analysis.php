<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'GLOBAL360_AI_VERSION', '0.2.0-dev' );

$analysis_options = array();
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function remove_accents( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $key, $default = false ) { global $analysis_options; return $analysis_options[ $key ] ?? $default; }
function update_option( $key, $value ) { global $analysis_options; $analysis_options[ $key ] = $value; return true; }
function delete_option( $key ) { global $analysis_options; unset( $analysis_options[ $key ] ); return true; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value ) { return esc_html( $value ); }

require_once dirname( __DIR__ ) . '/src/AI/ProviderInterface.php';
require_once dirname( __DIR__ ) . '/src/AI/AnalysisResponse.php';
require_once dirname( __DIR__ ) . '/src/AI/OpportunityRequest.php';
require_once dirname( __DIR__ ) . '/src/AI/MockProvider.php';
require_once dirname( __DIR__ ) . '/src/AI/OpenAIProvider.php';
require_once dirname( __DIR__ ) . '/src/AI/AnthropicProvider.php';
require_once dirname( __DIR__ ) . '/src/Settings/ApiKeyManager.php';
require_once dirname( __DIR__ ) . '/src/AI/ProviderFactory.php';
require_once dirname( __DIR__ ) . '/src/Analysis/OpportunityValidationException.php';
require_once dirname( __DIR__ ) . '/src/Analysis/OpportunityValidator.php';
require_once dirname( __DIR__ ) . '/src/Core/CoreAdapter.php';
require_once dirname( __DIR__ ) . '/src/Context/ContentInventory.php';
require_once dirname( __DIR__ ) . '/src/Context/AnalysisContentReader.php';
require_once dirname( __DIR__ ) . '/src/Context/SiteContextBuilder.php';
require_once dirname( __DIR__ ) . '/src/Analysis/AnalysisContextBuilder.php';
require_once dirname( __DIR__ ) . '/src/Storage/AnalysisRepository.php';
require_once dirname( __DIR__ ) . '/src/Analysis/OpportunityService.php';
require_once dirname( __DIR__ ) . '/src/Admin/ContentOpportunitiesPage.php';

use Global360\AI\AI\MockProvider;
use Global360\AI\AI\OpenAIProvider;
use Global360\AI\AI\ProviderInterface;
use Global360\AI\Analysis\AnalysisContextBuilder;
use Global360\AI\Analysis\OpportunityValidator;
use Global360\AI\Analysis\OpportunityValidationException;
use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Admin\ContentOpportunitiesPage;
use Global360\AI\Context\AnalysisContentReader;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;

function analysis_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
}

function analysis_rejects( callable $callback, $message ) {
	try { $callback(); } catch ( InvalidArgumentException $error ) { analysis_expect( true, $message ); return; }
	analysis_expect( false, $message );
}

function analysis_diagnostic( callable $callback ) {
	try { $callback(); } catch ( OpportunityValidationException $error ) { return $error->diagnostics(); }
	throw new RuntimeException( 'Expected structured opportunity validation failure.' );
}

function fixture_opportunity( $number ) {
	return array(
		'id' => 'item-' . $number, 'proposed_title' => 'Distinct Educational Topic ' . $number,
		'suggested_slug' => 'distinct-educational-topic-' . $number, 'content_type' => 'post', 'category' => 'informational',
		'target_topic' => 'topic ' . $number, 'search_intent' => 'informational', 'rationale' => 'A bounded educational gap.',
		'audience' => 'Patients researching care', 'geo_scope' => 'national', 'relevant_location_ids' => array(),
		'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array( 101 ),
		'priority' => 80, 'confidence' => 0.8,
	);
}

function fixture_five() { return array_map( 'fixture_opportunity', range( 1, 5 ) ); }

$context = array(
	'existing_content' => array( array( 'id' => 101, 'title' => 'Understanding Recovery Time', 'slug' => 'understanding-recovery-time' ) ),
	'allowed_entities' => array(
		'clinic_ids' => array( 10 ), 'doctor_ids' => array( 20 ),
		'locations' => array( array( 'id' => 'city:austin', 'name' => 'Austin', 'clinic_ids' => array( 10 ) ) ),
	),
);

$mock = new MockProvider();
analysis_expect( $mock instanceof ProviderInterface && $mock->is_configured(), 'MockProvider implements the configured provider contract' );
analysis_expect( 5 === count( $mock->analyze( array() )->opportunities ), 'MockProvider returns exactly five deterministic opportunities' );
$format = OpenAIProvider::format();
analysis_expect( 'json_schema' === $format['type'] && true === $format['strict'] && 5 === $format['schema']['properties']['opportunities']['minItems'] && 5 === $format['schema']['properties']['opportunities']['maxItems'], 'OpenAI provider uses strict exactly-five structured output' );

$validator = new OpportunityValidator();
analysis_expect( 5 === count( $validator->validate( fixture_five(), $context ) ), 'exactly-five opportunity schema validates' );
$count_diagnostic = analysis_diagnostic( static function () use ( $validator, $context ) { $validator->validate( array_slice( fixture_five(), 0, 4 ), $context ); } );
analysis_expect( 'opportunity_count' === $count_diagnostic['validation_rule'] && '4' === $count_diagnostic['offending_value'], 'count failure retains a stable rule and bounded primitive count' );
$malformed_diagnostic = analysis_diagnostic( static function () use ( $validator, $context ) { $items = fixture_five(); $items[2] = 'invalid'; $validator->validate( $items, $context ); } );
analysis_expect( 'malformed_opportunity' === $malformed_diagnostic['validation_rule'] && 3 === $malformed_diagnostic['opportunity_index'], 'malformed opportunity retains a stable rule and index without retaining its payload' );
analysis_rejects( static function () use ( $validator, $context ) { $validator->validate( array_slice( fixture_five(), 0, 4 ), $context ); }, 'four opportunities are rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[] = fixture_opportunity( 6 ); $validator->validate( $items, $context ); }, 'six opportunities are rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['priority'] = -1; $validator->validate( $items, $context ); }, 'priority below zero is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['priority'] = 101; $validator->validate( $items, $context ); }, 'priority above 100 is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['confidence'] = -0.1; $validator->validate( $items, $context ); }, 'confidence below zero is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['confidence'] = 1.1; $validator->validate( $items, $context ); }, 'confidence above one is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['content_type'] = 'clinic'; $validator->validate( $items, $context ); }, 'invalid content type is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['category'] = 'doorway'; $validator->validate( $items, $context ); }, 'invalid category is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[1]['proposed_title'] = $items[0]['proposed_title']; $validator->validate( $items, $context ); }, 'duplicate recommendation titles are rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[1]['suggested_slug'] = $items[0]['suggested_slug']; $validator->validate( $items, $context ); }, 'duplicate recommendation slugs are rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['proposed_title'] = 'Recovery Time: Understanding It'; $validator->validate( $items, $context ); }, 'substantial existing-title overlap is rejected' );

$valid_refs = fixture_five();
$valid_refs[0]['relevant_clinic_ids'] = array( 10 ); $valid_refs[0]['relevant_doctor_ids'] = array( 20 );
analysis_expect( 10 === $validator->validate( $valid_refs, $context )[0]['relevant_clinic_ids'][0], 'valid Clinic and Doctor references are accepted' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['relevant_clinic_ids'] = array( 999 ); $validator->validate( $items, $context ); }, 'invalid Clinic reference is rejected' );
$reference_diagnostic = analysis_diagnostic( static function () use ( $validator, $context ) { $items = fixture_five(); $items[3]['relevant_location_ids'] = array( 'city:xx:example' ); $validator->validate( $items, $context ); } );
analysis_expect( 'invalid_location_id' === $reference_diagnostic['validation_rule'] && 4 === $reference_diagnostic['opportunity_index'] && 'item-4' === $reference_diagnostic['opportunity_id'] && 'city:xx:example' === $reference_diagnostic['offending_value'], 'entity failure retains rule, opportunity identity, and bounded normalized identifier' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['relevant_doctor_ids'] = array( 999 ); $validator->validate( $items, $context ); }, 'invalid Doctor reference is rejected' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['suggested_internal_link_ids'] = array( 999 ); $validator->validate( $items, $context ); }, 'invalid internal-link ID is rejected' );
$local = fixture_five(); $local[0]['category'] = 'local'; $local[0]['geo_scope'] = 'Austin'; $local[0]['relevant_location_ids'] = array( 'city:austin' ); $local[0]['relevant_clinic_ids'] = array( 10 );
analysis_expect( 'city:austin' === $validator->validate( $local, $context )[0]['relevant_location_ids'][0], 'valid local opportunity is grounded in Core context' );
analysis_rejects( static function () use ( $validator, $context ) { $items = fixture_five(); $items[0]['category'] = 'local'; $items[0]['geo_scope'] = 'Nowhere'; $validator->validate( $items, $context ); }, 'ungrounded local opportunity is rejected' );
$mismatched_context = $context; $mismatched_context['allowed_entities']['clinic_ids'][] = 11;
analysis_rejects( static function () use ( $validator, $mismatched_context ) { $items = fixture_five(); $items[0]['category'] = 'local'; $items[0]['geo_scope'] = 'Austin'; $items[0]['relevant_location_ids'] = array( 'city:austin' ); $items[0]['relevant_clinic_ids'] = array( 11 ); $validator->validate( $items, $mismatched_context ); }, 'Clinic outside the referenced location is rejected' );

$rule_cases = array(
	'invalid_title' => static function () { $items = fixture_five(); $items[0]['proposed_title'] = ''; return $items; },
	'invalid_slug' => static function () { $items = fixture_five(); $items[0]['suggested_slug'] = ''; return $items; },
	'invalid_content_type' => static function () { $items = fixture_five(); $items[0]['content_type'] = 'clinic'; return $items; },
	'invalid_category' => static function () { $items = fixture_five(); $items[0]['category'] = 'doorway'; return $items; },
	'duplicate_title' => static function () { $items = fixture_five(); $items[1]['proposed_title'] = $items[0]['proposed_title']; return $items; },
	'duplicate_slug' => static function () { $items = fixture_five(); $items[1]['suggested_slug'] = $items[0]['suggested_slug']; return $items; },
	'existing_content_overlap' => static function () { $items = fixture_five(); $items[0]['proposed_title'] = 'Recovery Time: Understanding It'; return $items; },
	'invalid_location_id' => static function () { $items = fixture_five(); $items[0]['relevant_location_ids'] = array( 'city:invalid' ); return $items; },
	'invalid_clinic_id' => static function () { $items = fixture_five(); $items[0]['relevant_clinic_ids'] = array( 999 ); return $items; },
	'invalid_doctor_id' => static function () { $items = fixture_five(); $items[0]['relevant_doctor_ids'] = array( 999 ); return $items; },
	'invalid_internal_link_id' => static function () { $items = fixture_five(); $items[0]['suggested_internal_link_ids'] = array( 999 ); return $items; },
	'missing_local_grounding' => static function () { $items = fixture_five(); $items[0]['category'] = 'local'; $items[0]['geo_scope'] = 'Austin'; return $items; },
	'invalid_priority' => static function () { $items = fixture_five(); $items[0]['priority'] = 101; return $items; },
	'invalid_confidence' => static function () { $items = fixture_five(); $items[0]['confidence'] = 1.1; return $items; },
);
foreach ( $rule_cases as $expected_rule => $fixture ) {
	$diagnostic = analysis_diagnostic( static function () use ( $validator, $context, $fixture ) { $validator->validate( $fixture(), $context ); } );
	$expected_index = in_array( $expected_rule, array( 'duplicate_title', 'duplicate_slug' ), true ) ? 2 : 1;
	analysis_expect( $expected_rule === $diagnostic['validation_rule'] && $expected_index === $diagnostic['opportunity_index'], 'stable diagnostic rule is retained for ' . $expected_rule );
}
$mismatch_diagnostic = analysis_diagnostic( static function () use ( $validator, $mismatched_context ) { $items = fixture_five(); $items[0]['category'] = 'local'; $items[0]['geo_scope'] = 'Austin'; $items[0]['relevant_location_ids'] = array( 'city:austin' ); $items[0]['relevant_clinic_ids'] = array( 11 ); $validator->validate( $items, $mismatched_context ); } );
analysis_expect( 'clinic_location_mismatch' === $mismatch_diagnostic['validation_rule'] && '11' === $mismatch_diagnostic['offending_value'], 'Clinic/location mismatch retains its stable rule and bounded Clinic ID' );

$builder = new AnalysisContextBuilder( new SiteContextBuilder( new CoreAdapter( static function () { return null; } ), new ContentInventory() ), new AnalysisContentReader(), new CoreAdapter( static function () { return null; } ) );
analysis_expect( $builder->hash( array( 'b' => 2, 'a' => 1 ) ) === $builder->hash( array( 'a' => 1, 'b' => 2 ) ), 'context hashing is deterministic across associative key order' );

$repository = new AnalysisRepository();
$id = $repository->save( array( 'provider' => 'mock', 'model' => 'fixture', 'context_hash' => 'hash', 'status' => 'success', 'opportunities' => fixture_five(), 'usage' => array( 'input_tokens' => 12, 'output_tokens' => 34, 'total_tokens' => 46 ), 'api_key' => 'must-not-persist' ) );
$saved = $repository->latest();
analysis_expect( $id === $saved['analysis_id'] && 46 === $saved['usage']['total_tokens'], 'analysis history and token usage are stored' );
analysis_expect( ! isset( $saved['api_key'] ), 'analysis history strips API-key material' );
$repository->save( array( 'provider' => 'anthropic', 'status' => 'failed', 'diagnostics' => array( 'http_status' => 400, 'provider_error_type' => 'invalid_request_error', 'provider_error_message' => 'Example validation failure.', 'provider_request_id' => 'req_test123' ), 'headers' => array( 'x-api-key' => 'must-not-persist' ), 'request_body' => 'must-not-persist', 'raw_response' => 'must-not-persist' ) );
$saved_failure = $repository->latest();
analysis_expect( 400 === $saved_failure['diagnostics']['http_status'] && 'req_test123' === $saved_failure['diagnostics']['provider_request_id'], 'sanitized Anthropic diagnostics persist in private history' );
analysis_expect( ! isset( $saved_failure['headers'], $saved_failure['request_body'], $saved_failure['raw_response'] ), 'analysis history strips raw provider request and response material' );

$service_reflection = new ReflectionClass( OpportunityService::class );
$service = $service_reflection->newInstanceWithoutConstructor();
$repository_property = $service_reflection->getProperty( 'repository' ); $repository_property->setAccessible( true ); $repository_property->setValue( $service, $repository );
$save_failure = $service_reflection->getMethod( 'save_failure' ); $save_failure->setAccessible( true );
$save_failure->invoke( $service, 'anthropic', 'claude-sonnet-5', 'safe-context-hash', 'Structured response failed deterministic validation.', array( 'failure_stage' => 'validation', 'validation_rule' => 'invalid_location_id', 'opportunity_index' => 4, 'opportunity_id' => 'item-4', 'offending_field' => 'location_id', 'offending_value' => 'city:xx:example', 'raw_response' => 'must-not-persist', 'context' => 'must-not-persist', 'api_key' => 'must-not-persist' ), array( 'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150 ) );
$validation_failure = $repository->latest();
analysis_expect( 'validation' === $validation_failure['diagnostics']['failure_stage'] && 'invalid_location_id' === $validation_failure['diagnostics']['validation_rule'] && 4 === $validation_failure['diagnostics']['opportunity_index'], 'post-provider validation failure retains structured sanitized diagnostics' );
analysis_expect( 150 === $validation_failure['usage']['total_tokens'] && array() === $validation_failure['opportunities'], 'post-provider validation failure preserves usage and saves zero recommendations' );
analysis_expect( ! isset( $validation_failure['diagnostics']['raw_response'], $validation_failure['diagnostics']['context'], $validation_failure['diagnostics']['api_key'] ), 'validation diagnostics exclude raw output, context, and credentials' );

$page_reflection = new ReflectionClass( ContentOpportunitiesPage::class );
$page = $page_reflection->newInstanceWithoutConstructor();
$render_failure = $page_reflection->getMethod( 'render_analysis_failure' ); $render_failure->setAccessible( true );
ob_start(); $render_failure->invoke( $page, $validation_failure ); $diagnostic_html = ob_get_clean();
analysis_expect( false !== strpos( $diagnostic_html, 'invalid_location_id' ) && false !== strpos( $diagnostic_html, 'Opportunity item-4' ) && false !== strpos( $diagnostic_html, 'city:xx:example' ), 'private admin history renders concise sanitized validation diagnostics' );
ob_start(); $render_failure->invoke( $page, array( 'status' => 'failed', 'diagnostics' => array() ) ); $historical_html = ob_get_clean();
analysis_expect( false !== strpos( $historical_html, 'No additional diagnostic details' ), 'historical diagnostic-less failures render safely without fabricated details' );

$keys = new ApiKeyManager();
$keys->save( 'secret-value' );
analysis_expect( 'database' === $keys->source() && 'secret-value' === $keys->get(), 'API key can be stored server-side' );
$keys->save( '' );
analysis_expect( 'secret-value' === $keys->get(), 'blank key submission preserves existing secret' );
$keys->save( '', true );
analysis_expect( '' === $keys->get(), 'explicit removal deletes the stored key' );

$analysis_sources = file_get_contents( dirname( __DIR__ ) . '/src/Analysis/OpportunityService.php' ) . file_get_contents( dirname( __DIR__ ) . '/src/Analysis/AnalysisContextBuilder.php' );
analysis_expect( false === strpos( $analysis_sources, 'wp_insert_post' ), 'opportunity analysis contains no Page/Post creation behavior' );
analysis_expect( ! preg_match( '/\b(?:add|update|delete)_post_meta\s*\(/', $analysis_sources ), 'opportunity analysis contains no Clinic/Doctor or SEOPress metadata writes' );
$admin_source = file_get_contents( dirname( __DIR__ ) . '/src/Admin/ContentOpportunitiesPage.php' );
analysis_expect( false !== strpos( $admin_source, 'current_user_can' ) && false !== strpos( $admin_source, 'check_admin_referer' ), 'analysis actions enforce capability and nonce protection' );

echo "360 AI analysis tests passed.\n";
