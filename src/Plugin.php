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
use Global360\AI\Core\CoreAdapter;
use Global360\AI\Settings\ApiKeyManager;
use Global360\AI\Storage\AnalysisRepository;

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
		$opportunities = new ContentOpportunitiesPage( $service, $repository, $key_manager );
		$opportunities->register_hooks();
	}
}
