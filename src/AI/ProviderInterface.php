<?php

namespace Global360\AI\AI;

use Global360\AI\Generation\DraftResponse;

interface ProviderInterface {
	public function name(): string;
	public function model(): string;
	public function is_configured(): bool;
	public function analyze( array $context ): AnalysisResponse;
	public function generate_draft( array $context ): DraftResponse;
}
