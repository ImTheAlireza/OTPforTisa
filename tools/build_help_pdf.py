#!/usr/bin/env python3
"""Build help.pdf (the راست‌چین help file) from docs/help/help.fa.txt.

Needs: reportlab, arabic-reshaper, python-bidi (a venv is enough):
    python3 -m venv /tmp/pdfenv
    /tmp/pdfenv/bin/pip install reportlab arabic-reshaper python-bidi
    /tmp/pdfenv/bin/python tools/build_help_pdf.py [out.pdf]

Source syntax (one block per line group):
    # title        cover title (first line only)
    ## heading     section heading (starts a new page if little room is left)
    ### heading    sub heading
    - item         bullet
    1. item        numbered item (the number is shown in Persian digits)
    > text         note box
    ```            fenced code block, printed left-to-right
    anything else  paragraph; a blank line ends it

ReportLab draws glyphs left-to-right and knows nothing about Arabic shaping, so
every line is shaped with arabic_reshaper, wrapped by measuring the shaped
words, and only then reordered with bidi, one line at a time.  Reordering a
whole paragraph first would scramble the words across lines.
"""
import os
import re
import sys

import arabic_reshaper
# The legacy implementation mirrors brackets and «» in RTL runs; the newer
# top-level get_display does not, which prints ) and « facing the wrong way.
from bidi.algorithm import get_display
from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import A4
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCE = os.path.join(ROOT, 'docs', 'help', 'help.fa.txt')
FONTS = os.path.join(ROOT, 'tools', 'fonts')

pdfmetrics.registerFont(TTFont('Vazir', os.path.join(FONTS, 'Vazirmatn-Regular.ttf')))
pdfmetrics.registerFont(TTFont('VazirBold', os.path.join(FONTS, 'Vazirmatn-Bold.ttf')))

W, H = A4
MARGIN = 56
RIGHT = W - MARGIN
LEFT = MARGIN
WIDTH = RIGHT - LEFT
TOP = H - 64
BOTTOM = 60

INK = HexColor('#1f2433')
MUTED = HexColor('#6b7185')
ACCENT = HexColor('#4f46e5')
SOFT = HexColor('#eef0fb')
CODE_BG = HexColor('#f4f5f8')
RULE = HexColor('#dfe2ea')

FA_DIGITS = str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')

_reshaper = arabic_reshaper.ArabicReshaper(configuration={'delete_harakat': True})


def version():
    head = open(os.path.join(ROOT, 'signa', 'signa.php'), encoding='utf-8').read(4096)
    return re.search(r'Version:\s*([0-9.]+)', head).group(1)


def shape(text):
    # The font has no arrows and the reshaper drops the hamza mark of «هٔ»,
    # so use the precomposed «ۀ» and a chevron (mirrored to ‹ in RTL).
    text = text.replace('\u0647\u0654', '\u06c0').replace('←', '›')
    return _reshaper.reshape(text)


def visual(text, base='R'):
    return get_display(shape(text), base_dir=base)


def width(text, font, size):
    return pdfmetrics.stringWidth(shape(text), font, size)


def wrap(text, font, size, room):
    """Greedy wrap in logical order, measured on shaped text."""
    lines, line = [], ''
    for word in text.split():
        trial = word if not line else line + ' ' + word
        if width(trial, font, size) <= room or not line:
            line = trial
        else:
            lines.append(line)
            line = word
    if line:
        lines.append(line)
    return lines


def wrap_code(text, font, size, room):
    """Code wraps after commas or spaces, else hard at the edge."""
    out = []
    while pdfmetrics.stringWidth(text, font, size) > room:
        cut = len(text)
        while cut > 1 and pdfmetrics.stringWidth(text[:cut], font, size) > room:
            cut -= 1
        soft = max(text.rfind(',', 0, cut), text.rfind(' ', 0, cut))
        if soft > cut // 2:
            cut = soft + 1
        out.append(text[:cut])
        text = '    ' + text[cut:].lstrip()
    out.append(text)
    return out


def parse(source):
    blocks, para, code = [], [], None
    def flush():
        if para:
            blocks.append(('p', ' '.join(para)))
            para.clear()
    for raw in source.splitlines():
        line = raw.rstrip()
        if code is not None:
            if line.startswith('```'):
                blocks.append(('code', code))
                code = None
            else:
                code.append(line)
            continue
        if line.startswith('```'):
            flush()
            code = []
        elif not line.strip():
            flush()
        elif line.startswith('### '):
            flush(); blocks.append(('h3', line[4:]))
        elif line.startswith('## '):
            flush(); blocks.append(('h2', line[3:]))
        elif line.startswith('# '):
            flush(); blocks.append(('h1', line[2:]))
        elif line.startswith('- '):
            flush(); blocks.append(('li', line[2:]))
        elif re.match(r'^\d+\. ', line):
            flush()
            num, rest = line.split('. ', 1)
            blocks.append(('ol', (num.translate(FA_DIGITS), rest)))
        elif line.startswith('> '):
            flush(); blocks.append(('note', line[2:]))
        else:
            para.append(line.strip())
    flush()
    return blocks


class Doc:
    def __init__(self, path, ver):
        self.c = canvas.Canvas(path, pagesize=A4)
        self.c.setTitle('راهنمای سیگنا')
        self.c.setAuthor('پارسنا')
        self.c.setSubject('Signa %s' % ver)
        self.ver = ver
        self.page = 0
        self.y = TOP

    def new_page(self, footer=True):
        if self.page:
            self.c.showPage()
        self.page += 1
        self.y = TOP
        if footer:
            c = self.c
            c.setStrokeColor(RULE)
            c.setLineWidth(0.6)
            c.line(LEFT, BOTTOM - 18, RIGHT, BOTTOM - 18)
            c.setFillColor(MUTED)
            c.setFont('Vazir', 8.5)
            c.drawRightString(RIGHT, BOTTOM - 32, visual('راهنمای سیگنا — نسخهٔ ' + self.ver.translate(FA_DIGITS)))
            c.drawString(LEFT, BOTTOM - 32, visual(str(self.page).translate(FA_DIGITS)))

    def need(self, h):
        if self.y - h < BOTTOM:
            self.new_page()

    def text_lines(self, text, font, size, lead, right, room, color=INK):
        lines = wrap(text, font, size, room)
        c = self.c
        for i, ln in enumerate(lines):
            self.need(lead)
            c.setFillColor(color)
            c.setFont(font, size)
            c.drawRightString(right, self.y - size, visual(ln))
            self.y -= lead
        return lines

    def cover(self, title):
        self.new_page(footer=False)
        c = self.c
        c.setFillColor(ACCENT)
        c.rect(0, H - 250, W, 250, stroke=0, fill=1)
        c.setFillColor(HexColor('#ffffff'))
        c.setFont('VazirBold', 40)
        c.drawRightString(RIGHT, H - 130, visual('سیگنا'))
        c.setFont('Vazir', 15)
        c.drawRightString(RIGHT, H - 165, visual('سیگنال بده، وارد شو.'))
        c.setFont('Vazir', 11)
        c.drawRightString(RIGHT, H - 205, visual('ورود و عضویت با کد یک‌بارمصرف برای وردپرس و ووکامرس'))
        self.y = H - 310
        self.text_lines(title, 'VazirBold', 20, 34, RIGHT, WIDTH)
        self.text_lines('نسخهٔ ' + self.ver.translate(FA_DIGITS), 'Vazir', 12, 24, RIGHT, WIDTH, MUTED)
        self.y -= 10

    def block(self, kind, value):
        c = self.c
        if kind == 'h2':
            if self.y < TOP - 10 and self.y - 150 < BOTTOM:
                self.new_page()
            self.y -= 14
            self.need(40)
            c.setFillColor(ACCENT)
            c.rect(RIGHT - 4, self.y - 22, 4, 22, stroke=0, fill=1)
            self.text_lines(value, 'VazirBold', 15.5, 30, RIGHT - 12, WIDTH - 12)
            self.y -= 2
        elif kind == 'h3':
            self.y -= 6
            self.need(60)
            self.text_lines(value, 'VazirBold', 12, 22, RIGHT, WIDTH, ACCENT)
        elif kind == 'p':
            self.text_lines(value, 'Vazir', 10.5, 20, RIGHT, WIDTH)
            self.y -= 6
        elif kind in ('li', 'ol'):
            label, text = ('•', value) if kind == 'li' else (value[0] + '.', value[1])
            self.need(20)
            c.setFillColor(ACCENT)
            c.setFont('VazirBold', 10.5)
            c.drawRightString(RIGHT - 2, self.y - 10.5, visual(label))
            self.text_lines(text, 'Vazir', 10.5, 20, RIGHT - 18, WIDTH - 18)
            self.y -= 2
        elif kind == 'note':
            lines = wrap(value, 'Vazir', 10, WIDTH - 28)
            h = len(lines) * 19 + 16
            self.need(h)
            c.setFillColor(SOFT)
            c.roundRect(LEFT, self.y - h, WIDTH, h, 6, stroke=0, fill=1)
            c.setFillColor(ACCENT)
            c.rect(RIGHT - 3, self.y - h, 3, h, stroke=0, fill=1)
            self.y -= 8
            for ln in lines:
                c.setFillColor(INK)
                c.setFont('Vazir', 10)
                c.drawRightString(RIGHT - 14, self.y - 10, visual(ln))
                self.y -= 19
            self.y -= 14
        elif kind == 'code':
            size, lead = 8.6, 14
            lines = []
            for ln in value:
                lines += wrap_code(ln, 'Vazir', size, WIDTH - 24)
            h = len(lines) * lead + 16
            self.need(h)
            c.setFillColor(CODE_BG)
            c.roundRect(LEFT, self.y - h, WIDTH, h, 5, stroke=0, fill=1)
            self.y -= 8
            for ln in lines:
                c.setFillColor(INK)
                c.setFont('Vazir', size)
                c.drawString(LEFT + 12, self.y - size - 1, visual(ln, base='L'))
                self.y -= lead
            self.y -= 16

    def save(self):
        self.c.showPage()
        self.c.save()


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'help.pdf')
    blocks = parse(open(SOURCE, encoding='utf-8').read())
    doc = Doc(out, version())
    first = blocks.pop(0) if blocks and blocks[0][0] == 'h1' else ('h1', 'راهنما')
    doc.cover(first[1])
    for kind, value in blocks:
        doc.block(kind, value)
    doc.save()
    print('wrote %s (%d pages)' % (os.path.relpath(out, ROOT), doc.page))


if __name__ == '__main__':
    main()
