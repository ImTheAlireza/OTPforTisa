<?php
/**
 * Step 2 — registration fields.
 *
 * The same markup serves both flows: fields can be collected before the code
 * (`fields_then_code`) or after it (`code_then_fields`).
 *
 * @package TisaOtp
 */

defined( 'ABSPATH' ) || exit;

$fieldsTitleId = $instance . '-fields-title';
$fieldsHintId  = $instance . '-fields-hint';
?>
<section class="tisa-step" data-tisa-step="fields" aria-labelledby="<?php echo esc_attr( $fieldsTitleId ); ?>">
	<header class="tisa-step__head">
		<h2 class="tisa-step__title" id="<?php echo esc_attr( $fieldsTitleId ); ?>" tabindex="-1"><?php echo esc_html( $regHeading ); ?></h2>
		<?php if ( '' !== trim( (string) $regHint ) ) : ?>
			<p class="tisa-step__hint" id="<?php echo esc_attr( $fieldsHintId ); ?>"><?php echo esc_html( $regHint ); ?></p>
		<?php endif; ?>
	</header>

	<div class="tisa-fields">
		<?php foreach ( $fields as $field ) : ?>
			<?php
			$fieldId        = $instance . '-f-' . $field['id'];
			$widthClass     = 'half' === $field['width'] ? 'tisa-field--half' : 'tisa-field--full';
			$required       = ! empty( $field['required'] );
			$fieldHintId    = $fieldId . '-hint';
			$fieldErrorId   = $fieldId . '-error';
			$hasFieldHint   = '' !== trim( (string) $field['hint'] );

			// Hint first, error second: readers announce context, then the problem.
			$fieldDescribedBy = trim( ( $hasFieldHint ? $fieldHintId . ' ' : '' ) . $fieldErrorId );
			?>
			<div class="tisa-field <?php echo esc_attr( $widthClass ); ?>" data-tisa-field="<?php echo esc_attr( $field['id'] ); ?>">
				<label class="tisa-field__label" for="<?php echo esc_attr( $fieldId ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( $required ) : ?>
						<span class="tisa-field__star" aria-hidden="true">*</span>
					<?php endif; ?>
				</label>

				<?php if ( 'textarea' === $field['type'] ) : ?>
					<textarea
						class="tisa-field__input"
						id="<?php echo esc_attr( $fieldId ); ?>"
						rows="3"
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						data-tisa-input="<?php echo esc_attr( $field['id'] ); ?>"
						aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
						aria-invalid="false"
						<?php echo $required ? 'required' : ''; ?>
					></textarea>

				<?php elseif ( 'select' === $field['type'] ) : ?>
					<select
						class="tisa-field__input tisa-field__select"
						id="<?php echo esc_attr( $fieldId ); ?>"
						data-tisa-input="<?php echo esc_attr( $field['id'] ); ?>"
						aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
						aria-invalid="false"
						<?php echo $required ? 'required' : ''; ?>
					>
						<option value=""><?php esc_html_e( 'انتخاب کنید…', 'tisa-otp' ); ?></option>
						<?php foreach ( (array) $field['options'] as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>

				<?php elseif ( 'checkbox' === $field['type'] ) : ?>
					<label class="tisa-check">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $fieldId ); ?>"
							data-tisa-input="<?php echo esc_attr( $field['id'] ); ?>"
							aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
							aria-invalid="false"
							<?php echo $required ? 'required' : ''; ?>
						>
						<span><?php echo esc_html( $field['placeholder'] ? $field['placeholder'] : $field['label'] ); ?></span>
					</label>

				<?php else : ?>
					<input
						class="tisa-field__input"
						id="<?php echo esc_attr( $fieldId ); ?>"
						type="<?php echo esc_attr( in_array( $field['type'], array( 'email', 'tel', 'number', 'date', 'text' ), true ) ? $field['type'] : 'text' ); ?>"
						<?php echo 'tel' === $field['type'] ? 'inputmode="numeric" dir="ltr"' : ''; ?>
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						data-tisa-input="<?php echo esc_attr( $field['id'] ); ?>"
						aria-describedby="<?php echo esc_attr( $fieldDescribedBy ); ?>"
						aria-invalid="false"
						<?php echo $required ? 'required' : ''; ?>
					>
				<?php endif; ?>

				<?php if ( $hasFieldHint ) : ?>
					<p class="tisa-field__hint" id="<?php echo esc_attr( $fieldHintId ); ?>"><?php echo esc_html( $field['hint'] ); ?></p>
				<?php endif; ?>

				<p
					class="tisa-field__error"
					id="<?php echo esc_attr( $fieldErrorId ); ?>"
					data-tisa-error="<?php echo esc_attr( $field['id'] ); ?>"
					role="alert"
					hidden
				></p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="tisa-captcha" data-tisa-captcha hidden></div>

	<button type="button" class="tisa-btn tisa-btn--primary" data-tisa-action="submit-fields">
		<span class="tisa-btn__label"><?php echo esc_html( $continueLabel ); ?></span>
		<span class="tisa-btn__spinner" aria-hidden="true"></span>
	</button>

	<button type="button" class="tisa-btn tisa-btn--ghost" data-tisa-action="back"><?php echo esc_html( $editLabel ); ?></button>
</section>
