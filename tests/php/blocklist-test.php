<?php
/**
 * Behaviour of the blocklist rules.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Blocklist\Blocklist;
use TisaOtp\Blocklist\Rule;

tisa_start( 'rule parsing' );

$exact = Rule::parse( '09121234567' );
tisa_check( 'a full number becomes an exact rule', $exact instanceof Rule && Rule::KIND_EXACT === $exact->kind() );
tisa_same( 'the exact rule stores canonical digits', '09121234567', $exact->pattern() );

$intl = Rule::parse( '+98 912 123 4567' );
tisa_same( 'an international spelling folds to the same rule', '09121234567', $intl->pattern() );

$ints = Rule::parse( '00989121234567' );
tisa_same( '00-prefixed spelling folds too', '09121234567', $ints->pattern() );

$persian = Rule::parse( '۰۹۱۲۱۲۳۴۵۶۷' );
tisa_same( 'Persian digits are accepted', '09121234567', $persian->pattern() );

$short = Rule::parse( '0912' );
tisa_check( 'a short number becomes a prefix rule', Rule::KIND_PREFIX === $short->kind() );
tisa_same( 'the prefix is canonical', '0912', $short->pattern() );

$trailingStar = Rule::parse( '0912*' );
tisa_check( 'a trailing star is stored as a prefix, not a pattern', Rule::KIND_PREFIX === $trailingStar->kind() );

$bare9 = Rule::parse( '912' );
tisa_same( 'a bare 9… prefix gains the leading zero', '0912', $bare9->pattern() );

$intlPrefix = Rule::parse( '98912' );
tisa_same( 'an international prefix gains the leading zero', '0912', $intlPrefix->pattern() );

$wild = Rule::parse( '0935*4567' );
tisa_check( 'a middle star becomes a wildcard rule', Rule::KIND_WILD === $wild->kind() );

tisa_check( 'a bare star is refused', null === Rule::parse( '*' ) );
tisa_check( 'letter soup is refused', null === Rule::parse( 'سلام' ) );
tisa_check( 'an empty entry is refused', null === Rule::parse( '   ' ) );

tisa_start( 'rule matching' );

tisa_check( 'exact matches exactly', $exact->matches( '09121234567' ) );
tisa_check( 'exact matches nothing else', ! $exact->matches( '09121234568' ) );

tisa_check( 'prefix covers its range', $short->matches( '09125550000' ) );
tisa_check( 'prefix stops at its boundary', ! $short->matches( '09355550000' ) );

tisa_check( 'wildcard matches the middle', $wild->matches( '09351114567' ) );
tisa_check( 'wildcard rejects a wrong tail', ! $wild->matches( '09351114568' ) );
tisa_check( 'wildcard rejects a wrong head', ! $wild->matches( '09121114567' ) );

$expired = Rule::parse( '09129999999', '', time() - 60 );
tisa_check( 'an expired rule matches nothing', ! $expired->matches( '09129999999' ) );
tisa_check( 'an expired rule reports itself', $expired->isExpired() );

$future = Rule::parse( '09129999998', '', time() + 3600 );
tisa_check( 'a rule that expires later still matches', $future->matches( '09129999998' ) );

tisa_start( 'blocklist store' );

$list = new Blocklist();

tisa_check( 'a fresh list is empty', 0 === $list->count() );
tisa_check( 'nothing is blocked yet', ! $list->blocks( '09121234567' ) );

tisa_check( 'adding a number succeeds', $list->add( '09121234567', 'spam' ) );
tisa_check( 'the same number is not added twice', ! $list->add( '09121234567' ) );
tisa_same( 'the list counted one entry', 1, $list->count() );

tisa_check( 'the number is now blocked', $list->blocks( '09121234567' ) );
tisa_check( 'a different number is unaffected', ! $list->blocks( '09121234568' ) );

tisa_check( 'adding a prefix succeeds', $list->add( '0990' ) );
tisa_check( 'anything inside the prefix is blocked', $list->blocks( '09901234567' ) );

$match = $list->match( '09901234567' );
tisa_check( 'match() reports the rule that fired', $match instanceof Rule && '0990' === $match->pattern() );

$note = $list->match( '09121234567' );
tisa_same( 'the note survives a round trip', 'spam', $note->note() );

list( $added, $skipped ) = $list->addMany( "09361112233\n09361112233\n\n09121110000", 'bulk' );
tisa_same( 'bulk add counted the new entries', 2, $added );
tisa_same( 'bulk add counted the duplicate', 1, $skipped );

$bulk = $list->all();
$last = $bulk[0];
tisa_same( 'the newest entry is first', '09121110000', $last->pattern() );

tisa_check( 'removing an entry works', $list->remove( $last->id() ) );
tisa_check( 'the removed entry is gone', ! $list->blocks( '09121110000' ) );
tisa_check( 'removing it again reports nothing to do', ! $list->remove( $last->id() ) );

// A fresh site: the option row is shared between instances, so reset it first.
$GLOBALS['tisa_options'] = array();

$expiring = new Blocklist();
$expiring->add( '09001112233', '', time() - 1 );
tisa_same( 'the expired entry stays in the list for the record', 1, $expiring->count() );
tisa_check( 'but it does not block', ! $expiring->blocks( '09001112233' ) );
tisa_same( 'purging drops it', 1, $expiring->purgeExpired() );
tisa_same( 'nothing left afterwards', 0, $expiring->count() );

tisa_check( 'clearing empties the list', $list->clear() );
tisa_same( 'the list is empty', 0, $list->count() );

tisa_start( 'stored data is re-read, not trusted' );

$GLOBALS['tisa_options'][ Blocklist::OPTION ] = array(
	'not-an-array',
	array( 'kind' => 'exact', 'pattern' => '09121234567' ),
	array( 'kind' => 'nonsense', 'pattern' => '0912' ),
	array( 'kind' => 'prefix' ),
);

$reloaded = new Blocklist();
tisa_same( 'only well-formed rows survive a reload', 1, $reloaded->count() );
tisa_check( 'the valid row still blocks', $reloaded->blocks( '09121234567' ) );

tisa_finish();
