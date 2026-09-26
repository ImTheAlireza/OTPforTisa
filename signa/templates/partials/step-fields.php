<?php

defined( 'ABSPATH' ) || exit;

$fieldsTitleId = $instance . '-fields-title';
$fieldsHintId  = $instance . '-fields-hint';
?>
<section class="signa-step" data-signa-step="fields" aria-labelledby="<?php echo esc_attr( $fieldsTitleId ); ?>">
	<header class="signa-step__head">
		<h2 class="signa-step__title" id="<?php echo esc_attr( $fieldsTitleId ); ?>" tabindex="-1"><?php echo esc_html( $regHeading ); ?></h2>
		<?php if ( '' !== trim( (string) $regHint ) ) : ?>
			<p class="signa-step__hint" id="<?php echo esc_attr( $fieldsHintId ); ?>"><?php echo esc_html( $regHint ); ?></p>
		<?php endif; ?>
	</header>

	<div class="signa-fields">
		<?php foreach ( $fields as $field ) : ?>
			<?php
			$fieldId        = $instance . '-f-' . $field['id'];
			$widthClass     = 'half' === $field['width'] ? 'signa-field--half' : 'signa-field--full';
			$required       = ! empty( $field['required'] );
			$fieldHintId    = $fieldId . '-hint';
			$fieldErrorId   = $fieldId . '-error';
			$hasFieldHint   = '' !== trim( (string) $field['hint'] );

			$fieldDescribedBy = trim( ( $hasFieldHint ? $fieldHintId . ' ' : '' ) . $fieldErrorId );
			?>
			<div class="signa-field <?php echo esc_attr( $widthClass ); ?>" data-signa-field="<?php echo esc_attr( $field['id'] ); ?>">
				<label class="signa-field__label" for="<?php echo esc_attr( $fieldId ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( $required ) : ?>
						<span class="signa-field__star" aria-hidden="true">*</span>
					<?php endif; ?>
				</label>

				<?php if ( 'textarea' === $field['type'] ) : ?>
					<textarea
						class="signa-field__input"
						id="<?php echo esc_attr( $fieldId ); ?>"
						rows="3"
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						data-signa-input="<?php echo esc_attr( $field['id'] ); ?>"
						aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
						aria-invalid="false"
						<?php echo $required ? 'required' : ''; ?>
					></textarea>

				<?php elseif ( 'select' === $field['type'] ) : ?>
					<select
						class="signa-field__input signa-field__select"
						id="<?php echo esc_attr( $fieldId ); ?>"
						data-signa-input="<?php echo esc_attr( $field['id'] ); ?>"
						aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
						aria-invalid="false"
						<?php echo $required ? 'required' : ''; ?>
					>
						<option value=""><?php esc_html_e( 'انتخاب کنید…', 'signa' ); ?></option>
						<?php foreach ( (array) $field['options'] as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>

				<?php elseif ( 'checkbox' === $field['type'] ) : ?>
					<label class="signa-check">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $fieldId ); ?>"
							data-signa-input="<?php echo esc_attr( $field['id'] ); ?>"
							aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
							aria-invalid="false"
							<?php echo $required ? 'required' : ''; ?>
						>
						<span><?php echo esc_html( $field['placeholder'] ? $field['placeholder'] : $field['label'] ); ?></span>
					</label>

				<?php else : ?>
					<input
						class="signa-field__input"
						id="<?php echo esc_attr( $fieldId ); ?>"
						type="<?php echo esc_attr( in_array( $field['type'], array( 'email', 'tel', 'number', 'date', 'text' ), true ) ? $field['type'] : 'text' ); ?>"
						<?php echo in_array( $field['type'], array( 'tel', 'postcode' ), true ) ? 'inputmode="numeric" dir="ltr"' : ''; ?>
					<?php echo 'postcode' === $field['type'] ? 'maxlength="10" autocomplete="postal-code"' : ''; ?>
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						data-signa-input="<?php echo esc_attr( $field['id'] ); ?>"
						aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
						aria-invalid="false"
						<?php echo $required ? 'required' : ''; ?>
					>
				<?php endif; ?>

				<?php if ( $hasFieldHint ) : ?>
					<p class="signa-field__hint" id="<?php echo esc_attr( $fieldHintId ); ?>"><?php echo esc_html( $field['hint'] ); ?></p>
				<?php endif; ?>

				<p
					class="signa-field__error"
					id="<?php echo esc_attr( $fieldErrorId ); ?>"
					data-signa-error="<?php echo esc_attr( $field['id'] ); ?>"
					role="alert"
					hidden
				></p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="signa-captcha" data-signa-captcha hidden></div>

	<button type="button" class="signa-btn signa-btn--primary" data-signa-action="submit-fields">
		<span class="signa-btn__label"><?php echo esc_html( $continueLabel ); ?></span>
		<span class="signa-btn__spinner" aria-hidden="true"></span>
	</button>

	<button type="button" class="signa-btn signa-btn--ghost" data-signa-action="back"><?php echo esc_html( $editLabel ); ?></button>
</section>
