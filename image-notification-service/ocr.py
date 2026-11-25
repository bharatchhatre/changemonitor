#!/usr/bin/env python3
"""
CLI helper to convert an image file to text.
Usage:
  python3 ocr.py path/to/image.png
  cat image.png | python3 ocr.py -
"""

import sys
import os
import subprocess
import tempfile

def image_to_text(image_bytes: bytes, whitelist: str = None, psm: int = 3) -> str:
    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp_in:
        tmp_in.write(image_bytes)
        tmp_in_path = tmp_in.name

    tmp_out_base = tmp_in_path + "_out"
    tmp_out_txt = tmp_out_base + ".txt"

    try:
        cmd = ["tesseract", tmp_in_path, tmp_out_base, "--psm", str(psm)]
        if whitelist:
            cmd.extend(["-c", f"tessedit_char_whitelist={whitelist}"])

        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if proc.returncode != 0 and not os.path.exists(tmp_out_txt):
            raise RuntimeError(f"OCR Error: {proc.stderr.strip() or 'Tesseract failed'}")

        if os.path.exists(tmp_out_txt):
            with open(tmp_out_txt, "r", encoding="utf-8", errors="replace") as f:
                return f.read().strip()
        return ""
    finally:
        for p in (tmp_in_path, tmp_out_txt):
            if os.path.exists(p):
                try:
                    os.remove(p)
                except OSError:
                    pass

if __name__ == "__main__":
    if len(sys.argv) < 2 or sys.argv[1] in ("-h", "--help"):
        print("Usage: python3 ocr.py <image_path>")
        print("   or: cat image.png | python3 ocr.py -")
        sys.exit(0)

    arg = sys.argv[1]
    if arg == "-":
        data = sys.stdin.buffer.read()
    else:
        if not os.path.exists(arg):
            print(f"File not found: {arg}", file=sys.stderr)
            sys.exit(1)
        with open(arg, "rb") as f:
            data = f.read()

    result = image_to_text(data)
    print(result)
