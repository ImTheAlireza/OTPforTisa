<?php
/**
 * Plugin orchestrator: wires the service container and boots hook-bearing services.
 *
 * @package Signa
 */

namespace Signa;

use Signa\Support\Container;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance;

	/** @var Container */
	private $container;

	/** @var bool */
	private $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	public static function boot( string $pluginFile ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}

		self::$instance->start();

		return self::$instance;
	}

	public static function i(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
			self::$instance->start();
		}

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * @return mixed
	 */
	public function get( string $id ) {
		return $this->container->make( $id );
	}

	private function start(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'signa', false, dirname( plugin_basename( SIGNA_FILE ) ) . '/languages' );

		/*
		 * Every service is built and started through the guard. A constructor
		 * whose wiring drifted, a class that is not in the package, a file that
		 * was half replaced — all of them used to be a fatal on every request,
		 * front end included. Now they are one sentence in the admin and the
		 * rest of the plugin keeps working.
		 */
		try {
			$this->container->make( Install\Upgrades::class )->run();
		} catch ( \Throwable $error ) {
			Install\Guard::record( 'Install\\Upgrades', $error );
		}

		foreach ( $this->bootables() as $id ) {
			try {
				$service = $this->container->make( $id );

				if ( $service instanceof Bootable ) {
					$service->boot();
				}
			} catch ( \Throwable $error ) {
				Install\Guard::record( $id, $error );
			}
		}

		/**
		 * Fires after every Signa service has been attached to WordPress.
		 *
		 * @param Container $container Plugin service container.
		 */
		do_action( 'signa_booted', $this->container );
	}

	/**
	 * Services that must be instantiated on every request to attach hooks.
	 *
	 * @return string[]
	 */
	private function bootables(): array {
		return array(
			Install\Guard::class,
			Http\Api::class,
			Front\Assets::class,
			Front\Shortcodes::class,
			Front\LoginBridge::class,
			Woo\Bridge::class,
			Integrations\WoodMart::class,
			Elementor\Module::class,
			Cron\Maintenance::class,
			User\ProfileField::class,
			Admin\ReportScreen::class,
			Admin\LogsScreen::class,
			Admin\ToolsScreen::class,
			Admin\AccessScreen::class,
			Admin\FormPreview::class,
			Admin\AppMode::class,
			Admin\Gate::class,
		);
	}

	private function register(): void {
		$c = $this->container;

		$c->bind( Config\Settings::class, static function () {
			return new Config\Settings();
		} );

		$c->bind( Install\Schema::class, static function () {
			return new Install\Schema();
		} );

		$c->bind( Install\Upgrades::class, static function ( Container $c ) {
			return new Install\Upgrades( $c->make( Config\Settings::class ), $c->make( Install\Schema::class ) );
		} );

		$c->bind( State\StateStore::class, static function () {
			return new State\StateStore();
		} );

		$c->bind( Support\Lock::class, static function ( Container $c ) {
			return new Support\Lock( $c->make( State\StateStore::class ) );
		} );

		$c->bind( Throttle\Throttle::class, static function ( Container $c ) {
			return new Throttle\Throttle( $c->make( State\StateStore::class ), $c->make( Config\Settings::class ) );
		} );

		$c->bind( Log\Redactor::class, static function () {
			return new Log\Redactor();
		} );

		$c->bind( Log\LogStore::class, static function ( Container $c ) {
			return new Log\LogStore( $c->make( Config\Settings::class ) );
		} );

		$c->bind( Log\Logger::class, static function ( Container $c ) {
			return new Log\Logger(
				$c->make( Config\Settings::class ),
				$c->make( Log\Redactor::class ),
				$c->make( Log\LogStore::class )
			);
		} );

		$c->bind( Otp\CodeStore::class, static function ( Container $c ) {
			$settings = $c->make( Config\Settings::class );

			return 'cache' === $settings->str( 'code_store', 'database' )
				? new Otp\CacheCodeStore()
				: new Otp\TableCodeStore();
		} );

		$c->bind( Otp\OtpService::class, static function ( Container $c ) {
			return new Otp\OtpService(
				$c->make( Config\Settings::class ),
				$c->make( Otp\CodeStore::class )
			);
		} );

		$c->bind( Gateway\Registry::class, static function ( Container $c ) {
			return new Gateway\Registry( $c->make( Config\Settings::class ) );
		} );

		$c->bind( Gateway\FailoverChain::class, static function ( Container $c ) {
			return new Gateway\FailoverChain(
				$c->make( Gateway\Registry::class ),
				$c->make( Config\Settings::class ),
				$c->make( Log\Logger::class )
			);
		} );

		$c->bind( Channel\Dispatcher::class, static function ( Container $c ) {
			$settings = $c->make( Config\Settings::class );
			$logger   = $c->make( Log\Logger::class );

			$channels = array(
				'sms'   => new Channel\SmsChannel( $c->make( Gateway\FailoverChain::class ), $settings ),
				'email' => new Channel\EmailChannel( $settings, $logger ),
			);

			return new Channel\Dispatcher( $channels, $settings, $logger );
		} );

		$c->bind( Captcha\Manager::class, static function ( Container $c ) {
			return new Captcha\Manager( $c->make( Config\Settings::class ), $c->make( Log\Logger::class ) );
		} );

		$c->bind( User\PhoneLocator::class, static function ( Container $c ) {
			return new User\PhoneLocator( $c->make( Config\Settings::class ), $c->make( Log\Logger::class ) );
		} );

		$c->bind( User\AccessPolicy::class, static function ( Container $c ) {
			return new User\AccessPolicy( $c->make( Config\Settings::class ) );
		} );

		$c->bind( User\RedirectResolver::class, static function ( Container $c ) {
			return new User\RedirectResolver( $c->make( Config\Settings::class ) );
		} );

		$c->bind( User\AccountFactory::class, static function ( Container $c ) {
			return new User\AccountFactory(
				$c->make( Config\Settings::class ),
				$c->make( User\PhoneLocator::class ),
				$c->make( Support\Lock::class ),
				$c->make( Log\Logger::class )
			);
		} );

		$c->bind( User\Session::class, static function ( Container $c ) {
			return new User\Session(
				$c->make( User\RedirectResolver::class ),
				$c->make( Config\Settings::class ),
				$c->make( Log\Logger::class )
			);
		} );

		$c->bind( User\ProfileField::class, static function ( Container $c ) {
			return new User\ProfileField(
				$c->make( Config\Settings::class ),
				$c->make( User\PhoneLocator::class )
			);
		} );

		$c->bind( Registration\FieldSchema::class, static function ( Container $c ) {
			return new Registration\FieldSchema( $c->make( Config\Settings::class ) );
		} );

		$c->bind( Registration\FieldValidator::class, static function ( Container $c ) {
			return new Registration\FieldValidator( $c->make( Config\Settings::class ) );
		} );

		$c->bind( Registration\DraftStore::class, static function ( Container $c ) {
			return new Registration\DraftStore( $c->make( State\StateStore::class ) );
		} );

		$c->bind( Registration\RegistrationService::class, static function ( Container $c ) {
			return new Registration\RegistrationService(
				$c->make( Config\Settings::class ),
				$c->make( Registration\FieldSchema::class ),
				$c->make( Registration\FieldValidator::class ),
				$c->make( Registration\DraftStore::class ),
				$c->make( User\AccountFactory::class ),
				$c->make( User\PhoneLocator::class ),
				$c->make( Log\Logger::class )
			);
		} );

		$c->bind( Blocklist\Blocklist::class, static function () {
			return new Blocklist\Blocklist();
		} );

		$c->bind( Blocklist\Trusted::class, static function ( Container $c ) {
			return new Blocklist\Trusted( $c->make( Config\Settings::class ) );
		} );

		$c->bind( Access\EmergencyToken::class, static function () {
			return new Access\EmergencyToken();
		} );

		$c->bind( Guard\Pipeline::class, static function ( Container $c ) {
			return new Guard\Pipeline(
				$c->make( Config\Settings::class ),
				$c->make( Throttle\Throttle::class ),
				$c->make( Captcha\Manager::class ),
				$c->make( Log\Logger::class ),
				$c->make( Blocklist\Blocklist::class ),
				$c->make( Blocklist\Trusted::class )
			);
		} );

		$c->bind( Http\AuthController::class, static function ( Container $c ) {
			return new Http\AuthController(
				$c->make( Config\Settings::class ),
				$c->make( Otp\OtpService::class ),
				$c->make( Guard\Pipeline::class ),
				$c->make( Channel\Dispatcher::class ),
				$c->make( Registration\RegistrationService::class ),
				$c->make( User\PhoneLocator::class ),
				$c->make( User\Session::class ),
				$c->make( User\AccessPolicy::class ),
				$c->make( Throttle\Throttle::class ),
				$c->make( Access\EmergencyToken::class ),
				$c->make( Log\Logger::class )
			);
		} );

		$c->bind( Import\Runner::class, static function ( Container $c ) {
			return new Import\Runner(
				$c->make( Config\Settings::class ),
				$c->make( State\StateStore::class ),
				$c->make( User\PhoneLocator::class ),
				$c->make( Log\Logger::class )
			);
		} );

		$c->bind( Http\AdminController::class, static function ( Container $c ) {
			return new Http\AdminController(
				$c->make( Config\Settings::class ),
				$c->make( Channel\Dispatcher::class ),
				$c->make( Otp\OtpService::class ),
				$c->make( Throttle\Throttle::class ),
				$c->make( Log\Logger::class ),
				$c->make( Log\LogStore::class ),
				$c->make( Import\Runner::class ),
				$c->make( Gateway\Registry::class ),
				$c->make( Captcha\Manager::class ),
				$c->make( Diagnostics\SelfTest::class )
			);
		} );

		$c->bind( Http\Api::class, static function ( Container $c ) {
			return new Http\Api(
				$c->make( Http\AuthController::class ),
				$c->make( Http\AdminController::class ),
				$c->make( Config\Settings::class ),
				$c->make( Front\FormRenderer::class )
			);
		} );

		$c->bind( Support\View::class, static function () {
			return new Support\View();
		} );

		$c->bind( Front\FormRenderer::class, static function ( Container $c ) {
			return new Front\FormRenderer(
				$c->make( Config\Settings::class ),
				$c->make( Registration\FieldSchema::class ),
				$c->make( Captcha\Manager::class ),
				$c->make( Support\View::class ),
				$c->make( Front\Assets::class )
			);
		} );

		$c->bind( Front\Assets::class, static function ( Container $c ) {
			return new Front\Assets( $c->make( Config\Settings::class ), $c->make( Captcha\Manager::class ) );
		} );

		$c->bind( Front\Shortcodes::class, static function ( Container $c ) {
			return new Front\Shortcodes( $c->make( Front\FormRenderer::class ), $c->make( Config\Settings::class ) );
		} );

		$c->bind( Front\LoginBridge::class, static function ( Container $c ) {
			return new Front\LoginBridge( $c->make( Config\Settings::class ), $c->make( Front\FormRenderer::class ) );
		} );

		$c->bind( Woo\Bridge::class, static function ( Container $c ) {
			return new Woo\Bridge( $c->make( Config\Settings::class ), $c->make( Front\FormRenderer::class ) );
		} );

		$c->bind( Integrations\WoodMart::class, static function ( Container $c ) {
			return new Integrations\WoodMart(
				$c->make( Config\Settings::class ),
				$c->make( Front\FormRenderer::class ),
				$c->make( Front\Assets::class )
			);
		} );

		$c->bind( Elementor\Module::class, static function ( Container $c ) {
			return new Elementor\Module( $c->make( Front\FormRenderer::class ), $c->make( Config\Settings::class ) );
		} );

		$c->bind( Cron\Maintenance::class, static function ( Container $c ) {
			return new Cron\Maintenance(
				$c->make( Config\Settings::class ),
				$c->make( State\StateStore::class ),
				$c->make( Log\LogStore::class ),
				$c->make( Otp\CodeStore::class )
			);
		} );

		$c->bind( Admin\Controls::class, static function ( Container $c ) {
			return new Admin\Controls( $c->make( Config\Settings::class ) );
		} );

		$c->bind( Admin\AppMode::class, static function () {
			return new Admin\AppMode();
		} );

		$c->bind( Install\Guard::class, static function () {
			return new Install\Guard();
		} );

		$c->bind( Admin\SettingsScreen::class, static function ( Container $c ) {
			return new Admin\SettingsScreen(
				$c->make( Config\Settings::class ),
				$c->make( Admin\Controls::class ),
				$c->make( Gateway\Registry::class ),
				$c->make( Registration\FieldSchema::class ),
				$c->make( Captcha\Manager::class ),
				$c->make( Log\LogStore::class ),
				$c->make( Admin\ReportScreen::class ),
				$c->make( Admin\Dashboard::class )
			);
		} );

		$c->bind( Admin\Dashboard::class, static function ( Container $c ) {
			return new Admin\Dashboard(
				$c->make( Config\Settings::class ),
				$c->make( Gateway\Registry::class ),
				$c->make( Log\LogStore::class )
			);
		} );

		$c->bind( Admin\FormPreview::class, static function ( Container $c ) {
			return new Admin\FormPreview(
				$c->make( Config\Settings::class ),
				$c->make( Front\FormRenderer::class ),
				$c->make( Front\Assets::class )
			);
		} );

		$c->bind( Diagnostics\SelfTest::class, static function ( Container $c ) {
			return new Diagnostics\SelfTest(
				$c->make( Config\Settings::class ),
				$c->make( Install\Schema::class ),
				$c->make( Otp\OtpService::class ),
				$c->make( Otp\CodeStore::class ),
				$c->make( Gateway\Registry::class ),
				$c->make( Log\LogStore::class ),
				$c->make( Captcha\Manager::class ),
				$c->make( Registration\FieldSchema::class )
			);
		} );

		$c->bind( Admin\LogsScreen::class, static function ( Container $c ) {
			return new Admin\LogsScreen( $c->make( Log\LogStore::class ), $c->make( Config\Settings::class ) );
		} );

		$c->bind( Admin\ReportScreen::class, static function ( Container $c ) {
			return new Admin\ReportScreen( $c->make( Log\LogStore::class ), $c->make( Config\Settings::class ) );
		} );

		$c->bind( Admin\ToolsScreen::class, static function ( Container $c ) {
			return new Admin\ToolsScreen(
				$c->make( Config\Settings::class ),
				$c->make( Import\Runner::class ),
				$c->make( Throttle\Throttle::class ),
				$c->make( Log\LogStore::class ),
				$c->make( Install\Schema::class )
			);
		} );

		$c->bind( Admin\AccessScreen::class, static function ( Container $c ) {
			return new Admin\AccessScreen(
				$c->make( Config\Settings::class ),
				$c->make( Blocklist\Blocklist::class ),
				$c->make( Access\EmergencyToken::class ),
				$c->make( Log\Logger::class )
			);
		} );

		/*
		 * The admin menu is reached through the gate: Menu.php is the file the
		 * marketplace encodes, and the gate only loads it where it can run.
		 */
		$c->bind( Admin\Gate::class, static function ( Container $c ) {
			return new Admin\Gate(
				static function () use ( $c ) {
					return $c->make( Admin\Menu::class );
				}
			);
		} );

		$c->bind( Admin\Menu::class, static function ( Container $c ) {
			return new Admin\Menu(
				$c->make( Config\Settings::class ),
				$c->make( Admin\SettingsScreen::class ),
				$c->make( Admin\ReportScreen::class ),
				$c->make( Admin\LogsScreen::class ),
				$c->make( Admin\ToolsScreen::class ),
				$c->make( Admin\AccessScreen::class )
			);
		} );
	}
}
