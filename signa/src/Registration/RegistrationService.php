<?php

namespace Signa\Registration;

use Signa\Config\Settings;
use Signa\Log\Logger;
use Signa\Support\Phone;
use Signa\User\AccountFactory;
use Signa\User\PhoneLocator;

defined( 'ABSPATH' ) || exit;

final class RegistrationService {
	private $settings;
	private $schema;
	private $validator;
	private $drafts;
	private $accounts;
	private $locator;
	private $logger;

	public function __construct(
		Settings $settings,
		FieldSchema $schema,
		FieldValidator $validator,
		DraftStore $drafts,
		AccountFactory $accounts,
		PhoneLocator $locator,
		Logger $logger
	) {
		$this->settings  = $settings;
		$this->schema    = $schema;
		$this->validator = $validator;
		$this->drafts    = $drafts;
		$this->accounts  = $accounts;
		$this->locator   = $locator;
		$this->logger    = $logger;
	}

	public function isOpen(): bool {
		return $this->schema->enabled() && $this->settings->bool( 'auto_register', true );
	}

	public function schema(): FieldSchema {
		return $this->schema;
	}

	public function drafts(): DraftStore {
		return $this->drafts;
	}

	public function fields(): array {
		return $this->schema->active();
	}

	public function validate( array $raw ): array {
		return $this->validator->validate( $raw, $this->fields() );
	}

	public function storeDraft( string $phone, array $values ): string {
		return $this->drafts->create( $phone, $values, false );
	}

	public function storeVerified( string $phone ): string {
		return $this->drafts->create( $phone, array(), true );
	}

	public function register( string $phone, array $values ) {
		$phone = Phone::normalize( $phone );

		if ( ! $this->isOpen() ) {
			return new \WP_Error( 'registration_closed', __( 'عضویت جدید در حال حاضر غیرفعال است.', 'signa' ) );
		}

		$existing = $this->locator->find( $phone );

		if ( $existing instanceof \WP_User ) {
			return new \WP_Error( 'already_registered', __( 'برای این شماره حساب کاربری وجود دارد. وارد شوید.', 'signa' ) );
		}

		if ( $this->locator->isAmbiguous( $phone ) ) {
			return new \WP_Error( 'ambiguous_phone', __( 'این شماره به بیش از یک حساب متصل است. با پشتیبانی تماس بگیرید.', 'signa' ) );
		}

		$user = $this->accounts->create( $phone, $values, $this->fields() );

		if ( is_wp_error( $user ) ) {
			$this->logger->warning(
				'registration.failed',
				array(
					'phone'      => $phone,
					'error_code' => $user->get_error_code(),
				)
			);

			do_action( 'signa_registration_failed', $phone, $user->get_error_code(), $values );
		}

		return $user;
	}

	public function completeDraft( string $token, string $phone ) {
		$draft = $this->drafts->find( $token );

		if ( null === $draft ) {
			return new \WP_Error( 'draft_expired', __( 'فرصت تکمیل عضویت تمام شد. لطفاً از ابتدا شروع کنید.', 'signa' ) );
		}

		if ( ! $this->drafts->matches( $draft, $phone ) ) {
			return new \WP_Error( 'draft_mismatch', __( 'این پیوند عضویت به شماره دیگری تعلق دارد.', 'signa' ) );
		}

		if ( $this->drafts->isVerifiedOnly( $draft ) ) {
			return new \WP_Error( 'draft_incomplete', __( 'ابتدا فرم عضویت را کامل کنید.', 'signa' ) );
		}

		$user = $this->register( $phone, $this->drafts->values( $draft ) );

		if ( ! is_wp_error( $user ) ) {
			$this->drafts->destroy( $token );
		}

		return $user;
	}

	public function completeVerified( string $token, string $phone, array $rawValues ) {
		$draft = $this->drafts->find( $token );

		if ( null === $draft || ! $this->drafts->matches( $draft, $phone ) || ! $this->drafts->isVerifiedOnly( $draft ) ) {
			return new \WP_Error( 'verification_expired', __( 'اعتبار تأیید شماره تمام شد. لطفاً دوباره کد بگیرید.', 'signa' ) );
		}

		$validated = $this->validate( $rawValues );

		if ( ! $validated['ok'] ) {
			return new \WP_Error( 'invalid_fields', __( 'برخی فیلدهای فرم نیاز به اصلاح دارند.', 'signa' ), array( 'errors' => $validated['errors'] ) );
		}

		$user = $this->register( $phone, $validated['values'] );

		if ( ! is_wp_error( $user ) ) {
			$this->drafts->destroy( $token );
		}

		return $user;
	}
}
