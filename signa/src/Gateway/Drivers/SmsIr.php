<?php
/**
 * SMS.ir driver.
 *
 * Two documented ways to send: a verification template (`/v1/send/verify`,
 * which is what an OTP should use) and free text over a dedicated line
 * (`/v1/send/bulk`). The panel answers both with its own status code in the
 * body — `status: 1` means accepted, anything else is a documented refusal with
 * a number (10 invalid key … 123 line not activated). Those numbers are the
 * whole answer to "why was my code not sent", so they are translated here
 * instead of being flattened into "rejected".
 *
 * @see https://sms.ir/rest-api/
 *
 * @package Signa
 */

namespace Signa\Gateway\Drivers;

use Signa\Gateway\AccountProbe;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\HttpGateway;
use Signa\Support\Phone;
use Signa\Support\Transport;

defined( 'ABSPATH' ) || exit;

final class SmsIr extends HttpGateway implements AccountProbe {

	const VERIFY_ENDPOINT = 'https://api.sms.ir/v1/send/verify';
	const BULK_ENDPOINT   = 'https://api.sms.ir/v1/send/bulk';
	const CREDIT_ENDPOINT = 'https://api.sms.ir/v1/credit';
	const LINE_ENDPOINT   = 'https://api.sms.ir/v1/line';

	/** Documented limit for one template parameter value. */
	const PARAM_MAX = 25;

	/**
	 * The panel's own status codes: our error code, and what the owner should do.
	 *
	 * @see https://sms.ir/rest-api/ (جدول کدهای وضعیت)
	 *
	 * @var array<int,array<int,string>>
	 */
	private static $statuses = array(
		0   => array( 'upstream', 'سامانهٔ SMS.ir خطا داد؛ چند دقیقه بعد دوباره تلاش کنید و اگر تکرار شد با پشتیبانی تماس بگیرید.' ),
		10  => array( 'unauthorized', 'کلید API نامعتبر است؛ از پنل SMS.ir، بخش برنامه‌نویسان، کلید تازه بسازید و همین‌جا بگذارید.' ),
		11  => array( 'unauthorized', 'این کلید در پنل SMS.ir غیرفعال شده است؛ کلید تازه بسازید.' ),
		12  => array( 'unauthorized', 'این کلید به IP مشخصی محدود شده است؛ IP این سرور را در پنل SMS.ir مجاز کنید.' ),
		13  => array( 'unauthorized', 'حساب SMS.ir غیرفعال است؛ با پشتیبانی سامانه تماس بگیرید.' ),
		14  => array( 'unauthorized', 'حساب SMS.ir در حالت تعلیق است؛ با پشتیبانی سامانه تماس بگیرید.' ),
		20  => array( 'rate_limited', 'تعداد درخواست‌ها از سقف مجاز SMS.ir گذشت؛ چند دقیقه صبر کنید.' ),
		101 => array( 'rejected', 'شماره خط نامعتبر است؛ شمارهٔ خط را از پنل SMS.ir کپی کنید.' ),
		102 => array( 'no_credit', 'اعتبار حساب SMS.ir تمام شده است؛ حساب را شارژ کنید.' ),
		103 => array( 'rejected', 'متن پیامک خالی بوده است.' ),
		104 => array( 'rejected', 'شمارهٔ موبایل نادرست است.' ),
		105 => array( 'rejected', 'تعداد گیرنده‌ها از حد مجاز SMS.ir بیشتر است.' ),
		106 => array( 'rejected', 'تعداد متن‌ها از حد مجاز SMS.ir بیشتر است.' ),
		107 => array( 'rejected', 'فهرست گیرنده‌ها خالی است.' ),
		108 => array( 'rejected', 'فهرست متن‌ها خالی است.' ),
		109 => array( 'rejected', 'زمان ارسال نامعتبر است.' ),
		110 => array( 'rejected', 'تعداد شماره‌ها و متن‌ها با هم برابر نیست.' ),
		111 => array( 'rejected', 'با این شناسه ارسالی ثبت نشده است.' ),
		112 => array( 'rejected', 'رکوردی برای حذف پیدا نشد.' ),
		113 => array( 'rejected', 'الگوی تأیید با این شناسه در حساب شما نیست؛ شناسهٔ الگو را از بخش «ارسال سریع» پنل SMS.ir بردارید.' ),
		114 => array( 'rejected', 'مقدار یکی از پارامترهای الگو بیش از ۲۵ نویسه است.' ),
		115 => array( 'rejected', 'این شماره در لیست سیاه SMS.ir است؛ فقط با خط خدماتی و الگو می‌شود به آن پیامک فرستاد.' ),
		116 => array( 'rejected', 'نام پارامتر الگو خالی است.' ),
		117 => array( 'rejected', 'متن آزاد شما در SMS.ir تأیید نشده است؛ از الگوی تأییدشده استفاده کنید.' ),
		118 => array( 'rejected', 'تعداد پیام‌ها از حد مجاز SMS.ir بیشتر است.' ),
		119 => array( 'rejected', 'قالب اختصاصی روی پلن فعلی SMS.ir فعال نیست؛ پلن را ارتقا دهید.' ),
		123 => array( 'rejected', 'خط ارسال‌کننده در SMS.ir فعال نشده است؛ از پنل، خط را فعال کنید.' ),
	);

	public function id(): string {
		return 'smsir';
	}

	public function label(): string {
		return __( 'SMS.ir', 'signa' );
	}

	public function docsUrl(): string {
		return 'https://sms.ir/rest-api/';
	}

	public function fields(): array {
		return array(
			'smsir_api_key'     => array(
				'label' => __( 'کلید API', 'signa' ),
				'type'  => 'password',
			),
			'smsir_template_id' => array(
				'label' => __( 'شناسه الگوی تأیید', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'خالی بماند، پیامک متنی معمولی فرستاده می‌شود.', 'signa' ),
			),
			'smsir_param'       => array(
				'label' => __( 'نام متغیر الگو', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'همان نامی که بین # در الگو آمده، با همان حروف کوچک و بزرگ؛ پیش‌فرض: CODE', 'signa' ),
			),
			'smsir_sender'      => array(
				'label' => __( 'شماره خط', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'فقط برای پیامک متنی لازم است.', 'signa' ),
			),
		);
	}

	public function missing(): array {
		return '' === trim( $this->option( 'smsir_api_key' ) ) ? array( 'smsir_api_key' ) : array();
	}

	/**
	 * The configured line, with Persian digits folded and separators removed.
	 */
	private function line(): string {
		return (string) preg_replace( '/\D/', '', Phone::latinDigits( trim( $this->option( 'smsir_sender' ) ) ) );
	}

	/**
	 * The configured template id, or '' when it is not a plain number.
	 */
	private function template(): string {
		$value = trim( Phone::latinDigits( $this->option( 'smsir_template_id' ) ) );

		return (string) preg_replace( '/\D/', '', $value );
	}

	/**
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array {
		$key      = trim( $this->option( 'smsir_api_key' ) );
		$template = $this->template();
		$sender   = $this->line();
		$issues   = array();

		if ( '' === $key ) {
			$issues[] = __( 'کلید API سرویس SMS.ir تنظیم نشده است.', 'signa' );
		}

		if ( '' !== trim( $this->option( 'smsir_template_id' ) ) && '' === $template ) {
			$issues[] = __( 'شناسه الگو باید عدد باشد؛ همان عددی که در بخش «ارسال سریع» پنل SMS.ir آمده است.', 'signa' );
		}

		if ( '' !== trim( $this->option( 'smsir_sender' ) ) && '' === $sender ) {
			$issues[] = __( 'شماره خط باید عدد باشد؛ همان شماره‌ای که در پنل SMS.ir برای شما فعال است.', 'signa' );
		}

		if ( '' === $template && '' === $sender ) {
			$issues[] = __( 'نه شناسه الگو و نه شماره خط تنظیم شده است؛ SMS.ir بدون هیچ‌کدام پیامکی نمی‌پذیرد.', 'signa' );
		}

		return array(
			'mode'     => '' !== $template ? 'pattern' : 'text',
			'sender'   => $sender,
			'template' => $template,
			'endpoint' => '' !== $template ? self::VERIFY_ENDPOINT : self::BULK_ENDPOINT,
			'issues'   => $issues,
			'notes'    => array(),
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = trim( $this->option( 'smsir_api_key' ) );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'برای SMS.ir کلید API را در تنظیمات کامل کنید.', 'signa' ) );
		}

		$args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-API-KEY'    => $apiKey,
			),
		);

		if ( '' !== $this->template() ) {
			return $this->sendVerify( $request, $args );
		}

		return $this->sendBulk( $request, $args );
	}

	/**
	 * Templated send. The panel refuses an empty parameter name (116) and a
	 * value longer than 25 characters (114) with a bare number, so both are
	 * caught here with a sentence that says which one it is.
	 */
	private function sendVerify( DeliveryRequest $request, array $args ): GatewayResult {
		/**
		 * The parameter name written between # in the SMS.ir template.
		 *
		 * @param string $name Parameter name.
		 */
		$name = trim( (string) apply_filters( 'signa_smsir_param', $this->paramName( 'smsir_param', 'CODE' ) ) );
		$code = $request->code();

		if ( '' === $name ) {
			return $this->reject( __( 'نام پارامتر الگو خالی است؛ مقدار پیش‌فرض CODE را نگه دارید.', 'signa' ), 'SMS.ir: empty parameter name' );
		}

		if ( mb_strlen( $code ) > self::PARAM_MAX ) {
			return $this->reject(
				sprintf(
					/* translators: %d: maximum number of characters */
					__( 'کد تولیدشده بیش از %d نویسه است؛ SMS.ir آن را در الگو نمی‌پذیرد.', 'signa' ),
					self::PARAM_MAX
				),
				'SMS.ir 114: parameter value longer than ' . self::PARAM_MAX
			);
		}

		$args['body'] = wp_json_encode(
			array(
				'mobile'     => $request->phone(),
				'templateId' => (int) $this->template(),
				'parameters' => array(
					array(
						'name'  => $name,
						'value' => $code,
					),
				),
			)
		);

		return $this->evaluate( $this->post( self::VERIFY_ENDPOINT, $args ) );
	}

	/**
	 * Free text over the dedicated line.
	 *
	 * `lineNumber` is documented as a number, so it must reach the panel as a
	 * JSON number and not as a quoted string. It is not cast to an int for that
	 * either: on a 32-bit PHP (where the largest int is 2 147 483 647) casting a
	 * 14-digit line number saturates and the panel would receive a line that
	 * belongs to nobody. The digits are written into the JSON as they are.
	 */
	private function sendBulk( DeliveryRequest $request, array $args ): GatewayResult {
		$sender = $this->line();

		if ( '' === $sender ) {
			return $this->notConfigured( __( 'برای ارسال متنی SMS.ir شماره خط لازم است؛ در تنظیمات آن را وارد کنید.', 'signa' ) );
		}

		$rest = wp_json_encode(
			array(
				'messageText' => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
				'mobiles'     => array( $request->phone() ),
			)
		);

		$args['body'] = '{"lineNumber":' . $sender . ',' . ltrim( (string) $rest, '{' );

		return $this->evaluate( $this->post( self::BULK_ENDPOINT, $args ) );
	}

	private function reject( string $message, string $reason ): GatewayResult {
		return GatewayResult::failed( $this->id(), 'rejected', $message, 0, array( 'reason' => $reason ) );
	}

	/**
	 * @param array|\WP_Error $response
	 */
	private function evaluate( $response ): GatewayResult {
		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );
		$api    = isset( $body['status'] ) && is_numeric( $body['status'] ) ? (int) $body['status'] : null;

		if ( 200 === $status && 1 === $api ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'data.messageId' ) ), $status, array( 'api_status' => 1 ) );
		}

		$issue = $this->issue( $api, $status, $body );

		return GatewayResult::failed( $this->id(), $issue['code'], $issue['message'], $status, $issue['meta'] );
	}

	/**
	 * Turn whatever the panel answered into our code, a sentence for the owner
	 * and the panel's own words as the reason that travels to the log.
	 *
	 * @return array{code:string,message:string,meta:array<string,mixed>}
	 */
	private function issue( ?int $api, int $http, array $body ): array {
		$sentence = isset( $body['message'] ) && is_scalar( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : '';

		if ( null !== $api && isset( self::$statuses[ $api ] ) ) {
			$advice = self::$statuses[ $api ][1];

			return array(
				'code'    => self::$statuses[ $api ][0],
				'message' => $advice,
				'meta'    => array(
					'api_status' => $api,
					'reason'     => 'SMS.ir ' . $api . ': ' . ( '' !== $sentence ? $sentence : $advice ),
				),
			);
		}

		if ( null !== $api ) {
			return array(
				'code'    => $this->codeForStatus( $http ),
				'message' => '' !== $sentence ? $sentence : __( 'سامانهٔ SMS.ir درخواست را نپذیرفت.', 'signa' ),
				'meta'    => array(
					'api_status' => $api,
					'reason'     => 'SMS.ir status ' . $api . ': ' . $sentence,
				),
			);
		}

		return array(
			'code'    => $this->codeForStatus( $http ),
			'message' => '' !== $sentence ? $sentence : __( 'پاسخ SMS.ir قابل خواندن نبود.', 'signa' ),
			'meta'    => array(
				'reason' => 'SMS.ir: HTTP ' . $http . ( '' !== $sentence ? ' — ' . $sentence : '' ),
			),
		);
	}

	/**
	 * Ask the account, not the message: key accepted? credit left? line ours?
	 *
	 * Nothing is sent and nothing is charged, so this can be run as often as an
	 * owner likes — and it separates the three refusals that all look like "the
	 * code did not arrive".
	 */
	public function probe(): array {
		$out = array(
			'ok'         => false,
			'status'     => 'fail',
			'error_code' => '',
			'reason'     => '',
			'message'    => '',
			'credit'     => null,
			'lines'      => array(),
			'sender'     => $this->line(),
			'sender_ok'  => null,
		);

		$apiKey = trim( $this->option( 'smsir_api_key' ) );

		if ( '' === $apiKey ) {
			$out['error_code'] = 'not_configured';
			$out['message']    = __( 'کلید API سامانه SMS.ir تنظیم نشده است.', 'signa' );
			$out['reason']     = 'SMS.ir: no API key stored';

			return $out;
		}

		$credit = $this->read( self::CREDIT_ENDPOINT, $apiKey );

		if ( ! $credit['ok'] ) {
			$out['error_code'] = $credit['code'];
			$out['message']    = $credit['message'];
			$out['reason']     = $credit['reason'];

			return $out;
		}

		$out['ok']     = true;
		$out['status'] = 'ok';
		$out['credit'] = is_numeric( $credit['data'] ) ? (float) $credit['data'] : null;
		$out['reason'] = 'SMS.ir: key accepted, credit ' . ( null === $out['credit'] ? 'unknown' : (string) $out['credit'] );

		$lines = $this->read( self::LINE_ENDPOINT, $apiKey );

		if ( $lines['ok'] && is_array( $lines['data'] ) ) {
			$out['lines'] = array_values( array_map( 'strval', $lines['data'] ) );

			if ( '' !== $out['sender'] ) {
				$out['sender_ok'] = in_array( $out['sender'], $out['lines'], true );

				if ( ! $out['sender_ok'] ) {
					$out['ok']            = false;
					$out['status']        = 'warn';
					$out['error_code']    = 'rejected';
					$out['message']       = __( 'شماره خطی که در تنظیمات گذاشته‌اید در فهرست خطوط این حساب نیست؛ شماره را از پنل SMS.ir کپی کنید.', 'signa' );
					$out['reason']        = 'SMS.ir: line ' . $out['sender'] . ' is not in the account line list';
				}
			}

			return $out;
		}

		if ( ! $lines['ok'] && '' !== $lines['message'] ) {
			$out['status']  = 'warn';
			$out['message'] = $lines['message'];
			$out['reason']  = $lines['reason'];
		}

		return $out;
	}

	/**
	 * One read-only GET against the panel.
	 *
	 * @return array{ok:bool,code:string,message:string,reason:string,data:mixed}
	 */
	private function read( string $url, string $apiKey ): array {
		$response = $this->get(
			$url,
			array(
				'timeout'     => (int) apply_filters( 'signa_probe_timeout', 8 ),
				'redirection' => 0,
				'headers'     => array(
					'Accept'    => 'application/json',
					'X-API-KEY' => $apiKey,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$transport = Transport::fromError( $response );

			return array(
				'ok'      => false,
				'code'    => 'transport',
				'message' => $transport['message'],
				'reason'  => $transport['reason'],
				'data'    => null,
			);
		}

		$http = $this->status( $response );
		$body = $this->decode( $response );
		$api  = isset( $body['status'] ) && is_numeric( $body['status'] ) ? (int) $body['status'] : null;

		if ( 200 === $http && 1 === $api ) {
			return array(
				'ok'      => true,
				'code'    => '',
				'message' => '',
				'reason'  => '',
				'data'    => isset( $body['data'] ) ? $body['data'] : null,
			);
		}

		$issue = $this->issue( $api, $http, $body );

		return array(
			'ok'      => false,
			'code'    => $issue['code'],
			'message' => $issue['message'],
			'reason'  => isset( $issue['meta']['reason'] ) ? (string) $issue['meta']['reason'] : '',
			'data'    => null,
		);
	}
}
