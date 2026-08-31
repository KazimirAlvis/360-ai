<?php

namespace Global360\AI\Admin;

use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;

final class ContentOpportunitiesPage {
	private const SLUG = '360-ai-opportunities';
	private $service;
	private $repository;
	private $keys;

	public function __construct( OpportunityService $service, AnalysisRepository $repository, ApiKeyManager $keys ) {
		$this->service = $service; $this->repository = $repository; $this->keys = $keys;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_global360_ai_analyze', array( $this, 'handle_analyze' ) );
		add_action( 'admin_post_global360_ai_save_key', array( $this, 'handle_save_key' ) );
	}

	public function register_menu(): void {
		add_submenu_page( AdminPage::MENU_SLUG, __( 'Content Opportunities', '360-ai' ), __( 'Content Opportunities', '360-ai' ), AdminPage::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	public function handle_analyze(): void {
		$this->authorize( 'global360_ai_analyze' );
		try {
			$this->service->analyze();
			$this->redirect( 'analysis_complete' );
		} catch ( \Throwable $error ) {
			$this->redirect( 'analysis_failed', $this->safe_error( $error->getMessage() ) );
		}
	}

	public function handle_save_key(): void {
		$this->authorize( 'global360_ai_save_key' );
		$this->keys->save( (string) ( $_POST['openai_api_key'] ?? '' ), ! empty( $_POST['remove_openai_api_key'] ) );
		$this->redirect( 'settings_saved' );
	}

	public function render(): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) { wp_die( esc_html__( 'You do not have permission to access 360 AI.', '360-ai' ) ); }
		$latest = $this->repository->latest();
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Content Opportunities', '360-ai' ); ?></h1>
		<p><?php echo esc_html__( 'Analyze published site context and receive exactly five human-review recommendations. This tool never creates content.', '360-ai' ); ?></p>
		<?php $this->notice(); ?>
		<h2><?php echo esc_html__( 'Provider Configuration', '360-ai' ); ?></h2>
		<p><?php echo esc_html( sprintf( __( 'OpenAI key status: %s. A GLOBAL360_AI_OPENAI_API_KEY constant takes precedence over the saved database value.', '360-ai' ), $this->keys->source() ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="global360_ai_save_key"><?php wp_nonce_field( 'global360_ai_save_key' ); ?>
			<label for="global360-ai-key"><?php echo esc_html__( 'OpenAI API key', '360-ai' ); ?></label>
			<input id="global360-ai-key" name="openai_api_key" type="password" value="" autocomplete="new-password" <?php disabled( 'constant', $this->keys->source() ); ?>>
			<label><input type="checkbox" name="remove_openai_api_key" value="1" <?php disabled( 'constant', $this->keys->source() ); ?>> <?php echo esc_html__( 'Remove saved key', '360-ai' ); ?></label>
			<?php submit_button( __( 'Save Provider Settings', '360-ai' ), 'secondary', 'submit', false ); ?>
		</form>
		<h2><?php echo esc_html__( 'Site Analysis', '360-ai' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="global360_ai_analyze"><?php wp_nonce_field( 'global360_ai_analyze' ); ?>
			<?php submit_button( __( 'Analyze Site', '360-ai' ), 'primary', 'submit', false ); ?>
		</form>
		<?php if ( is_array( $latest ) ) : ?>
			<h2><?php echo esc_html__( 'Latest Analysis', '360-ai' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Provider: %1$s · Model: %2$s · Context: %3$s', '360-ai' ), (string) $latest['provider'], (string) $latest['model'], substr( (string) $latest['context_hash'], 0, 12 ) ) ); ?></p>
			<div class="global360-ai-opportunities">
			<?php foreach ( (array) ( $latest['opportunities'] ?? array() ) as $item ) : ?>
				<div class="card"><h3><?php echo esc_html( (string) $item['proposed_title'] ); ?></h3>
				<p><strong><?php echo esc_html( strtoupper( (string) $item['content_type'] ) . ' · ' . (string) $item['category'] . ' · ' . (string) $item['search_intent'] ); ?></strong></p>
				<p><?php echo esc_html( (string) $item['rationale'] ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Audience: %1$s · GEO: %2$s · Priority: %3$d · Confidence: %4$s', '360-ai' ), (string) $item['audience'], (string) $item['geo_scope'], (int) $item['priority'], (string) $item['confidence'] ) ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Locations: %1$s · Clinics: %2$s · Doctors: %3$s · Internal links: %4$s', '360-ai' ), implode( ', ', $item['relevant_location_ids'] ), implode( ', ', $item['relevant_clinic_ids'] ), implode( ', ', $item['relevant_doctor_ids'] ), implode( ', ', $item['suggested_internal_link_ids'] ) ) ); ?></p>
				</div>
			<?php endforeach; ?></div>
		<?php endif; ?></div>
		<?php
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', '360-ai' ) ); }
		check_admin_referer( $action );
	}

	private function redirect( string $status, string $error = '' ): void {
		$url = add_query_arg( array_filter( array( 'page' => self::SLUG, 'global360_ai_status' => $status, 'global360_ai_error' => $error ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}

	private function safe_error( string $message ): string {
		$allowed = array( '360 Platform Core is required for site analysis.', 'No analysis provider is configured.', 'The provider request failed.', 'The provider returned an HTTP error.', 'The provider returned invalid structured output.' );
		return in_array( $message, $allowed, true ) ? $message : 'Analysis failed validation. No recommendations were saved.';
	}

	private function notice(): void {
		$status = sanitize_key( (string) ( $_GET['global360_ai_status'] ?? '' ) );
		if ( 'analysis_complete' === $status ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Analysis completed and five validated opportunities were saved.', '360-ai' ) . '</p></div>'; }
		if ( 'settings_saved' === $status ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Provider settings saved.', '360-ai' ) . '</p></div>'; }
		if ( 'analysis_failed' === $status ) { echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $_GET['global360_ai_error'] ?? __( 'Analysis failed.', '360-ai' ) ) ) . '</p></div>'; }
	}
}
