<?php

namespace Global360\AI\Settings;

final class ApiKeyManager {
	private const OPTION = 'global360_ai_openai_api_key';

	public function get(): string {
		if ( defined( 'GLOBAL360_AI_OPENAI_API_KEY' ) && '' !== trim( (string) GLOBAL360_AI_OPENAI_API_KEY ) ) {
			return trim( (string) GLOBAL360_AI_OPENAI_API_KEY );
		}
		return trim( (string) get_option( self::OPTION, '' ) );
	}

	public function source(): string {
		if ( defined( 'GLOBAL360_AI_OPENAI_API_KEY' ) && '' !== trim( (string) GLOBAL360_AI_OPENAI_API_KEY ) ) {
			return 'constant';
		}
		return '' !== $this->get() ? 'database' : 'none';
	}

	public function save( string $key, bool $remove = false ): void {
		if ( 'constant' === $this->source() ) {
			return;
		}
		if ( $remove ) {
			delete_option( self::OPTION );
			return;
		}
		$key = trim( sanitize_text_field( $key ) );
		if ( '' !== $key ) {
			update_option( self::OPTION, $key, false );
		}
	}
}
