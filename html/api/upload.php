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
    $summary = '';
    $bullets = [];
    $links = [];

    // STEP 1: Local OCR via Ollama vision model (moondream)
    $ocrText = ocrWithOllama($imageData);

    if ($ocrText) {
        // STEP 2: Parse OCR description into structured card
        $parsed = parseOcrToCard($ocrText);
        $source = $parsed['source'];
        $cat = $parsed['cat'];
        $summary = $parsed['summary'];
        $bullets = $parsed['bullets'];
        $links = $parsed['links'];
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
        'prompt' => 'Describe everything you see in this image in detail. Who posted it, what did they write, and what is the post about?',
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

    error_log("OCR raw: " . substr($text, 0, 300));
    return $text;
}


// ══════════════════════════════════════════════════════════════════════
// STEP 2: Parse moondream description into structured card
// ══════════════════════════════════════════════════════════════════════

function parseOcrToCard($text) {
    // Extract quoted text (actual content from the image)
    $quotedTexts = [];
    if (preg_match_all('/"([^"]+)"/', $text, $m)) {
        $quotedTexts = $m[1];
    }
    if (preg_match_all('/\'([^\']+)\'/', $text, $m)) {
        $quotedTexts = array_merge($quotedTexts, $m[1]);
    }

    // Build the actual post content from quoted texts
    $postContent = !empty($quotedTexts) ? implode(' ', $quotedTexts) : $text;

    // Extract source (author name)
    $source = extractSource($text, $quotedTexts);

    // Extract category
    $cat = detectCategory($postContent . ' ' . $text);

    // Build summary from the actual content
    $summary = buildSummary($text, $quotedTexts);

    // Extract bullets (key points)
    $bullets = extractBullets($text, $quotedTexts);

    // Extract links
    $links = extractLinks($text);

    // Extract hashtags
    $hashtags = extractHashtags($text);

    return [
        'source' => $source,
        'cat' => $cat,
        'summary' => $summary,
        'bullets' => $bullets,
        'links' => $links
    ];
}

function extractSource($text, $quotedTexts) {
    // Pattern: "from/by/sent by Name" (most common in moondream output)
    if (preg_match('/(?:from|by|written by|posted by|authored by|sent by)\s+([A-Z][a-z]+(?:\s+(?!to |at |and |the |his |her )[A-Z][a-z]+)*)/i', $text, $m)) {
        return $m[1];
    }

    // Pattern: "reads/says 'Name'"
    if (preg_match('/(?:reads?|says?|titled|named)\s*[:\s]*["\']([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $text, $m)) {
        return $m[1];
    }

    // Pattern: "Name followed by" or "Name announces"
    if (preg_match('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+(?:followed|announces|announced|shares|shared|posts|posted|writes|wrote|says|said|displaying)/i', $text, $m)) {
        return $m[1];
    }

    // Pattern: "Name, Title" or "Name at Company"
    if (preg_match('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*[,\u00B7]\s*(?:VP|CEO|CTO|Director|Manager|Engineer|Lead|Head|Chief)/i', $text, $m)) {
        return $m[1];
    }

    // Look in quoted texts for a name-like string
    foreach ($quotedTexts as $qt) {
        if (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)$/', trim($qt), $m)) {
            return $m[1];
        }
    }

    // First proper name in text
    if (preg_match('/([A-Z][a-z]+ [A-Z][a-z]+)/', $text, $m)) {
        return $m[1];
    }

    return 'User Upload';
}


function detectCategory($text) {
    $t = strtolower($text);

    if (preg_match('/gaussian|splat|3dgs|4dgs|nerf|point.?cloud|colmap|mesh|3d.?reconstruct|gaussiansplatting/i', $t)) return '3dgs';
    if (preg_match('/virtual.?prod|unreal|led.?stage|icvfx|in.?camera|nDisplay|stagecraft|volume|vp|virtual production/i', $t)) return 'vp';
    if (preg_match('/comfyui|blender|unity|houdini|mayа|plugin|sdk|app|github|tool|software/i', $t)) return 'tools';
    if (preg_match('/dm|message|connect|networking|met at|follow|endorse|contact/i', $t)) return 'contact';
    if (preg_match('/ai|machine.?learn|llm|diffusion|model|neural|deep.?learn|gpt|claude|gemini|sprint|real.?time/i', $t)) return 'ai';

    return 'ai';
}

function buildSummary($text, $quotedTexts) {
    // Use quoted texts as the actual content
    if (!empty($quotedTexts)) {
        // Filter out very short quotes and author names
        $content = array_filter($quotedTexts, function($q) {
            return strlen($q) > 10 && !preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', trim($q));
        });
        if (!empty($content)) {
            $summary = implode(' — ', array_slice($content, 0, 3));
            return $summary;
        }
    }

    // Fallback: extract meaningful sentences from description
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $meaningful = [];
    foreach ($sentences as $s) {
        $s = trim($s);
        // Skip "The image shows..." type sentences
        if (preg_match('/^(the image|this image|there is|there are|it appears|the screenshot)/i', $s)) continue;
        if (strlen($s) > 15) $meaningful[] = $s;
        if (count($meaningful) >= 2) break;
    }

    return !empty($meaningful) ? implode(' ', $meaningful) : substr($text, 0, 200);
}

function extractBullets($text, $quotedTexts) {
    $bullets = [];

    // Extract quoted texts that look like content
    foreach ($quotedTexts as $qt) {
        $qt = trim($qt);
        if (strlen($qt) > 15 && !preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $qt)) {
            $bullets[] = $qt;
        }
        if (count($bullets) >= 5) break;
    }

    // If no bullets from quotes, extract key sentences
    if (empty($bullets)) {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($sentences as $s) {
            $s = trim($s);
            if (preg_match('/^(the image|this image|there is|there are)/i', $s)) continue;
            if (strlen($s) > 20) $bullets[] = $s;
            if (count($bullets) >= 3) break;
        }
    }

    return $bullets;
}

function extractLinks($text) {
    $links = [];

    // Match full URLs
    if (preg_match_all('/(https?:\/\/[^\s<>"\')]+)/i', $text, $m)) {
        foreach ($m[1] as $url) {
            $url = rtrim($url, '.,;:)');
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            $links[] = ['l' => $host, 'u' => $url, 't' => ''];
        }
    }

    // Match www. URLs
    if (preg_match_all('/(www\.[^\s<>"\')]+)/i', $text, $m)) {
        foreach ($m[1] as $url) {
            $url = rtrim($url, '.,;:)');
            if (strpos($url, 'http') !== 0) $url = 'https://' . $url;
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            $links[] = ['l' => $host, 'u' => $url, 't' => ''];
        }
    }

    return $links;
}

function extractHashtags($text) {
    $hashtags = [];
    if (preg_match_all('/#([a-zA-Z0-9_]+)/', $text, $m)) {
        $hashtags = $m[1];
    }
    return $hashtags;
}
?>
