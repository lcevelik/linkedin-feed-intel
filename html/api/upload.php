<?php
require_once 'config.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload error: ' . $file['error']]);
        exit;
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'File too large (max 10MB)']);
        exit;
    }

    if (!in_array($file['type'], ALLOWED_MIMES)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type. Only images allowed.']);
        exit;
    }

    $imageData = file_get_contents($file['tmp_name']);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $filepath = UPLOADS_DIR . $filename;

    if (!file_put_contents($filepath, $imageData)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file']);
        exit;
    }

    $imgHash = hash('sha256', $imageData);
    $id = generateId();
    $ts = getCurrentTimestamp();
    $source = 'User Upload';
    $cat = 'ai';
    $summary = 'Image processing in progress...';
    $bullets = [];
    $links = [];

    // STEP 1: Local OCR via Ollama vision model
    $extractedText = ocrWithOllama($imageData, $file['type']);

    if ($extractedText) {
        // STEP 2: Structure into card via MiMo (OpenRouter)
        $result = structureWithMiMo($extractedText);
        if ($result) {
            $source = $result['source'] ?? $source;
            $cat = $result['cat'] ?? $cat;
            $summary = $result['summary'] ?? $summary;
            $bullets = $result['bullets'] ?? [];
            $links = $result['links'] ?? [];
        }
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO posts (id, source, cat, ts, summary, bullets, links, img_path, img_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $id, $source, $cat, $ts, $summary,
        json_encode($bullets), json_encode($links),
        $filename, $imgHash
    ]);

    echo json_encode([
        'id' => $id, 'source' => $source, 'cat' => $cat,
        'ts' => $ts, 'summary' => $summary,
        'bullets' => $bullets, 'links' => $links,
        'img' => UPLOADS_URL . $filename, 'imgHash' => $imgHash
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}


// ══════════════════════════════════════════════════════════════════════
// STEP 1: Local OCR via Ollama Vision Model
// ══════════════════════════════════════════════════════════════════════

function ocrWithOllama($imageData, $mimeType) {
    $b64 = base64_encode($imageData);

    $payload = [
        'model' => VISION_MODEL,
        'prompt' => 'Read all text in this LinkedIn screenshot. Extract every piece of text visible: author name, post content, any links, hashtags, dates, follower counts. Return the raw text exactly as it appears, preserving line breaks. Do not summarize or reformat.',
        'images' => [$b64],
        'stream' => false,
        'options' => [
            'temperature' => 0.1,
            'num_predict' => 1024
        ]
    ];

    $ch = curl_init(OLLAMA_URL . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        error_log("Ollama OCR curl error: $curlError");
        return null;
    }

    if ($httpCode !== 200) {
        error_log("Ollama OCR error: HTTP $httpCode - $response");
        return null;
    }

    $decoded = json_decode($response, true);
    if (!$decoded || !isset($decoded['response'])) {
        error_log("Ollama OCR decode failed");
        return null;
    }

    $text = trim($decoded['response']);
    if (empty($text)) {
        error_log("Ollama OCR returned empty text");
        return null;
    }

    return $text;
}


// ══════════════════════════════════════════════════════════════════════
// STEP 2: Structure extracted text into card via MiMo (OpenRouter)
// ══════════════════════════════════════════════════════════════════════

function structureWithMiMo($extractedText) {
    $apiKey = OPENROUTER_API_KEY;
    if (empty($apiKey) || strlen($apiKey) < 10) {
        error_log("MiMo API key not configured");
        return null;
    }

    $prompt = "You are a LinkedIn post analyst. Given the raw text extracted from a LinkedIn screenshot, structure it into a JSON card.\n\nEXTRACTED TEXT:\n---\n" . $extractedText . "\n---\n\nReturn ONLY valid JSON with this exact structure (no markdown, no backticks, no explanation):\n{\n  \"source\": \"Person/Company name . role/followers if shown\",\n  \"cat\": \"3dgs|vp|ai|tools|contact\",\n  \"summary\": \"2-3 sentences about the post\",\n  \"bullets\": [\"key point 1\", \"key point 2\"],\n  \"links\": [{\"l\":\"label\",\"u\":\"URL\",\"t\":\"\"}]\n}\n\nCategories:\n- 3dgs = Gaussian Splatting, 3D reconstruction, 4DGS, NeRF, point clouds\n- vp = Virtual Production, Unreal Engine, LED stages, real-time rendering, in-camera VFX\n- ai = AI, machine learning, ComfyUI, LLMs, agents, diffusion models\n- tools = Software, plugins, apps, SDKs, services, developer tools\n- contact = Direct messages, networking, people connections\n\nLink types (t field): \"pr\" = product/official, \"co\" = contact/email, \"\" = other\n\nIf the text is unreadable or not a LinkedIn post, return:\n{\"source\":\"Unable to read\",\"cat\":\"ai\",\"summary\":\"Could not read post content.\",\"bullets\":[],\"links\":[]}";

    $payload = [
        'model' => CARD_MODEL,
        'max_tokens' => 800,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            'HTTP-Referer: https://links.steadiczech.com'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("MiMo curl error: $curlError");
        return null;
    }

    if ($httpCode !== 200) {
        error_log("MiMo API error: $httpCode - $response");
        return null;
    }

    $decoded = json_decode($response, true);
    if (!$decoded || !isset($decoded['choices'][0]['message']['content'])) {
        error_log("MiMo response decode failed");
        return null;
    }

    $text = $decoded['choices'][0]['message']['content'];
    $text = str_replace(['```json', '```'], '', $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if (!$parsed) {
        error_log("MiMo JSON parse failed: " . substr($text, 0, 200));
        return null;
    }

    return $parsed;
}
?>
