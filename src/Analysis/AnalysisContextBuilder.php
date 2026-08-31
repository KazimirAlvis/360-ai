<?php

namespace Global360\AI\Analysis;

use Global360\AI\Context\AnalysisContentReader;
use Global360\AI\Context\SiteContextBuilder;
use Global360\AI\Core\CoreAdapter;

final class AnalysisContextBuilder {
	private $site_context;
	private $content;
	private $core;

	public function __construct( SiteContextBuilder $site_context, AnalysisContentReader $content, CoreAdapter $core ) {
		$this->site_context = $site_context;
		$this->content      = $content;
		$this->core         = $core;
	}

	/** @return array<string,mixed> */
	public function build(): array {
		$context = $this->site_context->build();
		$context['existing_content'] = $this->content->read();
		$context['allowed_entities'] = $this->core->analysis_entities();
		$context['analysis_rules'] = array(
			'opportunity_count' => 5,
			'content_types'     => array( 'page', 'post' ),
			'categories'        => OpportunityValidator::CATEGORIES,
			'ymyl'              => 'Educational topics only; no diagnosis, efficacy claims, invented studies, or personalized medical advice.',
			'geo'               => 'Local topics must reference supplied location and clinic IDs.',
		);
		return $context;
	}

	public function hash( array $context ): string {
		return hash( 'sha256', (string) wp_json_encode( $this->canonicalize( $context ) ) );
	}

	private function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		return $value;
	}
}
