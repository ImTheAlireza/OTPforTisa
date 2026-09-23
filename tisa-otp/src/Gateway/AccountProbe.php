<?php
/**
 * Optional capability: asking a panel about the account, not about a message.
 *
 * A driver that implements this can answer "is the key itself accepted, is there
 * credit, and is the line I configured actually mine" without sending anything.
 * That is the difference between the two failures an owner cannot tell apart:
 * a server that cannot reach the panel, and a panel that is refusing the key,
 * the line or the empty account behind it.
 *
 * It is deliberately separate from SmsGateway: a third-party driver written
 * before this existed must keep working untouched.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

defined( 'ABSPATH' ) || exit;

interface AccountProbe {

	/**
	 * Read-only account check. Must never send a message and never change state.
	 *
	 * @return array{
	 *     ok:bool,
	 *     status:string,
	 *     value:string,
	 *     error_code:string,
	 *     reason:string,
	 *     message:string,
	 *     credit:float|null,
	 *     lines:string[],
	 *     sender:string,
	 *     sender_ok:bool|null
	 * }
	 */
	public function probe(): array;
}
