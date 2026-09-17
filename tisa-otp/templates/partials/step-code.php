<?php
/**
 * Step 3 — enter the one-time code.
 *
 * @package TisaOtp
 */

defined( 'ABSPATH' ) || exit;

$codeFieldId = $instance . '-code';
?>
<section class="tisa-step" data-tisa-step="code">
	<header class="tisa-step__head">
		<h2 class="tisa-step__title"><?php echo esc_html( $codeLabel ); ?></h2>
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
		>

		<div class="tisa-code__boxes" id="<?php echo esc_attr( $instance ); ?>-boxes" data-tisa-boxes>
			<?php for ( $digit = 0; $digit < (int) $codeLength; $digit++ ) : ?>
				<input
					class="tisa-code__box"
					type="text"
					inputmode="numeric"
					maxlength="1"
					autocomplete="off"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: digit position */ __( 'رقم %d', 'tisa-otp' ), $digit + 1 ) ); ?>"
					data-tisa-box="<?php echo esc_attr( (string) $digit ); ?>"
				>
			<?php endfor; ?>
		</div>
	</div>

	<button type="button" class="tisa-btn tisa-btn--primary" data-tisa-action="verify">
		<span class="tisa-btn__label"><?php echo esc_html( $verifyLabel ); ?></span>
		<span class="tisa-btn__spinner" aria-hidden="true"></span>
	</button>

	<div class="tisa-code__footer">
		<button type="button" class="tisa-link" data-tisa-action="resend" disabled>
			<span data-tisa-resend-label><?php echo esc_html( $resendLabel ); ?></span>
		</button>
		<button type="button" class="tisa-link" data-tisa-action="edit-phone"><?php echo esc_html( $editLabel ); ?></button>
	</div>
</section>
