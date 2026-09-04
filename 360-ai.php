<?php
/**
 * Plugin Name: 360 AI
 * Description: General AI foundation and future AI-assisted workflows for the 360 platform.
 * Version: 0.3.0-dev
 * Author: PR360
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: 360-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GLOBAL360_AI_VERSION', '0.3.0-dev' );
define( 'GLOBAL360_AI_FILE', __FILE__ );
define( 'GLOBAL360_AI_PATH', plugin_dir_path( __FILE__ ) );

$global360_ai_files = array(
	'src/Core/CoreAdapter.php',
	'src/Context/ContentInventory.php',
	'src/Context/AnalysisContentReader.php',
	'src/Context/SiteContextBuilder.php',
	'src/Analysis/AnalysisContextBuilder.php',
	'src/Analysis/OpportunityValidator.php',
	'src/AI/ProviderInterface.php',
	'src/AI/AnalysisResponse.php',
	'src/Generation/DraftResponse.php',
	'src/Generation/DraftValidationException.php',
	'src/Generation/DraftRequest.php',
	'src/AI/OpportunityRequest.php',
	'src/AI/MockProvider.php',
	'src/AI/OpenAIProvider.php',
	'src/AI/AnthropicProvider.php',
	'src/AI/ProviderFactory.php',
	'src/Settings/ApiKeyManager.php',
	'src/Storage/AnalysisRepository.php',
	'src/Storage/WorkflowRepository.php',
	'src/Security/WorkflowRequestGuard.php',
	'src/Analysis/OpportunityService.php',
	'src/Generation/DraftGenerationContextBuilder.php',
	'src/Generation/DraftValidator.php',
	'src/Generation/GenerationLock.php',
	'src/Generation/DraftService.php',
	'src/Admin/AdminPage.php',
	'src/Admin/ContentOpportunitiesPage.php',
	'src/Plugin.php',
);

foreach ( $global360_ai_files as $global360_ai_file ) {
	require_once GLOBAL360_AI_PATH . $global360_ai_file;
}
unset( $global360_ai_file, $global360_ai_files );

\Global360\AI\Plugin::instance()->boot();
