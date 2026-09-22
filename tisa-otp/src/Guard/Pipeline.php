<?php
/**
 * Runs the configured guards in order for a given stage.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Blocklist\Blocklist;
use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Settings;
use TisaOtp\Http\Request;
use TisaOtp\Log\Logger;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class Pipeline {

	const STAGE_SEND     = 'send';
	const STAGE_VERIFY   = 'verify';
	const STAGE_REGISTER = 'register';

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	/** @var Guard[]|null */
	private $guards;

	/** @var Throttle */
	private $throttle;

	/** @var Manager */
	private $captcha;

	/** @var Blocklist */
	private $blocklist;

	public function __construct( Settings $settings, Throttle $throttle, Manager $captcha, Logger $logger, Blocklist $blocklist ) {
		$this->settings  = $settings;
		$this->throttle  = $throttle;
		$this->captcha   = $captcha;
		$this->logger    = $logger;
		$this->blocklist = $blocklist;
	}

	/**
	 * @return Guard[]
	 */
	public function guards(): array {
		if ( null !== $this->guards ) {
			return $this->guards;
		}

		// The blocklist runs first: a banned number must not reserve a cooldown
		// slot, spend a quota unit or reach a paid gateway.
		$this->guards = array(
			new BlocklistGuard( $this->blocklist ),
			new BotGuard(),
			new ThrottleGuard( $this->throttle ),
			new CaptchaGuard( $this->captcha, $this->throttle, $this->logger ),
		);

		/**
		 * Add or replace guards in the pipeline.
		 *
		 * @param Guard[]  $guards   Ordered guards.
		 * @param Settings $settings Plugin settings.
		 */
		$custom = (array) apply_filters( 'tisa_otp_guards', $this->guards, $this->settings );

		$this->guards = array_values( array_filter( $custom, static function ( $guard ) {
			return $guard instanceof Guard;
		} ) );

		return $this->guards;
	}

	/**
	 * @throws Rejection When any guard refuses the request.
	 */
	public function run( string $stage, Request $request ): void {
		if ( ! $this->settings->bool( 'enabled', true ) ) {
			throw Rejection::make( 'disabled', __( 'سرویس ورود پیامکی در حال حاضر غیرفعال است.', 'tisa-otp' ) );
		}

		foreach ( $this->guards() as $guard ) {
			if ( ! in_array( $stage, $guard->stages(), true ) ) {
				continue;
			}

			try {
				$guard->inspect( $request, $stage );
			} catch ( Rejection $rejection ) {
				$this->logger->notice(
					'guard.rejected',
					array(
						'guard'      => $guard->name(),
						'stage'      => $stage,
						'error_code' => $rejection->errorCode(),
						'phone'      => $request->phone(),
						'ip'         => $request->ip(),
					)
				);

				throw $rejection;
			}
		}
	}
}
