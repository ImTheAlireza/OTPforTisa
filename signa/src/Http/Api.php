<?php
/**
 * REST surface of the plugin.
 *
 * Everything the browser needs lives under `signa/v1`, which keeps the flow
 * cache-friendly and independent from admin-ajax.
 *
 * @package Signa
 */

namespace Signa\Http;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Front\FormRenderer;
use Signa\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class Api implements Bootable {

	const ROUTE_NS = 'signa/v1';

	/** @var AuthController */
	private $auth;

	/** @var AdminController */
	private $admin;

	/** @var Settings */
	private $settings;

	/** @var FormRenderer */
	private $renderer;

	public function __construct( AuthController $auth, AdminController $admin, Settings $settings, FormRenderer $renderer ) {
		$this->auth     = $auth;
		$this->admin    = $admin;
		$this->settings = $settings;
		$this->renderer = $renderer;
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		$public = array(
			'/start'    => 'start',
			'/code'     => 'sendCode',
			'/verify'   => 'verify',
			'/register' => 'register',
		);

		foreach ( $public as $route => $method ) {
			register_rest_route(
				self::ROUTE_NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => $this->handler( $this->auth, $method ),
					'permission_callback' => array( $this, 'allowPublic' ),
					'args'                => array(
						'phone' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}

		register_rest_route(
			self::ROUTE_NS,
			'/form-config',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'formConfig' ),
				'permission_callback' => '__return_true',
			)
		);

		$privileged = array(
			'/admin/test'           => 'sendTest',
			'/admin/check'          => 'check',
			'/admin/throttle-reset' => 'resetThrottle',
			'/admin/summary'        => 'summary',
			'/admin/doctor'         => 'doctor',
			'/admin/probe'          => 'probe',
			'/admin/import/start'   => 'importStart',
			'/admin/import/step'    => 'importStep',
			'/admin/import/undo'    => 'importUndo',
		);

		foreach ( $privileged as $route => $method ) {
			register_rest_route(
				self::ROUTE_NS,
				$route,
				array(
					'methods'             => in_array( $method, array( 'summary', 'doctor' ), true ) ? 'GET' : 'POST',
					'callback'            => $this->handler( $this->admin, $method ),
					'permission_callback' => array( $this, 'allowAdmin' ),
				)
			);
		}
	}

	public function allowPublic(): bool {
		return $this->settings->bool( 'enabled', true );
	}

	public function allowAdmin(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Wrap a controller method so thrown rejections become JSON errors.
	 */
	private function handler( object $controller, string $method ): callable {
		return function ( \WP_REST_Request $rest ) use ( $controller, $method ) {
			try {
				$request = Request::fromRest( $rest, $this->settings );
				$payload = $controller->{$method}( $request );

				return new \WP_REST_Response(
					array(
						'success' => true,
						'data'    => $payload,
					),
					200
				);
			} catch ( Rejection $rejection ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'code'    => $rejection->errorCode(),
						'message' => $rejection->getMessage(),
						'data'    => $rejection->payload(),
					),
					$rejection->status()
				);
			} catch ( \Throwable $error ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'server_error',
						'message' => __( 'خطای غیرمنتظره در پردازش درخواست. لطفاً دوباره تلاش کنید.', 'signa' ),
						'data'    => defined( 'WP_DEBUG' ) && WP_DEBUG ? array( 'detail' => $error->getMessage() ) : array(),
					),
					500
				);
			}
		};
	}

	/**
	 * Public bootstrap payload used by lazily rendered forms.
	 */
	public function formConfig(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->renderer->clientConfig(),
			),
			200
		);

		// This payload carries a fresh nonce, so a cached copy would defeat it.
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}
}
