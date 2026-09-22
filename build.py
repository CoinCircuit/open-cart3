#!/usr/bin/env python3
"""Builds coincircuit.ocmod.zip, the installable OpenCart 3 extension."""

import os
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
OUTPUT = os.path.join(ROOT, "coincircuit.ocmod.zip")
PACKAGE_FILES = (
    "install.json",
    "upload/admin/controller/extension/payment/coincircuit.php",
    "upload/admin/language/en-gb/extension/payment/coincircuit.php",
    "upload/admin/model/extension/payment/coincircuit.php",
    "upload/admin/view/template/extension/payment/coincircuit.twig",
    "upload/admin/view/image/payment/coincircuit.png",
    "upload/catalog/controller/extension/payment/coincircuit.php",
    "upload/catalog/language/en-gb/extension/payment/coincircuit.php",
    "upload/catalog/model/extension/payment/coincircuit.php",
    "upload/catalog/view/javascript/coincircuit/checkout.js",
    "upload/catalog/view/theme/default/template/extension/payment/coincircuit.twig",
)


def collect_files():
    entries = []
    for arcname in PACKAGE_FILES:
        full = os.path.join(ROOT, *arcname.split("/"))
        if not os.path.isfile(full):
            raise SystemExit("Missing package file: %s" % arcname)
        entries.append((full, arcname))
    return entries


def main():
    entries = collect_files()
    if os.path.exists(OUTPUT):
        os.remove(OUTPUT)

    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as archive:
        for full, arcname in entries:
            archive.write(full, arcname)

    print("Wrote %s (%d files)" % (os.path.basename(OUTPUT), len(entries)))


if __name__ == "__main__":
    main()
