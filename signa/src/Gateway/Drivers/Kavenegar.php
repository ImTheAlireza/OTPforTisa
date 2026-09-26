<?php

namespace Signa\Gateway\Drivers;

use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class Kavenegar extends HttpGateway {
	const API_BASE = 'https://api.kavenegar.com/v1/';
	private static $statuses = array(
		400 => array( 'rejected', 'پارامترهای درخواست ناقص است.' ),
		401 => array( 'unauthorized', 'حساب کاوه‌نگار غیرفعال شده است؛ با پشتیبانی کاوه‌نگار تماس بگیرید.' ),
		402 => array( 'upstream', 'کاوه‌نگار عملیات را ناموفق اعلام کرد؛ کمی بعد دوباره تلاش کنید.' ),
		403 => array( 'unauthorized', 'کلید API کاوه‌نگار نامعتبر است؛ کلید را از پنل، بخش تنظیمات حساب، دوباره کپی کنید.' ),
		404 => array( 'rejected', 'متد درخواستی در کاوه‌نگار پیدا نشد.' ),
		405 => array( 'rejected', 'نوع درخواست (GET/POST) برای این متد درست نیست.' ),
		407 => array( 'unauthorized', 'دسترسی رد شد؛ IP این سرور را در پنل کاوه‌نگار، بخش تنظیمات امنیتی، مجاز کنید.' ),
		409 => array( 'upstream', 'سرور کاوه‌نگار موقتاً پاسخ نمی‌دهد؛ کمی بعد دوباره تلاش کنید.' ),
		411 => array( 'invalid_recipient', 'شمارهٔ گیرنده از نظر کاوه‌نگار نامعتبر است.' ),
		412 => array( 'rejected', 'شمارهٔ فرستنده نامعتبر است یا متعلق به حساب شما نیست.' ),
		413 => array( 'rejected', 'متن پیامک خالی یا بیش از حد طولانی است.' ),
		414 => array( 'rejected', 'حجم درخواست بیش از حد مجاز کاوه‌نگار است.' ),
		417 => array( 'rejected', 'تاریخ ارسال نامعتبر است.' ),
		418 => array( 'no_credit', 'اعتبار حساب کاوه‌نگار کافی نیست؛ حساب را شارژ کنید.' ),
		422 => array( 'rejected', 'داده‌ها کاراکتر نامناسب دارند.' ),
		424 => array( 'rejected', 'الگویی با این نام در کاوه‌نگار نیست یا هنوز تأیید نشده است؛ نام الگو را دقیقاً مثل پنل وارد کنید.' ),
		426 => array( 'rejected', 'استفاده از الگو (Verify) به سرویس پیشرفتهٔ کاوه‌نگار نیاز دارد؛ آن را از پنل فعال کنید.' ),
		428 => array( 'rejected', 'ارسال کد به‌صورت تماس صوتی ممکن نیست؛ توکن باید فقط عدد باشد.' ),
		431 => array( 'rejected', 'توکن نباید فاصله، خط جدید یا زیرخط داشته باشد.' ),
		432 => array( 'rejected', 'در متن الگوی کاوه‌نگار عبارت %token نیست؛ الگو را اصلاح کنید.' ),
		451 => array( 'rate_limited', 'فراخوانی بیش از حد از این IP؛ کاوه‌نگار موقتاً محدود کرده است.' ),
		501 => array( 'rejected', 'حساب کاوه‌نگار هنوز آزمایشی است و فقط به شمارهٔ صاحب حساب پیامک می‌فرستد.' ),
		607 => array( 'rejected', 'نام یکی از تگ‌های ارسالی اشتباه است.' ),
	);

	public function id(): string {
		return 'kavenegar';
	}

	public function label(): string {
		return __( 'کاوه‌نگار', 'signa' );
	}

	public function docsUrl(): string {
		return 'https://kavenegar.com/rest.html';
	}

	public function fields(): array {
		return array(
			'kavenegar_api_key'  => array(
				'label' => __( 'کلید API', 'signa' ),
				'type'  => 'password',
			),
			'kavenegar_template' => array(
				'label' => __( 'نام الگوی تأیید', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'در صورت تنظیم، از سرویس Verify استفاده می‌شود؛ متن الگو باید %token داشته باشد.', 'signa' ),
			),
			'kavenegar_sender'   => array(
				'label' => __( 'شماره فرستنده', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'فقط برای پیامک متنی؛ خالی بماند، خط پیش‌فرض حساب استفاده می‌شود.', 'signa' ),
			),
		);
	}

	public function missing(): array {
		return '' === trim( $this->option( 'kavenegar_api_key' ) ) ? array( 'kavenegar_api_key' ) : array();
	}

	public function plan(): array {
		$template = trim( $this->option( 'kavenegar_template' ) );
		$sender   = trim( $this->option( 'kavenegar_sender' ) );
		$issues   = array();
		$notes    = array();

		if ( '' === trim( $this->option( 'kavenegar_api_key' ) ) ) {
			$issues[] = __( 'کلید API کاوه‌نگار تنظیم نشده است.', 'signa' );
		}

		if ( '' === $template ) {
			$notes[] = __( 'بدون الگو، پیامک متنی از خط تبلیغاتی به شماره‌های «لیست سیاه» نمی‌رسد؛ برای کد ورود، الگوی Verify توصیه می‌شود.', 'signa' );
		}

		return array(
			'mode'     => '' !== $template ? 'pattern' : 'text',
			'sender'   => $sender,
			'template' => $template,
			'endpoint' => self::API_BASE . ( '' !== $template ? '…/verify/lookup.json' : '…/sms/send.json' ),
			'issues'   => $issues,
			'notes'    => $notes,
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = trim( $this->option( 'kavenegar_api_key' ) );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'برای کاوه‌نگار کلید API را در تنظیمات کامل کنید.', 'signa' ) );
		}

		$template = trim( $this->option( 'kavenegar_template' ) );

		if ( '' !== $template ) {
			$token = (string) preg_replace( '/[\s_]+/u', '', $request->code() );

			return $this->evaluate(
				$this->post(
					self::API_BASE . rawurlencode( $apiKey ) . '/verify/lookup.json',
					array(
						'headers' => $this->formHeaders(),
						'body'    => array(
							'receptor' => $request->phone(),
							'token'    => $token,
							'template' => $template,
						),
					)
				),
				'pattern'
			);
		}

		$body = array(
			'receptor' => $request->phone(),
			'message'  => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
		);

		$sender = trim( $this->option( 'kavenegar_sender' ) );
		if ( '' !== $sender ) {
			$body['sender'] = $sender;
		}

		return $this->evaluate(
			$this->post(
				self::API_BASE . rawurlencode( $apiKey ) . '/sms/send.json',
				array(
					'headers' => $this->formHeaders(),
					'body'    => $body,
				)
			),
			'text'
		);
	}

	private function evaluate( $response, string $mode ): GatewayResult {
		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );
		$api    = isset( $body['return']['status'] ) && is_numeric( $body['return']['status'] ) ? (int) $body['return']['status'] : null;

		if ( 200 === $api ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'entries.0.messageid' ) ), $status, array( 'mode' => $mode, 'api_status' => 200 ) );
		}

		$sentence = isset( $body['return']['message'] ) && is_scalar( $body['return']['message'] ) ? sanitize_text_field( (string) $body['return']['message'] ) : '';

		if ( null !== $api && isset( self::$statuses[ $api ] ) ) {
			return GatewayResult::failed(
				$this->id(),
				self::$statuses[ $api ][0],
				self::$statuses[ $api ][1],
				$status,
				array(
					'mode'       => $mode,
					'api_status' => $api,
					'reason'     => 'Kavenegar ' . $api . ': ' . ( '' !== $sentence ? $sentence : self::$statuses[ $api ][1] ),
				)
			);
		}

		$code = $this->codeForStatus( null !== $api ? $api : $status );

		return GatewayResult::failed(
			$this->id(),
			$code,
			'' !== $sentence ? $sentence : __( 'پاسخ کاوه‌نگار قابل خواندن نبود.', 'signa' ),
			$status,
			array(
				'mode'   => $mode,
				'reason' => 'Kavenegar ' . ( null !== $api ? $api : 'HTTP ' . $status ) . ( '' !== $sentence ? ': ' . $sentence : '' ),
			)
		);
	}
}
