<?php
/**
 * Admin menu and page routing.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Menu implements Bootable {

	const CAPABILITY = 'manage_options';
	const ROOT       = 'tisa-otp';

	/** @var Settings */
	private $settings;

	/** @var SettingsScreen */
	private $settingsScreen;

	/** @var LogsScreen */
	private $logsScreen;

	/** @var ToolsScreen */
	private $toolsScreen;

	/** @var AccessScreen */
	private $accessScreen;

	public function __construct( Settings $settings, SettingsScreen $settingsScreen, LogsScreen $logsScreen, ToolsScreen $toolsScreen, AccessScreen $accessScreen ) {
		$this->settings       = $settings;
		$this->settingsScreen = $settingsScreen;
		$this->logsScreen     = $logsScreen;
		$this->toolsScreen    = $toolsScreen;
		$this->accessScreen   = $accessScreen;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_init', array( $this, 'registerSetting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TISA_OTP_FILE ), array( $this, 'actionLinks' ) );
	}

	public function register(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( $this->icon() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page(
			__( 'تیسا OTP', 'tisa-otp' ),
			__( 'تیسا OTP', 'tisa-otp' ),
			self::CAPABILITY,
			self::ROOT,
			array( $this->settingsScreen, 'render' ),
			$icon,
			57
		);

		add_submenu_page( self::ROOT, __( 'تنظیمات', 'tisa-otp' ), __( 'تنظیمات', 'tisa-otp' ), self::CAPABILITY, self::ROOT, array( $this->settingsScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'رویدادها', 'tisa-otp' ), __( 'رویدادها', 'tisa-otp' ), self::CAPABILITY, self::ROOT . '-logs', array( $this->logsScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'ابزارها و وضعیت', 'tisa-otp' ), __( 'ابزارها', 'tisa-otp' ), self::CAPABILITY, self::ROOT . '-tools', array( $this->toolsScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'دسترسی و مسدودی', 'tisa-otp' ), __( 'دسترسی و مسدودی', 'tisa-otp' ), self::CAPABILITY, AccessScreen::SLUG, array( $this->accessScreen, 'render' ) );
	}

	public function registerSetting(): void {
		register_setting(
			'tisa_otp_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'تنظیمات افزونه تیسا OTP', 'tisa-otp' ),
				'sanitize_callback' => array( $this->settingsScreen, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function actionLinks( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::ROOT );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'تنظیمات', 'tisa-otp' ) )
		);

		return $links;
	}

	private function icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10.5 5.5h3"/><path d="M9 12.5l2 2 4-4.5"/></svg>';
	}
}
