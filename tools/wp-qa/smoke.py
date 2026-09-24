#!/usr/bin/env python3
"""
End-to-end smoke test of Signa inside a real WordPress (WordPress Playground).

Started by tools/wp-qa/run.sh, which boots WordPress with the plugin mounted
and signa-qa.php as a must-use plugin. Every step talks to WordPress over HTTP
the way a browser does, and the run fails if any PHP error, warning, notice or
deprecation was logged on the way.

    python3 tools/wp-qa/smoke.py http://127.0.0.1:9400 /path/to/wordpress
"""
import html
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.parse
from html.parser import HTMLParser

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:9400'
WP_DIR = sys.argv[2] if len(sys.argv) > 2 else ''
TMP = tempfile.mkdtemp(prefix='signa-qa-')
ADMIN = os.path.join(TMP, 'admin.jar')
VISITOR = os.path.join(TMP, 'visitor.jar')
failed = 0


def check(label, ok, detail=''):
    global failed
    if not ok:
        failed += 1
    print(('  ok    ' if ok else '  FAIL  ') + label + (('  — ' + str(detail)) if detail and not ok else ''))


def req(path, data=None, headers=None, jar=ADMIN):
    url = path if path.startswith('http') else BASE + path
    cmd = ['curl', '-s', '-b', jar, '-c', jar, '-D', TMP + '/h', '-o', TMP + '/b', url]
    for key, value in (headers or {}).items():
        cmd += ['-H', key + ': ' + value]
    if data is not None:
        if isinstance(data, (list, dict)):
            data = urllib.parse.urlencode([tuple(x) for x in data] if isinstance(data, list) else data).encode()
        open(TMP + '/post', 'wb').write(data)
        cmd += ['--data-binary', '@' + TMP + '/post']
    subprocess.run(cmd, check=True)
    head = re.split(r'\r?\n\r?\n', open(TMP + '/h', encoding='latin1').read().strip())[-1].splitlines()
    status = int(head[0].split()[1])
    heads = {}
    for line in head[1:]:
        if ':' in line:
            key, value = line.split(':', 1)
            heads[key.strip().lower()] = value.strip()
    return status, heads, open(TMP + '/b', encoding='utf8', errors='replace').read()


class SettingsForm(HTMLParser):
    """Collects what a browser would submit from form#signa-settings."""

    def __init__(self):
        super().__init__()
        self.inside = self.template = False
        self.action = None
        self.fields = []
        self.select = None
        self.textarea = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form' and a.get('id') == 'signa-settings':
            self.inside, self.action = True, html.unescape(a.get('action', ''))
            return
        if not self.inside:
            return
        if tag == 'template':
            self.template = True
        if self.template:
            return
        if tag == 'input' and a.get('name'):
            kind = a.get('type', 'text')
            if kind in ('submit', 'button', 'file') or (kind in ('checkbox', 'radio') and 'checked' not in a):
                return
            self.fields.append((a['name'], a.get('value', 'on')))
        elif tag == 'select' and a.get('name'):
            self.select = {'name': a['name'], 'first': None, 'picked': [], 'multiple': 'multiple' in a}
        elif tag == 'option' and self.select is not None:
            value = a.get('value', '')
            if self.select['first'] is None:
                self.select['first'] = value
            if 'selected' in a:
                self.select['picked'].append(value)
        elif tag == 'textarea' and a.get('name'):
            self.textarea = [a['name'], '']

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea[1] += data

    def handle_endtag(self, tag):
        if tag == 'template':
            self.template = False
        if not self.inside:
            return
        if tag == 'select' and self.select:
            s = self.select
            values = s['picked'] if s['multiple'] else (s['picked'][-1:] or [s['first'] or ''])
            self.fields += [(s['name'], v) for v in values]
            self.select = None
        elif tag == 'textarea' and self.textarea is not None:
            self.fields.append((self.textarea[0], html.unescape(self.textarea[1].lstrip('\n'))))
            self.textarea = None
        elif tag == 'form':
            self.inside = False


def form_of(page):
    parser = SettingsForm()
    parser.feed(page)
    return parser


def local(url):
    return re.sub(r'^https?://[^/]+', BASE, url)


def qa_log():
    path = os.path.join(WP_DIR, 'wp-content', 'signa-qa.log')
    return open(path, encoding='utf8').read().splitlines() if WP_DIR and os.path.exists(path) else []


def sms_log():
    path = os.path.join(WP_DIR, 'wp-content', 'signa-qa-sms.log')
    return open(path, encoding='utf8').read().splitlines() if WP_DIR and os.path.exists(path) else []



def fresh(jar):
    # The blueprint has no auto-login step: a visitor is a visitor until wp-login.php.
    open(jar, 'w').write('# Netscape HTTP Cookie File\n')


for path in (ADMIN, VISITOR):
    fresh(path)

print('== WordPress ==')
for _ in range(3):  # the server may answer its very first request with a bounce
    status, _, version = req('/?signa_qa_php=1', jar=VISITOR)
    if status == 200:
        break
check('the QA helper answers', status == 200 and 'WP' in version, version[:120])
print('  ' + version)

print('== sign in as the administrator ==')
req('/wp-login.php')
status, heads, _ = req('/wp-login.php', {'log': 'admin', 'pwd': 'password', 'wp-submit': 'Log In', 'testcookie': '1'})
check('wp-login accepts admin/password', status == 302, status)

print('== every admin screen renders ==')
screens = ['page=signa'] + ['page=signa&tab=' + t for t in ('login', 'channels', 'formskin', 'security', 'integ', 'reports', 'advanced')] \
    + ['page=signa-logs', 'page=signa-tools', 'page=signa-access', 'page=signa-reports']
for screen in screens:
    status, _, page = req('/wp-admin/admin.php?' + screen)
    ok = status == 200 and 'signa-wrap' in page and not re.search(r'(Fatal error|Warning|Notice|Deprecated)</b>:', page)
    check(screen, ok, status)

print('== settings survive a save through options.php ==')
status, _, page = req('/wp-admin/admin.php?page=signa&tab=formskin')
form = form_of(page)
names = [k for k, _ in form.fields]
check('the form carries the options nonce and referer', '_wpnonce' in names and '_wp_http_referer' in names and 'option_page' in names)
marker = 'آزمون سیگنا ' + str(int(time.time()))
posted = [(k, marker if k == 'signa_settings[form_heading]' else v) for k, v in form.fields]
status, heads, _ = req(local(form.action), posted)
check('options.php redirects back with settings-updated', status == 302 and 'settings-updated=true' in heads.get('location', ''), (status, heads))
status, _, page2 = req('/wp-admin/admin.php?page=signa&tab=formskin')
again = form_of(page2)
check('the new value is on the page', marker in page2)
scrub = lambda fields: sorted((k, v) for k, v in fields if k not in ('_wpnonce', '_wp_http_referer', 'signa_settings[form_heading]'))
drift = sorted(set(scrub(form.fields)) ^ set(scrub(again.fields)))
check('nothing else moved in the round trip', not drift, drift[:6])

print('== live preview ==')
match = re.search(r'data-signa-preview data-url="([^"]+)"', page2)
check('the preview has an address', bool(match))
if match:
    url = local(html.unescape(match.group(1)))
    status, _, frame = req(url)
    check('GET draws the saved form', status == 200 and marker in frame, status)
    draft = [(k, v) for k, v in again.fields if k not in ('_wpnonce', '_wp_http_referer', 'option_page', 'action')]
    draft = [(k, 'پیش‌نویس' if k == 'signa_settings[form_heading]' else ('#ff0055' if k == 'signa_settings[accent]' else v)) for k, v in draft]
    status, _, frame = req(url, draft + [('step', 'code')])
    check('POST draws the unsaved draft', status == 200 and 'پیش‌نویس' in frame and '#ff0055' in frame.lower())
    check('on the requested step', 'data-signa-step="code"' in frame)

print('== self-tests ==')
nonce = re.search(r'signaOtpAdmin = \{"restUrl":"[^"]*","nonce":"([^"]+)"', page2)
check('admin.js is handed a REST nonce', bool(nonce))
for kind in ('general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data'):
    status, _, body = req('/wp-json/signa/v1/admin/check', json.dumps({'kind': kind}).encode(),
                          {'Content-Type': 'application/json', 'X-WP-Nonce': nonce.group(1) if nonce else ''})
    try:
        data = json.loads(body)
        data = data.get('data', data)
        rows = data.get('rows') or data.get('steps') or []
    except ValueError:
        rows = []
    check('check ' + kind, status == 200 and len(rows) > 0, status)

print('== a visitor signs up with a code ==')
req('/?signa_qa_setup=1', jar=VISITOR)
status, _, pages = req('/wp-json/wp/v2/pages?slug=signa-login', jar=VISITOR)
page_id = (re.search(r'"id":(\d+)', pages) or [None, None])[1]
if not page_id:
    wp_nonce = re.search(r'wpApiSettings = \{[^}]*"nonce":"([^"]+)"', page2)
    status, _, made = req('/wp-json/wp/v2/pages', json.dumps({'title': 'ورود', 'slug': 'signa-login', 'content': '[signa_form]', 'status': 'publish'}).encode(),
                          {'Content-Type': 'application/json', 'X-WP-Nonce': wp_nonce.group(1) if wp_nonce else ''})
    page_id = (re.search(r'"id":(\d+)', made) or [None, None])[1]
check('a page with [signa_form] exists', bool(page_id))
fresh(VISITOR)
subprocess.run(['curl', '-s', '-L', '-b', VISITOR, '-c', VISITOR, '-o', TMP + '/front', BASE + '/?page_id=' + str(page_id)], check=True)
front = open(TMP + '/front', encoding='utf8').read()
token = re.search(r'data-form-token="([^"]+)"', front)
fnonce = re.search(r'signaOtp = \{[^;]*?"nonce":"([^"]+)"', front)
check('the form renders for a visitor', 'data-signa-form' in front and bool(token))
check('with its stylesheet and script', 'signa/assets/css/front.css' in front and 'signa/assets/js/front.js' in front)
phone = '0912' + str(int(time.time()))[-7:]
base = {'phone': phone, 'channel': 'sms', 'signa_hp': '', 'signa_ts': str(int(time.time()) - 15), 'signa_ft': token.group(1) if token else ''}


def api(route, extra=None):
    body = dict(base, **(extra or {}))
    cmd = ['curl', '-s', '-b', VISITOR, '-c', VISITOR, '-H', 'Content-Type: application/json',
           '-H', 'X-WP-Nonce: ' + (fnonce.group(1) if fnonce else ''),
           '--data-binary', json.dumps(body), BASE + '/wp-json/signa/v1/' + route]
    out = subprocess.run(cmd, check=True, capture_output=True).stdout.decode('utf8', 'replace')
    try:
        return json.loads(out)
    except ValueError:
        return {'success': False, 'raw': out[:200]}


fields = {'first_name': 'علی', 'last_name': 'رضایی'}
start = api('start')
check('start asks a new number for the signup fields', start.get('success') and start['data'].get('step') == 'register_form', start)
sent = api('register', {'fields': fields})
check('register sends the code', sent.get('success') and sent['data'].get('step') == 'verify', sent)
if not sent.get('success'):
    print('  settings now: ' + req('/?signa_qa_opts=1', jar=VISITOR)[2][:1500])
code = ((re.findall(r'"value":"(\d+)"', sms_log()[-1]) if sms_log() else []) or [''])[-1]
check('the code went to sms.ir in the documented shape', bool(code) and phone in (sms_log() or [''])[-1])
wrong = api('verify', {'code': '0' * len(code) if code != '0' * len(code) else '1' * len(code), 'fields': fields})
check('a wrong code is refused', not wrong.get('success'), wrong)
done = api('verify', {'code': code, 'fields': fields})
check('the right code creates the account and signs in', done.get('success') and done['data'].get('step') == 'signed_in', done)

if ' WC ' in version:
    print('== WooCommerce ==')
    woo = json.loads(req('/?signa_qa_woo=1', jar=VISITOR)[2] or '{}')
    check('Signa declares HPOS compatibility', 'signa/signa.php' in (woo.get('hpos') or []), woo.get('hpos'))
    fresh(VISITOR)
    status, _, account = req(local(woo.get('account') or '/'), jar=VISITOR)
    check('My Account shows the Signa form to a visitor', status == 200 and 'data-signa-form' in account, status)
    check('instead of the WooCommerce password form', 'woocommerce-form-login' not in account)

print('== the dashboard counts it ==')
status, _, dash = req('/wp-admin/admin.php?page=signa')
values = re.findall(r'signa-kpi__value">([^<]*)', dash)
check('requests and successes are counted', len(values) >= 2 and values[0] not in ('0', '') and values[1] not in ('0', ''), values)

print('== uninstall with "wipe" leaves nothing of the plugin ==')
status, _, left = req('/?signa_qa_uninstall=1', jar=VISITOR)
try:
    left = json.loads(left)
except ValueError:
    left = {'raw': left[:200]}
check('its tables are dropped', left.get('before') and left.get('tables') == [], left)
check('its options and transients are gone', left.get('opts') == [], left.get('opts'))
check('the phone numbers on profiles stay (they are the site\'s data)', 'signa_phone' in (left.get('meta') or []))
req('/wp-admin/admin.php?page=signa')  # the next load puts the tables back

print('== PHP stayed quiet ==')
noise = [line for line in qa_log() if 'qa ping' not in line]
check('no error, warning, notice or deprecation was logged', not noise, '\n        '.join(noise[:8]))

print('\n%s' % ('%d FAILED' % failed if failed else 'all smoke checks passed'))
sys.exit(1 if failed else 0)
