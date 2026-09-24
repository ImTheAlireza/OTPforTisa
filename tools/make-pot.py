#!/usr/bin/env python3
"""
Build `signa/languages/signa.pot` from the plugin sources.

WP-CLI is not available in every environment, so this is a small, dependency-free
stand-in that understands the gettext calls this plugin actually uses:

    __()  _e()  esc_html__()  esc_html_e()  esc_attr__()  esc_attr_e()  _x()
    esc_html_x()  esc_attr_x()  _n()

It also carries `/* translators: ... */` comments through, which are the part a
naive regex scraper usually drops.

Usage:  python3 tools/make-pot.py
"""

import os
import re
import sys
from collections import OrderedDict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'signa')
OUT = os.path.join(PLUGIN, 'languages', 'signa.pot')
DOMAIN = 'signa'

HEADER = '''# Copyright (C) {year} Signa
# This file is distributed under the GPLv2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Signa {version}\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/signa\\n"
"POT-Creation-Date: {date}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: fa_IR\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\\n"
"X-Generator: Signa pot builder 1.2\\n"
"X-Domain: {domain}\\n"
'''

# A PHP single- or double-quoted string.
SQ = r"'((?:[^'\\]|\\.)*)'"
DQ = r'"((?:[^"\\]|\\.)*)"'
STR = r'(?:' + SQ + r'|' + DQ + r')'

# Every call shape, with the group indices that hold singular/plural/context.
CALLS = [
    # name, regex, (singular, plural, context) group offsets within the match
    ('simple', r'\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*'
               + STR + r'\s*,\s*' + STR + r'\s*\)', (1, 2), None, None),
    ('context', r'\b(?:_x|esc_html_x|esc_attr_x)\s*\(\s*'
                + STR + r'\s*,\s*' + STR + r'\s*,\s*' + STR + r'\s*\)', (1, 2), None, (3, 4)),
    ('plural', r'\b_n\s*\(\s*' + STR + r'\s*,\s*' + STR + r'\s*,\s*[^,]+,\s*'
               + STR + r'\s*\)', (1, 2), (3, 4), None),
]


def unescape(raw, quote):
    """Undo PHP string escaping for the quote style the literal used."""
    if quote == "'":
        return raw.replace("\\'", "'").replace('\\\\', '\\')
    out = raw
    for src, dst in (('\\n', '\n'), ('\\t', '\t'), ('\\r', '\r'),
                     ('\\"', '"'), ('\\$', '$'), ('\\\\', '\\')):
        out = out.replace(src, dst)
    return out


def pick(match, first, second):
    """Return whichever alternation branch matched, unescaped."""
    if match.group(first) is not None:
        return unescape(match.group(first), "'")
    if match.group(second) is not None:
        return unescape(match.group(second), '"')
    return None


def escape_po(value):
    out = value.replace('\\', '\\\\').replace('"', '\\"')
    return out.replace('\n', '\\n').replace('\t', '\\t').replace('\r', '')


def translator_comment(source, index):
    """
    Find a `/* translators: ... */` comment immediately above `index`.

    The lazy `(.*?)\\*/` form is wrong here — with DOTALL it happily reaches
    across an earlier comment — so the body is matched as "anything that is not
    a closing delimiter".
    """
    head = source[:index]
    match = re.search(
        r'/\*\s*(translators:(?:[^*]|\*(?!/))*)\*/\s*$',
        head,
        re.IGNORECASE,
    )
    if not match:
        # Also allow one line of code (e.g. `sprintf(`) between comment and call.
        match = re.search(
            r'/\*\s*(translators:(?:[^*]|\*(?!/))*)\*/\s*\n?[^\n]*\n?\s*$',
            head,
            re.IGNORECASE,
        )
    if not match:
        return None
    body = ' '.join(match.group(1).split())
    return body


def collect():
    entries = OrderedDict()
    files = []
    for base, dirs, names in os.walk(PLUGIN):
        dirs[:] = [d for d in dirs if d not in ('node_modules', '.git', 'languages')]
        for name in sorted(names):
            if name.endswith('.php'):
                files.append(os.path.join(base, name))

    for filepath in sorted(files):
        with open(filepath, encoding='utf-8') as handle:
            source = handle.read()
        rel = os.path.relpath(filepath, PLUGIN).replace(os.sep, '/')

        for _name, pattern, sing, plural, ctx in CALLS:
            for match in re.finditer(pattern, source):
                # The last string argument is the text domain; skip other plugins'.
                text = pick(match, *sing)
                if text is None:
                    continue

                domain_groups = match.groups()
                if DOMAIN not in [g for g in domain_groups if g]:
                    continue

                context = pick(match, *ctx) if ctx else None
                plural_text = pick(match, *plural) if plural else None

                line = source.count('\n', 0, match.start()) + 1
                key = (context or '', text, plural_text or '')

                entry = entries.setdefault(key, {
                    'context': context,
                    'text': text,
                    'plural': plural_text,
                    'refs': [],
                    'comment': None,
                })
                entry['refs'].append('%s:%d' % (rel, line))
                if not entry['comment']:
                    entry['comment'] = translator_comment(source, match.start())

    return entries


def plugin_meta():
    with open(os.path.join(PLUGIN, 'signa.php'), encoding='utf-8') as handle:
        head = handle.read(4000)
    version = re.search(r'^\s*\*\s*Version:\s*(.+)$', head, re.M)
    return version.group(1).strip() if version else '0.0.0'


def main():
    import datetime

    entries = collect()
    now = datetime.datetime.utcnow()
    out = [HEADER.format(
        year=now.year,
        version=plugin_meta(),
        date=now.strftime('%Y-%m-%d %H:%M+0000'),
        domain=DOMAIN,
    )]

    for entry in entries.values():
        out.append('')
        if entry['comment']:
            out.append('#. ' + entry['comment'])
        for ref in entry['refs']:
            out.append('#: ' + ref)
        if entry['context']:
            out.append('msgctxt "%s"' % escape_po(entry['context']))
        out.append('msgid "%s"' % escape_po(entry['text']))
        if entry['plural']:
            out.append('msgid_plural "%s"' % escape_po(entry['plural']))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        else:
            out.append('msgstr ""')

    with open(OUT, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(out) + '\n')

    print('wrote %s with %d entries' % (os.path.relpath(OUT, ROOT), len(entries)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
