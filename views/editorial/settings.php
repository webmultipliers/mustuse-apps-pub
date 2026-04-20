<?php
/**
 * Editorial surface: Publisher Settings.
 *
 * @var array $branding
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Insufficient permissions.', 'mustuse-apps-pub' ) );
}
?>
<div class="wrap mua-editorial">
	<h1 class="mua-page-title"><?php esc_html_e( 'Publisher Settings', 'mustuse-apps-pub' ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="mua-notice mua-notice--success">
			<?php esc_html_e( 'Settings saved. These defaults will be inherited by all apps.', 'mustuse-apps-pub' ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="mua_action" value="save_settings">
		<?php wp_nonce_field( 'mua_save_settings', '_mua_nonce' ); ?>

		<div class="mua-card">
			<h2 class="mua-section-heading"><?php esc_html_e( 'Publisher Identity', 'mustuse-apps-pub' ); ?></h2>

			<div class="mua-form-group">
				<label class="mua-label"
					for="publisher_name"><?php esc_html_e( 'Publisher Name', 'mustuse-apps-pub' ); ?></label>
				<input type="text" id="publisher_name" name="publisher_name" class="mua-input"
					value="<?php echo esc_attr( $branding['name'] ?? '' ); ?>"
					placeholder="<?php esc_attr_e( 'e.g., Acme News Group', 'mustuse-apps-pub' ); ?>">
			</div>

			<div class="mua-form-group">
				<label class="mua-label"
					for="publisher_support_email"><?php esc_html_e( 'Support Email', 'mustuse-apps-pub' ); ?></label>
				<input type="email" id="publisher_support_email" name="publisher_support_email" class="mua-input"
					value="<?php echo esc_attr( $branding['support_email'] ?? '' ); ?>"
					placeholder="<?php esc_attr_e( 'support@example.com', 'mustuse-apps-pub' ); ?>">
			</div>

			<div class="mua-form-group">
				<label class="mua-label"
					for="publisher_privacy_url"><?php esc_html_e( 'Privacy Policy URL', 'mustuse-apps-pub' ); ?></label>
				<input type="url" id="publisher_privacy_url" name="publisher_privacy_url" class="mua-input"
					value="<?php echo esc_attr( $branding['privacy_policy_url'] ?? '' ); ?>" placeholder="https://">
			</div>
		</div>

		<div class="mua-card" style="margin-top: var(--mua-space-lg);">
			<h2 class="mua-section-heading"><?php esc_html_e( 'Brand Defaults', 'mustuse-apps-pub' ); ?></h2>
			<p class="mua-text-muted">
				<?php esc_html_e( 'These defaults are inherited by all apps. Individual apps can override them.', 'mustuse-apps-pub' ); ?>
			</p>

			<div class="mua-form-row">
				<div class="mua-form-group">
					<label class="mua-label"
						for="publisher_primary_color"><?php esc_html_e( 'Primary Color', 'mustuse-apps-pub' ); ?></label>
					<input type="color" id="publisher_primary_color" name="publisher_primary_color"
						class="mua-input-color"
						value="<?php echo esc_attr( $branding['primary_color'] ?? '#1e1e1e' ); ?>">
				</div>
				<div class="mua-form-group">
					<label class="mua-label"
						for="publisher_accent_color"><?php esc_html_e( 'Accent Color', 'mustuse-apps-pub' ); ?></label>
					<input type="color" id="publisher_accent_color" name="publisher_accent_color"
						class="mua-input-color"
						value="<?php echo esc_attr( $branding['accent_color'] ?? '#2271b1' ); ?>">
				</div>
			</div>

			<div class="mua-form-group">
				<label class="mua-label"><?php esc_html_e( 'Publisher Logo', 'mustuse-apps-pub' ); ?></label>
				<div class="mua-media-field" data-target="publisher_logo_url">
					<input type="hidden" name="publisher_logo_url" id="publisher_logo_url"
						value="<?php echo esc_attr( $branding['logo_url'] ?? '' ); ?>">
					<?php if ( ! empty( $branding['logo_url'] ) ) : ?>
						<img src="<?php echo esc_url( $branding['logo_url'] ); ?>" class="mua-media-preview" alt="">
					<?php endif; ?>
					<button type="button" class="mua-button mua-button--outline mua-media-upload-btn">
						<?php esc_html_e( 'Choose Image', 'mustuse-apps-pub' ); ?>
					</button>
					<button type="button" class="mua-button mua-button--outline mua-media-remove-btn" <?php echo empty( $branding['logo_url'] ) ? 'style="display:none"' : ''; ?>>
						<?php esc_html_e( 'Remove', 'mustuse-apps-pub' ); ?>
					</button>
				</div>
			</div>
		</div>

		<div class="mua-save-bar">
			<button type="submit" class="mua-button mua-button--primary">
				<?php esc_html_e( 'Save Settings', 'mustuse-apps-pub' ); ?>
			</button>
		</div>
	</form>

	<?php \MustUse\Pub\Admin\KitchenSinkScaffold::renderSettingsCard(); ?>
	<?php \MustUse\Pub\Admin\ContentShowcaseScaffold::renderSettingsCard(); ?>
</div>