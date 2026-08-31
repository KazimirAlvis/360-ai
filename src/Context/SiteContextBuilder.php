<?php

namespace Global360\AI\Context;

use Global360\AI\Core\CoreAdapter;

final class SiteContextBuilder {
	/** @var CoreAdapter */
	private $core;

	/** @var ContentInventory */
	private $inventory;

	public function __construct( CoreAdapter $core, ContentInventory $inventory ) {
		$this->core      = $core;
		$this->inventory = $inventory;
	}

	/** @return array<string,mixed> */
	public function build(): array {
		$connected = $this->core->is_available();
		$summary   = $this->core->platform_summary();

		return array(
			'site' => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
			'platform' => array(
				'connected'  => $connected,
				'core_version'=> $this->core->version(),
				'ai_version'  => defined( 'GLOBAL360_AI_VERSION' ) ? GLOBAL360_AI_VERSION : '0.2.0-dev',
			),
			'site_context'   => $connected ? $this->core->site_context() : array(),
			'content_summary'=> $this->inventory->summary(),
			'platform_data'  => array(
				'clinics'       => $summary['clinics'] ?? null,
				'doctors'       => $summary['doctors'] ?? null,
				'relationships' => $summary['relationships'] ?? null,
			),
			'locations' => array(
				'states' => (array) ( $summary['states'] ?? array() ),
				'cities' => (array) ( $summary['cities'] ?? array() ),
			),
		);
	}
}
