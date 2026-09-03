<?php

namespace Global360\AI\AI;

use Global360\AI\Settings\ApiKeyManager;

final class ProviderFactory {
	private $keys;

	public function __construct( ApiKeyManager $keys ) { $this->keys = $keys; }

	public function create(): ProviderInterface {
		if ( defined( 'GLOBAL360_AI_USE_MOCK_PROVIDER' ) && true === GLOBAL360_AI_USE_MOCK_PROVIDER ) {
			return new MockProvider();
		}
		$provider = $this->keys->selected_provider();
		if ( 'anthropic' === $provider ) {
			return new AnthropicProvider( $this->keys->get_for( 'anthropic' ) );
		}
		return new OpenAIProvider( $this->keys->get_for( 'openai' ) );
	}
}
