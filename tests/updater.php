<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'GLOBAL360_AI_VERSION', '0.4.4' );
define( 'GLOBAL360_AI_FILE', dirname( __DIR__ ) . '/360-ai.php' );

$manifest_version = '0.4.4';
function add_filter() {}
function plugin_basename( $file ) { return '360-ai/' . basename( $file ); }
function sanitize_text_field( $value ) { return (string) $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_kses_post( $value ) { return (string) $value; }
function is_wp_error() { return false; }
function wp_remote_retrieve_response_code() { return 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function wp_remote_get() {
	global $manifest_version;
	return array( 'body' => json_encode( array(
		'name' => '360 AI', 'version' => $manifest_version,
		'download_url' => 'https://github.com/KazimirAlvis/360-ai/archive/refs/heads/main.zip',
		'homepage' => 'https://github.com/KazimirAlvis/360-ai',
	) ) );
}
function updater_expect( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } echo "PASS: $message\n"; }

require dirname( __DIR__ ) . '/src/Updater.php';

$same = (object) array( 'checked' => array( '360-ai/360-ai.php' => '0.4.4' ), 'response' => array() );
$same = \Global360\AI\Updater::inject_update( $same );
updater_expect( empty( $same->response ), 'matching manifest version does not offer an update' );

$manifest_version = '0.4.5';
$newer = (object) array( 'checked' => array( '360-ai/360-ai.php' => '0.4.4' ), 'response' => array() );
$newer = \Global360\AI\Updater::inject_update( $newer );
updater_expect( isset( $newer->response['360-ai/360-ai.php'] ), 'newer manifest version offers an update' );
updater_expect( '0.4.5' === $newer->response['360-ai/360-ai.php']->new_version, 'update response carries the newer version' );

$temp = sys_get_temp_dir() . '/360-ai-updater-' . uniqid();
mkdir( $temp );

$unrelated = $temp . '/unrelated-plugin';
mkdir( $unrelated );
file_put_contents( $unrelated . '/unrelated-plugin.php', "<?php\n/* Plugin Name: Unrelated */\n" );
$original = trailingslashit( $unrelated );
$result = \Global360\AI\Updater::rename_github_package( $original, $temp, null, array( 'type' => 'plugin' ) );
updater_expect( $original === $result && is_dir( $unrelated ), 'empty plugin metadata does not claim an unrelated upload' );
$result = \Global360\AI\Updater::rename_github_package( $original, $temp, null, array( 'type' => 'plugin', 'plugin' => 'unrelated-plugin/unrelated-plugin.php' ) );
updater_expect( $original === $result && is_dir( $unrelated ), 'explicit unrelated plugin basename is ignored' );

$github_dir = $temp . '/360-ai-main';
mkdir( $github_dir );
file_put_contents( $github_dir . '/360-ai.php', "<?php\n/* Plugin Name: 360 AI */\n" );
$renamed = \Global360\AI\Updater::rename_github_package( trailingslashit( $github_dir ), $temp, null, array( 'type' => 'plugin' ) );
$canonical = $temp . '/360-ai';
updater_expect( trailingslashit( $canonical ) === $renamed && is_dir( $canonical ), 'proven 360 AI GitHub package normalizes with a trailing slash' );

$collision = $temp . '/360-ai-feature';
mkdir( $collision );
file_put_contents( $collision . '/360-ai.php', "<?php\n/* Plugin Name: 360 AI */\n" );
$collision_source = trailingslashit( $collision );
$result = \Global360\AI\Updater::rename_github_package( $collision_source, $temp, null, array( 'type' => 'plugin', 'plugin' => '360-ai/360-ai.php' ) );
updater_expect( $collision_source === $result && is_dir( $collision ) && is_dir( $canonical ), 'existing canonical target is never overwritten' );

unlink( $unrelated . '/unrelated-plugin.php' );
rmdir( $unrelated );
unlink( $canonical . '/360-ai.php' );
rmdir( $canonical );
unlink( $collision . '/360-ai.php' );
rmdir( $collision );
rmdir( $temp );

echo "360 AI updater tests passed.\n";
