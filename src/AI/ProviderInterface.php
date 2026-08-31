<?php

namespace Global360\AI\AI;

interface ProviderInterface {
	public function name(): string;
	public function model(): string;
	public function is_configured(): bool;
	public function analyze( array $context ): AnalysisResponse;
}
