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
require_once dirname( __DIR__ ) . '/src/Generation/DraftValidationException.php';
require_once dirname( __DIR__ ) . '/src/Generation/DraftValidator.php';
require_once dirname( __DIR__ ) . '/src/Generation/GenerationLock.php';

use Global360\AI\Generation\DraftRequest;
use Global360\AI\Generation\DraftValidationException;
use Global360\AI\Generation\DraftValidator;
use Global360\AI\Generation\GenerationLock;
use Global360\AI\Security\WorkflowRequestGuard;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Storage\WorkflowRepository;

function draft_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
function draft_rejects( callable $callback, $message ) { try { $callback(); } catch ( Throwable $error ) { draft_expect( true, $message ); return; } draft_expect( false, $message ); }
function draft_validation_diagnostics( callable $callback ) { try { $callback(); } catch ( DraftValidationException $error ) { return $error->diagnostics(); } throw new RuntimeException( 'Expected DraftValidationException.' ); }

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
$semantic = $valid; $semantic['content'] = '<p>' . str_repeat( 'Educational information for review. ', 7 ) . '</p><h2>Heading</h2><h3>Subheading</h3><ul><li><strong>Strong</strong></li></ul><ol><li><em>Emphasis.</em></li></ol>';
draft_expect( $semantic['content'] === $validator->validate( $semantic, $opportunity, $context )['content'], 'exact semantic allowlist passes without sanitization' );
$incomplete = $valid; $incomplete['content'] = '<p>' . str_repeat( 'Educational information for careful human review. ', 8 ) . 'Some individuals report numbness, tingling, or a</p>';
$incomplete_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $incomplete, $opportunity, $context ) { $validator->validate( $incomplete, $opportunity, $context, array( 'completion_status' => 'completed', 'stop_reason' => 'end_turn' ) ); } );
draft_expect( 'incomplete_content' === $incomplete_diagnostics['validation_rule'] && 'end_turn' === $incomplete_diagnostics['stop_reason'], 'mid-sentence provider output is rejected even after a normal provider stop' );
foreach ( array( '?', '!', '.&rdquo;', '.&quot;', '.)' ) as $ending ) {
	$item = $valid; $item['content'] = '<p>' . str_repeat( 'Educational information for careful human review. ', 8 ) . 'Is this complete' . $ending . '</p>';
	draft_expect( $item['slug'] === $validator->validate( $item, $opportunity, $context )['slug'], 'complete terminal punctuation is accepted: ' . $ending );
}
$limited_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $valid, $opportunity, $context ) { $validator->validate( $valid, $opportunity, $context, array( 'completion_status' => 'incomplete', 'stop_reason' => 'max_tokens', 'output_limit_reached' => true ) ); } );
draft_expect( 'incomplete_content' === $limited_diagnostics['validation_rule'] && true === $limited_diagnostics['output_limit_reached'], 'provider output-limit completion is rejected deterministically' );
foreach ( array( 'This draft requires human editorial review before publication.', 'This article was generated by AI.', 'As an AI language model, I cannot provide more detail.' ) as $meta_sentence ) {
	$item = $valid; $item['content'] = '<p>' . str_repeat( 'Educational information for careful human review. ', 8 ) . $meta_sentence . '</p>';
	$meta_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $item, $opportunity, $context ) { $validator->validate( $item, $opportunity, $context ); } );
	draft_expect( 'internal_meta_content' === $meta_diagnostics['validation_rule'], 'internal generation meta-content is rejected: ' . $meta_sentence );
}
foreach ( array( 'A physician may review imaging before discussing treatment options.', 'A published medical review may summarize several treatment approaches.', 'A study may undergo peer review before publication.' ) as $legitimate_sentence ) {
	$item = $valid; $item['content'] = '<p>' . str_repeat( 'Educational information for careful human review. ', 8 ) . $legitimate_sentence . '</p>';
	draft_expect( $item['slug'] === $validator->validate( $item, $opportunity, $context )['slug'], 'legitimate medical review language remains accepted: ' . $legitimate_sentence );
}
$page_opportunity = draft_opportunity( 'page', 'page' );
$page_draft = $valid; $page_draft['title'] = $page_opportunity['proposed_title']; $page_draft['slug'] = $page_opportunity['suggested_slug']; $page_draft['seo_title'] = $page_opportunity['proposed_title'];
$short_page_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $page_draft, $page_opportunity, $context ) { $validator->validate( $page_draft, $page_opportunity, $context ); } );
draft_expect( 'minimum_content' === $short_page_diagnostics['validation_rule'], 'short Page output is rejected as insufficiently complete' );
$page_draft['content'] = '<p>' . str_repeat( 'Useful educational context supports careful questions and qualified human review. ', 50 ) . 'This complete draft requires editorial review.</p>';
draft_expect( $page_draft['slug'] === $validator->validate( $page_draft, $page_opportunity, $context )['slug'], 'substantive Page output meeting the minimum is accepted' );
$encoded = $valid; $encoded['title'] = 'Approved Educational Topic One u0026 Two'; $encoded['seo_title'] = 'Signs u0026 Symptoms'; $encoded['excerpt'] = 'Care \\u0026 review.'; $encoded['meta_description'] = 'Questions &amp; answers.';
$encoded_opportunity = $opportunity; $encoded_opportunity['proposed_title'] = 'Approved Educational Topic One & Two';
$normalized = $validator->validate( $encoded, $encoded_opportunity, $context );
draft_expect( 'Approved Educational Topic One & Two' === $normalized['title'] && 'Signs & Symptoms' === $normalized['seo_title'] && 'Care & review.' === $normalized['excerpt'] && 'Questions &amp; answers.' === $normalized['meta_description'], 'narrow malformed u0026 text is normalized without double-decoding valid ampersands or entities' );
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
$unsupported = $valid; $unsupported['content'] .= '<h4>Private words</h4><div><span>Never persist this sentence.</span></div><table><tr><td>Secret prose</td></tr></table>';
$unsupported_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $unsupported, $opportunity, $context ) { $validator->validate( $unsupported, $opportunity, $context ); } );
draft_expect( array( 'div', 'h4', 'span', 'table', 'td', 'tr' ) === $unsupported_diagnostics['unsupported_tags'], 'unsupported non-dangerous tags are diagnosed, deduplicated, and sorted' );
$unsafe = $valid; $unsafe['content'] .= '<script>privateText()</script><iframe src="private"></iframe><form><input onerror="private()"></form><style>.private{}</style><svg onclick="private()"></svg>';
$unsafe_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $unsafe, $opportunity, $context ) { $validator->validate( $unsafe, $opportunity, $context ); } );
draft_expect( 'unsafe_markup' === $unsafe_diagnostics['validation_rule'] && array( 'form', 'iframe', 'input', 'script', 'style', 'svg' ) === $unsafe_diagnostics['unsafe_tags'], 'unsafe tags are structurally classified' );
draft_expect( array( 'onclick', 'onerror' ) === $unsafe_diagnostics['unsafe_attributes'], 'event-handler attributes are classified as unsafe and deduplicated' );
$attributes = $valid; $attributes['content'] .= '<p class="private prose" id="private" style="color:red" onclick="private()">Never store these words.</p><p class="again">Repeated.</p>';
$attribute_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $attributes, $opportunity, $context ) { $validator->validate( $attributes, $opportunity, $context ); } );
draft_expect( array( 'class', 'id', 'style' ) === $attribute_diagnostics['unsupported_attributes'] && array( 'onclick' ) === $attribute_diagnostics['unsafe_attributes'], 'disallowed and unsafe attributes are separated' );
$common = $valid; $common['content'] .= '<a href="javascript:private()">Link</a><blockquote>Quote</blockquote><h4>Heading</h4><br><hr><b>Bold</b><i>Italic</i>';
$common_diagnostics = draft_validation_diagnostics( static function () use ( $validator, $common, $opportunity, $context ) { $validator->validate( $common, $opportunity, $context ); } );
draft_expect( array( 'a', 'b', 'blockquote', 'br', 'h4', 'hr', 'i' ) === $common_diagnostics['unsupported_tags'] && array( 'href' ) === $common_diagnostics['unsafe_attributes'], 'anchors and optional common article tags remain explicitly rejected, including javascript URLs' );
draft_expect( false === strpos( serialize( array( $unsupported_diagnostics, $unsafe_diagnostics, $attribute_diagnostics ) ), 'Never persist' ) && false === strpos( serialize( $unsafe_diagnostics ), 'privateText' ), 'validation diagnostics contain no generated prose or attribute values' );
$many_tags = array(); $many_attributes = array(); for ( $i = 0; $i < 30; $i++ ) { $many_tags[] = 'tag-' . $i . '<private>'; $many_attributes[] = 'attribute-' . $i . '<private>'; }
$workflow->record_failure( $analysis_id, 'one', 'Bounded fixture.', array( 'unsupported_tags' => $many_tags, 'unsupported_attributes' => $many_attributes ) );
$bounded = $workflow->get( $analysis_id, 'one' )['generation_diagnostics'];
draft_expect( 10 === count( $bounded['unsupported_tags'] ) && 20 === count( $bounded['unsupported_attributes'] ), 'stored structural diagnostics enforce tag and attribute count bounds' );
draft_expect( false === strpos( serialize( $bounded ), '<private>' ), 'stored structural diagnostics retain only normalized name characters' );
draft_expect( 10 === DraftRequest::schema()['properties']['suggested_internal_link_ids']['maxItems'], 'canonical draft schema retains internal-link limit' );
$instructions = DraftRequest::instructions();
draft_expect( false !== strpos( $instructions, 'only <p>, <h2>, <h3>, <ul>, <ol>, <li>, <strong>, and <em>' ), 'canonical generation instructions state the exact HTML tag allowlist' );
draft_expect( false !== strpos( $instructions, 'attribute-free HTML' ) && false !== strpos( $instructions, 'Do not use any other HTML tags or attributes' ), 'canonical generation instructions prohibit HTML attributes' );
draft_expect( false !== strpos( $instructions, 'Do not insert hyperlinks or <a> tags into the generated content.' ), 'canonical generation instructions explicitly prohibit anchors and hyperlinks' );
draft_expect( false !== strpos( $instructions, 'href' ) && false !== strpos( $instructions, 'links, or URLs in content' ), 'canonical generation instructions prohibit href values, links, and URLs in content' );
draft_expect( false !== strpos( $instructions, 'only through suggested_internal_link_ids' ) && false !== strpos( $instructions, 'only IDs supplied in allowed_internal_links' ), 'canonical generation instructions isolate internal-link recommendations to approved IDs' );
draft_expect( false !== strpos( $instructions, 'Never stop mid-sentence or mid-section' ) && false !== strpos( $instructions, 'finish with a complete final paragraph' ), 'canonical generation instructions explicitly require a complete ending' );
draft_expect( false !== strpos( $instructions, 'do not repeat the same disclaimer or caution in multiple sections' ) && false !== strpos( $instructions, 'not mechanically throughout the article' ), 'canonical instructions discourage repetitive medical disclaimers without weakening safety rules' );
draft_expect( false !== strpos( $instructions, 'Prefer concrete, useful explanations' ) && false !== strpos( $instructions, 'Concrete does not mean speculative' ), 'canonical instructions request supportable educational substance' );
draft_expect( false !== strpos( $instructions, 'Avoid formulaic filler' ) && false !== strpos( $instructions, 'varied structure' ), 'canonical instructions discourage formulaic AI-style prose' );
draft_expect( false !== strpos( $instructions, 'Do not mention AI generation, draft status, human editorial review, internal workflow, or publication process' ) && false !== strpos( $instructions, 'ends naturally for the reader' ), 'canonical instructions keep internal workflow language out of reader-facing content' );

echo "360 AI draft workflow and validator tests passed.\n";
