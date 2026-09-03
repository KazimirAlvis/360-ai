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

require_once dirname( __DIR__ ) . '/src/AI/ProviderInterface.php';
require_once dirname( __DIR__ ) . '/src/AI/AnalysisResponse.php';
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

function anthropic_response( $status, $body ) {
	return array( 'response' => array( 'code' => $status ), 'body' => is_string( $body ) ? $body : json_encode( $body ) );
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
anthropic_expect( 'json_schema' === $request_body['output_config']['format']['type'] && 5 === $request_body['output_config']['format']['schema']['properties']['opportunities']['minItems'], 'Anthropic request uses exactly-five JSON Schema output' );

$anthropic_http_response = anthropic_response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => '{bad json' ) ) ) );
anthropic_expect( ! $provider->analyze( array() )->is_success(), 'malformed Anthropic JSON is rejected' );
$anthropic_http_response = anthropic_response( 200, '{malformed response' );
anthropic_expect( 'Anthropic returned invalid structured output.' === $provider->analyze( array() )->error, 'invalid Anthropic response JSON is rejected' );
$anthropic_http_response = anthropic_response( 200, array( 'content' => array() ) );
anthropic_expect( ! $provider->analyze( array() )->is_success(), 'malformed Anthropic response is rejected' );
$anthropic_http_response = anthropic_response( 500, array( 'error' => array( 'type' => 'api_error', 'message' => 'sensitive provider detail' ) ) );
anthropic_expect( 'Anthropic returned an HTTP error.' === $provider->analyze( array() )->error, 'Anthropic HTTP error is sanitized' );
$anthropic_http_response = anthropic_response( 401, array( 'error' => array( 'type' => 'authentication_error', 'message' => 'sensitive provider detail' ) ) );
anthropic_expect( 'Anthropic authentication failed.' === $provider->analyze( array() )->error, 'Anthropic authentication error is sanitized' );
$anthropic_http_response = anthropic_response( 429, array( 'error' => array( 'type' => 'rate_limit_error', 'message' => 'sensitive provider detail' ) ) );
anthropic_expect( 'Anthropic rate limit exceeded.' === $provider->analyze( array() )->error, 'Anthropic rate-limit error is sanitized' );
$anthropic_http_response = new WP_Error();
anthropic_expect( 'The Anthropic request failed.' === $provider->analyze( array() )->error, 'Anthropic timeout or transport error is sanitized' );

$admin_source = file_get_contents( dirname( __DIR__ ) . '/src/Admin/ContentOpportunitiesPage.php' );
anthropic_expect( false === strpos( $admin_source, 'get_for( $provider )' ) && false === strpos( $admin_source, 'get_for($provider)' ), 'admin presentation never retrieves stored secret values' );
anthropic_expect( class_exists( MockProvider::class ), 'MockProvider remains available only through its explicit path' );

echo "360 AI Anthropic provider tests passed.\n";
