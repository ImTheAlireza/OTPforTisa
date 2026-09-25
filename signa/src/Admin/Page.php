<?php
/**
 * The admin area's name and the capability that opens it.
 *
 * These used to live on Menu. They moved here because Menu.php is the file the
 * marketplace encodes for licensing: anything that only needs a slug or a
 * capability must be able to ask without loading an encoded file — on a host
 * without the loader, loading it would stop the request.
 *
 * @package Signa
 */

namespace Signa\Admin;

defined( 'ABSPATH' ) || exit;

final class Page {

	const CAPABILITY = 'manage_options';
	const ROOT       = 'signa';
}
