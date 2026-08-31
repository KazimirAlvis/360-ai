<?php

namespace Global360\AI\AI;

final class AnalysisResponse {
	public $opportunities;
	public $provider;
	public $model;
	public $usage;
	public $error;

	public function __construct( array $opportunities, string $provider, string $model, array $usage = array(), string $error = '' ) {
		$this->opportunities = $opportunities;
		$this->provider      = $provider;
		$this->model         = $model;
		$this->usage         = $usage;
		$this->error         = $error;
	}

	public function is_success(): bool {
		return '' === $this->error;
	}

	public static function failure( string $provider, string $model, string $error ): self {
		return new self( array(), $provider, $model, array(), $error );
	}
}
