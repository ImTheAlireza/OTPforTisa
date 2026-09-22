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

	<?php // Revealed 30 seconds in, when "it never arrived" becomes plausible. ?>
	<div class="tisa-code__rescue" data-tisa-rescue hidden>
		<span class="tisa-code__rescue-icon" aria-hidden="true">
			<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" focusable="false"><path d="M3 5.5A2.5 2.5 0 0 1 5.5 3h9A2.5 2.5 0 0 1 17 5.5v6a2.5 2.5 0 0 1-2.5 2.5H9l-3.6 2.6A.6.6 0 0 1 4.5 16v-2A2.5 2.5 0 0 1 3 11.5v-6Z" stroke-linejoin="round"></path><path d="M7 8.2h6M7 10.6h3.5" stroke-linecap="round"></path></svg>
		</span>
		<div class="tisa-code__rescue-body">
			<p class="tisa-code__rescue-title"><?php esc_html_e( 'پیامک نرسید؟', 'tisa-otp' ); ?></p>
			<p class="tisa-code__rescue-note"><?php esc_html_e( 'دو راه سریع پیش رو دارید.', 'tisa-otp' ); ?></p>
			<div class="tisa-code__rescue-actions">
				<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--compact" data-tisa-action="resend"><?php echo esc_html( $resendLabel ); ?></button>
				<button type="button" class="tisa-link" data-tisa-action="edit-phone"><?php esc_html_e( 'شماره را اصلاح می‌کنم', 'tisa-otp' ); ?></button>
			</div>
		</div>
	</div>

	<?php // The visible countdown changes every second; this region speaks only at milestones. ?>
	<p class="tisa-screen-reader" role="status" aria-live="polite" data-tisa-resend-live></p>
</section>
