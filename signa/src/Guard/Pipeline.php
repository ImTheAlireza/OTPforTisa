<?php
/**
 * Runs the configured guards in order for a given stage.
 *
 * @package Signa
 */

namespace Signa\Guard;

use Signa\Blocklist\Blocklist;
use Signa\Blocklist\Trusted;
use Signa\Captcha\Manager;
use Signa\Config\Settings;
use Signa\Http\Request;
use Signa\Log\Logger;
use Signa\Support\Rejection;
use Signa\Throttle\Throttle;

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

	/** @var Trusted */
	private $trusted;

	public function __construct( Settings $settings, Throttle $throttle, Manager $captcha, Logger $logger, Blocklist $blocklist, ?Trusted $trusted = null ) {
		$this->settings  = $settings;
		$this->throttle  = $throttle;
		$this->captcha   = $captcha;
		$this->logger    = $logger;
		$this->blocklist = $blocklist;
		$this->trusted   = null === $trusted ? new Trusted( $settings ) : $trusted;
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
		$custom = (array) apply_filters( 'signa_guards', $this->guards, $this->settings );

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
			throw Rejection::make( 'disabled', __( 'سرویس ورود پیامکی در حال حاضر غیرفعال است.', 'signa' ) );
		}

		// A number on the trusted list skips only the guards that exist to slow
		// strangers down. It still has to prove it owns the phone with a code,
		// and the blocklist is never skipped.
		$trusted = $this->trusted->matches( $request->phone() );

		foreach ( $this->guards() as $guard ) {
			if ( ! in_array( $stage, $guard->stages(), true ) ) {
				continue;
			}

			if ( $trusted && $this->trusted->skipsGuard( $guard->name() ) ) {
				$this->logger->notice(
					'guard.trusted_skip',
					array(
						'guard' => $guard->name(),
						'stage' => $stage,
						'phone' => $request->phone(),
					)
				);

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
						/*
						 * Who was on the other end. A rejection without a token is a robot
						 * when it arrives from a script and a visitor when it arrives from a
						 * browser — and the difference is the whole answer this row gives
						 * the administrator, who was told "users' widgets did not load"
						 * for requests that had no browser at all.
						 */
						'ua'         => substr( trim( $request->userAgent() ), 0, 200 ),
					)
				);

				throw $rejection;
			}
		}
	}
}
