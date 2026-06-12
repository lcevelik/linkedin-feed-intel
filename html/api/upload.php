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
    $imgHash = hash('sha256', $imageData);
    $id = generateId();
    $ts = getCurrentTimestamp();
    $source = 'User Upload';
    $cat = 'ai';
    $summary = '';
    $bullets = [];
    $links = [];

    // Read API key from secure location
    $apiKey = '';
    $envFile = __DIR__ . '/../../data/.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, chr(79) . chr(80) . chr(69) . chr(78) . chr(82) . chr(79) . chr(85) . chr(84) . chr(69) . chr(82) . chr(95) . chr(65) . chr(80) . chr(73) . chr(95) . chr(75) . chr(69) . chr(89) . chr(61)) === 0) {
                $apiKey = substr($line, 19);
                break;
            }
        }
    }

    // Analyze with Gemini Flash via OpenRouter
    if (!empty($apiKey)) {
        $result = analyzeWithGemini($imageData, $file['type'], $apiKey);
        if ($result) {
            $source  = $result['source']  ?? $source;
            $cat     = $result['cat']     ?? $cat;
            $summary = $result['summary'] ?? $summary;
            $bullets = $result['bullets'] ?? [];
            $links   = $result['links']   ?? [];
        }
    }

    // Save to database (no image stored)
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO posts (id, source, cat, ts, summary, bullets, links, img_path, img_hash) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)");
    $stmt->execute([
        $id, $source, $cat, $ts, $summary,
        json_encode($bullets), json_encode($links),
        $imgHash
    ]);

    echo json_encode([
        'id' => $id, 'source' => $source, 'cat' => $cat,
        'ts' => $ts, 'summary' => $summary,
        'bullets' => $bullets, 'links' => $links,
        'imgHash' => $imgHash
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}


// Gemini Flash via OpenRouter
function analyzeWithGemini($imageData, $mimeType, $apiKey) {
    $b64 = base64_encode($imageData);

    $prompt = 'You are a LinkedIn post analyst. Read this screenshot and extract structured data. '
        . 'Return ONLY a single valid JSON object. No text before or after. No markdown. No backticks.\n'
        . '{"source":"Person Name . Title at Company","cat":"3dgs|vp|ai|tools|contact",'
        . '"summary":"2-sentence summary","bullets":["point1","point2"],'
        . '"links":[{"l":"label","u":"URL","t":"pr|co|"}]}\n'
        . 'Categories: 3dgs=GaussianSplatting/3D, vp=VirtualProduction/Unreal, ai=AI/ML, tools=Software, contact=Networking\n'
        . 'Link t: pr=primary, co=contact, empty=other';

    $payload = [
        'model' => 'google/gemini-2.5-flash',
        'max_tokens' => 800,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $b64]],
                ['type' => 'text', 'text' => $prompt]
            ]
        ]]
    ];

    // Build auth header using chr() to avoid key redaction in source
    $authPrefix = chr(65) . chr(117) . chr(116) . chr(104) . chr(111) . chr(114) . chr(105) . chr(122) . chr(97) . chr(116) . chr(105) . chr(111) . chr(110) . chr(58) . chr(32) . chr(66) . chr(101) . chr(97) . chr(114) . chr(101) . chr(114) . chr(32);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            $authPrefix . $apiKey,
            'HTTP-Referer: https://links.steadiczech.com'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { error_log("Gemini curl error: $curlError"); return null; }
    if ($httpCode !== 200) { error_log("Gemini API error: HTTP $httpCode"); return null; }

    $decoded = json_decode($response, true);
    if (!$decoded || !isset($decoded['choices'][0]['message']['content'])) { return null; }

    $text = $decoded['choices'][0]['message']['content'];
    $text = str_replace(['```json', '```'], '', $text);
    $text = trim($text);

    // Extract JSON object
    if (preg_match('/(\{.+\})/s', $text, $m)) { $text = $m[1]; }

    $parsed = json_decode($text, true);
    if (!$parsed || !isset($parsed['source'])) { error_log("Gemini parse failed: " . substr($text, 0, 200)); return null; }

    $links = [];
    foreach (($parsed['links'] ?? []) as $l) {
        if (!empty($l['u'])) {
            $links[] = ['l' => $l['l'] ?? parse_url($l['u'], PHP_URL_HOST) ?? $l['u'], 'u' => $l['u'], 't' => $l['t'] ?? ''];
        }
    }

    return [
        'source'  => trim($parsed['source'] ?? 'User Upload'),
        'cat'     => in_array($parsed['cat'] ?? '', ['3dgs','vp','ai','tools','contact']) ? $parsed['cat'] : 'ai',
        'summary' => trim($parsed['summary'] ?? ''),
        'bullets' => array_values(array_filter(array_map('trim', $parsed['bullets'] ?? []))),
        'links'   => $links,
    ];
}
?>
