<?php

namespace Global360\AI\Settings;

final class ApiKeyManager {
	private const OPENAI_OPTION = 'global360_ai_openai_api_key';
	private const ANTHROPIC_OPTION = 'global360_ai_anthropic_api_key';
	private const PROVIDER_OPTION = 'global360_ai_provider';

	public function get(): string {
		return $this->get_for( 'openai' );
	}

	public function source(): string {
		return $this->source_for( 'openai' );
	}

	public function save( string $key, bool $remove = false ): void {
		$this->save_for( 'openai', $key, $remove );
	}

	public function get_for( string $provider ): string {
		$constant = $this->constant_name( $provider );
		if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
			return trim( (string) constant( $constant ) );
		}
		return trim( (string) get_option( $this->option_name( $provider ), '' ) );
	}

	public function source_for( string $provider ): string {
		$constant = $this->constant_name( $provider );
		if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
			return 'constant';
		}
		return '' !== $this->get_for( $provider ) ? 'database' : 'none';
	}

	public function save_for( string $provider, string $key, bool $remove = false ): void {
		if ( 'constant' === $this->source_for( $provider ) ) {
			return;
		}
		if ( $remove ) {
			delete_option( $this->option_name( $provider ) );
			return;
		}
		$key = trim( sanitize_text_field( $key ) );
		if ( '' !== $key ) {
			update_option( $this->option_name( $provider ), $key, false );
		}
	}

	public function selected_provider(): string {
		$provider = sanitize_key( (string) get_option( self::PROVIDER_OPTION, 'openai' ) );
		return in_array( $provider, array( 'openai', 'anthropic' ), true ) ? $provider : 'openai';
	}

	public function save_selected_provider( string $provider ): void {
		$provider = sanitize_key( $provider );
		if ( in_array( $provider, array( 'openai', 'anthropic' ), true ) ) {
			update_option( self::PROVIDER_OPTION, $provider, false );
		}
	}

	private function option_name( string $provider ): string {
		return 'anthropic' === $provider ? self::ANTHROPIC_OPTION : self::OPENAI_OPTION;
	}

	private function constant_name( string $provider ): string {
		return 'anthropic' === $provider ? 'GLOBAL360_AI_ANTHROPIC_API_KEY' : 'GLOBAL360_AI_OPENAI_API_KEY';
	}
}
