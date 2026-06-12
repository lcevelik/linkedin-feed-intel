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

    // Phase 1: Extract raw text from image (OCR)
    $rawText = extractTextFromImage($imageData);

    if ($rawText) {
        // Phase 2: Structure raw text into card fields using LLM
        $card = structureWithLLM($rawText);

        if (!$card) {
            // Fallback: regex parsing if LLM structuring fails
            $card = buildCardFromRawText($rawText);
        }

        $source  = $card['source']  ?? 'User Upload';
        $cat     = $card['cat']     ?? 'ai';
        $summary = $card['summary'] ?? '';
        $bullets = $card['bullets'] ?? [];
        $links   = $card['links']   ?? [];
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
// PHASE 1: Extract raw text from image using vision model
// ══════════════════════════════════════════════════════════════════════

function extractTextFromImage($imageData) {
    $b64 = base64_encode($imageData);

    // Focused OCR prompt — asks for verbatim text, not a description
    $prompt = 'Read and list ALL text visible in this image exactly as written. '
            . 'Include: the person\'s name, job title, company, the full post text word for word, '
            . 'any URLs (https://...), hashtags (#tag), email addresses, and bullet points. '
            . 'Output only the raw text you can read. Do not describe the image.';

    // Try vision models in order of OCR quality
    // gemma4:e2b — modern multimodal with native vision encoder, good at reading text
    // minicpm-v  — document/OCR-specialist with CLIP encoder
    // moondream  — small fallback; works but prone to hallucination
    $visionModels = ['gemma4:e2b', 'minicpm-v', 'moondream'];

    foreach ($visionModels as $model) {
        $text = callVisionModel($model, $b64, $prompt);
        if (!$text) continue;

        // Accept result if it looks like actual OCR text, not an image description
        if (!looksLikeDescription($text) && strlen($text) > 20) {
            error_log("OCR OK [$model]: " . substr($text, 0, 300));
            return $text;
        }

        // moondream returned a description — keep as last-resort fallback
        error_log("OCR description from [$model]: " . substr($text, 0, 200));
        $descriptionFallback = $text;
    }

    return $descriptionFallback ?? null;
}

function callVisionModel($model, $b64, $prompt) {
    $payload = [
        'model'   => $model,
        'prompt'  => $prompt,
        'images'  => [$b64],
        'stream'  => false,
        'options' => ['temperature' => 0.0, 'num_predict' => 700]
    ];

    $ch = curl_init(OLLAMA_URL . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("OCR [$model] curl error: $curlErr");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("OCR [$model] HTTP $httpCode: " . substr($response, 0, 300));
        return null;
    }

    $decoded = json_decode($response, true);
    return isset($decoded['response']) ? trim($decoded['response']) : null;
}

function looksLikeDescription($text) {
    // True when the model returned a description of the image rather than OCR text.
    // Common moondream patterns: "The image shows...", "This screenshot displays..."
    return (bool) preg_match(
        '/^(the image (shows?|displays?|contains?|depicts?)|this (image|screenshot|picture)|there (is|are) a)/i',
        trim($text)
    );
}


// ══════════════════════════════════════════════════════════════════════
// PHASE 2: Structure raw OCR text into card fields using a text LLM
// ══════════════════════════════════════════════════════════════════════

function structureWithLLM($rawText) {
    // /no_think disables qwen3 chain-of-thought output
    $prompt = '/no_think
Extract structured data from this LinkedIn post text and return ONLY valid JSON — no explanation, no markdown fences.

Required JSON format:
{"source":"Author Name · Job Title at Company","cat":"ai","summary":"1-2 sentence summary of what was shared","bullets":["key point 1","key point 2"],"links":[{"l":"display label","u":"https://url","t":""}]}

Category values (pick one): 3dgs (gaussian splatting/NeRF/3D reconstruction), vp (virtual production/Unreal Engine/LED volume), tools (software/ComfyUI/GitHub/SDK/app), contact (DM/networking/met someone), ai (AI/ML/LLM/everything else)
Link "t" field: "pr" = primary/main link, "co" = email/contact address, "" = secondary link

LINKEDIN POST TEXT:
' . substr($rawText, 0, 2000);

    // qwen3:1.7b — fast text model, handles JSON well; qwen3.5:4b as backup
    foreach (['qwen3:1.7b', 'qwen3.5:4b'] as $model) {
        $result = callTextModel($model, $prompt);
        if (!$result) continue;

        $card = parseJsonFromLLM($result);
        if ($card) {
            error_log("Structure OK [$model]: source=" . ($card['source'] ?? '?'));
            return normaliseCard($card);
        }
        error_log("Structure JSON parse failed [$model]: " . substr($result, 0, 300));
    }

    return null;
}

function callTextModel($model, $prompt) {
    $payload = [
        'model'   => $model,
        'prompt'  => $prompt,
        'stream'  => false,
        'options' => ['temperature' => 0.1, 'num_predict' => 500]
    ];

    $ch = curl_init(OLLAMA_URL . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        error_log("Text model [$model] error: $curlErr / HTTP $httpCode");
        return null;
    }

    $decoded = json_decode($response, true);
    return isset($decoded['response']) ? trim($decoded['response']) : null;
}

function parseJsonFromLLM($text) {
    // Strip qwen3 thinking tags
    $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
    $text = trim($text);

    // Direct parse
    $d = json_decode($text, true);
    if (is_array($d) && isset($d['source'])) return $d;

    // Strip markdown code fences
    if (preg_match('/```(?:json)?\s*(\{.+?\})\s*```/s', $text, $m)) {
        $d = json_decode($m[1], true);
        if (is_array($d) && isset($d['source'])) return $d;
    }

    // Find first JSON object in output (handles leading explanation)
    if (preg_match('/(\{(?:[^{}]|\{[^{}]*\})*\})/s', $text, $m)) {
        $d = json_decode($m[1], true);
        if (is_array($d) && isset($d['source'])) return $d;
    }

    return null;
}

function normaliseCard($card) {
    $links = [];
    foreach (($card['links'] ?? []) as $l) {
        if (!empty($l['u'])) {
            $links[] = [
                'l' => $l['l'] ?? parse_url($l['u'], PHP_URL_HOST) ?? $l['u'],
                'u' => $l['u'],
                't' => $l['t'] ?? '',
            ];
        }
    }

    return [
        'source'  => trim($card['source']  ?? 'User Upload'),
        'cat'     => validateCat($card['cat'] ?? 'ai'),
        'summary' => trim($card['summary'] ?? ''),
        'bullets' => array_values(array_filter(array_map('trim', $card['bullets'] ?? []))),
        'links'   => $links,
    ];
}

function validateCat($cat) {
    return in_array($cat, ['3dgs', 'vp', 'tools', 'contact', 'ai']) ? $cat : 'ai';
}


// ══════════════════════════════════════════════════════════════════════
// FALLBACK: Regex parsing when LLM structuring is unavailable
// ══════════════════════════════════════════════════════════════════════

function buildCardFromRawText($text) {
    return [
        'source'  => extractSourceFromText($text),
        'cat'     => detectCategory($text),
        'summary' => buildSummaryFromText($text),
        'bullets' => extractBulletsFromText($text),
        'links'   => extractLinksFromText($text),
    ];
}

function extractSourceFromText($text) {
    // "Name · Title" or "Name | Title"
    if (preg_match('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*[·|]\s*(.{5,60})/', $text, $m)) {
        return trim($m[1]) . ' · ' . trim($m[2]);
    }
    // "by/from Name"
    if (preg_match('/(?:by|from|posted by)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $text, $m)) {
        return $m[1];
    }
    // First proper name-like string
    if (preg_match('/([A-Z][a-z]+ [A-Z][a-z]+)/', $text, $m)) {
        return $m[1];
    }
    return 'User Upload';
}

function detectCategory($text) {
    $t = strtolower($text);
    if (preg_match('/gaussian|splat|3dgs|4dgs|nerf|point.?cloud|colmap|mesh|3d.?reconstruct/i', $t)) return '3dgs';
    if (preg_match('/virtual.?prod|unreal|led.?stage|icvfx|in.?camera|ndisplay|volume|stagecraft/i', $t))  return 'vp';
    if (preg_match('/comfyui|blender|unity|houdini|plugin|sdk|github|tool|software|app\b/i', $t))          return 'tools';
    if (preg_match('/\bdm\b|message|connect|networking|met at|follow|endorse/i', $t))                      return 'contact';
    return 'ai';
}

function buildSummaryFromText($text) {
    // Take first 1-2 meaningful sentences, skipping short/noise lines
    $lines = preg_split('/\n+/', trim($text));
    $content = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (strlen($line) > 30 && !preg_match('/^(https?:|#|@|\d+\s*(likes?|comments?))/i', $line)) {
            $content[] = $line;
            if (count($content) >= 2) break;
        }
    }
    return implode(' ', $content) ?: substr($text, 0, 200);
}

function extractBulletsFromText($text) {
    $bullets = [];
    // Lines starting with bullet chars or dashes
    $lines = preg_split('/\n+/', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^[•\-\*▪➤►]\s+(.+)/', $line, $m) && strlen($m[1]) > 10) {
            $bullets[] = $m[1];
        }
        if (count($bullets) >= 5) break;
    }

    if (empty($bullets)) {
        // Fall back to mid-length lines as implicit bullets
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 20 && strlen($line) < 150 && !preg_match('/^https?:/', $line)) {
                $bullets[] = $line;
            }
            if (count($bullets) >= 4) break;
        }
    }

    return $bullets;
}

function extractLinksFromText($text) {
    $links = [];
    if (preg_match_all('/(https?:\/\/[^\s<>"\')\]]+)/i', $text, $m)) {
        foreach ($m[1] as $url) {
            $url  = rtrim($url, '.,;:)');
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            $links[] = ['l' => $host, 'u' => $url, 't' => ''];
        }
    }
    if (preg_match_all('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $text, $m)) {
        foreach ($m[1] as $email) {
            $links[] = ['l' => $email, 'u' => 'mailto:' . $email, 't' => 'co'];
        }
    }
    return $links;
}
?>
