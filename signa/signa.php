<?php
/**
 * Plugin Name:       سیگنا — ورود و عضویت با کد یکبارمصرف
 * Plugin URI:        https://parsena.ir/signa/
 * Description:       ورود، عضویت و تأیید شماره موبایل با کد یکبارمصرف از طریق پیامک یا ایمیل؛ معماری ماژولار، کانال‌های قابل‌تعویض و محافظت چندلایه در برابر ربات.
 * Version:           2.0.1
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * WC requires at least: 7.1
 * WC tested up to:  11.1
 * Author:            پارسنا
 * Author URI:        https://parsena.ir/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       signa
 * Domain Path:       /languages
 *
 * @package Signa
 */

defined( 'ABSPATH' ) || exit;

define( 'SIGNA_VERSION', '2.0.1' );
define( 'SIGNA_FILE', __FILE__ );
define( 'SIGNA_PATH', plugin_dir_path( __FILE__ ) );
define( 'SIGNA_URL', plugin_dir_url( __FILE__ ) );
define( 'SIGNA_SLUG', 'signa' );
define( 'SIGNA_MIN_PHP', '7.4' );

/**
 * Fail loudly but safely on unsupported PHP versions.
 */
if ( version_compare( PHP_VERSION, SIGNA_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: minimum PHP version */
						__( 'سیگنا به PHP نسخه %s یا جدیدتر نیاز دارد و فعلاً غیرفعال است.', 'signa' ),
						SIGNA_MIN_PHP
					)
				)
			);
		}
	);
	return;
}

require_once SIGNA_PATH . 'src/Autoloader.php';

Signa\Autoloader::register();

register_activation_hook( __FILE__, array( Signa\Install\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Signa\Install\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Signa\Plugin::boot( SIGNA_FILE );
	},
	5
);
