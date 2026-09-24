<?php
/**
 * The service container: does every wire plug into a socket that exists?
 *
 * This file exists because of a real failure. `Diagnostics\SelfTest` was added
 * to `AdminController` as a tenth constructor argument, and the binding in
 * `Plugin::register()` was not updated to pass it. Nothing in the test suite
 * looked at wiring, so every gate stayed green, the package shipped, and the
 * site that installed it died on every request with:
 *
 *     ArgumentCountError: Too few arguments to function
 *     AdminController::__construct(), 9 passed ... exactly 10 expected
 *
 * The container is late-bound, so a mismatch like that is invisible until the
 * service is first resolved — which, for `Http\Api`, is every request. These
 * checks read the bindings out of `Plugin.php` and compare them with the real
 * classes, using reflection so the signatures cannot drift out of date:
 *
 *   1. every binding passes exactly as many arguments as the class needs;
 *   2. every class the boot sequence resolves is bound (no "not registered");
 *   3. every `$c->make()` inside a binding points at something bound, too.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

/**
 * Split a call's argument list on the commas that belong to it.
 *
 * @param string $inner Text between the parentheses.
 * @return string[]
 */
function signa_split_args( string $inner ): array {
	$args  = array();
	$depth = 0;
	$quote = '';
	$buf   = '';
	$len   = strlen( $inner );

	for ( $i = 0; $i < $len; $i++ ) {
		$char = $inner[ $i ];

		if ( '' !== $quote ) {
			$buf .= $char;

			if ( '\\' === $char ) {
				$buf .= isset( $inner[ $i + 1 ] ) ? $inner[ ++$i ] : '';
				continue;
			}

			if ( $char === $quote ) {
				$quote = '';
			}

			continue;
		}

		if ( "'" === $char || '"' === $char ) {
			$quote = $char;
			$buf  .= $char;
			continue;
		}

		if ( '(' === $char || '[' === $char ) {
			$depth++;
		} elseif ( ')' === $char || ']' === $char ) {
			$depth--;
		}

		if ( ',' === $char && 0 === $depth ) {
			$args[] = trim( $buf );
			$buf    = '';
			continue;
		}

		$buf .= $char;
	}

	if ( '' !== trim( $buf ) ) {
		$args[] = trim( $buf );
	}

	return $args;
}

/**
 * The text between the parentheses that open at $start.
 *
 * @param string $source Full source text.
 * @param int    $start  Index of the opening parenthesis.
 */
function signa_inside( string $source, int $start ): string {
	$depth = 0;
	$quote = '';

	for ( $i = $start, $len = strlen( $source ); $i < $len; $i++ ) {
		$char = $source[ $i ];

		if ( '' !== $quote ) {
			if ( '\\' === $char ) {
				$i++;
				continue;
			}

			if ( $char === $quote ) {
				$quote = '';
			}

			continue;
		}

		if ( "'" === $char || '"' === $char ) {
			$quote = $char;
			continue;
		}

		if ( '(' === $char ) {
			$depth++;
		} elseif ( ')' === $char ) {
			$depth--;

			if ( 0 === $depth ) {
				return substr( $source, $start + 1, $i - $start - 1 );
			}
		}
	}

	return '';
}

/**
 * The services Plugin::start() resolves on every request.
 *
 * @param string $source Plugin source text.
 * @return string[]
 */
function signa_bootables( string $source ): array {
	$start = strpos( $source, 'private function bootables()' );
	$end   = strpos( $source, 'private function register()' );

	if ( false === $start || false === $end ) {
		return array();
	}

	preg_match_all(
		'/([A-Za-z][A-Za-z0-9_\\\\]*::class)/',
		substr( $source, $start, $end - $start ),
		$found
	);

	return array_map(
		static function ( $id ) {
			return 'Signa\\' . str_replace( '::class', '', $id );
		},
		array_unique( $found[1] )
	);
}

$source = file_get_contents( SIGNA_PATH . 'src/Plugin.php' );

signa_start( 'every binding feeds its class exactly what it asks for' );

/*
 * `$c->bind( Something::class, static function ( Container $c ) { ... } )`
 * with the `new` call inside it.
 */
preg_match_all(
	'/\$c->bind\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class,\s*static function\s*\(\s*Container \$c\s*\)\s*\{(.*?)\n\t\t\} \);/s',
	$source,
	$bindings,
	PREG_SET_ORDER
);

signa_check( 'the bindings were found at all', count( $bindings ) > 20 );

$bound = array();

preg_match_all( '/\$c->bind\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class\s*,/', $source, $registered );

foreach ( $registered[1] as $id ) {
	$bound[ 'Signa\\' . $id ] = true;
}

$wiring = array();

foreach ( $bindings as $binding ) {
	$bound[ 'Signa\\' . $binding[1] ] = true;
}

foreach ( $bindings as $binding ) {
	$id   = 'Signa\\' . $binding[1];
	$body = $binding[2];

	// The service this binding returns, which is what the arguments must match.
	if ( ! preg_match( '/return new ([A-Za-z][A-Za-z0-9_\\\\]*)\(/', $body, $created ) ) {
		// A factory that decides at runtime (the code store) cannot be read here.
		continue;
	}

	$class = 'Signa\\' . $created[1];
	$from  = strpos( $body, 'return new ' . $created[1] . '(' );
	$args  = signa_split_args( signa_inside( $body, $from + strlen( 'return new ' . $created[1] ) ) );

	$reflection = null;

	if ( class_exists( $class ) ) {
		$reflection = new ReflectionClass( $class );
	}

	if ( null === $reflection || ! $reflection->getConstructor() ) {
		signa_check( $class . ' exists and has a constructor', false );
		continue;
	}

	$needed = $reflection->getConstructor()->getNumberOfRequiredParameters();
	$total  = $reflection->getConstructor()->getNumberOfParameters();
	$given  = count( $args );

	signa_check(
		$class . ' is given every argument it requires' . ( $given < $needed || $given > $total ? ' (' . $given . ' given, ' . $needed . '–' . $total . ' expected)' : '' ),
		$given >= $needed && $given <= $total
	);

	$wiring[ $id ] = array(
		'class' => $class,
		'args'  => $args,
	);
}

signa_start( 'nothing the boot sequence resolves is missing' );

foreach ( signa_bootables( $source ) as $id ) {
	signa_check( $id . ' is bound before it is resolved', isset( $bound[ $id ] ) );
}

signa_start( 'and neither is anything a binding asks for' );

foreach ( $wiring as $id => $call ) {
	foreach ( $call['args'] as $arg ) {
		if ( ! preg_match( '/\$c->make\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class\s*\)/', $arg, $made ) ) {
			continue;
		}

		$target = 'Signa\\' . $made[1];

		signa_check( $target . ' (wanted by ' . $call['class'] . ') is bound', isset( $bound[ $target ] ) );
	}
}

signa_start( 'the arguments arrive in the order the constructors declare' );

foreach ( $wiring as $call ) {
	$constructor = ( new ReflectionClass( $call['class'] ) )->getConstructor();
	$types       = array();

	foreach ( $constructor->getParameters() as $parameter ) {
		$hint = $parameter->getType();

		if ( $hint instanceof ReflectionNamedType && ! $hint->isBuiltin() ) {
			$name    = str_replace( 'self', $call['class'], $hint->getName() );
			$types[] = 0 === strpos( $name, 'Signa' ) ? $name : 'Signa\\' . ltrim( $name, '\\' );
		} else {
			$types[] = '';
		}
	}

	$mismatch = array();

	foreach ( $call['args'] as $index => $arg ) {
		$given = '';

		if ( preg_match( '/\$c->make\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class\s*\)/', $arg, $made ) ) {
			$given = 'Signa\\' . $made[1];
		} else {
			continue;
		}

		if ( isset( $types[ $index ] ) && '' !== $types[ $index ] && $types[ $index ] !== $given ) {
			$mismatch[] = $call['class'] . ' #' . ( $index + 1 ) . ': ' . $types[ $index ] . ' expected, ' . $given . ' given';
		}
	}

	signa_same( $call['class'] . ' is wired in order', array(), $mismatch );
}

signa_start( 'the exact failure of 1.3.2 is reproduced here, and it is caught' );

/*
 * On 2026-09-22 the container handed AdminController nine arguments while the
 * constructor wanted ten. This builds the same nine — real objects of the
 * declared types, made without running their constructors — and then does with
 * the throw what Plugin::start() does with it: hands it to the guard.
 */
$nine = array(
	Signa\Config\Settings::class,
	Signa\Channel\Dispatcher::class,
	Signa\Otp\OtpService::class,
	Signa\Throttle\Throttle::class,
	Signa\Log\Logger::class,
	Signa\Log\LogStore::class,
	Signa\Import\Runner::class,
	Signa\Gateway\Registry::class,
	Signa\Captcha\Manager::class,
);

$objects = array();

foreach ( $nine as $type ) {
	$objects[] = ( new ReflectionClass( $type ) )->newInstanceWithoutConstructor();
}

$caught = null;

try {
	$constructor = ( new ReflectionClass( Signa\Http\AdminController::class ) )->getConstructor();
	( new ReflectionClass( Signa\Http\AdminController::class ) )->newInstanceArgs( $objects );
	unset( $constructor );
} catch ( \Throwable $error ) {
	$caught = $error;
}

signa_check( 'nine arguments where ten are required throws', $caught instanceof ArgumentCountError );
signa_check( 'and the message is the one the site showed', null !== $caught && false !== strpos( $caught->getMessage(), 'Too few arguments' ) );

if ( null !== $caught ) {
	Signa\Install\Guard::record( Signa\Http\AdminController::class, $caught );
}

signa_check( 'the guard takes it instead of the site dying', Signa\Install\Guard::failed( Signa\Http\AdminController::class ) );

$notice = new ReflectionMethod( Signa\Install\Guard::class, 'printFailures' );
$notice->setAccessible( true );

ob_start();
$notice->invoke( new Signa\Install\Guard() );
$markup = (string) ob_get_clean();

signa_check( 'and the administrator sees which service did not start', false !== strpos( $markup, 'AdminController' ) );
signa_check( 'with the way out of it', false !== strpos( $markup, 'جایگزینی با نسخهٔ بارگذاری‌شده' ) );

/* -------------------------------------------------------------------------
 * The same accident, one size smaller
 *
 * 1.3.2 died because a constructor grew an argument and its call site did not.
 * The container is guarded by reflection now, but a call *inside* the plugin —
 * `$this->card( $title, $body )` after `card()` grew a third parameter — has no
 * such net: PHP only complains when that line actually runs, which on a settings
 * screen is when an administrator opens that tab.
 *
 * This walks every plugin file, finds calls to a method of the same class, and
 * counts the arguments against the signature. It reads the source with the
 * tokenizer, so it sees the real calls and not a comment about them.
 * ---------------------------------------------------------------------- */

/**
 * The token kinds that can spell a name, on PHP 7 and on PHP 8.
 *
 * @return int[]
 */
function signa_name_tokens(): array {
	$kinds = array( T_STRING );

	if ( defined( 'T_NAME_QUALIFIED' ) ) {
		$kinds[] = T_NAME_QUALIFIED;
	}

	return $kinds;
}

/**
 * Every PHP file of the plugin, keyed by its path inside the plugin directory.
 *
 * @return array<string,string>
 */
function signa_plugin_sources(): array {
	$root    = rtrim( SIGNA_PATH, '/' ) . '/src';
	$files   = signa_php_files( $root, 2 );
	$sources = array();

	foreach ( $files as $file ) {
		$sources[ ltrim( str_replace( $root, 'src', $file ), '/' ) ] = (string) file_get_contents( $file );
	}

	ksort( $sources );

	return $sources;
}

/**
 * PHP files under a directory, two levels deep — which is every level the
 * plugin uses (`src/Gateway/Drivers/`). `glob()` rather than
 * `RecursiveDirectoryIterator`, because the test runner's filesystem answers
 * `glob()` and not every iterator it is handed.
 *
 * @return string[]
 */
function signa_php_files( string $dir, int $depth ): array {
	$found = array();

	foreach ( (array) glob( rtrim( $dir, '/' ) . '/*.php' ) as $file ) {
		$found[] = $file;
	}

	if ( $depth > 0 ) {
		foreach ( (array) glob( rtrim( $dir, '/' ) . '/*', GLOB_ONLYDIR ) as $sub ) {
			$found = array_merge( $found, signa_php_files( (string) $sub, $depth - 1 ) );
		}
	}

	return $found;
}

/**
 * Class names declared in a file, and the methods they call on themselves.
 *
 * @return array{classes:string[],calls:array<int,array{method:string,args:int,line:int}>}
 */
function signa_self_calls( string $source ): array {
	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	$namespace = '';
	$classes   = array();
	$calls     = array();
	$class     = '';

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
			$namespace = '';

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}

				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], signa_name_tokens(), true ) ) {
					$namespace .= $tokens[ $j ][1];
					continue;
				}
				if ( is_array( $tokens[ $j ] ) && T_NS_SEPARATOR === $tokens[ $j ][0] ) {
					$namespace .= '\\';
					continue;
				}
				break;
			}
		}

		if ( is_array( $token ) && T_CLASS === $token[0] ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$class     = $namespace . '\\' . $tokens[ $j ][1];
					$classes[] = $class;
					break;
				}
				if ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
					break;
				}
			}
		}

		/*
		 * `$this->method(` and `self::method(` — the shape a call takes when the
		 * callee is in the same class. A second `->` (a service on a property)
		 * is skipped, because that method is not ours.
		 */
		// `parent::__construct()` is a call to another class' method, not ours.
		$is_this = is_array( $token ) && T_VARIABLE === $token[0] && '$this' === $token[1];
		$is_self = is_array( $token ) && T_STRING === $token[0] && in_array( $token[1], array( 'self', 'static' ), true );

		if ( ! $is_this && ! $is_self ) {
			continue;
		}

		$k = $i + 1;

		while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
			$k++;
		}

		$operator = $is_this ? T_OBJECT_OPERATOR : T_DOUBLE_COLON;


		if ( ! isset( $tokens[ $k ] ) || ! is_array( $tokens[ $k ] ) || $operator !== $tokens[ $k ][0] ) {
			continue;
		}

		$k++;

		while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
			$k++;
		}

		if ( ! isset( $tokens[ $k ] ) || ! is_array( $tokens[ $k ] ) || T_STRING !== $tokens[ $k ][0] ) {
			continue;
		}

		$method = $tokens[ $k ][1];
		$line   = $tokens[ $k ][2];
		$k++;

		while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
			$k++;
		}

		if ( ! isset( $tokens[ $k ] ) || '(' !== $tokens[ $k ] ) {
			continue;
		}

		// Count the arguments at depth one.
		$depth    = 0;
		$args     = 0;
		$hasToken = false;

		for ( $m = $k; $m < $count; $m++ ) {
			$current = $tokens[ $m ];

			if ( ! is_array( $current ) ) {
				if ( in_array( $current, array( '(', '[', '{' ), true ) ) {
					$depth++;
					if ( $depth > 1 ) {
						$hasToken = true;
					}
					continue;
				}

				if ( in_array( $current, array( ')', ']', '}' ), true ) ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
					continue;
				}

				if ( ',' === $current && 1 === $depth ) {
					$args++;
					continue;
				}
			}

			if ( 1 === $depth ) {
				if ( is_array( $current ) && T_WHITESPACE === $current[0] ) {
					continue;
				}
				if ( is_array( $current ) && in_array( $current[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$hasToken = true;
			}
		}

		$calls[] = array(
			'method' => $method,
			'args'   => $hasToken ? $args + 1 : 0,
			'line'   => $line,
		);
	}

	return array( 'classes' => $classes, 'calls' => $calls );
}

signa_start( 'no call inside the plugin passes fewer arguments than the method requires' );

$thin = array();
$checked = 0;

foreach ( signa_plugin_sources() as $relative => $source ) {
	$parsed = signa_self_calls( $source );

	foreach ( $parsed['calls'] as $call ) {
		foreach ( $parsed['classes'] as $candidate ) {
			if ( ! class_exists( $candidate ) && ! interface_exists( $candidate ) && ! trait_exists( $candidate ) ) {
				continue;
			}

			$reflection = new ReflectionClass( $candidate );

			if ( ! $reflection->hasMethod( $call['method'] ) ) {
				continue;
			}

			$method = $reflection->getMethod( $call['method'] );

			$checked++;

			if ( $call['args'] < $method->getNumberOfRequiredParameters() ) {
				$thin[] = $candidate . '::' . $call['method'] . ' (' . $relative . ':' . $call['line'] . ') '
					. $call['args'] . ' arguments where ' . $method->getNumberOfRequiredParameters() . ' are required';
			}

			break;
		}
	}
}

/*
 * A walk that finds nothing because it cannot see anything is worse than no
 * walk: it turns a green gate into a false promise. So the counter is checked
 * against a call that is deliberately one argument short.
 */
$control = "<?php\nnamespace Signa\\Probe;\n\nclass Sample {\n\tprivate function needs_two( string $a, string $b ): void {}\n\tpublic function run(): void {\n\t\t\$this->needs_two( 'one' );\n\t\t\$this->needs_two( 'one', 'two' );\n\t}\n}\n";
$seen    = signa_self_calls( $control );
$counts  = array();

foreach ( $seen['calls'] as $call ) {
	if ( 'needs_two' === $call['method'] ) {
		$counts[] = $call['args'];
	}
}

signa_same( 'the walk counts the arguments of a call, including the short one', array( 1, 2 ), $counts );

signa_check( 'the walk found calls to check', $checked > 100, 'checked ' . $checked . ' calls in ' . count( signa_plugin_sources() ) . ' files' );
foreach ( array_slice( $thin, 0, 8 ) as $short ) {
	echo '        short: ' . $short . "\n";
}

signa_check( 'and none of them is short of an argument', array() === $thin, implode( '; ', array_slice( $thin, 0, 4 ) ) );

signa_finish();
