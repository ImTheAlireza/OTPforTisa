#!/usr/bin/env python3
"""Build the installable package, and describe it so it can check itself.

Two things went wrong in this repository that this script exists to prevent:

1. `tisa-otp.zip` was committed several releases out of date, so the file
   somebody downloaded was not the code that had just been written.
2. A file was added to a constructor without updating the container binding.
   Everything in the repository was consistent, so no test noticed — but the
   package on the site fatals when the first request resolves that service.

So the package now carries `build.json`: the release version and a sha256 for
every file in it. The plugin verifies that manifest at runtime (`Install\Package`),
which turns "half the files were replaced" from a white screen into a specific
message naming the files. `--check` verifies the same thing here, in CI, before
a human ever downloads anything.

Usage:

    python3 tools/build_package.py            # refresh build.json and the zip
    python3 tools/build_package.py --check     # verify only; exit 1 on drift

The zip is written deterministically (sorted walk, fixed timestamps, level 9)
so rebuilding an unchanged tree produces a byte-identical file.
"""

import hashlib
import json
import os
import re
import sys
import time
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'tisa-otp')
ARCHIVE = os.path.join(ROOT, 'tisa-otp.zip')
MANIFEST = os.path.join(PLUGIN, 'build.json')

# A fixed stamp keeps the archive reproducible; the release version, not the
# clock, is what identifies a build here.
STAMP = time.localtime(time.mktime(time.strptime('2026-09-22 05:36:00', '%Y-%m-%d %H:%M:%S')))[:6]


def plugin_version():
    with open(os.path.join(PLUGIN, 'tisa-otp.php'), encoding='utf-8') as handle:
        source = handle.read()

    match = re.search(r"define\(\s*'TISA_OTP_VERSION',\s*'([^']+)'\s*\)", source)

    if not match:
        raise SystemExit('tisa-otp.php does not declare TISA_OTP_VERSION')

    return match.group(1)


def relative_files():
    """Every file in the plugin, plugin-relative, in a stable order."""
    found = []

    for base, dirs, files in os.walk(PLUGIN):
        dirs.sort()

        for name in sorted(files):
            path = os.path.join(base, name)
            found.append(os.path.relpath(path, PLUGIN).replace(os.sep, '/'))

    return sorted(found)


def digest(path):
    sha = hashlib.sha256()

    with open(path, 'rb') as handle:
        for block in iter(lambda: handle.read(65536), b''):
            sha.update(block)

    return sha.hexdigest()


def manifest(write=False):
    """The manifest the package should have, and the one it has."""
    wanted = {
        'name': 'tisa-otp',
        'version': plugin_version(),
        'files': {},
    }

    for name in relative_files():
        if name == 'build.json':
            continue

        wanted['files'][name] = digest(os.path.join(PLUGIN, name))

    if write:
        with open(MANIFEST, 'w', encoding='utf-8') as handle:
            json.dump(wanted, handle, ensure_ascii=False, indent='\t', sort_keys=True)
            handle.write('\n')

    return wanted


def archive_entries(write=False):
    """(name, is_dir) for every entry the archive should hold."""
    entries = []

    def walk(directory, prefix):
        entries.append((prefix + '/', True))

        names = sorted(os.listdir(directory))

        for name in [n for n in names if os.path.isfile(os.path.join(directory, n))]:
            entries.append((prefix + '/' + name, False))

        for name in [n for n in names if os.path.isdir(os.path.join(directory, n))]:
            walk(os.path.join(directory, name), prefix + '/' + name)

    walk(PLUGIN, 'tisa-otp')

    if not write:
        return entries

    with zipfile.ZipFile(ARCHIVE, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for name, is_dir in entries:
            info = zipfile.ZipInfo(name, STAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = ((0o40755 if is_dir else 0o100644) << 16) | (0x10 if is_dir else 0)

            if is_dir:
                zf.writestr(info, b'')
                continue

            with open(os.path.join(PLUGIN, *name.split('/')[1:]), 'rb') as handle:
                zf.writestr(info, handle.read())

    return entries


def in_zip(entry):
    """Plugin-relative name inside the archive for an archive entry."""
    return entry[len('tisa-otp/'):] if entry.startswith('tisa-otp/') else entry


def check():
    problems = []

    # 1. The committed manifest describes the tree that is committed.
    wanted = manifest()

    if not os.path.exists(MANIFEST):
        problems.append('tisa-otp/build.json is missing — run tools/build_package.py')
        committed = {'files': {}, 'version': '?'}
    else:
        with open(MANIFEST, encoding='utf-8') as handle:
            committed = json.load(handle)

        for name, sha in wanted['files'].items():
            if name not in committed.get('files', {}):
                problems.append('not in build.json: ' + name)
            elif committed['files'][name] != sha:
                problems.append('build.json is stale for ' + name)

        for name in committed.get('files', {}):
            if name not in wanted['files']:
                problems.append('build.json lists a file that is gone: ' + name)

        if committed.get('version') != wanted['version']:
            problems.append('build.json says version %s, the plugin says %s' % (committed.get('version'), wanted['version']))

    # 2. The committed archive holds the committed tree, byte for byte.
    if not os.path.exists(ARCHIVE):
        problems.append('tisa-otp.zip is missing — run tools/build_package.py')
        return problems

    with zipfile.ZipFile(ARCHIVE) as zf:
        names = zf.namelist()
        files = [n for n in names if not n.endswith('/')]

        expected = [n for n, is_dir in archive_entries() if not is_dir]

        if set(files) != set(expected):
            for name in sorted(set(expected) - set(files)):
                problems.append('missing from the archive: ' + name)
            for name in sorted(set(files) - set(expected)):
                problems.append('in the archive but not in the tree: ' + name)

        for name in sorted(set(files) & set(expected)):
            sha = hashlib.sha256(zf.read(name)).hexdigest()
            local = os.path.join(PLUGIN, *name.split('/')[1:])

            if sha != digest(local):
                problems.append('the archive holds another version of ' + name)

    return problems


def main():
    if '--check' in sys.argv:
        problems = check()

        if problems:
            print('package check failed: %d problem(s)' % len(problems))

            for line in problems[:20]:
                print('  -', line)

            if len(problems) > 20:
                print('  ... and %d more' % (len(problems) - 20))

            return 1

        wanted = manifest()
        print('package check ok: %s, %d files, manifest and archive match the tree' % (wanted['version'], len(wanted['files'])))

        return 0

    wanted = manifest(write=True)
    entries = archive_entries(write=True)
    print('%s: %d files, %d entries, %s bytes' % (wanted['version'], len(wanted['files']), len(entries), format(os.path.getsize(ARCHIVE), ',')))

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
