<?php
/**
 * Admin menu and page routing.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Bootable;
use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Menu implements Bootable {

	const CAPABILITY = 'manage_options';
	const ROOT       = 'signa';

	/** @var Settings */
	private $settings;

	/** @var SettingsScreen */
	private $settingsScreen;

	/** @var ReportScreen */
	private $reportScreen;

	/** @var LogsScreen */
	private $logsScreen;

	/** @var ToolsScreen */
	private $toolsScreen;

	/** @var AccessScreen */
	private $accessScreen;

	public function __construct( Settings $settings, SettingsScreen $settingsScreen, ReportScreen $reportScreen, LogsScreen $logsScreen, ToolsScreen $toolsScreen, AccessScreen $accessScreen ) {
		$this->settings       = $settings;
		$this->settingsScreen = $settingsScreen;
		$this->reportScreen   = $reportScreen;
		$this->logsScreen     = $logsScreen;
		$this->toolsScreen    = $toolsScreen;
		$this->accessScreen   = $accessScreen;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_init', array( $this, 'registerSetting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SIGNA_FILE ), array( $this, 'actionLinks' ) );
	}

	public function register(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( $this->icon() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page(
			__( 'سیگنا', 'signa' ),
			__( 'سیگنا', 'signa' ),
			self::CAPABILITY,
			self::ROOT,
			array( $this->settingsScreen, 'render' ),
			$icon,
			57
		);

		add_submenu_page( self::ROOT, __( 'تنظیمات', 'signa' ), __( 'تنظیمات', 'signa' ), self::CAPABILITY, self::ROOT, array( $this->settingsScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'گزارش‌ها', 'signa' ), __( 'گزارش‌ها', 'signa' ), self::CAPABILITY, ReportScreen::SLUG, array( $this->reportScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'رویدادها', 'signa' ), __( 'رویدادها', 'signa' ), self::CAPABILITY, LogsScreen::SLUG, array( $this->logsScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'ابزارها و وضعیت', 'signa' ), __( 'ابزارها', 'signa' ), self::CAPABILITY, ToolsScreen::SLUG, array( $this->toolsScreen, 'render' ) );
		add_submenu_page( self::ROOT, __( 'دسترسی و مسدودی', 'signa' ), __( 'دسترسی و مسدودی', 'signa' ), self::CAPABILITY, AccessScreen::SLUG, array( $this->accessScreen, 'render' ) );
	}

	public function registerSetting(): void {
		register_setting(
			'signa_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'تنظیمات افزونه سیگنا', 'signa' ),
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
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'تنظیمات', 'signa' ) )
		);

		return $links;
	}

	private function icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10.5 5.5h3"/><path d="M9 12.5l2 2 4-4.5"/></svg>';
	}
}
