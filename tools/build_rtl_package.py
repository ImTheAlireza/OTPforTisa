#!/usr/bin/env python3
"""Assemble the upload for راست‌چین (rtl-theme.com).

راست‌چین wants one archive that holds a `Plugin/` folder with the installable zip
and a `help.pdf` next to it:

    signa-<version>-rtl-theme.zip
    ├── Plugin/
    │   └── signa.zip
    └── help.pdf

Steps, each of which refuses to continue on drift:

1. `build_package.py --check`: signa.zip and build.json must match the tree.
2. help.pdf is rebuilt from docs/help/help.fa.txt (needs reportlab,
   arabic-reshaper and python-bidi; run this script with that interpreter).
3. The help text and the plugin are scanned for personal links and contacts,
   because راست‌چین rejects a product that carries any.
4. The archive is written to docs/help/help.pdf and rtl-theme/ (the upload).

Usage:
    /tmp/pdfenv/bin/python tools/build_rtl_package.py
"""
import os
import re
import subprocess
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN_ZIP = os.path.join(ROOT, 'signa.zip')
HELP_PDF = os.path.join(ROOT, 'docs', 'help', 'help.pdf')
OUT_DIR = os.path.join(ROOT, 'rtl-theme')
STAMP = (2026, 1, 1, 0, 0, 0)

# Hosts a buyer is expected to see: SMS panels, captcha services, WordPress,
# the font licence and the documented placeholders.
ALLOWED_HOSTS = re.compile(
    r'(sms\.ir|kavenegar\.com|payamak-panel\.com|melipayamak\.com|ippanel|farazsms\.com|iranpayamak\.com|'
    r'google\.com|recaptcha\.net|hcaptcha\.com|arcaptcha\.(co|ir)|wordpress\.org|gnu\.org|'
    r'w3\.org|sil\.org|github\.com/rastikerdar/vazirmatn|\.example|example\.(com|ir|org))',
    re.I,
)
CONTACT = re.compile(r'(t\.me/|telegram\.me|instagram\.com|wa\.me/|@gmail\.|@yahoo\.|mailto:)', re.I)


def version():
    head = open(os.path.join(ROOT, 'signa', 'signa.php'), encoding='utf-8').read(4096)
    return re.search(r'Version:\s*([0-9.]+)', head).group(1)


def scan(name, text, problems):
    for url in re.findall(r'https?://[^\s\'"<>)]+', text):
        if not ALLOWED_HOSTS.search(url):
            problems.append('%s: link %s' % (name, url))
    for hit in CONTACT.findall(text):
        problems.append('%s: contact %s' % (name, hit[0] if isinstance(hit, tuple) else hit))


def main():
    subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'build_package.py'), '--check'], check=True)
    subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'build_help_pdf.py'), HELP_PDF], check=True)

    problems = []
    scan('help.fa.txt', open(os.path.join(ROOT, 'docs', 'help', 'help.fa.txt'), encoding='utf-8').read(), problems)
    with zipfile.ZipFile(PLUGIN_ZIP) as z:
        for info in z.infolist():
            if info.filename.endswith(('.php', '.txt', '.js', '.css', '.json', '.html')):
                scan(info.filename, z.read(info).decode('utf-8', 'replace'), problems)
    if problems:
        print('personal links or contacts found; راست‌چین rejects these:')
        print('\n'.join('  ' + p for p in problems))
        sys.exit(1)

    ver = version()
    os.makedirs(OUT_DIR, exist_ok=True)
    out = os.path.join(OUT_DIR, 'signa-%s-rtl-theme.zip' % ver)
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for src, arc in ((PLUGIN_ZIP, 'Plugin/signa.zip'), (HELP_PDF, 'help.pdf')):
            info = zipfile.ZipInfo(arc, STAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, open(src, 'rb').read())
    print('wrote %s (%s bytes)' % (os.path.relpath(out, ROOT), format(os.path.getsize(out), ',')))


if __name__ == '__main__':
    main()
