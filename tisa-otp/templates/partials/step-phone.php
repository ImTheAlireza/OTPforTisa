<?php
/**
 * Step 1 — collect the phone number.
 *
 * @package TisaOtp
 */

defined( 'ABSPATH' ) || exit;

$phoneFieldId = $instance . '-phone';
$phoneTitleId = $instance . '-phone-title';
$phoneHintId  = $instance . '-phone-hint';
$phoneErrorId = $instance . '-phone-error';
$hasPhoneHint = '' !== trim( (string) $hint );

// Point the field at its own hint and error so screen readers read the reason.
$phoneDescribedBy = trim( ( $hasPhoneHint ? $phoneHintId . ' ' : '' ) . $phoneErrorId );
?>
<section class="tisa-step is-current" data-tisa-step="phone" aria-labelledby="<?php echo esc_attr( $phoneTitleId ); ?>">
	<header class="tisa-step__head">
		<h2 class="tisa-step__title" id="<?php echo esc_attr( $phoneTitleId ); ?>" tabindex="-1"><?php echo esc_html( $heading ); ?></h2>
		<?php if ( $hasPhoneHint ) : ?>
			<p class="tisa-step__hint" id="<?php echo esc_attr( $phoneHintId ); ?>"><?php echo esc_html( $hint ); ?></p>
		<?php endif; ?>
	</header>

	<div class="tisa-field">
		<label class="tisa-field__label" for="<?php echo esc_attr( $phoneFieldId ); ?>">
			<?php echo esc_html( $phoneLabel ); ?>
		</label>

		<div class="tisa-phone">
			<span class="tisa-phone__dial" aria-hidden="true">۰۹</span>
			<input
				class="tisa-field__input tisa-phone__input"
				id="<?php echo esc_attr( $phoneFieldId ); ?>"
				type="tel"
				inputmode="numeric"
				autocomplete="tel"
				maxlength="11"
				dir="ltr"
				placeholder="09xxxxxxxxx"
				data-tisa-phone
				aria-describedby="<?php echo esc_attr( $phoneDescribedBy ); ?>"
				aria-invalid="false"
				required
			>
		</div>

		<p
			class="tisa-field__error"
			id="<?php echo esc_attr( $phoneErrorId ); ?>"
			data-tisa-error="phone"
			role="alert"
			hidden
		></p>
	</div>

	<div class="tisa-captcha" data-tisa-captcha hidden></div>

	<button type="button" class="tisa-btn tisa-btn--primary" data-tisa-action="start">
		<span class="tisa-btn__label"><?php echo esc_html( $sendLabel ); ?></span>
		<span class="tisa-btn__spinner" aria-hidden="true"></span>
	</button>
</section>
