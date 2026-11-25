<?php
/**
 * Image Notification Service - API Endpoint (PHP)
 * Converts image input (containing alphanumeric text) to text output using Tesseract OCR.
 * Accepts:
 *   - multipart/form-data with file in 'image' or 'file'
 *   - Raw binary POST with Content-Type: image/*
 *   - JSON POST with {"image": "<base64_string>"} or {"image_url": "https://..."}
 */

// Include config if available
if (file_exists(__DIR__ . '/../includes/config.php')) {
    require_once __DIR__ . '/../includes/config.php';
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') === 'health') {
        $tesseractPath = trim((string)shell_exec('which tesseract 2>/dev/null'));
        $tessVersion = $tesseractPath ? shell_exec(escapeshellcmd($tesseractPath) . ' --version 2>&1') : 'not found';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'healthy',
            'service' => 'image-notification-service',
            'tesseract_path' => $tesseractPath ?: 'not in PATH',
            'tesseract_version' => $tessVersion ? explode("\n", $tessVersion)[0] : 'N/A'
        ]);
        exit;
    }

    // Serve HTML Web UI
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Image Notification Service</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; margin: 0; }
            .card { max-width: 600px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
            h1 { margin-top: 0; color: #38bdf8; font-size: 1.4rem; }
            .dropzone { border: 2px dashed #475569; border-radius: 8px; padding: 2rem; text-align: center; cursor: pointer; margin-bottom: 1.5rem; background: #0f172a; }
            input[type="file"] { display: none; }
            button { background: #0284c7; color: white; border: none; padding: 0.75rem 1.5rem; font-size: 1rem; border-radius: 6px; cursor: pointer; width: 100%; font-weight: 600; }
            pre { background: #0f172a; padding: 1rem; border-radius: 6px; color: #a7f3d0; white-space: pre-wrap; word-break: break-all; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>🔍 PHP Image-to-Text OCR</h1>
            <form method="POST" enctype="multipart/form-data">
                <div class="dropzone" onclick="document.getElementById('fileInput').click()">
                    <span>Click to choose image</span>
                    <input type="file" id="fileInput" name="image" accept="image/*" onchange="this.form.submit()">
                </div>
            </form>
            <?php
            if (file_exists(__DIR__ . '/sample.png')) {
                echo '<p style="font-size:0.85rem; color:#94a3b8;">Sample cURL: <code>curl -X POST -F "image=@sample.png" ' . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] : '') . '</code></p>';
            }
            ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle POST
header('Content-Type: application/json; charset=utf-8');

$imageContent = null;
$whitelist = $_POST['whitelist'] ?? null;
$psm = isset($_POST['psm']) ? (int)$_POST['psm'] : 3;

// 1. Check $_FILES
if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
    $imageContent = file_get_contents($_FILES['image']['tmp_name']);
} elseif (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $imageContent = file_get_contents($_FILES['file']['tmp_name']);
} else {
    // 2. Check JSON or raw binary
    $rawInput = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        $json = json_decode($rawInput, true);
        if (!empty($json['whitelist'])) $whitelist = $json['whitelist'];
        if (!empty($json['psm'])) $psm = (int)$json['psm'];

        if (!empty($json['image'])) {
            $b64 = $json['image'];
            if (str_contains($b64, 'base64,')) {
                $b64 = explode('base64,', $b64, 2)[1];
            }
            $imageContent = base64_decode($b64);
        } elseif (!empty($json['image_url'])) {
            $imageContent = @file_get_contents($json['image_url']);
        }
    } elseif (!empty($rawInput)) {
        // Raw image binary
        $imageContent = $rawInput;
    }
}

if (empty($imageContent)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No image received. Upload file via "image" field, raw binary, or JSON {"image": "<base64>"}.'
    ]);
    exit;
}

// Write to temp file and run tesseract
$tmpIn = tempnam(sys_get_temp_dir(), 'ocr_in_') . '.png';
file_put_contents($tmpIn, $imageContent);
$tmpOut = tempnam(sys_get_temp_dir(), 'ocr_out_');

try {
    $tesseract = null;
    $possiblePaths = [
        trim((string)shell_exec('which tesseract 2>/dev/null')),
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
        '/opt/homebrew/bin/tesseract',
        '/opt/local/bin/tesseract',
    ];

    foreach ($possiblePaths as $p) {
        if (!empty($p) && @is_executable($p)) {
            $tesseract = $p;
            break;
        }
    }

    if ($tesseract) {
        $cmd = escapeshellcmd($tesseract) . ' ' . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut) . ' --psm ' . escapeshellarg((string)$psm);
        if (!empty($whitelist)) {
            $cmd .= ' -c tessedit_char_whitelist=' . escapeshellarg($whitelist);
        }
        $cmd .= ' 2>&1';

        $execOutput = shell_exec($cmd);
        $txtFile = $tmpOut . '.txt';

        $text = file_exists($txtFile) ? file_get_contents($txtFile) : '';
        $text = trim((string)$text);

        if (($_GET['format'] ?? $_POST['format'] ?? '') === 'text') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $text;
            exit;
        }

        echo json_encode([
            'success' => true,
            'text' => $text,
            'length' => mb_strlen($text),
            'engine' => 'local_tesseract'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        // Fallback for shared hosting without root/tesseract installed:
        // Use OCR.Space engine API with failover keys (supports OCR_SPACE_API_KEY from .env or fallback)
        // Support custom apikey from request query/POST, or .env, or working pool
        $reqKey = $_GET['apikey'] ?? $_POST['apikey'] ?? '';
        $customKey = function_exists('cm_env') ? cm_env('OCR_SPACE_API_KEY', '') : (getenv('OCR_SPACE_API_KEY') ?: '');
        
        $apiKeys = array_values(array_unique(array_filter([
            $reqKey,
            $customKey,
            'K81682784588957',
            'K86650462588957',
            'helloworld'
        ])));
        
        $parsedText = '';
        $lastError = '';

        foreach ($apiKeys as $key) {
            $postData = [
                'apikey' => $key,
                'language' => 'eng',
                'isOverlayRequired' => 'false',
                'scale' => 'true',
                'base64Image' => 'data:image/png;base64,' . base64_encode($imageContent),
                'OCREngine' => '1'
            ];

            $ch = curl_init('https://api.ocr.space/parse/image');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $lastError = $curlErr;
                continue;
            }

            $resJson = json_decode((string)$response, true);
            // Check if this key gave an error (e.g. rate limit / monthly conversion limit)
            $isError = !empty($resJson['IsErroredOnProcessing']) || !empty($resJson['ErrorMessage']) || !empty($resJson['error']);
            if (!$isError && !empty($resJson['ParsedResults'][0]['ParsedText'])) {
                $parsedText = trim($resJson['ParsedResults'][0]['ParsedText']);
                break;
            } else {
                $lastError = $resJson['error'] ?? (is_array($resJson['ErrorMessage'] ?? null) ? implode(', ', $resJson['ErrorMessage']) : ($resJson['ErrorMessage'] ?? 'Failed parsing'));
                // Continue to next key in pool
            }
        }

        $rawFormat = ($_GET['format'] ?? $_POST['format'] ?? '') === 'text';

        if (empty($parsedText) && !empty($lastError)) {
            if ($rawFormat) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "OCR Error: $lastError";
                exit;
            }
            echo json_encode([
                'success' => false,
                'error' => "OCR Service Error: $lastError",
                'engine' => 'ocr_space_cloud_fallback'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        if ($rawFormat) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $parsedText;
            exit;
        }

        echo json_encode([
            'success' => true,
            'text' => $parsedText,
            'length' => mb_strlen($parsedText),
            'engine' => 'ocr_space_cloud_fallback'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    if (file_exists($tmpIn)) @unlink($tmpIn);
    if (file_exists($tmpOut)) @unlink($tmpOut);
    if (isset($txtFile) && file_exists($txtFile)) @unlink($txtFile);
}
