<?php
/**
 * Step 3 — enter the one-time code.
 *
 * @package TisaOtp
 */

defined( 'ABSPATH' ) || exit;

$codeFieldId = $instance . '-code';
$codeTitleId = $instance . '-code-title';
?>
<section class="tisa-step" data-tisa-step="code" aria-labelledby="<?php echo esc_attr( $codeTitleId ); ?>">
	<header class="tisa-step__head">
		<h2 class="tisa-step__title" id="<?php echo esc_attr( $codeTitleId ); ?>" tabindex="-1"><?php echo esc_html( $codeLabel ); ?></h2>
		<p class="tisa-step__hint">
			<?php
			printf(
				/* translators: %s: masked phone number */
				esc_html__( 'کد ارسال‌شده به %s را وارد کنید.', 'tisa-otp' ),
				'<span class="tisa-otp__masked" data-tisa-masked>—</span>'
			);
			?>
		</p>
	</header>

	<?php // Which number this code went to, with the way back to fix it. ?>
	<div class="tisa-code__phone" data-tisa-phone-chip hidden>
		<span class="tisa-code__phone-label"><?php esc_html_e( 'کد ارسال‌شده به', 'tisa-otp' ); ?></span>
		<strong class="tisa-code__phone-value" data-tisa-phone-chip-value dir="ltr">—</strong>
		<button type="button" class="tisa-link" data-tisa-action="edit-phone"><?php echo esc_html( $editLabel ); ?></button>
	</div>

	<div class="tisa-code" data-tisa-code>
		<label class="tisa-screen-reader" for="<?php echo esc_attr( $codeFieldId ); ?>"><?php echo esc_html( $codeLabel ); ?></label>

		<input
			class="tisa-code__bulk"
			id="<?php echo esc_attr( $codeFieldId ); ?>"
			type="text"
			inputmode="numeric"
			autocomplete="one-time-code"
			maxlength="<?php echo esc_attr( (string) $codeLength ); ?>"
			data-tisa-code-bulk
			aria-describedby="<?php echo esc_attr( $instance ); ?>-boxes"
			aria-invalid="false"
		>

		<div
			class="tisa-code__boxes"
			id="<?php echo esc_attr( $instance ); ?>-boxes"
			role="group"
			aria-labelledby="<?php echo esc_attr( $codeTitleId ); ?>"
			data-tisa-boxes
		>
			<?php for ( $digit = 0; $digit < (int) $codeLength; $digit++ ) : ?>
				<input
					class="tisa-code__box"
					type="text"
					inputmode="numeric"
					maxlength="1"
					<?php // The first box carries the autofill hint; the rest stay opaque. ?>
					autocomplete="<?php echo 0 === $digit ? 'one-time-code' : 'off'; ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: digit position */ __( 'رقم %d', 'tisa-otp' ), $digit + 1 ) ); ?>"
					aria-invalid="false"
					data-tisa-box="<?php echo esc_attr( (string) $digit ); ?>"
				>
			<?php endfor; ?>
		</div>
	</div>

	<button type="button" class="tisa-link tisa-code__paste" data-tisa-paste>
		<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><rect x="6.5" y="3.5" width="9" height="12" rx="2"></rect><path d="M4.5 6.5v8a2 2 0 0 0 2 2h6" stroke-linecap="round"></path></svg>
		<span><?php esc_html_e( 'چسباندن کد از پیامک', 'tisa-otp' ); ?></span>
	</button>

	<button type="button" class="tisa-btn tisa-btn--primary" data-tisa-action="verify">
		<span class="tisa-btn__label"><?php echo esc_html( $verifyLabel ); ?></span>
		<span class="tisa-btn__spinner" aria-hidden="true"></span>
	</button>

	<?php // Server-reported remaining attempts; hidden until the server sends one. ?>
	<p class="tisa-code__attempts" data-tisa-attempts role="status" aria-live="polite" hidden></p>

	<div class="tisa-code__footer">
		<button type="button" class="tisa-link" data-tisa-action="resend" disabled>
			<span data-tisa-resend-label><?php echo esc_html( $resendLabel ); ?></span>
		</button>
		<button type="button" class="tisa-link" data-tisa-action="edit-phone"><?php echo esc_html( $editLabel ); ?></button>
	</div>

	<?php // One CSS animation, no second timer. Hidden while no cooldown runs. ?>
	<div class="tisa-code__cooldown" data-tisa-cooldown aria-hidden="true" hidden><i></i></div>

	<?php
	/*
	 * Revealed 30 seconds in, when "it never arrived" becomes plausible.
	 *
	 * This is a callout, not a toolbar: it explains and points at the two
	 * controls that already exist above it ("resend" beside the countdown, and
	 * the edit link on the number chip). A third copy of those buttons would
	 * only raise the question "are these the same button?".
	 */
	?>
	<div class="tisa-code__rescue" data-tisa-rescue hidden>
		<p class="tisa-code__rescue-title">
			<span class="tisa-code__rescue-icon" aria-hidden="true">
				<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" focusable="false"><circle cx="10" cy="10" r="7"></circle><path d="M10 6.2V10l2.6 1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>
			</span>
			<?php esc_html_e( 'پیامک نرسید؟', 'tisa-otp' ); ?>
		</p>
		<ul class="tisa-code__rescue-list">
			<li><?php esc_html_e( 'معمولاً تا یک دقیقه می‌رسد؛ اگر نرسید، «ارسال دوبارهٔ کد» را بزنید.', 'tisa-otp' ); ?></li>
			<li><?php esc_html_e( 'اگر شماره را اشتباه وارد کرده‌اید، «ویرایش شماره» را بزنید.', 'tisa-otp' ); ?></li>
		</ul>
	</div>

	<?php // The visible countdown changes every second; this region speaks only at milestones. ?>
	<p class="tisa-screen-reader" role="status" aria-live="polite" data-tisa-resend-live></p>
</section>
