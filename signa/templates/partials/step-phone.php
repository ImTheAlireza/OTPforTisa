<?php

defined( 'ABSPATH' ) || exit;

$phoneFieldId = $instance . '-phone';
$phoneTitleId = $instance . '-phone-title';
$phoneHintId  = $instance . '-phone-hint';
$phoneErrorId = $instance . '-phone-error';
$hasPhoneHint = '' !== trim( (string) $hint );

$phoneDescribedBy = trim( ( $hasPhoneHint ? $phoneHintId . ' ' : '' ) . $phoneErrorId );

$phonePlaceholder = isset( $phonePlaceholder ) && '' !== $phonePlaceholder ? (string) $phonePlaceholder : '09121234567';
?>
<section class="signa-step is-current" data-signa-step="phone" aria-labelledby="<?php echo esc_attr( $phoneTitleId ); ?>">
	<header class="signa-step__head">
		<h2 class="signa-step__title" id="<?php echo esc_attr( $phoneTitleId ); ?>" tabindex="-1"><?php echo esc_html( $heading ); ?></h2>
		<?php if ( $hasPhoneHint ) : ?>
			<p class="signa-step__hint" id="<?php echo esc_attr( $phoneHintId ); ?>"><?php echo esc_html( $hint ); ?></p>
		<?php endif; ?>
	</header>

	<div class="signa-field">
		<label class="signa-field__label" for="<?php echo esc_attr( $phoneFieldId ); ?>">
			<?php echo esc_html( $phoneLabel ); ?>
		</label>

		<div class="signa-phone">
			<input
				class="signa-field__input signa-phone__input"
				id="<?php echo esc_attr( $phoneFieldId ); ?>"
				type="tel"
				inputmode="numeric"
				autocomplete="tel"
				maxlength="11"
				dir="ltr"
				placeholder="<?php echo esc_attr( $phonePlaceholder ); ?>"
				data-signa-phone
				aria-describedby="<?php echo esc_attr( $phoneDescribedBy ); ?>"
				aria-invalid="false"
				required
			>
		</div>

		<p
			class="signa-field__error"
			id="<?php echo esc_attr( $phoneErrorId ); ?>"
			data-signa-error="phone"
			role="alert"
			hidden
		></p>
	</div>

	<div class="signa-captcha" data-signa-captcha hidden></div>

	<button type="button" class="signa-btn signa-btn--primary" data-signa-action="start">
		<span class="signa-btn__label"><?php echo esc_html( $sendLabel ); ?></span>
		<span class="signa-btn__spinner" aria-hidden="true"></span>
	</button>

	<?php if ( ! empty( $trust ) ) : ?>
		<ul class="signa__trust">
			<?php foreach ( (array) $trust as $item ) : ?>
				<li class="signa__trust-item">
					<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" focusable="false"><?php echo $item['icon']; ?></svg>
					<span><?php echo esc_html( (string) $item['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
