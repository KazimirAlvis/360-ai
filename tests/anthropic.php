<?php

define( 'ABSPATH', __DIR__ . '/' );

$anthropic_options = array();
$anthropic_http_response = null;
$anthropic_request = array();

class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function remove_accents( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $key, $default = false ) { global $anthropic_options; return array_key_exists( $key, $anthropic_options ) ? $anthropic_options[ $key ] : $default; }
function update_option( $key, $value ) { global $anthropic_options; $anthropic_options[ $key ] = $value; return true; }
function delete_option( $key ) { global $anthropic_options; unset( $anthropic_options[ $key ] ); return true; }
function wp_remote_post( $url, $args ) { global $anthropic_http_response, $anthropic_request; $anthropic_request = compact( 'url', 'args' ); return $anthropic_http_response; }
function wp_remote_retrieve_response_code( $response ) { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ) { return (string) ( $response['body'] ?? '' ); }
function wp_remote_retrieve_header( $response, $name ) { return (string) ( $response['headers'][ $name ] ?? '' ); }

require_once dirname( __DIR__ ) . '/src/AI/ProviderInterface.php';
require_once dirname( __DIR__ ) . '/src/AI/AnalysisResponse.php';
require_once dirname( __DIR__ ) . '/src/Generation/DraftResponse.php';
require_once dirname( __DIR__ ) . '/src/Generation/DraftRequest.php';
require_once dirname( __DIR__ ) . '/src/AI/OpportunityRequest.php';
require_once dirname( __DIR__ ) . '/src/AI/MockProvider.php';
require_once dirname( __DIR__ ) . '/src/AI/OpenAIProvider.php';
require_once dirname( __DIR__ ) . '/src/AI/AnthropicProvider.php';
require_once dirname( __DIR__ ) . '/src/Settings/ApiKeyManager.php';
require_once dirname( __DIR__ ) . '/src/AI/ProviderFactory.php';
require_once dirname( __DIR__ ) . '/src/Analysis/OpportunityValidator.php';

use Global360\AI\AI\AnthropicProvider;
use Global360\AI\AI\MockProvider;
use Global360\AI\AI\OpenAIProvider;
use Global360\AI\AI\ProviderFactory;
use Global360\AI\Analysis\OpportunityValidator;
use Global360\AI\Settings\ApiKeyManager;

function anthropic_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
}

function anthropic_response( $status, $body, $headers = array() ) {
	return array( 'response' => array( 'code' => $status ), 'body' => is_string( $body ) ? $body : json_encode( $body ), 'headers' => $headers );
}

function anthropic_schema_has_keyword( $schema, $keyword ) {
	if ( ! is_array( $schema ) ) { return false; }
	if ( array_key_exists( $keyword, $schema ) ) { return true; }
	foreach ( $schema as $value ) { if ( anthropic_schema_has_keyword( $value, $keyword ) ) { return true; } }
	return false;
}

function anthropic_opportunity( $number ) {
	return array(
		'id' => 'anthropic-' . $number, 'proposed_title' => 'Educational Topic ' . $number,
		'suggested_slug' => 'educational-topic-' . $number, 'content_type' => 'post', 'category' => 'informational',
		'target_topic' => 'topic', 'search_intent' => 'informational', 'rationale' => 'A grounded educational gap.',
		'audience' => 'Patients', 'geo_scope' => 'national', 'relevant_location_ids' => array(),
		'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'suggested_internal_link_ids' => array(),
		'priority' => 80, 'confidence' => 0.8,
	);
}

$keys = new ApiKeyManager();
$factory = new ProviderFactory( $keys );
anthropic_expect( $factory->create() instanceof OpenAIProvider, 'ProviderFactory defaults existing installations to OpenAI' );
$keys->save_selected_provider( 'anthropic' );
anthropic_expect( $factory->create() instanceof AnthropicProvider, 'ProviderFactory selects Anthropic explicitly' );
anthropic_expect( ! $factory->create()->is_configured(), 'selected unconfigured Anthropic provider does not fall back' );
$keys->save_selected_provider( 'invalid' );
anthropic_expect( 'anthropic' === $keys->selected_provider(), 'invalid provider selection is ignored' );
$keys->save_selected_provider( 'openai' );
$keys->save( 'openai-fixture' );
anthropic_expect( 'openai-fixture' === $keys->get() && 'database' === $keys->source(), 'existing OpenAI key methods remain compatible' );
$keys->save_for( 'anthropic', 'anthropic-fixture' );
anthropic_expect( 'anthropic-fixture' === $keys->get_for( 'anthropic' ), 'Anthropic database option stores independently' );
$keys->save_for( 'anthropic', '' );
anthropic_expect( 'anthropic-fixture' === $keys->get_for( 'anthropic' ), 'blank Anthropic submission preserves its key' );
$keys->save_for( 'anthropic', '', true );
anthropic_expect( '' === $keys->get_for( 'anthropic' ), 'explicit Anthropic removal deletes its stored key' );
define( 'GLOBAL360_AI_ANTHROPIC_API_KEY', 'constant-fixture' );
$keys->save_for( 'anthropic', 'ignored-fixture' );
anthropic_expect( 'constant' === $keys->source_for( 'anthropic' ) && 'constant-fixture' === $keys->get_for( 'anthropic' ), 'Anthropic constant wins and cannot be overwritten' );

$provider = new AnthropicProvider( 'request-fixture' );
$items = array_map( 'anthropic_opportunity', range( 1, 5 ) );
$anthropic_http_response = anthropic_response( 200, array(
	'model' => 'claude-sonnet-5',
	'content' => array( array( 'type' => 'text', 'text' => json_encode( array( 'opportunities' => $items ) ) ) ),
	'usage' => array( 'input_tokens' => 123, 'output_tokens' => 456 ),
) );
$result = $provider->analyze( array( 'bounded' => 'context' ) );
anthropic_expect( $result->is_success() && 5 === count( $result->opportunities ), 'valid Anthropic structured response is normalized' );
anthropic_expect( 5 === count( ( new OpportunityValidator() )->validate( $result->opportunities, array() ) ), 'shared deterministic validator accepts valid Anthropic output' );
anthropic_expect( 123 === $result->usage['input_tokens'] && 456 === $result->usage['output_tokens'] && 579 === $result->usage['total_tokens'], 'Anthropic token usage is extracted without fabrication' );
anthropic_expect( 'anthropic' === $result->provider && 'claude-sonnet-5' === $result->model, 'Anthropic provenance uses the actual response model' );
anthropic_expect( 'https://api.anthropic.com/v1/messages' === $anthropic_request['url'], 'Anthropic Messages endpoint is used' );
anthropic_expect( '2023-06-01' === $anthropic_request['args']['headers']['anthropic-version'] && isset( $anthropic_request['args']['headers']['x-api-key'] ), 'required Anthropic headers are sent server-side' );
$request_body = json_decode( $anthropic_request['args']['body'], true );
$anthropic_schema = $request_body['output_config']['format']['schema'];
anthropic_expect( 'json_schema' === $request_body['output_config']['format']['type'], 'Anthropic request uses JSON Schema output' );
foreach ( array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems', 'minLength', 'maxLength' ) as $keyword ) {
	anthropic_expect( ! anthropic_schema_has_keyword( $anthropic_schema, $keyword ), 'Anthropic schema removes unsupported ' . $keyword );
}
anthropic_expect( array_keys( \Global360\AI\AI\OpportunityRequest::schema()['properties']['opportunities']['items']['properties'] ) === array_keys( $anthropic_schema['properties']['opportunities']['items']['properties'] ), 'Anthropic schema retains every opportunity property' );
anthropic_expect( \Global360\AI\AI\OpportunityRequest::schema()['properties']['opportunities']['items']['required'] === $anthropic_schema['properties']['opportunities']['items']['required'], 'Anthropic schema retains required fields' );
anthropic_expect( array( 'page', 'post' ) === $anthropic_schema['properties']['opportunities']['items']['properties']['content_type']['enum'], 'Anthropic schema retains supported enums' );
anthropic_expect( 'integer' === $anthropic_schema['properties']['opportunities']['items']['properties']['priority']['type'] && 'number' === $anthropic_schema['properties']['opportunities']['items']['properties']['confidence']['type'], 'Anthropic schema retains scalar types' );
anthropic_expect( 5 === \Global360\AI\AI\OpportunityRequest::schema()['properties']['opportunities']['minItems'] && 100 === \Global360\AI\AI\OpportunityRequest::schema()['properties']['opportunities']['items']['properties']['priority']['maximum'], 'canonical OpenAI schema retains stronger constraints' );

$draft_fixture = array( 'title' => 'Educational Topic 1', 'slug' => 'educational-topic-1', 'excerpt' => 'A sufficiently descriptive educational excerpt.', 'content' => '<p>' . str_repeat( 'Educational content for human review. ', 10 ) . '</p>', 'seo_title' => 'Educational Topic 1', 'meta_description' => 'A sufficiently descriptive educational meta description for review.', 'primary_keyphrase' => 'educational topic', 'secondary_keyphrases' => array( 'patient education guide', 'specialist care questions', 'health topic overview' ), 'content_tags' => array( 'Patient Education', 'Specialist Care', 'Treatment Questions' ), 'suggested_internal_link_ids' => array( 101 ) );
$anthropic_http_response = anthropic_response( 200, array( 'model' => 'claude-sonnet-5', 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => json_encode( $draft_fixture ) ) ), 'usage' => array( 'input_tokens' => 20, 'output_tokens' => 30 ) ) );
$draft_result = $provider->generate_draft( array( 'opportunity' => array( 'id' => 'anthropic-1' ) ) );
anthropic_expect( $draft_result->is_success() && $draft_fixture === $draft_result->draft, 'Anthropic draft response normalizes into DraftResponse' );
anthropic_expect( 50 === $draft_result->usage['total_tokens'], 'Anthropic draft token usage is preserved' );
anthropic_expect( 'completed' === $draft_result->completion['completion_status'] && 'end_turn' === $draft_result->completion['stop_reason'] && false === $draft_result->completion['output_limit_reached'], 'Anthropic draft completion metadata is normalized' );
$draft_body = json_decode( $anthropic_request['args']['body'], true );
anthropic_expect( 'json_schema' === $draft_body['output_config']['format']['type'], 'Anthropic draft request uses structured output' );
anthropic_expect( \Global360\AI\Generation\DraftRequest::instructions() === $draft_body['system'], 'Anthropic draft request uses the canonical generation instructions' );
foreach ( array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems', 'minLength', 'maxLength' ) as $keyword ) { anthropic_expect( ! anthropic_schema_has_keyword( $draft_body['output_config']['format']['schema'], $keyword ), 'Anthropic draft schema removes unsupported ' . $keyword ); }
anthropic_expect( 10 === \Global360\AI\Generation\DraftRequest::schema()['properties']['suggested_internal_link_ids']['maxItems'], 'canonical draft schema remains strict for OpenAI' );
anthropic_expect( in_array( 'primary_keyphrase', $draft_body['output_config']['format']['schema']['required'], true ) && in_array( 'secondary_keyphrases', $draft_body['output_config']['format']['schema']['required'], true ), 'Anthropic draft schema requires the same target-keyphrase fields' );
anthropic_expect( in_array( 'content_tags', $draft_body['output_config']['format']['schema']['required'], true ) && isset( $draft_body['output_config']['format']['schema']['properties']['content_tags'] ), 'Anthropic draft schema requires Content Tags after provider-safe sanitization' );
anthropic_expect( 2 === \Global360\AI\Generation\DraftRequest::schema()['properties']['secondary_keyphrases']['minItems'] && 4 === \Global360\AI\Generation\DraftRequest::schema()['properties']['secondary_keyphrases']['maxItems'], 'canonical OpenAI draft schema bounds secondary target keyphrases' );
$anthropic_http_response = anthropic_response( 200, array( 'model' => 'claude-sonnet-5', 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'text', 'text' => json_encode( $draft_fixture ) ) ), 'usage' => array( 'input_tokens' => 20, 'output_tokens' => 8192 ) ) );
$anthropic_limited = $provider->generate_draft( array() );
anthropic_expect( 'incomplete' === $anthropic_limited->completion['completion_status'] && true === $anthropic_limited->completion['output_limit_reached'], 'Anthropic max_tokens stop is normalized as incomplete' );
$anthropic_http_response = anthropic_response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => '{bad' ) ) ) );
anthropic_expect( ! $provider->generate_draft( array() )->is_success(), 'malformed Anthropic draft output is rejected' );
$anthropic_http_response = anthropic_response( 429, array( 'error' => array( 'type' => 'rate_limit_error', 'message' => 'Safe provider message.' ), 'request_id' => 'req_draft' ) );
$draft_failure = $provider->generate_draft( array( 'secret_context' => 'not-stored' ) );
anthropic_expect( 429 === $draft_failure->diagnostics['http_status'] && 'rate_limit_error' === $draft_failure->diagnostics['provider_error_type'], 'Anthropic draft HTTP diagnostics are structured and sanitized' );

$openai = new OpenAIProvider( 'request-fixture' );
$anthropic_http_response = anthropic_response( 200, array( 'model' => 'gpt-5-mini', 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => json_encode( $draft_fixture ) ) ) ) ), 'usage' => array( 'input_tokens' => 11, 'output_tokens' => 22, 'total_tokens' => 33 ) ) );
$openai_draft = $openai->generate_draft( array( 'bounded' => 'context' ) );
anthropic_expect( $openai_draft->is_success() && $draft_fixture === $openai_draft->draft && 33 === $openai_draft->usage['total_tokens'], 'OpenAI draft response normalizes into the same DraftResponse' );
anthropic_expect( 'completed' === $openai_draft->completion['completion_status'] && 'completed' === $openai_draft->completion['stop_reason'] && false === $openai_draft->completion['output_limit_reached'], 'OpenAI draft completion metadata is normalized' );
$openai_body = json_decode( $anthropic_request['args']['body'], true );
anthropic_expect( true === $openai_body['text']['format']['strict'] && 10 === $openai_body['text']['format']['schema']['properties']['suggested_internal_link_ids']['maxItems'] && 4 === $openai_body['text']['format']['schema']['properties']['secondary_keyphrases']['maxItems'], 'OpenAI draft request retains canonical strict schema and keyphrase bounds' );
anthropic_expect( 3 === $openai_body['text']['format']['schema']['properties']['content_tags']['minItems'] && 6 === $openai_body['text']['format']['schema']['properties']['content_tags']['maxItems'], 'OpenAI draft schema retains strict Content Tag bounds' );
anthropic_expect( \Global360\AI\Generation\DraftRequest::instructions() === $openai_body['instructions'], 'OpenAI draft request uses the canonical generation instructions' );
$anthropic_http_response = anthropic_response( 200, array( 'model' => 'gpt-5-mini', 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'max_output_tokens' ), 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => json_encode( $draft_fixture ) ) ) ) ), 'usage' => array( 'input_tokens' => 11, 'output_tokens' => 8192, 'total_tokens' => 8203 ) ) );
$openai_limited = $openai->generate_draft( array() );
anthropic_expect( 'incomplete' === $openai_limited->completion['completion_status'] && 'max_output_tokens' === $openai_limited->completion['stop_reason'] && true === $openai_limited->completion['output_limit_reached'], 'OpenAI max-output stop is normalized as incomplete' );
$anthropic_http_response = anthropic_response( 400, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Safe validation message.' ), 'request_id' => 'req_openai' ) );
$openai_failure = $openai->generate_draft( array( 'secret_context' => 'not-stored' ) );
anthropic_expect( 400 === $openai_failure->diagnostics['http_status'] && 'invalid_request_error' === $openai_failure->diagnostics['provider_error_type'] && ! isset( $openai_failure->diagnostics['headers'] ), 'OpenAI draft HTTP diagnostics are structured without secret leakage' );

$anthropic_http_response = anthropic_response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => '{bad json' ) ) ) );
anthropic_expect( ! $provider->analyze( array() )->is_success(), 'malformed Anthropic JSON is rejected' );
$anthropic_http_response = anthropic_response( 200, '{malformed response' );
anthropic_expect( 'Anthropic returned invalid structured output.' === $provider->analyze( array() )->error, 'invalid Anthropic response JSON is rejected' );
$anthropic_http_response = anthropic_response( 200, array( 'content' => array() ) );
anthropic_expect( ! $provider->analyze( array() )->is_success(), 'malformed Anthropic response is rejected' );
$error_cases = array( 400 => 'invalid_request_error', 401 => 'authentication_error', 403 => 'permission_error', 429 => 'rate_limit_error', 500 => 'api_error' );
foreach ( $error_cases as $status => $type ) {
	$anthropic_http_response = anthropic_response( $status, array( 'type' => 'error', 'error' => array( 'type' => $type, 'message' => "Example request validation failure.\n<script>" ), 'request_id' => 'req_test123' ) );
	$failure = $provider->analyze( array( 'secret_context' => 'must-not-persist' ) );
	anthropic_expect( ! $failure->is_success() && false !== strpos( $failure->error, 'HTTP ' . $status . ': ' . $type ), 'Anthropic HTTP ' . $status . ' has a concise admin error' );
	anthropic_expect( $status === $failure->diagnostics['http_status'] && $type === $failure->diagnostics['provider_error_type'], 'Anthropic HTTP ' . $status . ' captures status and error type' );
	anthropic_expect( 'Example request validation failure.' === $failure->diagnostics['provider_error_message'] && 'req_test123' === $failure->diagnostics['provider_request_id'], 'Anthropic HTTP ' . $status . ' sanitizes message and request ID' );
	anthropic_expect( ! isset( $failure->diagnostics['api_key'], $failure->diagnostics['headers'], $failure->diagnostics['body'], $failure->diagnostics['context'], $failure->diagnostics['raw_response'] ), 'Anthropic HTTP ' . $status . ' retains no secret-bearing data' );
}
$anthropic_http_response = anthropic_response( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Header fallback.' ) ), array( 'request-id' => 'req_header456' ) );
anthropic_expect( 'req_header456' === $provider->analyze( array() )->diagnostics['provider_request_id'], 'Anthropic diagnostics use request-id response-header fallback' );
$anthropic_http_response = new WP_Error();
anthropic_expect( 'The Anthropic request failed.' === $provider->analyze( array() )->error, 'Anthropic timeout or transport error is sanitized' );

$admin_source = file_get_contents( dirname( __DIR__ ) . '/src/Admin/ContentOpportunitiesPage.php' );
anthropic_expect( false === strpos( $admin_source, 'get_for( $provider )' ) && false === strpos( $admin_source, 'get_for($provider)' ), 'admin presentation never retrieves stored secret values' );
anthropic_expect( class_exists( MockProvider::class ), 'MockProvider remains available only through its explicit path' );

echo "360 AI Anthropic provider tests passed.\n";
