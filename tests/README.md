# Tests

The front-end and the design tokens run anywhere with node. The PHP logic runs
either in CI (real PHP 7.4 and 8.3) or locally through the WebAssembly PHP build
— see the last section.

| File | What it proves | Needs |
|---|---|---|
| `contrast.js` | Every colour pair the UI actually renders meets WCAG 1.4.3 (4.5:1 for text) and 1.4.11 (3:1 for control borders), in both the light palette and the `slate` skin. Values are read out of `front.css`, so the test fails if a token drifts. | node |
| `front.js` | The real `assets/js/front.js`, mounted on the real preview markup in jsdom and driven like a visitor: step bar, actionable errors, attempts left, code-length rebuild, "no SMS?" panel, cooldown, focus management, skip link, expiry, cache/nonce recovery, a captcha script that never loads, a form token frozen by a page cache, and pasting the code out of the SMS. The backend is stubbed at the `fetch` boundary with the exact envelope `src/Http/Api.php` returns. | node + jsdom |
| `php/blocklist-test.php` | Rule parsing (exact/prefix/wildcard, international and Persian spellings) and matching, including expiry. | PHP 7.4+ |
| `php/emergency-test.php` | The emergency code: hashing, holding a reveal, single use, revocation. | PHP 7.4+ |
| `php/trusted-test.php` | The trusted-number list: exact numbers, prefixes and patterns match (including international and Persian spellings), a bare `*` is refused so nobody trusts the whole world by accident, the blocklist can never be skipped, and the list switched off trusts nobody. | PHP 7.4+ |
| `php/screens-test.php` | The admin screen switcher: the row lists the five screens in the same order as the WordPress menu, every slug matches the class that owns it, each screen renders exactly one "you are here" marker and it is its own, and the row is a named `<nav>` list rather than a pile of links. | PHP 7.4+ |
| `php/transport-test.php` | The sentence WordPress hands back when a connection fails is classified into a cause (DNS, refused connection, TLS, timeout, blocked egress) and a Persian line that says what to do about it. Six real cURL messages are checked, including `Connection timed out`, which is a firewall and not a slow panel. | PHP 7.4+ |
| `php/smsir-test.php` | The SMS.ir driver, read against SMS.ir's own documentation: the shape of both send endpoints, `lineNumber` as a JSON number (a cast saturates on 32-bit PHP), all fifteen documented refusal codes with their own cause, a refusal hidden behind HTTP 200, the local guards, and the account probe that reads credit and lines without sending a message. | PHP 7.4+ |
| `php/breaker-test.php` | The gateway circuit breaker: three consecutive failures rest a gateway for ten minutes, a success clears it, an administrator can reset it, and — the rule that matters most — when every gateway is resting the chain still tries all of them, so a local mistake can never stop a site sending SMS. | PHP 7.4+ |

```bash
node tests/contrast.js
npm install --no-save jsdom && node tests/front.js
```

## PHP without a PHP binary

This sandbox has no `php`, so `tools/php-test.js` runs the same test files
in a real PHP build compiled to WebAssembly. Same classes, same bootstrap, same
assertions — only the interpreter is different.

```bash
npm install --no-save @php-wasm/node
node tools/php-test.js breaker-test.php               # one test file
node tools/php-test.js blocklist-test.php emergency-test.php screens-test.php
node tools/php-test.js --lint                         # compile every plugin file
TISA_PHP_VERSION=8.3 node tools/php-test.js --lint    # the version CI also runs
```

`--lint` compiles each file with `token_get_all( $source, TOKEN_PARSE )`, which
is `php -l` without executing anything, so templates that need WordPress are
still checked.

## Static checks

PHP is syntax-checked in CI with `php -l` on 7.4 and 8.3. A tokenizer alone
cannot see *compile* errors, so `tools/php-static-check.js` covers the gap —
duplicate method/property/constant declarations, abstract methods with bodies,
colliding `use` imports. It exists because a real
`Cannot redeclare CaptchaResult::passed()` fatal slipped past a tokenizer-only
lint and was only caught once CI ran `php -l`.

```bash
npm install --no-save php-parser && node tools/php-static-check.js tisa-otp
```
