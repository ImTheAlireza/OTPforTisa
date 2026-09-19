<?php
/**
 * Public sign-in / sign-up state machine.
 *
 * Four endpoints drive the whole flow:
 *   start    → probe the number and (usually) send the first code
 *   code     → resend a code
 *   verify   → check the code, then sign in or advance to the sign-up form
 *   register → submit sign-up fields (before or after verification)
 *
 * @package TisaOtp
 */

namespace TisaOtp\Http;

use TisaOtp\Channel\Dispatcher;
use TisaOtp\Config\Settings;
use TisaOtp\Guard\Pipeline;
use TisaOtp\Log\Logger;
use TisaOtp\Otp\OtpService;
use TisaOtp\Registration\RegistrationService;
use TisaOtp\Support\Phone;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;
use TisaOtp\User\AccessPolicy;
use TisaOtp\User\PhoneLocator;
use TisaOtp\User\Session;

defined( 'ABSPATH' ) || exit;

final class AuthController {

	const SCOPE_LOGIN       = 'login';
	const SCOPE_REGISTER    = 'register';
	const SCOPE_REGISTER_PRE = 'register_pre';

	/** @var Settings */
	private $settings;

	/** @var OtpService */
	private $otp;

	/** @var Pipeline */
	private $guards;

	/** @var Dispatcher */
	private $dispatcher;

	/** @var RegistrationService */
	private $registration;

	/** @var PhoneLocator */
	private $locator;

	/** @var Session */
	private $session;

	/** @var AccessPolicy */
	private $policy;

	/** @var Throttle */
	private $throttle;

	/** @var Logger */
	private $logger;

	public function __construct(
		Settings $settings,
		OtpService $otp,
		Pipeline $guards,
		Dispatcher $dispatcher,
		RegistrationService $registration,
		PhoneLocator $locator,
		Session $session,
		AccessPolicy $policy,
		Throttle $throttle,
		Logger $logger
	) {
		$this->settings     = $settings;
		$this->otp          = $otp;
		$this->guards       = $guards;
		$this->dispatcher   = $dispatcher;
		$this->registration = $registration;
		$this->locator      = $locator;
		$this->session      = $session;
		$this->policy       = $policy;
		$this->throttle     = $throttle;
		$this->logger       = $logger;
	}

	/**
	 * POST /start — decide what the visitor should do next.
	 */
	public function start( Request $request ): array {
		$this->assertPhone( $request );

		$mode = $this->settings->str( 'auth_mode', 'smart' );
		$user = $this->locator->find( $request->phone() );

		if ( $this->locator->isAmbiguous( $request->phone() ) ) {
			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'tisa-otp' ) );
		}

		if ( $user instanceof \WP_User ) {
			if ( 'register_only' === $mode ) {
				throw Rejection::make( 'already_registered', $this->wording( 'already_registered', __( 'برای این شماره حساب کاربری وجود دارد. کد ورود برایتان ارسال شد.', 'tisa-otp' ) ) );
			}

			$this->assertAllowed( $user );

			return $this->issue( $request, self::SCOPE_LOGIN, $user );
		}

		if ( 'login_only' === $mode ) {
			// Do not reveal whether the number exists; simply refuse sign-up.
			throw Rejection::make( 'no_account', $this->wording( 'no_account', __( 'برای این شماره حسابی یافت نشد و عضویت جدید غیرفعال است.', 'tisa-otp' ) ) );
		}

		if ( ! $this->registration->isOpen() ) {
			throw Rejection::make( 'registration_closed', $this->wording( 'registration_closed', __( 'عضویت جدید در حال حاضر غیرفعال است.', 'tisa-otp' ) ) );
		}

		if ( $this->registration->schema()->isCodeFirst() ) {
			return $this->issue( $request, self::SCOPE_REGISTER_PRE, null );
		}

		return array(
			'step'       => 'register_form',
			'scope'      => self::SCOPE_REGISTER,
			'flow'       => 'fields_then_code',
			'message'    => $this->settings->str( 'register_subheading', __( 'برای ساخت حساب کاربری، اطلاعات زیر را کامل کنید.', 'tisa-otp' ) ),
			'fields'     => $this->registration->schema()->forClient(),
			'headings'   => $this->headings(),
			'masked'     => Phone::mask( $request->phone() ),
		);
	}

	/**
	 * POST /code — send or resend a code.
	 */
	public function sendCode( Request $request ): array {
		$this->assertPhone( $request );

		$draft = $request->str( 'draft_token' );
		$scope = '' !== $draft ? self::SCOPE_REGISTER : self::SCOPE_LOGIN;
		$user  = $this->locator->find( $request->phone() );

		if ( $this->locator->isAmbiguous( $request->phone() ) ) {
			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'tisa-otp' ) );
		}

		if ( $user instanceof \WP_User ) {
			$this->assertAllowed( $user );
			$scope = self::SCOPE_LOGIN;
		}

		return $this->issue( $request, $scope, $user );
	}

	/**
	 * POST /verify — check a code and either sign in or move to the sign-up form.
	 */
	public function verify( Request $request ): array {
		$this->assertPhone( $request );

		$this->guards->run( Pipeline::STAGE_VERIFY, $request );

		$code   = $request->str( 'code' );
		$result = $this->otp->verify( $request->phone(), $code );

		if ( ! $result->isAccepted() ) {
			$this->logger->notice(
				'code.rejected',
				array(
					'phone'      => $request->phone(),
					'error_code' => $result->status(),
					'ip'         => $request->ip(),
				)
			);

			/**
			 * Fires on a failed verification attempt.
			 *
			 * @param string $phone  Canonical phone number.
			 * @param string $status Reason.
			 */
			do_action( 'tisa_otp_verify_failed', $request->phone(), $result->status() );

			throw Rejection::make( $result->status(), $result->message(), array( 'attempts_left' => $result->attemptsLeft() ) );
		}

		$draftToken = $request->str( 'draft_token' );

		if ( '' !== $draftToken ) {
			return $this->finishRegistration( $this->registration->completeDraft( $draftToken, $request->phone() ), $request );
		}

		$user = $this->locator->find( $request->phone() );

		if ( $this->locator->isAmbiguous( $request->phone() ) ) {
			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'tisa-otp' ) );
		}

		if ( $user instanceof \WP_User ) {
			$this->assertAllowed( $user );

			return $this->signIn( $user, $request, 'login' );
		}

		if ( ! $this->registration->isOpen() ) {
			throw Rejection::make( 'registration_closed', $this->wording( 'registration_closed', __( 'کد تأیید شد، اما عضویت جدید در این سایت غیرفعال است.', 'tisa-otp' ) ) );
		}

		if ( $this->registration->schema()->isCodeFirst() ) {
			$token = $this->registration->storeVerified( $request->phone() );

			return array(
				'step'            => 'register_form',
				'scope'           => self::SCOPE_REGISTER,
				'flow'            => 'code_then_fields',
				'verified_token'  => $token,
				'message'         => __( 'شماره تأیید شد. حالا اطلاعات حساب را کامل کنید.', 'tisa-otp' ),
				'fields'          => $this->registration->schema()->forClient(),
				'headings'        => $this->headings(),
				'masked'          => Phone::mask( $request->phone() ),
			);
		}

		// Fields-first flow without a draft: fall back to a minimal account.
		return $this->finishRegistration( $this->registration->register( $request->phone(), array() ), $request );
	}

	/**
	 * POST /register — submit sign-up fields.
	 */
	public function register( Request $request ): array {
		$this->assertPhone( $request );

		if ( ! $this->registration->isOpen() ) {
			throw Rejection::make( 'registration_closed', __( 'عضویت جدید در حال حاضر غیرفعال است.', 'tisa-otp' ) );
		}

		$verifiedToken = $request->str( 'verified_token' );

		if ( '' !== $verifiedToken ) {
			$this->guards->run( Pipeline::STAGE_REGISTER, $request );

			return $this->finishRegistration(
				$this->registration->completeVerified( $verifiedToken, $request->phone(), $request->fields() ),
				$request
			);
		}

		// Fields-first flow: validate, remember the values, then send the code.
		$this->guards->run( Pipeline::STAGE_SEND, $request );

		$validated = $this->registration->validate( $request->fields() );

		if ( ! $validated['ok'] ) {
			$this->throttle->releaseReservation( $request->phone() );

			throw Rejection::make(
				'invalid_fields',
				__( 'لطفاً خطاهای فرم را اصلاح کنید.', 'tisa-otp' ),
				array( 'errors' => $validated['errors'] )
			);
		}

		$existing = $this->locator->find( $request->phone() );

		if ( $existing instanceof \WP_User ) {
			$this->throttle->releaseReservation( $request->phone() );

			throw Rejection::make( 'already_registered', __( 'این شماره از قبل ثبت شده است. از همین فرم وارد شوید.', 'tisa-otp' ) );
		}

		$token   = $this->registration->storeDraft( $request->phone(), $validated['values'] );
		$payload = $this->issue( $request, self::SCOPE_REGISTER, null, true );

		$payload['draft_token'] = $token;
		$payload['step']        = 'verify';
		$payload['flow']        = 'fields_then_code';

		return $payload;
	}

	/**
	 * Generate, store and deliver a code — the only place that talks to a gateway.
	 *
	 * @param bool $reserved Set to true when the caller already ran the send guards.
	 */
	private function issue( Request $request, string $scope, ?\WP_User $user = null, bool $reserved = false ): array {
		if ( ! $reserved ) {
			$this->guards->run( Pipeline::STAGE_SEND, $request );
		}

		$phone    = $request->phone();
		$channel  = $request->key( 'channel', $this->settings->str( 'channel', 'sms' ) );
		$code     = $this->otp->generate();
		$context  = array(
			'scope'   => $scope,
			'channel' => $channel,
			'user_id' => $user instanceof \WP_User ? (int) $user->ID : 0,
			'email'   => $user instanceof \WP_User ? (string) $user->user_email : '',
			'ip'      => $request->ip(),
		);

		$this->otp->store( $phone, $code, $channel, $request->ip() );

		$result = $this->dispatcher->deliver( $phone, $code, $context );

		if ( ! $result->isSent() ) {
			$this->otp->revoke( $phone );
			$this->throttle->releaseReservation( $phone );

			throw Rejection::make(
				'delivery_failed',
				$result->message(),
				array(
					'gateway'    => $result->gateway(),
					'error_code' => $result->errorCode(),
				)
			);
		}

		$this->throttle->startCooldown( $phone );

		/**
		 * Fires after a code has been delivered.
		 *
		 * @param string $phone   Canonical phone number.
		 * @param string $channel Channel used.
		 * @param string $scope   login|register|register_pre.
		 * @param int    $userId  Existing user id, 0 for guests.
		 */
		do_action( 'tisa_otp_code_sent', $phone, $channel, $scope, $context['user_id'] );

		return array(
			'step'        => 'verify',
			'scope'       => $scope,
			'message'     => $this->wording( 'code_sent', __( 'کد تأیید ارسال شد. اگر پیامی دریافت نکردید، کمی بعد دوباره تلاش کنید.', 'tisa-otp' ) ),
			'masked'      => Phone::mask( $phone ),
			'channel'     => $channel,
			'via'         => $result->gateway(),
			'cooldown'    => $this->throttle->cooldownSeconds(),
			'expires_in'  => $this->otp->ttl(),
			'code_length' => $this->otp->length(),
			'headings'    => $this->headings(),
		);
	}

	/**
	 * @param \WP_User|\WP_Error $user
	 */
	private function finishRegistration( $user, Request $request ): array {
		if ( is_wp_error( $user ) ) {
			$data = $user->get_error_data();

			throw Rejection::make(
				$user->get_error_code(),
				$user->get_error_message(),
				is_array( $data ) ? $data : array()
			);
		}

		if ( ! $user instanceof \WP_User ) {
			throw Rejection::make( 'registration_failed', __( 'ساخت حساب کاربری ناموفق بود.', 'tisa-otp' ) );
		}

		$this->assertAllowed( $user );

		return $this->signIn( $user, $request, 'register' );
	}

	private function signIn( \WP_User $user, Request $request, string $context ): array {
		$session = $this->session->signIn( $user, $context, $request->str( 'redirect' ), $request->phone() );

		return array(
			'step'         => 'signed_in',
			'user_id'      => $session['user_id'],
			'redirect'     => $session['redirect'],
			'display_name' => $session['display_name'],
			'message'      => 'register' === $context
				? __( 'عضویت انجام شد. در حال انتقال…', 'tisa-otp' )
				: __( 'ورود موفق. در حال انتقال…', 'tisa-otp' ),
		);
	}

	private function assertPhone( Request $request ): void {
		if ( ! Phone::isValid( $request->phone() ) ) {
			throw Rejection::make( 'invalid_phone', __( 'شماره موبایل معتبر نیست. نمونه درست: 09121234567', 'tisa-otp' ) );
		}
	}

	private function assertAllowed( \WP_User $user ): void {
		if ( $this->policy->allows( $user ) ) {
			return;
		}

		$this->logger->notice( 'session.blocked_role', array( 'user_id' => (int) $user->ID ) );

		throw Rejection::make( 'role_blocked', $this->policy->denialMessage() );
	}

	private function headings(): array {
		return array(
			'form'     => $this->settings->str( 'form_heading', __( 'ورود یا عضویت', 'tisa-otp' ) ),
			'formHint' => $this->settings->str( 'form_subheading' ),
			'register' => $this->settings->str( 'register_heading', __( 'تکمیل اطلاعات', 'tisa-otp' ) ),
			'regHint'  => $this->settings->str( 'register_subheading' ),
		);
	}

	/**
	 * With enumeration protection on, every phone gets the same wording.
	 */
	private function wording( string $key, string $specific ): string {
		if ( $this->settings->bool( 'prevent_enumeration', true ) ) {
			$neutral = array(
				'code_sent'           => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'tisa-otp' ),
				'already_registered'  => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'tisa-otp' ),
				'no_account'          => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'tisa-otp' ),
				'registration_closed' => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'tisa-otp' ),
			);

			if ( isset( $neutral[ $key ] ) ) {
				return $neutral[ $key ];
			}
		}

		return $specific;
	}
}
