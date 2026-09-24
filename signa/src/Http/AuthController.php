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
 * @package Signa
 */

namespace Signa\Http;

use Signa\Access\EmergencyToken;
use Signa\Channel\Dispatcher;
use Signa\Config\Settings;
use Signa\Guard\Pipeline;
use Signa\Log\Logger;
use Signa\Otp\OtpService;
use Signa\Registration\RegistrationService;
use Signa\Support\Phone;
use Signa\Support\Rejection;
use Signa\Throttle\Throttle;
use Signa\User\AccessPolicy;
use Signa\User\PhoneLocator;
use Signa\User\Session;

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

	/** @var EmergencyToken */
	private $emergency;

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
		EmergencyToken $emergency,
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
		$this->emergency    = $emergency;
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
			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'signa' ) );
		}

		if ( $user instanceof \WP_User ) {
			if ( 'register_only' === $mode ) {
				throw Rejection::make( 'already_registered', $this->wording( 'already_registered', __( 'برای این شماره حساب کاربری وجود دارد. کد ورود برایتان ارسال شد.', 'signa' ) ) );
			}

			$this->assertAllowed( $user );

			return $this->issue( $request, self::SCOPE_LOGIN, $user );
		}

		if ( 'login_only' === $mode ) {
			// Do not reveal whether the number exists; simply refuse sign-up.
			throw Rejection::make( 'no_account', $this->wording( 'no_account', __( 'برای این شماره حسابی یافت نشد و عضویت جدید غیرفعال است.', 'signa' ) ) );
		}

		if ( ! $this->registration->isOpen() ) {
			throw Rejection::make( 'registration_closed', $this->wording( 'registration_closed', __( 'عضویت جدید در حال حاضر غیرفعال است.', 'signa' ) ) );
		}

		if ( $this->registration->schema()->isCodeFirst() ) {
			return $this->issue( $request, self::SCOPE_REGISTER_PRE, null );
		}

		return array(
			'step'       => 'register_form',
			'scope'      => self::SCOPE_REGISTER,
			'flow'       => 'fields_then_code',
			'message'    => $this->settings->str( 'register_subheading', __( 'برای ساخت حساب کاربری، اطلاعات زیر را کامل کنید.', 'signa' ) ),
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
			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'signa' ) );
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

		$code = $request->str( 'code' );

		// Break-glass path: only reachable while an administrator has armed a
		// secret on the access screen, and never able to create an account.
		$grant = $this->emergencyGrant( $request, $code );

		if ( null !== $grant ) {
			return $grant;
		}

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
			do_action( 'signa_verify_failed', $request->phone(), $result->status() );

			throw Rejection::make( $result->status(), $result->message(), array( 'attempts_left' => $result->attemptsLeft() ) );
		}

		$draftToken = $request->str( 'draft_token' );

		if ( '' !== $draftToken ) {
			return $this->finishRegistration( $this->registration->completeDraft( $draftToken, $request->phone() ), $request );
		}

		$user = $this->locator->find( $request->phone() );

		if ( $this->locator->isAmbiguous( $request->phone() ) ) {
			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'signa' ) );
		}

		if ( $user instanceof \WP_User ) {
			$this->assertAllowed( $user );

			return $this->signIn( $user, $request, 'login' );
		}

		if ( ! $this->registration->isOpen() ) {
			throw Rejection::make( 'registration_closed', $this->wording( 'registration_closed', __( 'کد تأیید شد، اما عضویت جدید در این سایت غیرفعال است.', 'signa' ) ) );
		}

		if ( $this->registration->schema()->isCodeFirst() ) {
			$token = $this->registration->storeVerified( $request->phone() );

			return array(
				'step'            => 'register_form',
				'scope'           => self::SCOPE_REGISTER,
				'flow'            => 'code_then_fields',
				'verified_token'  => $token,
				'message'         => __( 'شماره تأیید شد. حالا اطلاعات حساب را کامل کنید.', 'signa' ),
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
			throw Rejection::make( 'registration_closed', __( 'عضویت جدید در حال حاضر غیرفعال است.', 'signa' ) );
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
				__( 'لطفاً خطاهای فرم را اصلاح کنید.', 'signa' ),
				array( 'errors' => $validated['errors'] )
			);
		}

		$existing = $this->locator->find( $request->phone() );

		if ( $existing instanceof \WP_User ) {
			$this->throttle->releaseReservation( $request->phone() );

			throw Rejection::make( 'already_registered', __( 'این شماره از قبل ثبت شده است. از همین فرم وارد شوید.', 'signa' ) );
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

			/*
			 * The visitor reads `visitorMessage()`, never `message()`: the
			 * sentence that names wp-config.php and the wp-config constants is
			 * for the administrator, and it was being handed to whoever was
			 * trying to log in. The technical one still reaches the log and the
			 * admin panels, which is where somebody can act on it.
			 */
			throw Rejection::make(
				'delivery_failed',
				$result->visitorMessage(),
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
		do_action( 'signa_code_sent', $phone, $channel, $scope, $context['user_id'] );

		return array(
			'step'        => 'verify',
			'scope'       => $scope,
			'message'     => $this->wording( 'code_sent', __( 'کد تأیید ارسال شد. اگر پیامی دریافت نکردید، کمی بعد دوباره تلاش کنید.', 'signa' ) ),
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
			throw Rejection::make( 'registration_failed', __( 'ساخت حساب کاربری ناموفق بود.', 'signa' ) );
		}

		$this->assertAllowed( $user );

		return $this->signIn( $user, $request, 'register' );
	}

	/**
	 * Try the break-glass secret.
	 *
	 * Returns null when the request is none of our business — including a *wrong*
	 * guess, which deliberately falls through to the ordinary verifier so the
	 * response leaks nothing about whether an emergency code is armed.
	 *
	 * @return array<string,mixed>|null Signed-in payload, or null to continue.
	 */
	private function emergencyGrant( Request $request, string $code ): ?array {
		$verdict = $this->emergency->inspect( $code, $request->phone(), $request->ip() );
		$status  = $verdict['status'];

		if ( 'none' === $status ) {
			return null;
		}

		if ( 'match' !== $status ) {
			$this->logger->warning(
				'emergency.rejected',
				array(
					'reason'        => $status,
					'phone'         => $request->phone(),
					'ip'            => $request->ip(),
					'attempts_left' => $verdict['attempts_left'],
				)
			);

			// Hand the request to the normal verifier: same wording, same timing.
			return null;
		}

		$phone = $request->phone();

		if ( ! $this->emergency->phoneAllowed( $phone ) ) {
			$this->emergency->registerAbuse();

			$this->logger->warning( 'emergency.out_of_scope', array( 'phone' => $phone, 'ip' => $request->ip() ) );

			throw Rejection::make( 'blocked', __( 'امکان ورود با این شماره وجود ندارد. در صورت نیاز با پشتیبانی سایت تماس بگیرید.', 'signa' ) );
		}

		if ( $this->locator->isAmbiguous( $phone ) ) {
			$this->emergency->registerAbuse();

			throw Rejection::make( 'ambiguous_phone', __( 'این شماره به چند حساب متصل است. لطفاً با پشتیبانی سایت تماس بگیرید.', 'signa' ) );
		}

		$user = $this->locator->find( $phone );

		if ( ! $user instanceof \WP_User ) {
			// The emergency door never opens a new account.
			$this->emergency->registerAbuse();

			$this->logger->warning( 'emergency.no_account', array( 'phone' => $phone, 'ip' => $request->ip() ) );

			throw Rejection::make( 'invalid_phone', __( 'شماره موبایل معتبر نیست. نمونه درست: 09121234567', 'signa' ) );
		}

		$this->assertAllowed( $user );

		$this->emergency->consume();

		$this->logger->warning(
			'emergency.login',
			array(
				'user_id' => (int) $user->ID,
				'phone'   => $phone,
				'ip'      => $request->ip(),
				'uses'    => $this->emergency->summary()['uses_left'],
			)
		);

		/**
		 * Fires after a successful break-glass sign-in.
		 *
		 * @param \WP_User $user  The account that was entered.
		 * @param string   $phone Phone number used.
		 * @param string   $ip    Request address.
		 */
		do_action( 'signa_emergency_login', $user, $phone, $request->ip() );

		return $this->signIn( $user, $request, 'emergency' );
	}

	private function signIn( \WP_User $user, Request $request, string $context ): array {
		$session = $this->session->signIn( $user, $context, $request->str( 'redirect' ), $request->phone() );

		return array(
			'step'         => 'signed_in',
			'user_id'      => $session['user_id'],
			'redirect'     => $session['redirect'],
			'display_name' => $session['display_name'],
			'message'      => 'register' === $context
				? __( 'عضویت انجام شد. در حال انتقال…', 'signa' )
				: __( 'ورود موفق. در حال انتقال…', 'signa' ),
		);
	}

	private function assertPhone( Request $request ): void {
		if ( ! Phone::isValid( $request->phone() ) ) {
			throw Rejection::make( 'invalid_phone', __( 'شماره موبایل معتبر نیست. نمونه درست: 09121234567', 'signa' ) );
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
			'form'     => $this->settings->str( 'form_heading', __( 'ورود یا عضویت', 'signa' ) ),
			'formHint' => $this->settings->str( 'form_subheading' ),
			'register' => $this->settings->str( 'register_heading', __( 'تکمیل اطلاعات', 'signa' ) ),
			'regHint'  => $this->settings->str( 'register_subheading' ),
		);
	}

	/**
	 * With enumeration protection on, every phone gets the same wording.
	 */
	private function wording( string $key, string $specific ): string {
		if ( $this->settings->bool( 'prevent_enumeration', true ) ) {
			$neutral = array(
				'code_sent'           => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'signa' ),
				'already_registered'  => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'signa' ),
				'no_account'          => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'signa' ),
				'registration_closed' => __( 'در صورت معتبر بودن شماره، کد تأیید ارسال می‌شود.', 'signa' ),
			);

			if ( isset( $neutral[ $key ] ) ) {
				return $neutral[ $key ];
			}
		}

		return $specific;
	}
}
