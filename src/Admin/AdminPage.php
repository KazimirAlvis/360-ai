<?php

namespace Global360\AI\Admin;

use Global360\AI\Context\SiteContextBuilder;

final class AdminPage {
	public const CAPABILITY = 'manage_options';
	public const MENU_SLUG  = '360-ai';

	/** @var SiteContextBuilder */
	private $builder;

	public function __construct( SiteContextBuilder $builder ) {
		$this->builder = $builder;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( '360 AI', '360-ai' ),
			__( '360 AI', '360-ai' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-lightbulb',
			58
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access 360 AI.', '360-ai' ) );
		}

		$context   = $this->builder->build();
		$platform  = (array) $context['platform'];
		$content   = (array) $context['content_summary'];
		$data      = (array) $context['platform_data'];
		$locations = (array) $context['locations'];
		$site      = (array) $context['site'];
		$site_data = (array) $context['site_context'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( '360 AI', '360-ai' ); ?></h1>
			<p><?php echo esc_html__( 'Platform context diagnostics for future AI-assisted workflows.', '360-ai' ); ?></p>

			<h2><?php echo esc_html__( 'Platform Status', '360-ai' ); ?></h2>
			<?php if ( empty( $platform['connected'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html__( '360 Platform Core is required. Activate it to make shared platform context available to 360 AI.', '360-ai' ); ?></p></div>
			<?php endif; ?>
			<?php $this->render_rows( array(
				__( 'Platform Core', '360-ai' ) => empty( $platform['connected'] ) ? __( 'Not connected', '360-ai' ) : __( 'Connected', '360-ai' ),
				__( 'Core version', '360-ai' )  => $platform['core_version'] ?? '',
				__( '360 AI version', '360-ai' )=> $platform['ai_version'] ?? '',
			) ); ?>

			<h2><?php echo esc_html__( 'Site Context', '360-ai' ); ?></h2>
			<?php $this->render_rows( array(
				__( 'Site name', '360-ai' )         => $site_data['site_name'] ?? ( $site['name'] ?? '' ),
				__( 'Site URL', '360-ai' )          => $site['url'] ?? '',
				__( 'Primary condition', '360-ai' ) => $site_data['primary_condition'] ?? '',
				__( 'Primary treatment', '360-ai' ) => $site_data['primary_treatment'] ?? '',
			) ); ?>

			<h2><?php echo esc_html__( 'Content', '360-ai' ); ?></h2>
			<?php $this->render_rows( array(
				__( 'Published Pages', '360-ai' ) => $content['pages'] ?? 0,
				__( 'Published Posts', '360-ai' ) => $content['posts'] ?? 0,
			) ); ?>

			<h2><?php echo esc_html__( 'Platform Data', '360-ai' ); ?></h2>
			<?php $this->render_rows( array(
				__( 'Clinics', '360-ai' )      => $data['clinics'] ?? __( 'Unavailable', '360-ai' ),
				__( 'Doctors', '360-ai' )      => $data['doctors'] ?? __( 'Unavailable', '360-ai' ),
				__( 'Relationships', '360-ai' )=> $data['relationships'] ?? __( 'Unavailable', '360-ai' ),
			) ); ?>

			<h2><?php echo esc_html__( 'Locations', '360-ai' ); ?></h2>
			<?php $this->render_rows( array(
				__( 'States represented', '360-ai' ) => implode( ', ', (array) ( $locations['states'] ?? array() ) ),
				__( 'Cities represented', '360-ai' ) => implode( ', ', (array) ( $locations['cities'] ?? array() ) ),
			) ); ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $rows */
	private function render_rows( array $rows ): void {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			$display = is_scalar( $value ) && '' !== (string) $value ? (string) $value : __( 'Unavailable', '360-ai' );
			echo '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( $display ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
