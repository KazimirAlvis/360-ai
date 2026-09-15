<?php

namespace Global360\AI;

use Global360\AI\Admin\AdminPage;
use Global360\AI\Admin\ContentOpportunitiesPage;
use Global360\AI\AI\ProviderFactory;
use Global360\AI\Analysis\AnalysisContextBuilder;
use Global360\AI\Analysis\OpportunityService;
use Global360\AI\Analysis\OpportunityValidator;
use Global360\AI\Context\AnalysisContentReader;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Content\ContentDraftPostType;
use Global360\AI\Content\ContentFrontend;
use Global360\AI\Core\CoreAdapter;
use Global360\AI\Generation\DraftGenerationContextBuilder;
use Global360\AI\Generation\DraftService;
use Global360\AI\Generation\DraftValidator;
use Global360\AI\Generation\GenerationLock;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;
use Global360\AI\Storage\WorkflowRepository;

final class Plugin {
	/** @var self|null */
	private static $instance;

	/** @var bool */
	private $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		$adapter       = new CoreAdapter();
		$inventory     = new ContentInventory();
		$builder       = new SiteContextBuilder( $adapter, $inventory );
		$admin_page    = new AdminPage( $builder );
		$admin_page->register_hooks();

		$key_manager   = new ApiKeyManager();
		$analysis      = new AnalysisContextBuilder( $builder, new AnalysisContentReader(), $adapter );
		$repository    = new AnalysisRepository();
		$service       = new OpportunityService(
			$analysis,
			new ProviderFactory( $key_manager ),
			new OpportunityValidator(),
			$repository
		);
		$workflow      = new WorkflowRepository( $repository );
		$drafts        = new DraftService( $workflow, new DraftGenerationContextBuilder( $analysis, $adapter ), new ProviderFactory( $key_manager ), new DraftValidator(), new GenerationLock() );
		$content       = new ContentDraftPostType();
		$content->register_hooks();
		$content_frontend = new ContentFrontend();
		$content_frontend->register_hooks();
		$opportunities = new ContentOpportunitiesPage( $service, $repository, $key_manager, $workflow, $drafts );
		$opportunities->register_hooks();
	}
}
