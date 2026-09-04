<?php

namespace Global360\AI\Generation;

final class GenerationLock {
	private const TTL = 300;

	public function acquire( string $analysis_id, string $opportunity_id ): bool {
		$key = $this->key( $analysis_id, $opportunity_id );
		$expires = (int) get_option( $key, 0 );
		if ( $expires && $expires < time() ) { delete_option( $key ); }
		return add_option( $key, time() + self::TTL, '', false );
	}

	public function release( string $analysis_id, string $opportunity_id ): void { delete_option( $this->key( $analysis_id, $opportunity_id ) ); }

	private function key( string $analysis_id, string $opportunity_id ): string {
		return 'global360_ai_generation_lock_' . substr( hash( 'sha256', $analysis_id . ':' . $opportunity_id ), 0, 32 );
	}
}
