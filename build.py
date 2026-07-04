#!/usr/bin/env python3
"""Builds coincircuit.ocmod.zip, the installable OpenCart 3 extension."""

import os
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
UPLOAD_DIR = os.path.join(ROOT, "upload")
OUTPUT = os.path.join(ROOT, "coincircuit.ocmod.zip")


def collect_files():
    entries = []
    for base, _dirs, names in os.walk(UPLOAD_DIR):
        for name in names:
            full = os.path.join(base, name)
            arcname = os.path.relpath(full, ROOT).replace(os.sep, "/")
            entries.append((full, arcname))
    entries.sort(key=lambda pair: pair[1])
    return entries


def main():
    if not os.path.isdir(UPLOAD_DIR):
        raise SystemExit("upload/ folder not found next to build.py")

    entries = collect_files()
    if os.path.exists(OUTPUT):
        os.remove(OUTPUT)

    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as archive:
        for full, arcname in entries:
            archive.write(full, arcname)

    print("Wrote %s (%d files)" % (os.path.basename(OUTPUT), len(entries)))


if __name__ == "__main__":
    main()
