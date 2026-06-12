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

    // STEP 1: Local OCR via Ollama vision model (moondream)
    $extractedText = ocrWithOllama($imageData);

    if ($extractedText) {
        // STEP 2: Parse OCR text into card structure (no LLM needed)
        $parsed = parseOcrToCard($extractedText);
        $source = $parsed['source'] ?? $source;
        $cat = $parsed['cat'] ?? $cat;
        $summary = $parsed['summary'] ?? $summary;
        $bullets = $parsed['bullets'] ?? [];
        $links = $parsed['links'] ?? [];
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
// STEP 1: Local OCR via Ollama Vision Model (moondream)
// ══════════════════════════════════════════════════════════════════════

function ocrWithOllama($imageData) {
    $b64 = base64_encode($imageData);

    $payload = [
        'model' => VISION_MODEL,
        'prompt' => 'What text and information do you see in this image? Include author name, post content, links, hashtags, and any other visible text.',
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
// STEP 2: Parse OCR text into card structure (no LLM)
// ══════════════════════════════════════════════════════════════════════

function parseOcrToCard($text) {
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    $fullText = implode(' ', $lines);

    // Extract source (first line or first sentence with name/title patterns)
    $source = extractSource($lines, $fullText);

    // Extract category based on keywords
    $cat = detectCategory($fullText);

    // Extract summary (first 2-3 meaningful sentences)
    $summary = extractSummary($fullText);

    // Extract bullet points (key sentences)
    $bullets = extractBullets($fullText);

    // Extract links
    $links = extractLinks($fullText);

    return [
        'source' => $source,
        'cat' => $cat,
        'summary' => $summary,
        'bullets' => $bullets,
        'links' => $links
    ];
}

function extractSource($lines, $text) {
    // Try first line as author (if it looks like a name)
    if (!empty($lines[0])) {
        $first = $lines[0];
        if (strlen($first) > 3 && strlen($first) < 100 && !preg_match('/^(image|photo|screenshot|linkedin|the )/i', $first)) {
            return $first;
        }
    }

    // Handle moondream descriptive output: "reads 'John Smith'" or "says 'John Smith'"
    if (preg_match('/(?:reads?|says?|header|from|posted by|authored by|written by)[:\s]+["\']?([A-Z][a-z]+ [A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $text, $m)) {
        return $m[1];
    }

    // Find "Name followed by" or "Name announces" pattern
    if (preg_match('/([A-Z][a-z]+ [A-Z][a-z]+)\s+(?:followed|announces|announced|shares|shared|posts|posted|writes|wrote|says|said)/i', $text, $m)) {
        return $m[1];
    }

    // Try "Name · Title" or "Name at Company" pattern
    if (preg_match('/^([A-Z][a-z]+ [A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s*(?:·|at|@|—|-)\s*(.+)/', $text, $m)) {
        return trim($m[1] . ' · ' . $m[2]);
    }

    // Try "Name, Title" pattern
    if (preg_match('/([A-Z][a-z]+ [A-Z][a-z]+),\s*(?:VP|CEO|CTO|Director|Manager|Engineer|Lead|Head|Chief|Sr\.|Jr\.|Dr\.|Prof\.|Mr\.|Ms\.|Chief)/i', $text, $m)) {
        return $m[1];
    }

    // Generic two-word name at start
    if (preg_match('/^([A-Z][a-z]+ [A-Z][a-z]+)/', $text, $m)) {
        return $m[1];
    }

    return 'User Upload';
}

function detectCategory($text) {
    $text = strtolower($text);

    // 3DGS keywords
    if (preg_match('/gaussian|splat|3dgs|4dgs|nerf|point.?cloud|colmap|mesh|3d.?reconstruct/i', $text)) {
        return '3dgs';
    }

    // VP keywords
    if (preg_match('/virtual.?prod|unreal|led.?stage|icvfx|in.?camera|nDisplay|stagecraft|volume/i', $text)) {
        return 'vp';
    }

    // Tools keywords
    if (preg_match('/comfyui|blender|unity|houdini|mayа|plugin|sdk|app.?store|github\.com/i', $text)) {
        return 'tools';
    }

    // Contact keywords
    if (preg_match('/dm|message|connect|networking|met at|follow|endorse/i', $text)) {
        return 'contact';
    }

    // AI keywords (default fallback)
    if (preg_match('/ai|machine.?learn|llm|diffusion|model|neural|deep.?learn|gpt|claude|gemini/i', $text)) {
        return 'ai';
    }

    return 'ai';
}

function extractSummary($text) {
    // Split into sentences
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    $summary = [];
    foreach ($sentences as $s) {
        $s = trim($s);
        // Skip very short or generic sentences
        if (strlen($s) < 15) continue;
        if (preg_match('/^(image|photo|screenshot|see|click|view)/i', $s)) continue;

        $summary[] = $s;
        if (count($summary) >= 3) break;
    }

    return !empty($summary) ? implode(' ', $summary) : substr($text, 0, 200);
}

function extractBullets($text) {
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $bullets = [];

    foreach ($sentences as $s) {
        $s = trim($s);
        if (strlen($s) < 20) continue;
        if (preg_match('/^(image|photo|screenshot|see|click|view|the |a |an )/i', $s)) continue;

        $bullets[] = $s;
        if (count($bullets) >= 5) break;
    }

    return $bullets;
}

function extractLinks($text) {
    $links = [];

    // Match URLs
    if (preg_match_all('/(https?:\/\/[^\s<>\"\']+)/i', $text, $m)) {
        foreach ($m[1] as $url) {
            $url = rtrim($url, '.,;:)');
            $links[] = ['l' => parse_url($url, PHP_URL_HOST) ?: $url, 'u' => $url, 't' => ''];
        }
    }

    // Match "www." URLs
    if (preg_match_all('/(www\.[^\s<>\"\']+)/i', $text, $m)) {
        foreach ($m[1] as $url) {
            $url = rtrim($url, '.,;:)');
            if (strpos($url, 'http') !== 0) $url = 'https://' . $url;
            $links[] = ['l' => parse_url($url, PHP_URL_HOST) ?: $url, 'u' => $url, 't' => ''];
        }
    }

    return $links;
}
?>
