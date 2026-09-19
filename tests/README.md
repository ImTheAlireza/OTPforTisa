# Tests

No PHP runtime exists in this environment, so these cover the parts that can be
executed here: the front-end behaviour and the design tokens. Both are fast and
have no service dependencies.

| File | What it proves | Needs |
|---|---|---|
| `contrast.js` | Every colour pair the UI actually renders meets WCAG 1.4.3 (4.5:1 for text) and 1.4.11 (3:1 for control borders), in both the light palette and the `slate` skin. Values are read out of `front.css`, so the test fails if a token drifts. | node |
| `front.js` | The real `assets/js/front.js`, mounted on the real preview markup in jsdom and driven like a visitor: step bar, actionable errors, attempts left, code-length rebuild, "no SMS?" panel, cooldown, focus management, skip link, expiry, and the cache/nonce recovery. The backend is stubbed at the `fetch` boundary with the exact envelope `src/Http/Api.php` returns. | node + jsdom |

```bash
node tests/contrast.js
npm install --no-save jsdom && node tests/front.js
```

PHP is syntax-checked in CI with `php -l`; locally without PHP you can use
`php-parser` from npm over `tisa-otp/**/*.php`.
