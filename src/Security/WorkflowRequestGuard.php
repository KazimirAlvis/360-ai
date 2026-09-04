<?php

namespace Global360\AI\Security;

final class WorkflowRequestGuard {
	public function assert( string $nonce_action, string $analysis_id, string $opportunity_id, string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) { throw new \RuntimeException( 'Unauthorized workflow request.' ); }
		if ( '' === $analysis_id || '' === $opportunity_id ) { throw new \InvalidArgumentException( 'Workflow identifiers are required.' ); }
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) { throw new \RuntimeException( 'Invalid workflow nonce.' ); }
	}
}
