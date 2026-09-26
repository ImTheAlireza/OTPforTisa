<?php

defined( 'ABSPATH' ) || exit;

$codeFieldId = $instance . '-code';
$codeTitleId = $instance . '-code-title';
?>
<section class="signa-step" data-signa-step="code" aria-labelledby="<?php echo esc_attr( $codeTitleId ); ?>">
	<header class="signa-step__head">
		<h2 class="signa-step__title" id="<?php echo esc_attr( $codeTitleId ); ?>" tabindex="-1"><?php echo esc_html( $codeLabel ); ?></h2>
		<p class="signa-step__hint"><?php esc_html_e( 'کدی را که پیامک شد اینجا وارد کنید.', 'signa' ); ?></p>
	</header>

	<div class="signa-code__phone" data-signa-phone-chip hidden>
		<span class="signa-code__phone-label"><?php esc_html_e( 'کد ارسال‌شده به', 'signa' ); ?></span>
		<strong class="signa-code__phone-value" data-signa-phone-chip-value dir="ltr">—</strong>
		<button type="button" class="signa-link" data-signa-action="edit-phone"><?php echo esc_html( $editLabel ); ?></button>
	</div>

	<div class="signa-code" data-signa-code>
		<label class="signa-screen-reader" for="<?php echo esc_attr( $codeFieldId ); ?>"><?php echo esc_html( $codeLabel ); ?></label>

		<input
			class="signa-code__bulk"
			id="<?php echo esc_attr( $codeFieldId ); ?>"
			type="text"
			inputmode="numeric"
			autocomplete="one-time-code"
			maxlength="<?php echo esc_attr( (string) $codeLength ); ?>"
			data-signa-code-bulk
			aria-describedby="<?php echo esc_attr( $instance ); ?>-boxes"
			aria-invalid="false"
		>

		<div
			class="signa-code__boxes"
			id="<?php echo esc_attr( $instance ); ?>-boxes"
			role="group"
			aria-labelledby="<?php echo esc_attr( $codeTitleId ); ?>"
			data-signa-boxes
		>
			<?php for ( $digit = 0; $digit < (int) $codeLength; $digit++ ) : ?>
				<input
					class="signa-code__box"
					type="text"
					inputmode="numeric"
					maxlength="1"
					autocomplete="<?php echo 0 === $digit ? 'one-time-code' : 'off'; ?>"
					aria-label="<?php echo esc_attr( sprintf(  __( 'رقم %d', 'signa' ), $digit + 1 ) ); ?>"
					aria-invalid="false"
					data-signa-box="<?php echo esc_attr( (string) $digit ); ?>"
				>
			<?php endfor; ?>
		</div>
	</div>

	<button type="button" class="signa-link signa-code__paste" data-signa-paste>
		<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><rect x="6.5" y="3.5" width="9" height="12" rx="2"></rect><path d="M4.5 6.5v8a2 2 0 0 0 2 2h6" stroke-linecap="round"></path></svg>
		<span><?php esc_html_e( 'چسباندن کد از پیامک', 'signa' ); ?></span>
	</button>

	<button type="button" class="signa-btn signa-btn--primary" data-signa-action="verify">
		<span class="signa-btn__label"><?php echo esc_html( $verifyLabel ); ?></span>
		<span class="signa-btn__spinner" aria-hidden="true"></span>
	</button>

	<p class="signa-code__attempts" data-signa-attempts role="status" aria-live="polite" hidden></p>

	<div class="signa-code__footer">
		<button type="button" class="signa-link" data-signa-action="resend" disabled>
			<span data-signa-resend-label><?php echo esc_html( $resendLabel ); ?></span>
		</button>
		<button type="button" class="signa-link" data-signa-action="edit-phone"><?php echo esc_html( $editLabel ); ?></button>
	</div>

	<div class="signa-code__cooldown" data-signa-cooldown aria-hidden="true" hidden><i></i></div>

	<div class="signa-code__rescue" data-signa-rescue hidden>
		<p class="signa-code__rescue-title">
			<span class="signa-code__rescue-icon" aria-hidden="true">
				<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" focusable="false"><circle cx="10" cy="10" r="7"></circle><path d="M10 6.2V10l2.6 1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>
			</span>
			<?php esc_html_e( 'پیامک نرسید؟', 'signa' ); ?>
		</p>
		<ul class="signa-code__rescue-list">
			<li><?php esc_html_e( 'معمولاً تا یک دقیقه می‌رسد؛ اگر نرسید، «ارسال دوبارهٔ کد» را بزنید.', 'signa' ); ?></li>
			<li><?php esc_html_e( 'اگر شماره را اشتباه وارد کرده‌اید، «ویرایش شماره» را بزنید.', 'signa' ); ?></li>
		</ul>
	</div>

	<p class="signa-screen-reader" role="status" aria-live="polite" data-signa-resend-live></p>
</section>
