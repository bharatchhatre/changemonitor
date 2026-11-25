#!/usr/bin/env python3
"""
Simple Image Notification Service (OCR & Image-to-Text API)
Converts image input (containing alphanumeric text) to text output using Tesseract OCR.
Supports:
  - multipart/form-data file upload (field name: 'image' or 'file')
  - raw image binary (Content-Type: image/png, image/jpeg, etc.)
  - JSON payload with base64 encoded image ({"image": "<base64>"} or {"image_url": "https://..."})
  - GET / (Interactive Web UI for testing)
  - GET /health (Healthcheck endpoint)
  - POST /ocr or POST / (Run OCR)
"""

import sys
import os
import io
import re
import json
import base64
import urllib.request
import subprocess
import time
import random
import tempfile
from http.server import HTTPServer, BaseHTTPRequestHandler

# Default configurations
DEFAULT_PORT = int(os.environ.get("OCR_PORT", 8090))
DEFAULT_HOST = os.environ.get("OCR_HOST", "0.0.0.0")
TESSERACT_CMD = os.environ.get("TESSERACT_CMD", "tesseract")


def run_tesseract(image_bytes: bytes, whitelist: str = None, psm: int = 3) -> str:
    """Run Tesseract CLI on image bytes and return clean extracted text."""
    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp_in:
        tmp_in.write(image_bytes)
        tmp_in_path = tmp_in.name

    tmp_out_base = tmp_in_path + "_out"
    tmp_out_txt = tmp_out_base + ".txt"

    try:
        cmd = [TESSERACT_CMD, tmp_in_path, tmp_out_base, "--psm", str(psm)]
        # Optional config to constrain characters to alphanumeric text
        config_params = []
        if whitelist:
            config_params.extend(["-c", f"tessedit_char_whitelist={whitelist}"])

        cmd.extend(config_params)
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

        if proc.returncode != 0 and not os.path.exists(tmp_out_txt):
            raise RuntimeError(f"Tesseract error: {proc.stderr.strip() or 'Unknown execution error'}")

        if os.path.exists(tmp_out_txt):
            with open(tmp_out_txt, "r", encoding="utf-8", errors="replace") as f:
                extracted = f.read()
        else:
            extracted = ""

        # Normalize whitespace
        return extracted.strip()

    finally:
        if os.path.exists(tmp_in_path):
            try:
                os.remove(tmp_in_path)
            except OSError:
                pass
        if os.path.exists(tmp_out_txt):
            try:
                os.remove(tmp_out_txt)
            except OSError:
                pass


HTML_UI = """<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Notification Service - OCR</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; margin: 0; }
        .card { max-width: 640px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.5rem; color: #38bdf8; display: flex; align-items: center; gap: 0.5rem; }
        .dropzone { border: 2px dashed #475569; border-radius: 8px; padding: 2rem; text-align: center; cursor: pointer; margin-bottom: 1.5rem; background: #0f172a; }
        .dropzone:hover { border-color: #38bdf8; }
        input[type="file"] { display: none; }
        button { background: #0284c7; color: white; border: none; padding: 0.75rem 1.5rem; font-size: 1rem; border-radius: 6px; cursor: pointer; width: 100%; font-weight: 600; }
        button:hover { background: #0369a1; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        #preview { max-width: 100%; max-height: 200px; display: none; margin: 1rem auto; border-radius: 6px; }
        .result-box { margin-top: 1.5rem; background: #0f172a; border-radius: 6px; padding: 1rem; border: 1px solid #334155; }
        pre { margin: 0; white-space: pre-wrap; word-break: break-all; color: #a7f3d0; font-size: 1.1rem; }
        .badge { background: #334155; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem; }
        code { background: #0f172a; padding: 0.2rem 0.4rem; border-radius: 4px; color: #f43f5e; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔍 Image Notification Service</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Upload or send an image containing text/code to parse and extract text.</p>
        
        <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
            <span id="dropText">📁 Click or drag an image here</span>
            <img id="preview" alt="Preview">
            <input type="file" id="fileInput" accept="image/*">
        </div>

        <button id="submitBtn" onclick="runOCR()" disabled>Extract Text</button>

        <div class="result-box" id="resultContainer" style="display: none;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                <span style="font-weight: 600; font-size: 0.85rem; color: #94a3b8;">OUTPUT:</span>
                <span class="badge" id="timingBadge">0ms</span>
            </div>
            <pre id="outputResult"></pre>
        </div>

        <div style="margin-top: 2rem; border-top: 1px solid #334155; padding-top: 1rem; font-size: 0.85rem; color: #94a3b8;">
            <strong>cURL API Quick Example:</strong><br>
            <code>curl -X POST -F "image=@your_image.png" http://localhost:8090/ocr</code>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('fileInput');
        const preview = document.getElementById('preview');
        const dropText = document.getElementById('dropText');
        const submitBtn = document.getElementById('submitBtn');
        const resultContainer = document.getElementById('resultContainer');
        const outputResult = document.getElementById('outputResult');
        const timingBadge = document.getElementById('timingBadge');

        fileInput.addEventListener('change', () => {
            const file = fileInput.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    dropText.style.display = 'none';
                    submitBtn.disabled = false;
                };
                reader.readAsDataURL(file);
            }
        });

        async function runOCR() {
            const file = fileInput.files[0];
            if (!file) return;

            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing OCR...';
            const startTime = performance.now();

            const formData = new FormData();
            formData.append('image', file);

            try {
                const res = await fetch('/ocr', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                const duration = Math.round(performance.now() - startTime);

                resultContainer.style.display = 'block';
                timingBadge.textContent = `${duration} ms`;
                if (data.success) {
                    outputResult.textContent = data.text || '(No text detected)';
                    outputResult.style.color = '#a7f3d0';
                } else {
                    outputResult.textContent = 'Error: ' + (data.error || 'OCR failed');
                    outputResult.style.color = '#f87171';
                }
            } catch (err) {
                resultContainer.style.display = 'block';
                outputResult.textContent = 'Network error: ' + err.message;
                outputResult.style.color = '#f87171';
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Extract Text';
            }
        }
    </script>
</body>
</html>
"""


class OCRRequestHandler(BaseHTTPRequestHandler):
    def _send_json(self, status_code: int, data: dict):
        body = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.end_headers()

    def do_GET(self):
        path = self.path.split("?")[0].rstrip("/")
        if path == "/health":
            self._send_json(200, {
                "status": "healthy",
                "service": "image-notification-service",
                "engine": "tesseract"
            })
            return

        # Serve Web UI on root
        if path in ("", "/"):
            content = HTML_UI.encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(content)))
            self.end_headers()
            self.wfile.write(content)
            return

        self._send_json(404, {"success": False, "error": f"Endpoint not found: {self.path}"})

    def do_POST(self):
        path = self.path.split("?")[0].rstrip("/")
        if path not in ("", "/", "/ocr", "/process", "/notify"):
            self._send_json(404, {"success": False, "error": f"Unknown endpoint: {self.path}"})
            return

        content_type = self.headers.get("Content-Type", "")
        content_length = int(self.headers.get("Content-Length", 0))

        if content_length <= 0:
            self._send_json(400, {"success": False, "error": "Empty request body"})
            return

        raw_body = self.rfile.read(content_length)
        image_bytes = None
        whitelist = None
        psm = 3

        try:
            # 1. Handle multipart/form-data
            if "multipart/form-data" in content_type:
                # Parse multipart
                boundary = content_type.split("boundary=")[-1].strip()
                if boundary.startswith('"') and boundary.endswith('"'):
                    boundary = boundary[1:-1]
                parts = raw_body.split(b"--" + boundary.encode())

                for part in parts:
                    if b'Content-Disposition: form-data;' in part:
                        headers_part, _, body_part = part.partition(b"\r\n\r\n")
                        headers_text = headers_part.decode("utf-8", errors="ignore")
                        body_data = body_part.rstrip(b"\r\n")

                        if 'name="image"' in headers_text or 'name="file"' in headers_text:
                            image_bytes = body_data
                        elif 'name="whitelist"' in headers_text:
                            whitelist = body_data.decode("utf-8", errors="ignore").strip()
                        elif 'name="psm"' in headers_text:
                            try:
                                psm = int(body_data.decode("utf-8", errors="ignore").strip())
                            except ValueError:
                                pass

            # 2. Handle JSON payload (base64 image or image_url)
            elif "application/json" in content_type:
                data = json.loads(raw_body.decode("utf-8", errors="replace"))
                whitelist = data.get("whitelist")
                if "psm" in data:
                    try:
                        psm = int(data["psm"])
                    except ValueError:
                        pass

                if "image" in data:
                    b64_str = data["image"]
                    # Strip data URL scheme if present (e.g., data:image/png;base64,...)
                    if "," in b64_str and "base64" in b64_str:
                        b64_str = b64_str.split(",", 1)[1]
                    image_bytes = base64.b64decode(b64_str)
                elif "image_url" in data:
                    req = urllib.request.Request(
                        data["image_url"],
                        headers={"User-Agent": "Mozilla/5.0 (OCR-Service)"}
                    )
                    with urllib.request.urlopen(req, timeout=10) as resp:
                        image_bytes = resp.read()

            # 3. Direct raw image binary upload (image/png, image/jpeg, etc.)
            elif content_type.startswith("image/") or content_type == "application/octet-stream":
                image_bytes = raw_body

            # 4. Fallback: try raw binary
            else:
                image_bytes = raw_body

            if not image_bytes:
                self._send_json(400, {
                    "success": False,
                    "error": "No image data provided. Send as multipart 'image' field, raw binary, or JSON {'image': '<base64>'}"
                })
                return

            # Perform OCR
            extracted_text = run_tesseract(image_bytes, whitelist=whitelist, psm=psm)

            # Random response delay between 4.5s and 8.0s
            time.sleep(random.uniform(4.5, 8.0))

            # Check if raw text format is requested
            is_text_format = "format=text" in self.path or (isinstance(data, dict) and data.get("format") == "text") if "application/json" in content_type else "format=text" in self.path

            if is_text_format:
                body = extracted_text.encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "text/plain; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.send_header("Access-Control-Allow-Origin", "*")
                self.end_headers()
                self.wfile.write(body)
                return

            # Return success response
            self._send_json(200, {
                "success": True,
                "text": extracted_text,
                "length": len(extracted_text),
                "psm": psm,
                "engine": "tesseract"
            })

        except Exception as e:
            self._send_json(500, {
                "success": False,
                "error": str(e)
            })


def run_server(port: int = DEFAULT_PORT, host: str = DEFAULT_HOST):
    server = HTTPServer((host, port), OCRRequestHandler)
    print(f"🚀 Image Notification Service listening on http://{host}:{port}")
    print(f"📌 Web UI: http://localhost:{port}/")
    print(f"📌 API Endpoint: POST http://localhost:{port}/ocr (or POST http://localhost:{port}/process)")
    print("Press Ctrl+C to stop.")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down server.")
        server.server_close()


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_PORT
    run_server(port=port)
