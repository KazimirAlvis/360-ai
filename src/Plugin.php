<?php

namespace Global360\AI;

use Global360\AI\Admin\AdminPage;
use Global360\AI\Context\ContentInventory;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;

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
	}
}
