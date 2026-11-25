# Image Notification Service

A lightweight, zero-dependency (uses Python standard library + Tesseract) Image Notification Service designed to extract alphanumeric text from images.

## Features
- **Zero External Python Libraries**: Runs on standard Python 3 + `tesseract` binary.
- **Multiple Input Formats**:
  1. Standard file upload via `multipart/form-data` (form field: `image` or `file`)
  2. Direct binary POST (`Content-Type: image/png`, `image/jpeg`, etc.)
  3. JSON payload with Base64 encoded string (`{"image": "<base64_data>"}`)
  4. Remote URL download (`{"image_url": "https://..."}`)
- **Built-in Web Test UI**: Open `http://localhost:8090/` in a browser to drag-and-drop test images.
- **PHP Endpoint Alternative**: Also includes `index.php` for running inside any standard Apache/Nginx PHP host.

---

## Prerequisites

Ensure `tesseract` is installed:
- **macOS**: `brew install tesseract`
- **Ubuntu/Debian**: `sudo apt-get install -y tesseract-ocr`

---

## 1. Quick Start

### Start the Python API Server
```bash
python3 image-notification-service/server.py 8090
```
*(Optionally specify any port, default is 8090)*

### Or run directly via CLI
```bash
python3 image-notification-service/ocr.py image-notification-service/sample.png
# Output: CODE: 892K-x271
```

---

## 2. API Endpoints

### Health Check
- **Endpoint**: `GET /health`
```bash
curl http://localhost:8090/health
```
**Response**:
```json
{
  "status": "healthy",
  "service": "ocr-api",
  "engine": "tesseract"
}
```

---

### Extract Text (OCR)
- **Endpoint**: `POST /ocr` (or `POST /`)

#### Method A: Multipart Form Upload (Recommended for forms & Postman)
```bash
curl -X POST -F "image=@/path/to/image.png" http://localhost:8090/ocr
```

#### Method B: Raw Image Binary
```bash
curl -X POST \
  -H "Content-Type: image/png" \
  --data-binary "@/path/to/image.png" \
  http://localhost:8090/ocr
```

#### Method C: JSON with Base64 String
```bash
# In Bash/Zsh:
IMAGE_B64=$(base64 -i /path/to/image.png | tr -d '\n')
curl -X POST \
  -H "Content-Type: application/json" \
  -d "{\"image\": \"$IMAGE_B64\"}" \
  http://localhost:8090/ocr
```

#### Method D: JSON with Image URL
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"image_url": "https://example.com/captcha.png"}' \
  http://localhost:8090/ocr
```

---

## 3. API Response Format

### Success (`200 OK`)
```json
{
  "success": true,
  "text": "CODE: 892K-x271",
  "length": 15,
  "psm": 3,
  "engine": "tesseract"
}
```

### Error (`400` or `500`)
```json
{
  "success": false,
  "error": "No image data provided. Send as multipart 'image' field, raw binary, or JSON {'image': '<base64>'}"
}
```

---

## 4. Optional Parameters (Optimized for Alphanumeric / Captchas)

- **`whitelist`**: Restrict recognized characters to simple alphanumeric only.
  ```bash
  curl -X POST \
    -F "image=@image.png" \
    -F "whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz" \
    http://localhost:8090/ocr
  ```
- **`psm`**: Page Segmentation Mode (Tesseract `--psm`):
  - `3`: Fully automatic page segmentation (default)
  - `7`: Treat image as a single text line
  - `8`: Treat image as a single word
  ```bash
  curl -X POST -F "image=@image.png" -F "psm=7" http://localhost:8090/ocr
  ```

---

## 5. Alternative PHP Host Runner

If hosting on an Apache/PHP web server (e.g. Bluehost, cPanel, or local PHP):
```bash
php -S 0.0.0.0:8090 image-notification-service/index.php
```
Access the same endpoints and format via `POST http://localhost:8090/`.
