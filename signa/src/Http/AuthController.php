<?php

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
	private $settings;
	private $otp;
	private $guards;
	private $dispatcher;
	private $registration;
	private $locator;
	private $session;
	private $policy;
	private $throttle;
	private $emergency;
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

	public function verify( Request $request ): array {
		$this->assertPhone( $request );

		$this->guards->run( Pipeline::STAGE_VERIFY, $request );

		$code = $request->str( 'code' );

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

		return $this->finishRegistration( $this->registration->register( $request->phone(), array() ), $request );
	}

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
				$result->visitorMessage(),
				array(
					'gateway'    => $result->gateway(),
					'error_code' => $result->errorCode(),
				)
			);
		}

		$this->throttle->startCooldown( $phone );

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
