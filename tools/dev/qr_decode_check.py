#!/usr/bin/env python3
"""Reads the printed code of a simplified tax invoice back with scanners that share no code with the
application (OpenCV and ZXing-C++) and checks that what they read is what the application wrote.

For every case of the manifest that tools/dev/qr_render_cases.php writes it decodes the PNG of the picture
and the page of every PDF (rendered by pdftoppm at the given resolution), compares the text it reads with the
text the application made, then takes that text apart as base64 and TLV in this file and compares the five
fields with the values the case was built from. The exit status is 1 when any scanner fails on any source.

    python3 -m venv /tmp/f7venv && /tmp/f7venv/bin/pip install opencv-python-headless numpy pillow zxing-cpp
    MYSQL_DB_NAME=<seeded database> php tools/dev/qr_render_cases.php /tmp/qr-cases
    /tmp/f7venv/bin/python -I tools/dev/qr_decode_check.py /tmp/qr-cases/manifest.json [--dpi 200]
"""
import argparse
import base64
import json
import os
import subprocess
import sys
import tempfile

import cv2
import zxingcpp

FIELDS = {1: 'seller', 2: 'vat', 3: 'time', 4: 'total', 5: 'tax'}


def parse_tlv(payload):
    raw = base64.b64decode(payload, validate=True)
    fields = {}
    offset = 0
    while offset < len(raw):
        tag, length = raw[offset], raw[offset + 1]
        fields[FIELDS.get(tag, tag)] = raw[offset + 2:offset + 2 + length].decode('utf-8')
        offset += 2 + length
    return fields


def read_with_opencv(image):
    text, _, _ = cv2.QRCodeDetectorAruco().detectAndDecode(image)
    return text or None


def read_with_old_opencv(image):
    text, _, _ = cv2.QRCodeDetector().detectAndDecode(image)
    return text or None


def read_with_zxing(image):
    found = zxingcpp.read_barcodes(image, formats=zxingcpp.BarcodeFormat.QRCode)
    return found[0].text if found else None


def pages_of(pdf, dpi, work):
    stem = os.path.join(work, os.path.splitext(os.path.basename(pdf))[0])
    subprocess.run(['pdftoppm', '-r', str(dpi), '-png', pdf, stem], check=True)
    return sorted(os.path.join(work, name) for name in os.listdir(work) if name.startswith(os.path.basename(stem) + '-') and name.endswith('.png'))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('manifest')
    parser.add_argument('--dpi', type=int, default=200)
    arguments = parser.parse_args()

    directory = os.path.dirname(os.path.abspath(arguments.manifest))
    with open(arguments.manifest, encoding='utf-8') as handle:
        cases = json.load(handle)

    failures = 0
    rows = 0
    old_misses = 0
    print(f"{'case':<20}{'source':<11}{'modules':>8}{'opencv':>8}{'zxing':>7}{'text':>6}{'tlv':>6}{'old-ocv':>9}")

    with tempfile.TemporaryDirectory() as work:
        for case in cases:
            for source, name in case['files'].items():
                path = os.path.join(directory, name)
                if name.endswith('.pdf'):
                    images = [cv2.imread(page) for page in pages_of(path, arguments.dpi, work)]
                else:
                    images = [cv2.imread(path)]

                read = {'opencv': None, 'zxing': None}
                old = None
                for image in images:
                    read['opencv'] = read['opencv'] or read_with_opencv(image)
                    read['zxing'] = read['zxing'] or read_with_zxing(image)
                    old = old or read_with_old_opencv(image)
                old_misses += 0 if old == case['payload'] else 1

                same_text = all(value == case['payload'] for value in read.values() if value is not None)
                tlv_ok = '-'
                if case['expected'] is not None and read['opencv'] is not None:
                    try:
                        tlv_ok = 'ok' if parse_tlv(read['opencv']) == case['expected'] else 'WRONG'
                    except Exception:
                        tlv_ok = 'WRONG'

                ok = all(value is not None for value in read.values()) and same_text and tlv_ok != 'WRONG'
                failures += 0 if ok else 1
                rows += 1
                print(f"{case['name']:<20}{source:<11}{case['modules']:>8}{'ok' if read['opencv'] else 'FAIL':>8}{'ok' if read['zxing'] else 'FAIL':>7}{'same' if same_text else 'DIFF':>6}{tlv_ok:>6}{'ok' if old == case['payload'] else 'miss':>9}")

    print(f"\n{rows} sources, {failures} failures (the older OpenCV detector missed {old_misses}, not counted)")
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
