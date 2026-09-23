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

/*
 * One field, one number, no decoration.
 *
 * A fixed "09" chip used to sit inside this control while the error text asked
 * people to "start with 09" — the field contradicted its own rule. The field
 * now takes the number exactly as it is written on a phone, and the placeholder
 * shows the whole shape of it.
 */
$phonePlaceholder = isset( $phonePlaceholder ) && '' !== $phonePlaceholder ? (string) $phonePlaceholder : '09121234567';
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
			<input
				class="tisa-field__input tisa-phone__input"
				id="<?php echo esc_attr( $phoneFieldId ); ?>"
				type="tel"
				inputmode="numeric"
				autocomplete="tel"
				maxlength="11"
				dir="ltr"
				placeholder="<?php echo esc_attr( $phonePlaceholder ); ?>"
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

	<?php
	/*
	 * Three claims, right under the button the visitor is about to press. They
	 * answer the question that actually stops people ("why does this site want
	 * my number?") where the hesitation happens, and they stay quiet: this is
	 * reassurance, not a feature list.
	 */
	?>
	<?php if ( ! empty( $trust ) ) : ?>
		<ul class="tisa-otp__trust">
			<?php foreach ( (array) $trust as $item ) : ?>
				<li class="tisa-otp__trust-item">
					<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" focusable="false"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup from the renderer. ?></svg>
					<span><?php echo esc_html( (string) $item['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
