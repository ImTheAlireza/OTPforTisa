<?php
/**
 * Tisa OTP — sign-in form wrapper.
 *
 * Override in a theme by copying this file to `{theme}/tisa-otp/form.php`.
 *
 * Available variables: instance, classes, style, heading, hint, regHeading,
 * regHint, redirect, labels, codeLength, cooldown, showBrand, logo, logoWidth,
 * fields, flow, registration, captcha, terms, dir, configUrl, cacheMode, nonce,
 * restUrl, honeypot, timestampKey, renderedAt, view.
 *
 * @package TisaOtp
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	class="<?php echo esc_attr( $classes ); ?>"
	id="<?php echo esc_attr( $instance ); ?>"
	dir="<?php echo esc_attr( $dir ); ?>"
	style="<?php echo esc_attr( $style ); ?>"
	data-tisa-form
	data-endpoint="<?php echo esc_url( $restUrl ); ?>"
	data-config-url="<?php echo esc_url( $configUrl ); ?>"
	data-cache-mode="<?php echo esc_attr( $cacheMode ); ?>"
	data-nonce="<?php echo esc_attr( $nonce ); ?>"
	data-cooldown="<?php echo esc_attr( (string) $cooldown ); ?>"
	data-code-length="<?php echo esc_attr( (string) $codeLength ); ?>"
	data-flow="<?php echo esc_attr( $flow ); ?>"
	data-registration="<?php echo $registration ? '1' : '0'; ?>"
	data-redirect="<?php echo esc_url( $redirect ); ?>"
>
	<form class="tisa-otp__form" method="post" novalidate autocomplete="on">

		<?php // Bots fill this; people never see it. ?>
		<input type="text" name="<?php echo esc_attr( $honeypot ); ?>" class="tisa-otp__honeypot" tabindex="-1" autocomplete="off" aria-hidden="true" value="">
		<input type="hidden" name="<?php echo esc_attr( $timestampKey ); ?>" class="tisa-otp__rendered" value="<?php echo esc_attr( (string) $renderedAt ); ?>">

		<?php if ( $showBrand && '' !== $logo ) : ?>
			<div class="tisa-otp__brand">
				<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="<?php echo esc_attr( (string) $logoWidth ); ?>" style="max-width:<?php echo esc_attr( (string) $logoWidth ); ?>px">
			</div>
		<?php endif; ?>

		<div
			class="tisa-otp__status"
			id="<?php echo esc_attr( $instance ); ?>-status"
			role="status"
			aria-live="polite"
			aria-atomic="true"
			data-tisa-status
			hidden
		></div>

		<?php
		// Step 1 — phone number.
		echo $view->partial( 'step-phone', get_defined_vars() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Step 2 — registration fields (used by both flows).
		if ( $registration ) {
			echo $view->partial( 'step-fields', get_defined_vars() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Step 3 — one-time code.
		echo $view->partial( 'step-code', get_defined_vars() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<?php if ( ! empty( $terms['show'] ) ) : ?>
			<p class="tisa-otp__terms">
				<?php if ( ! empty( $terms['url'] ) ) : ?>
					<a href="<?php echo esc_url( $terms['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $terms['text'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $terms['text'] ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</form>
</div>
