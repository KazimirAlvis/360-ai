<?php

namespace Global360\AI\Analysis;

final class OpportunityValidationException extends \InvalidArgumentException {
	private $diagnostics;

	/** @param array<string,mixed> $diagnostics */
	public function __construct( string $message, array $diagnostics ) {
		parent::__construct( $message );
		$this->diagnostics = $diagnostics;
	}

	/** @return array<string,mixed> */
	public function diagnostics(): array { return $this->diagnostics; }
}
