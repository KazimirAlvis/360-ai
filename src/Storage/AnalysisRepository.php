<?php

namespace Global360\AI\Storage;

final class AnalysisRepository {
	private const OPTION = 'global360_ai_analysis_history';
	private const LIMIT  = 10;

	/** @param array<string,mixed> $record */
	public function save( array $record ): string {
		$history = $this->all();
		$id = wp_generate_uuid4();
		$record['analysis_id'] = $id;
		$record['timestamp'] = gmdate( 'c' );
		$record['plugin_version'] = defined( 'GLOBAL360_AI_VERSION' ) ? GLOBAL360_AI_VERSION : '';
		unset( $record['api_key'], $record['authorization'], $record['headers'], $record['request'], $record['request_body'], $record['payload'], $record['raw_response'], $record['context'] );
		array_unshift( $history, $record );
		update_option( self::OPTION, array_slice( $history, 0, self::LIMIT ), false );
		return $id;
	}

	/** @return array<int,array<string,mixed>> */
	public function all(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return array<string,mixed>|null */
	public function latest() {
		$history = $this->all();
		return isset( $history[0] ) && is_array( $history[0] ) ? $history[0] : null;
	}
}
