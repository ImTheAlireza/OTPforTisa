<?php
/**
 * Email channel — handy as a free fallback or for users without an Iranian SIM.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Channel;

use TisaOtp\Config\Settings;
use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class EmailChannel implements Channel {

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function id(): string {
		return 'email';
	}

	public function label(): string {
		return __( 'ایمیل', 'tisa-otp' );
	}

	public function available(): bool {
		return in_array( 'email', $this->settings->arr( 'channels_enabled' ), true );
	}

	public function unavailableReason(): string {
		return $this->available() ? '' : __( 'کانال ایمیل در تنظیمات فعال نشده است.', 'tisa-otp' );
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$to = (string) $request->context( 'email', '' );

		if ( ! is_email( $to ) ) {
			return GatewayResult::failed( $this->id(), 'no_address', __( 'آدرس ایمیلی برای این کاربر ثبت نشده است.', 'tisa-otp' ) );
		}

		$ttl     = $this->settings->int( 'code_ttl', 120 );
		$subject = $this->settings->str( 'email_subject', __( 'کد ورود یکبارمصرف', 'tisa-otp' ) );
		$body    = $request->render( $this->settings->str( 'email_body' ), $ttl );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from    = $this->settings->str( 'email_from' );

		if ( is_email( $from ) ) {
			$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $from );
		}

		/**
		 * Filter the outgoing OTP email.
		 *
		 * @param array           $email   Array with to/subject/body/headers.
		 * @param DeliveryRequest $request Delivery request.
		 */
		$email = (array) apply_filters(
			'tisa_otp_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$request
		);

		$sent = wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );

		if ( ! $sent ) {
			$this->logger->warning( 'channel.email_failed', array( 'channel' => $this->id(), 'phone' => $request->phone() ) );

			return GatewayResult::failed( $this->id(), 'mail_failed', __( 'ارسال ایمیل با خطا مواجه شد.', 'tisa-otp' ) );
		}

		return GatewayResult::sent( $this->id(), '', 200, array( 'channel' => $this->id() ) );
	}
}
