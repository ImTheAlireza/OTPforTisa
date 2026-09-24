<?php
/**
 * Behaviour of the blocklist rules.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Blocklist\Blocklist;
use Signa\Blocklist\Rule;

signa_start( 'rule parsing' );

$exact = Rule::parse( '09121234567' );
signa_check( 'a full number becomes an exact rule', $exact instanceof Rule && Rule::KIND_EXACT === $exact->kind() );
signa_same( 'the exact rule stores canonical digits', '09121234567', $exact->pattern() );

$intl = Rule::parse( '+98 912 123 4567' );
signa_same( 'an international spelling folds to the same rule', '09121234567', $intl->pattern() );

$ints = Rule::parse( '00989121234567' );
signa_same( '00-prefixed spelling folds too', '09121234567', $ints->pattern() );

$persian = Rule::parse( '۰۹۱۲۱۲۳۴۵۶۷' );
signa_same( 'Persian digits are accepted', '09121234567', $persian->pattern() );

$short = Rule::parse( '0912' );
signa_check( 'a short number becomes a prefix rule', Rule::KIND_PREFIX === $short->kind() );
signa_same( 'the prefix is canonical', '0912', $short->pattern() );

$trailingStar = Rule::parse( '0912*' );
signa_check( 'a trailing star is stored as a prefix, not a pattern', Rule::KIND_PREFIX === $trailingStar->kind() );

$bare9 = Rule::parse( '912' );
signa_same( 'a bare 9… prefix gains the leading zero', '0912', $bare9->pattern() );

$intlPrefix = Rule::parse( '98912' );
signa_same( 'an international prefix gains the leading zero', '0912', $intlPrefix->pattern() );

$wild = Rule::parse( '0935*4567' );
signa_check( 'a middle star becomes a wildcard rule', Rule::KIND_WILD === $wild->kind() );

signa_check( 'a bare star is refused', null === Rule::parse( '*' ) );
signa_check( 'letter soup is refused', null === Rule::parse( 'سلام' ) );
signa_check( 'an empty entry is refused', null === Rule::parse( '   ' ) );

signa_start( 'rule matching' );

signa_check( 'exact matches exactly', $exact->matches( '09121234567' ) );
signa_check( 'exact matches nothing else', ! $exact->matches( '09121234568' ) );

signa_check( 'prefix covers its range', $short->matches( '09125550000' ) );
signa_check( 'prefix stops at its boundary', ! $short->matches( '09355550000' ) );

signa_check( 'wildcard matches the middle', $wild->matches( '09351114567' ) );
signa_check( 'wildcard rejects a wrong tail', ! $wild->matches( '09351114568' ) );
signa_check( 'wildcard rejects a wrong head', ! $wild->matches( '09121114567' ) );

$expired = Rule::parse( '09129999999', '', time() - 60 );
signa_check( 'an expired rule matches nothing', ! $expired->matches( '09129999999' ) );
signa_check( 'an expired rule reports itself', $expired->isExpired() );

$future = Rule::parse( '09129999998', '', time() + 3600 );
signa_check( 'a rule that expires later still matches', $future->matches( '09129999998' ) );

signa_start( 'blocklist store' );

$list = new Blocklist();

signa_check( 'a fresh list is empty', 0 === $list->count() );
signa_check( 'nothing is blocked yet', ! $list->blocks( '09121234567' ) );

signa_check( 'adding a number succeeds', $list->add( '09121234567', 'spam' ) );
signa_check( 'the same number is not added twice', ! $list->add( '09121234567' ) );
signa_same( 'the list counted one entry', 1, $list->count() );

signa_check( 'the number is now blocked', $list->blocks( '09121234567' ) );
signa_check( 'a different number is unaffected', ! $list->blocks( '09121234568' ) );

signa_check( 'adding a prefix succeeds', $list->add( '0990' ) );
signa_check( 'anything inside the prefix is blocked', $list->blocks( '09901234567' ) );

$match = $list->match( '09901234567' );
signa_check( 'match() reports the rule that fired', $match instanceof Rule && '0990' === $match->pattern() );

$note = $list->match( '09121234567' );
signa_same( 'the note survives a round trip', 'spam', $note->note() );

list( $added, $skipped ) = $list->addMany( "09361112233\n09361112233\n\n09121110000", 'bulk' );
signa_same( 'bulk add counted the new entries', 2, $added );
signa_same( 'bulk add counted the duplicate', 1, $skipped );

$bulk = $list->all();
$last = $bulk[0];
signa_same( 'the newest entry is first', '09121110000', $last->pattern() );

signa_check( 'removing an entry works', $list->remove( $last->id() ) );
signa_check( 'the removed entry is gone', ! $list->blocks( '09121110000' ) );
signa_check( 'removing it again reports nothing to do', ! $list->remove( $last->id() ) );

// A fresh site: the option row is shared between instances, so reset it first.
$GLOBALS['signa_options'] = array();

$expiring = new Blocklist();
$expiring->add( '09001112233', '', time() - 1 );
signa_same( 'the expired entry stays in the list for the record', 1, $expiring->count() );
signa_check( 'but it does not block', ! $expiring->blocks( '09001112233' ) );
signa_same( 'purging drops it', 1, $expiring->purgeExpired() );
signa_same( 'nothing left afterwards', 0, $expiring->count() );

signa_check( 'clearing empties the list', $list->clear() );
signa_same( 'the list is empty', 0, $list->count() );

signa_start( 'stored data is re-read, not trusted' );

$GLOBALS['signa_options'][ Blocklist::OPTION ] = array(
	'not-an-array',
	array( 'kind' => 'exact', 'pattern' => '09121234567' ),
	array( 'kind' => 'nonsense', 'pattern' => '0912' ),
	array( 'kind' => 'prefix' ),
);

$reloaded = new Blocklist();
signa_same( 'only well-formed rows survive a reload', 1, $reloaded->count() );
signa_check( 'the valid row still blocks', $reloaded->blocks( '09121234567' ) );

signa_finish();
