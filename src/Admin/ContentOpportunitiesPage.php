<?php

namespace Global360\AI\Admin;

use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Generation\DraftService;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Security\WorkflowRequestGuard;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Storage\WorkflowRepository;

final class ContentOpportunitiesPage {
	private const SLUG = '360-ai-opportunities';
	private $service;
	private $repository;
	private $keys;
	private $workflow;
	private $drafts;
	private $guard;

	public function __construct( OpportunityService $service, AnalysisRepository $repository, ApiKeyManager $keys, WorkflowRepository $workflow = null, DraftService $drafts = null, WorkflowRequestGuard $guard = null ) {
		$this->service = $service; $this->repository = $repository; $this->keys = $keys; $this->workflow = $workflow; $this->drafts = $drafts; $this->guard = $guard ?: new WorkflowRequestGuard();
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_global360_ai_analyze', array( $this, 'handle_analyze' ) );
		add_action( 'admin_post_global360_ai_save_provider', array( $this, 'handle_save_provider' ) );
		add_action( 'admin_post_global360_ai_opportunity_workflow', array( $this, 'handle_workflow' ) );
		add_action( 'admin_post_global360_ai_generate_draft', array( $this, 'handle_generate_draft' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( '360-ai_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		$path = GLOBAL360_AI_PATH . 'assets/admin.css';
		wp_enqueue_style( 'global360-ai-admin', plugins_url( 'assets/admin.css', GLOBAL360_AI_FILE ), array(), is_file( $path ) ? (string) filemtime( $path ) : GLOBAL360_AI_VERSION );
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

	public function handle_save_provider(): void {
		$this->authorize( 'global360_ai_save_provider' );
		$this->keys->save_selected_provider( (string) ( $_POST['ai_provider'] ?? '' ) );
		$this->keys->save_for( 'openai', (string) ( $_POST['openai_api_key'] ?? '' ), ! empty( $_POST['remove_openai_api_key'] ) );
		$this->keys->save_for( 'anthropic', (string) ( $_POST['anthropic_api_key'] ?? '' ), ! empty( $_POST['remove_anthropic_api_key'] ) );
		$this->redirect( 'settings_saved' );
	}

	public function handle_workflow(): void {
		$analysis_id = sanitize_text_field( (string) ( $_POST['analysis_id'] ?? '' ) );
		$opportunity_id = sanitize_key( (string) ( $_POST['opportunity_id'] ?? '' ) );
		$this->guard->assert( 'global360_ai_workflow_' . $analysis_id . '_' . $opportunity_id, $analysis_id, $opportunity_id, sanitize_text_field( (string) ( $_POST['_wpnonce'] ?? '' ) ) );
		$intents = array( 'approve' => 'approved', 'reject' => 'rejected', 'restore' => 'pending' );
		$intent = sanitize_key( (string) ( $_POST['workflow_action'] ?? '' ) );
		if ( ! isset( $intents[ $intent ] ) || ! $this->workflow ) { $this->redirect( 'workflow_failed', 'Invalid workflow action.' ); }
		try { $this->workflow->transition( $analysis_id, $opportunity_id, $intents[ $intent ] ); $this->redirect( 'workflow_updated' ); }
		catch ( \Throwable $error ) { $this->redirect( 'workflow_failed', 'Workflow update failed.' ); }
	}

	public function handle_generate_draft(): void {
		$analysis_id = sanitize_text_field( (string) ( $_POST['analysis_id'] ?? '' ) );
		$opportunity_id = sanitize_key( (string) ( $_POST['opportunity_id'] ?? '' ) );
		$this->guard->assert( 'global360_ai_generate_' . $analysis_id . '_' . $opportunity_id, $analysis_id, $opportunity_id, sanitize_text_field( (string) ( $_POST['_wpnonce'] ?? '' ) ) );
		if ( ! $this->drafts ) { $this->redirect( 'draft_failed', 'Draft generation is unavailable.' ); }
		try { $post_id = $this->drafts->generate( $analysis_id, $opportunity_id, get_current_user_id() ); $this->redirect( 'draft_created', '', $post_id ); }
		catch ( \Throwable $error ) { $this->redirect( 'draft_failed', 'Draft generation failed. The opportunity remains approved.' ); }
	}

	public function render(): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) { wp_die( esc_html__( 'You do not have permission to access 360 AI.', '360-ai' ) ); }
		$latest = $this->repository->latest();
		$view = isset( $_GET['opportunity_view'] ) ? sanitize_key( wp_unslash( $_GET['opportunity_view'] ) ) : 'active';
		if ( ! in_array( $view, array( 'active', 'published', 'rejected', 'superseded', 'all' ), true ) ) { $view = 'active'; }
		$view_records = $this->records_for_view( $view );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Content Opportunities', '360-ai' ); ?></h1>
		<p><?php echo esc_html__( 'Analyze published site context, review each recommendation, and generate a WordPress draft only after explicit approval. Generated drafts are never published automatically.', '360-ai' ); ?></p>
		<?php $this->notice(); ?>
		<h2><?php echo esc_html__( 'Provider Configuration', '360-ai' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="global360_ai_save_provider"><?php wp_nonce_field( 'global360_ai_save_provider' ); ?>
			<fieldset><legend><strong><?php echo esc_html__( 'Provider', '360-ai' ); ?></strong></legend>
				<label><input type="radio" name="ai_provider" value="openai" <?php checked( 'openai', $this->keys->selected_provider() ); ?>> <?php echo esc_html__( 'OpenAI', '360-ai' ); ?></label><br>
				<label><input type="radio" name="ai_provider" value="anthropic" <?php checked( 'anthropic', $this->keys->selected_provider() ); ?>> <?php echo esc_html__( 'Anthropic / Claude', '360-ai' ); ?></label>
			</fieldset>
			<?php $this->key_fields( 'openai', 'OpenAI', 'GLOBAL360_AI_OPENAI_API_KEY' ); ?>
			<?php $this->key_fields( 'anthropic', 'Anthropic / Claude', 'GLOBAL360_AI_ANTHROPIC_API_KEY' ); ?>
			<?php submit_button( __( 'Save Provider Configuration', '360-ai' ), 'secondary', 'submit', false ); ?>
		</form>
		<h2><?php echo esc_html__( 'Site Analysis', '360-ai' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="global360_ai_analyze"><?php wp_nonce_field( 'global360_ai_analyze' ); ?>
			<?php submit_button( __( 'Analyze Site', '360-ai' ), 'primary', 'submit', false ); ?>
		</form>
		<?php if ( is_array( $latest ) && 'failed' === ( $latest['status'] ?? '' ) ) : ?>
			<h2><?php echo esc_html__( 'Latest Analysis', '360-ai' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Provider: %1$s · Model: %2$s · Context: %3$s', '360-ai' ), (string) $latest['provider'], (string) $latest['model'], substr( (string) $latest['context_hash'], 0, 12 ) ) ); ?></p>
			<?php $this->render_analysis_failure( $latest ); ?>
		<?php endif; ?>
		<h2><?php echo esc_html__( 'Opportunity Queue', '360-ai' ); ?></h2>
		<?php $this->render_views( $view ); ?>
		<div class="global360-ai-opportunities">
			<?php foreach ( $view_records as $entry ) : $item = $entry['opportunity']; $state = $entry['state']; ?>
				<div class="card"><h3><?php echo esc_html( (string) $item['proposed_title'] ); ?></h3>
				<p><strong><?php echo esc_html( strtoupper( (string) $item['content_type'] ) . ' · ' . (string) $item['category'] . ' · ' . (string) $item['search_intent'] ); ?></strong></p>
				<p><?php echo esc_html( (string) $item['rationale'] ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Audience: %1$s · GEO: %2$s · Priority: %3$d · Confidence: %4$s', '360-ai' ), (string) $item['audience'], (string) $item['geo_scope'], (int) $item['priority'], (string) $item['confidence'] ) ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Locations: %1$s · Clinics: %2$s · Doctors: %3$s · Internal links: %4$s', '360-ai' ), implode( ', ', $item['relevant_location_ids'] ), implode( ', ', $item['relevant_clinic_ids'] ), implode( ', ', $item['relevant_doctor_ids'] ), implode( ', ', $item['suggested_internal_link_ids'] ) ) ); ?></p>
				<?php $this->workflow_controls( (string) $entry['analysis_id'], (string) $item['id'], $state, (string) $entry['classification'] ); ?>
				</div>
			<?php endforeach; ?>
			<?php if ( empty( $view_records ) ) : ?><p><?php echo esc_html__( 'No opportunities found in this view.', '360-ai' ); ?></p><?php endif; ?>
		</div></div>
		<?php
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', '360-ai' ) ); }
		check_admin_referer( $action );
	}

	private function redirect( string $status, string $error = '', int $post_id = 0 ): void {
		$url = add_query_arg( array_filter( array( 'page' => self::SLUG, 'global360_ai_status' => $status, 'global360_ai_error' => $error, 'global360_ai_post_id' => $post_id ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}

	private function safe_error( string $message ): string {
		$allowed = array( '360 Platform Core is required for site analysis.', 'OpenAI is selected but no OpenAI API key is configured.', 'Anthropic is selected but no Anthropic API key is configured.', 'The provider request failed.', 'The provider returned an HTTP error.', 'The provider returned invalid structured output.', 'The Anthropic request failed.', 'Anthropic authentication failed.', 'Anthropic rate limit exceeded.', 'Anthropic returned an HTTP error.', 'Anthropic returned invalid structured output.' );
		if ( preg_match( '/^Anthropic request failed \(HTTP [1-5][0-9]{2}(?:: [a-z0-9_-]{1,64})?\)\.$/', $message ) ) { return $message; }
		return in_array( $message, $allowed, true ) ? $message : 'Analysis failed deterministic validation. No recommendations were saved. Review Latest Analysis for the sanitized diagnostic.';
	}

	/** @param array<string,mixed> $record */
	private function render_analysis_failure( array $record ): void {
		if ( 'failed' !== ( $record['status'] ?? '' ) ) { return; }
		$diagnostics = is_array( $record['diagnostics'] ?? null ) ? $record['diagnostics'] : array();
		$rule = sanitize_key( (string) ( $diagnostics['validation_rule'] ?? '' ) );
		if ( '' === $rule ) {
			echo '<p><strong>' . esc_html__( 'Analysis failed.', '360-ai' ) . '</strong> ' . esc_html__( 'No additional diagnostic details were retained for this historical attempt.', '360-ai' ) . '</p>';
			return;
		}
		$parts = array( 'Validation failed', $rule );
		if ( ! empty( $diagnostics['opportunity_id'] ) ) { $parts[] = 'Opportunity ' . sanitize_key( (string) $diagnostics['opportunity_id'] ); }
		elseif ( ! empty( $diagnostics['opportunity_index'] ) ) { $parts[] = 'Opportunity ' . (int) $diagnostics['opportunity_index']; }
		if ( ! empty( $diagnostics['offending_field'] ) ) {
			$value = (string) ( $diagnostics['offending_value'] ?? '' );
			$parts[] = sanitize_key( (string) $diagnostics['offending_field'] ) . ( '' !== $value ? ': ' . $value : '' );
		}
		echo '<p><strong>' . esc_html( implode( ' — ', $parts ) ) . '</strong></p>';
	}

	private function key_fields( string $provider, string $label, string $constant ): void {
		$source = $this->keys->source_for( $provider );
		$status = 'none' === $source ? __( 'Not configured', '360-ai' ) : ( 'constant' === $source ? __( 'Configured by server constant', '360-ai' ) : __( 'Configured', '360-ai' ) );
		?>
		<fieldset><legend><strong><?php echo esc_html( $label ); ?></strong></legend>
			<p><?php echo esc_html( sprintf( __( 'Status: %s.', '360-ai' ), $status ) ); ?> <?php if ( 'constant' === $source ) { echo esc_html( sprintf( __( '%s takes precedence and cannot be changed here.', '360-ai' ), $constant ) ); } ?></p>
			<label for="global360-ai-<?php echo esc_attr( $provider ); ?>-key"><?php echo esc_html( sprintf( __( '%s API key', '360-ai' ), $label ) ); ?></label>
			<input id="global360-ai-<?php echo esc_attr( $provider ); ?>-key" name="<?php echo esc_attr( $provider ); ?>_api_key" type="password" value="" autocomplete="new-password" <?php disabled( 'constant', $source ); ?>>
			<label><input type="checkbox" name="remove_<?php echo esc_attr( $provider ); ?>_api_key" value="1" <?php disabled( 'constant', $source ); ?>> <?php echo esc_html( sprintf( __( 'Remove stored %s key', '360-ai' ), $label ) ); ?></label>
		</fieldset>
		<?php
	}

	private function workflow_controls( string $analysis_id, string $opportunity_id, array $state, string $classification ): void {
		if ( 'superseded' === $classification ) {
			echo '<p><strong>' . esc_html__( 'Superseded', '360-ai' ) . '</strong> ' . esc_html__( 'A newer successful analysis replaced this untouched recommendation.', '360-ai' ) . '</p>';
			return;
		}
		$status = (string) ( $state['status'] ?? 'pending' );
		if ( 'draft_created' === $status ) {
			$draft_id = (int) ( $state['draft_post_id'] ?? 0 );
			$link = get_edit_post_link( $draft_id, '' );
			$published = $this->is_published_content( $draft_id );
			$promoted = $draft_id && metadata_exists( 'post', $draft_id, '_global360_ai_promoted_at' );
			$label = $published ? 'Published' : ( $promoted ? 'Content — Unpublished' : ( 'trash' === get_post_status( $draft_id ) ? 'Associated Content Trashed' : 'Content Draft Created' ) );
			echo '<p><strong>' . esc_html( $label ) . '</strong> ';
			if ( $link ) { echo '<a class="button button-primary" href="' . esc_url( $link ) . '">' . esc_html( $published || $promoted ? 'Edit Content' : 'Edit Content Draft' ) . '</a>'; }
			if ( $published ) { echo ' <a class="button" href="' . esc_url( get_permalink( $draft_id ) ) . '">' . esc_html__( 'View Content', '360-ai' ) . '</a>'; }
			echo '</p>'; return;
		}
		echo '<p><strong>' . esc_html( ucfirst( $status ) ) . '</strong></p>';
		if ( 'approved' === $status ) { $this->action_form( 'global360_ai_generate_draft', $analysis_id, $opportunity_id, '', __( 'Generate Draft', '360-ai' ), 'global360_ai_generate_' ); $this->action_form( 'global360_ai_opportunity_workflow', $analysis_id, $opportunity_id, 'restore', __( 'Return to Pending', '360-ai' ) ); return; }
		if ( 'rejected' === $status ) { $this->action_form( 'global360_ai_opportunity_workflow', $analysis_id, $opportunity_id, 'restore', __( 'Restore to Pending', '360-ai' ) ); return; }
		$this->action_form( 'global360_ai_opportunity_workflow', $analysis_id, $opportunity_id, 'approve', __( 'Approve', '360-ai' ) );
		$this->action_form( 'global360_ai_opportunity_workflow', $analysis_id, $opportunity_id, 'reject', __( 'Reject', '360-ai' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function records_for_view( string $view ): array {
		$view = in_array( $view, array( 'active', 'published', 'rejected', 'superseded', 'all' ), true ) ? $view : 'active';
		$records = array();
		$latest_success_id = $this->latest_successful_analysis_id();
		foreach ( $this->repository->all() as $analysis ) {
			if ( ! is_array( $analysis ) || 'success' !== ( $analysis['status'] ?? '' ) ) { continue; }
			$analysis_id = (string) ( $analysis['analysis_id'] ?? '' );
			foreach ( (array) ( $analysis['opportunities'] ?? array() ) as $opportunity ) {
				if ( ! is_array( $opportunity ) ) { continue; }
				$state = $this->workflow ? $this->workflow->get( $analysis_id, (string) ( $opportunity['id'] ?? '' ) ) : array( 'status' => 'pending', 'draft_post_id' => 0 );
				$classification = $this->classify( $analysis_id, $latest_success_id, $state );
				$include = 'all' === $view || $classification === $view;
				if ( $include ) { $records[] = array( 'analysis_id' => $analysis_id, 'analysis' => $analysis, 'opportunity' => $opportunity, 'state' => $state, 'classification' => $classification ); }
			}
		}
		return $records;
	}

	private function latest_successful_analysis_id(): string {
		foreach ( $this->repository->all() as $analysis ) {
			if ( is_array( $analysis ) && 'success' === ( $analysis['status'] ?? '' ) && 5 === count( (array) ( $analysis['opportunities'] ?? array() ) ) ) {
				return (string) ( $analysis['analysis_id'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Derive one mutually exclusive queue state without mutating immutable analysis history.
	 * Unknown historical workflow states are conservatively retained as Active.
	 *
	 * @param array<string,mixed> $state
	 */
	private function classify( string $analysis_id, string $latest_success_id, array $state ): string {
		if ( $this->is_published_content( (int) ( $state['draft_post_id'] ?? 0 ) ) ) { return 'published'; }
		$status = (string) ( $state['status'] ?? 'pending' );
		if ( 'rejected' === $status ) { return 'rejected'; }
		if ( 'pending' !== $status ) { return 'active'; }
		if ( '' === $latest_success_id ) { return 'active'; }
		return $analysis_id === $latest_success_id ? 'active' : 'superseded';
	}

	private function render_views( string $current ): void {
		$links = array();
		foreach ( array( 'active' => 'Active', 'published' => 'Published', 'rejected' => 'Rejected', 'superseded' => 'Superseded', 'all' => 'All' ) as $view => $label ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'opportunity_view' => $view ), admin_url( 'admin.php' ) );
			$links[] = '<a' . ( $current === $view ? ' class="current" aria-current="page"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' <span class="count">(' . count( $this->records_for_view( $view ) ) . ')</span></a>';
		}
		echo '<nav class="subsubsub">' . implode( ' | ', $links ) . '</nav><br class="clear">';
	}

	private function is_published_content( int $post_id ): bool { return 0 < $post_id && 'g360_content' === get_post_type( $post_id ) && 'publish' === get_post_status( $post_id ); }

	private function action_form( string $action, string $analysis_id, string $opportunity_id, string $intent, string $label, string $nonce_prefix = 'global360_ai_workflow_' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="analysis_id" value="' . esc_attr( $analysis_id ) . '"><input type="hidden" name="opportunity_id" value="' . esc_attr( $opportunity_id ) . '">';
		if ( '' !== $intent ) { echo '<input type="hidden" name="workflow_action" value="' . esc_attr( $intent ) . '">'; }
		wp_nonce_field( $nonce_prefix . $analysis_id . '_' . $opportunity_id );
		submit_button( $label, 'secondary', 'submit', false ); echo '</form>';
	}

	private function notice(): void {
		$status = sanitize_key( (string) ( $_GET['global360_ai_status'] ?? '' ) );
		if ( 'analysis_complete' === $status ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Analysis completed and five validated opportunities were saved.', '360-ai' ) . '</p></div>'; }
		if ( 'settings_saved' === $status ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Provider settings saved.', '360-ai' ) . '</p></div>'; }
		if ( 'analysis_failed' === $status ) { echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $_GET['global360_ai_error'] ?? __( 'Analysis failed.', '360-ai' ) ) ) . '</p></div>'; }
		if ( 'workflow_updated' === $status ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Opportunity workflow updated.', '360-ai' ) . '</p></div>'; }
		if ( 'workflow_failed' === $status || 'draft_failed' === $status ) { echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $_GET['global360_ai_error'] ?? __( 'Workflow action failed.', '360-ai' ) ) ) . '</p></div>'; }
		if ( 'draft_created' === $status ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Content draft created for human editorial and SEO review. It was not published.', '360-ai' ) . '</p></div>'; }
	}
}
