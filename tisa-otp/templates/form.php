<?php
/**
 * Tisa OTP — sign-in form wrapper.
 *
 * Override in a theme by copying this file to `{theme}/tisa-otp/form.php`.
 *
 * Available variables: instance, classes, style, heading, hint, regHeading,
 * regHint, redirect, labels, codeLength, cooldown, showBrand, logo, logoWidth,
 * fields, flow, registration, captcha, terms, dir, configUrl, cacheMode, nonce,
 * restUrl, honeypot, timestampKey, renderedAt, steps, view.
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

		<?php // First tab stop: jump straight to the field that matters. ?>
		<a class="tisa-otp__skip" href="#<?php echo esc_attr( $instance ); ?>-phone" data-tisa-skip><?php esc_html_e( 'رفتن به فرم ورود', 'tisa-otp' ); ?></a>

		<?php // Bots fill this; people never see it. ?>
		<input type="text" name="<?php echo esc_attr( $honeypot ); ?>" class="tisa-otp__honeypot" tabindex="-1" autocomplete="off" aria-hidden="true" value="">
		<input type="hidden" name="<?php echo esc_attr( $timestampKey ); ?>" class="tisa-otp__rendered" value="<?php echo esc_attr( (string) $renderedAt ); ?>">

		<?php if ( $showBrand && '' !== $logo ) : ?>
			<div class="tisa-otp__brand">
				<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="<?php echo esc_attr( (string) $logoWidth ); ?>" style="max-width:<?php echo esc_attr( (string) $logoWidth ); ?>px">
			</div>
		<?php endif; ?>

		<?php
		/*
		 * Step bar. The list is decorative (`aria-hidden`); the sentence beside
		 * it is what a screen reader hears, and JS keeps both in sync. It is only
		 * rendered when the flow really has three steps.
		 */
		if ( count( $steps ) > 2 ) :
			?>
			<ol class="tisa-otp__steps" data-tisa-steps aria-hidden="true">
				<?php foreach ( $steps as $index => $step ) : ?>
					<li
						class="<?php echo 0 === $index ? 'is-current' : ''; ?>"
						data-tisa-step-marker="<?php echo esc_attr( $step['id'] ); ?>"
					><?php echo esc_html( $step['label'] ); ?></li>
				<?php endforeach; ?>
			</ol>
			<p class="tisa-screen-reader tisa-otp__steps-text" data-tisa-steps-text>
				<?php
				printf(
					/* translators: 1: current step number, 2: total steps, 3: step name */
					esc_html__( 'گام %1$s از %2$s: %3$s', 'tisa-otp' ),
					esc_html( number_format_i18n( 1 ) ),
					esc_html( number_format_i18n( count( $steps ) ) ),
					esc_html( $steps[0]['label'] )
				);
				?>
			</p>
			<?php
		endif;
		?>

		<div
			class="tisa-otp__status"
			id="<?php echo esc_attr( $instance ); ?>-status"
			role="status"
			aria-live="polite"
			aria-atomic="true"
			data-tisa-status
			hidden
		>
			<svg class="tisa-otp__status-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" focusable="false" data-tisa-status-icon>
				<circle cx="10" cy="10" r="8"></circle>
				<path d="M10 6.5v4.5" stroke-linecap="round"></path>
				<path d="M10 13.6h.01" stroke-linecap="round"></path>
			</svg>
			<div class="tisa-otp__status-body">
				<p class="tisa-otp__status-text" data-tisa-status-text></p>
				<div class="tisa-otp__status-actions" data-tisa-status-actions></div>
			</div>
		</div>

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
