<?php

namespace Global360\AI\Generation;

final class DraftResponse {
	public $draft;
	public $provider;
	public $model;
	public $usage;
	public $error;
	public $diagnostics;

	public function __construct( array $draft, string $provider, string $model, array $usage = array(), string $error = '', array $diagnostics = array() ) {
		$this->draft = $draft;
		$this->provider = $provider;
		$this->model = $model;
		$this->usage = $usage;
		$this->error = $error;
		$this->diagnostics = $diagnostics;
	}

	public function is_success(): bool { return '' === $this->error; }

	public static function failure( string $provider, string $model, string $error, array $diagnostics = array() ): self {
		return new self( array(), $provider, $model, array(), $error, $diagnostics );
	}
}
