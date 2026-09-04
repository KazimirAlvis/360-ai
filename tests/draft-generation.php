<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'GLOBAL360_AI_VERSION', '0.3.0-dev' );

$draft_options = array();
$draft_can_manage = true;
$draft_nonce_valid = true;

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function remove_accents( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses( $value, $allowed ) { return strip_tags( (string) $value, implode( '', array_map( static function ( $tag ) { return '<' . $tag . '>'; }, array_keys( $allowed ) ) ) ); }
function wp_generate_uuid4() { static $i = 0; $i++; return '00000000-0000-4000-8000-' . str_pad( (string) $i, 12, '0', STR_PAD_LEFT ); }
function get_option( $key, $default = false ) { global $draft_options; return array_key_exists( $key, $draft_options ) ? $draft_options[ $key ] : $default; }
function update_option( $key, $value ) { global $draft_options; $draft_options[ $key ] = $value; return true; }
function delete_option( $key ) { global $draft_options; unset( $draft_options[ $key ] ); return true; }
function add_option( $key, $value ) { global $draft_options; if ( array_key_exists( $key, $draft_options ) ) { return false; } $draft_options[ $key ] = $value; return true; }
function current_user_can( $capability ) { global $draft_can_manage; return $draft_can_manage && 'manage_options' === $capability; }
function wp_verify_nonce( $nonce, $action ) { global $draft_nonce_valid; return $draft_nonce_valid && 'valid' === $nonce; }

require_once dirname( __DIR__ ) . '/src/Storage/AnalysisRepository.php';
require_once dirname( __DIR__ ) . '/src/Storage/WorkflowRepository.php';
require_once dirname( __DIR__ ) . '/src/Security/WorkflowRequestGuard.php';
require_once dirname( __DIR__ ) . '/src/Generation/DraftRequest.php';
require_once dirname( __DIR__ ) . '/src/Generation/DraftResponse.php';
require_once dirname( __DIR__ ) . '/src/Generation/DraftValidator.php';
require_once dirname( __DIR__ ) . '/src/Generation/GenerationLock.php';

use Global360\AI\Generation\DraftRequest;
use Global360\AI\Generation\DraftValidator;
use Global360\AI\Generation\GenerationLock;
use Global360\AI\Security\WorkflowRequestGuard;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Storage\WorkflowRepository;

function draft_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
function draft_rejects( callable $callback, $message ) { try { $callback(); } catch ( Throwable $error ) { draft_expect( true, $message ); return; } draft_expect( false, $message ); }

function draft_opportunity( $id, $type = 'post' ) {
	return array( 'id' => $id, 'proposed_title' => 'Approved Educational Topic ' . $id, 'suggested_slug' => 'approved-educational-topic-' . $id, 'content_type' => $type, 'category' => 'informational', 'suggested_internal_link_ids' => array( 101 ), 'relevant_clinic_ids' => array(), 'relevant_doctor_ids' => array(), 'relevant_location_ids' => array() );
}

$analysis_repository = new AnalysisRepository();
$analysis_id = $analysis_repository->save( array( 'status' => 'success', 'opportunities' => array_map( 'draft_opportunity', array( 'one', 'two', 'three', 'four', 'five' ) ) ) );
$workflow = new WorkflowRepository( $analysis_repository );
draft_expect( 'pending' === $workflow->get( $analysis_id, 'one' )['status'], 'new opportunity begins pending' );
draft_expect( 'approved' === $workflow->transition( $analysis_id, 'one', 'approved' )['status'], 'pending transitions to approved' );
draft_expect( ! isset( $analysis_repository->latest()['opportunities'][0]['status'] ), 'workflow state does not rewrite original analysis opportunities' );
draft_expect( 'pending' === $workflow->transition( $analysis_id, 'one', 'pending' )['status'], 'approved transitions to pending' );
draft_expect( 'rejected' === $workflow->transition( $analysis_id, 'two', 'rejected' )['status'], 'pending transitions to rejected' );
draft_expect( 'pending' === $workflow->transition( $analysis_id, 'two', 'pending' )['status'], 'rejected transitions to pending' );
$workflow->transition( $analysis_id, 'three', 'approved' );
draft_expect( 'draft_created' === $workflow->transition( $analysis_id, 'three', 'draft_created', 123 )['status'], 'approved transitions to draft_created' );
draft_rejects( static function () use ( $workflow, $analysis_id ) { $workflow->transition( $analysis_id, 'four', 'draft_created', 123 ); }, 'pending cannot transition to draft_created' );
$workflow->transition( $analysis_id, 'five', 'rejected' );
draft_rejects( static function () use ( $workflow, $analysis_id ) { $workflow->transition( $analysis_id, 'five', 'draft_created', 123 ); }, 'rejected cannot transition to draft_created' );
draft_rejects( static function () use ( $workflow ) { $workflow->get( 'unknown', 'one' ); }, 'unknown analysis ID is rejected' );
draft_rejects( static function () use ( $workflow, $analysis_id ) { $workflow->get( $analysis_id, 'unknown' ); }, 'unknown opportunity ID is rejected' );
draft_rejects( static function () use ( $workflow, $analysis_id ) { $workflow->transition( $analysis_id, 'three', 'pending' ); }, 'draft_created is terminal' );
draft_rejects( static function () use ( $workflow, $analysis_id ) { $workflow->transition( $analysis_id, 'three', 'approved' ); }, 'draft_created cannot transition to approved' );
draft_rejects( static function () use ( $workflow, $analysis_id ) { $workflow->transition( $analysis_id, 'three', 'rejected' ); }, 'draft_created cannot transition to rejected' );

$guard = new WorkflowRequestGuard();
$guard->assert( 'action', $analysis_id, 'one', 'valid' );
draft_expect( true, 'authenticated capability and valid nonce are accepted' );
$draft_can_manage = false;
draft_rejects( static function () use ( $guard, $analysis_id ) { $guard->assert( 'action', $analysis_id, 'one', 'valid' ); }, 'unauthorized capability is rejected' );
$draft_can_manage = true; $draft_nonce_valid = false;
draft_rejects( static function () use ( $guard, $analysis_id ) { $guard->assert( 'action', $analysis_id, 'one', 'invalid' ); }, 'invalid nonce is rejected' );
$draft_nonce_valid = true;

$lock = new GenerationLock();
draft_expect( $lock->acquire( $analysis_id, 'one' ), 'first generation lock acquisition succeeds' );
draft_expect( ! $lock->acquire( $analysis_id, 'one' ), 'concurrent generation lock acquisition is rejected' );
$lock->release( $analysis_id, 'one' );
draft_expect( $lock->acquire( $analysis_id, 'one' ), 'generation lock can be reacquired after release' );
$lock->release( $analysis_id, 'one' );

$opportunity = draft_opportunity( 'one' );
$context = array( 'allowed_internal_links' => array( array( 'id' => 101, 'title' => 'Allowed Page' ) ) );
$valid = array(
	'title' => $opportunity['proposed_title'], 'slug' => $opportunity['suggested_slug'],
	'excerpt' => 'A useful educational excerpt for human review.',
	'content' => '<p>' . str_repeat( 'Educational information for careful human review. ', 8 ) . '</p><h2>Questions to discuss</h2><p>Speak with a qualified professional about individual circumstances.</p>',
	'seo_title' => $opportunity['proposed_title'], 'meta_description' => 'A useful educational description for human review.', 'suggested_internal_link_ids' => array( 101 ),
);
$validator = new DraftValidator();
draft_expect( $valid['slug'] === $validator->validate( $valid, $opportunity, $context )['slug'], 'valid semantic HTML draft is accepted' );
foreach ( array( 'title', 'slug', 'excerpt', 'content' ) as $field ) {
	draft_rejects( static function () use ( $validator, $valid, $opportunity, $context, $field ) { $item = $valid; $item[ $field ] = ''; $validator->validate( $item, $opportunity, $context ); }, 'empty ' . $field . ' is rejected' );
}
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $item = $valid; unset( $item['seo_title'] ); $validator->validate( $item, $opportunity, $context ); }, 'missing required field is rejected' );
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $item = $valid; $item['meta_description'] = ''; $validator->validate( $item, $opportunity, $context ); }, 'empty required SEO field is rejected' );
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $item = $valid; $item['unexpected'] = 'field'; $validator->validate( $item, $opportunity, $context ); }, 'malformed output is rejected' );
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $item = $valid; $item['content'] = '<p>Too short.</p>'; $validator->validate( $item, $opportunity, $context ); }, 'content below sane length is rejected' );
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $item = $valid; $item['content'] = '<p>' . str_repeat( 'x', 20001 ) . '</p>'; $validator->validate( $item, $opportunity, $context ); }, 'content above sane length is rejected' );
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $bad = $opportunity; $bad['content_type'] = 'clinic'; $validator->validate( $valid, $bad, $context ); }, 'unexpected post type is rejected' );
draft_rejects( static function () use ( $validator, $valid, $opportunity, $context ) { $item = $valid; $item['suggested_internal_link_ids'] = array( 999 ); $validator->validate( $item, $opportunity, $context ); }, 'invalid internal-link target is rejected' );
foreach ( array( '<script>alert(1)</script>', '<iframe src="x"></iframe>', '<form></form>', '<p onclick="x()">click</p>', '<a href="javascript:alert(1)">link</a>', '<a href="https://unapproved.example">link</a>', '```html', '[arbitrary_shortcode]', '<h1>Unexpected H1</h1>' ) as $unsafe ) {
	draft_rejects( static function () use ( $validator, $valid, $opportunity, $context, $unsafe ) { $item = $valid; $item['content'] .= $unsafe; $validator->validate( $item, $opportunity, $context ); }, 'unsafe draft construct is rejected: ' . substr( $unsafe, 0, 24 ) );
}
draft_expect( 10 === DraftRequest::schema()['properties']['suggested_internal_link_ids']['maxItems'], 'canonical draft schema retains internal-link limit' );

echo "360 AI draft workflow and validator tests passed.\n";
