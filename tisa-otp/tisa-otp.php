<?php
/**
 * Plugin Name:       تیسا OTP — ورود و عضویت با کد یکبارمصرف
 * Plugin URI:        https://example.com/tisa-otp
 * Description:       ورود، عضویت و تأیید شماره موبایل با کد یکبارمصرف از طریق پیامک یا ایمیل؛ معماری ماژولار، کانال‌های قابل‌تعویض و محافظت چندلایه در برابر ربات.
 * Version:           1.2.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Tisa
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tisa-otp
 * Domain Path:       /languages
 *
 * @package TisaOtp
 */

defined( 'ABSPATH' ) || exit;

define( 'TISA_OTP_VERSION', '1.2.0' );
define( 'TISA_OTP_FILE', __FILE__ );
define( 'TISA_OTP_PATH', plugin_dir_path( __FILE__ ) );
define( 'TISA_OTP_URL', plugin_dir_url( __FILE__ ) );
define( 'TISA_OTP_SLUG', 'tisa-otp' );
define( 'TISA_OTP_MIN_PHP', '7.4' );

/**
 * Fail loudly but safely on unsupported PHP versions.
 */
if ( version_compare( PHP_VERSION, TISA_OTP_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: minimum PHP version */
						__( 'تیسا OTP به PHP نسخه %s یا جدیدتر نیاز دارد و فعلاً غیرفعال است.', 'tisa-otp' ),
						TISA_OTP_MIN_PHP
					)
				)
			);
		}
	);
	return;
}

require_once TISA_OTP_PATH . 'src/Autoloader.php';

TisaOtp\Autoloader::register();

register_activation_hook( __FILE__, array( TisaOtp\Install\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( TisaOtp\Install\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		TisaOtp\Plugin::boot( TISA_OTP_FILE );
	},
	5
);
