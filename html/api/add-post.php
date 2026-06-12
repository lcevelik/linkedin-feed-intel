<?php
require_once 'config.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $id = 's' . time() . substr(bin2hex(random_bytes(3)), 0, 6);
    $ts = (new DateTime())->format('Y-m-d\TH:i');

    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO posts (id, source, cat, ts, summary, bullets, links, img_path, img_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $id,
        $input['source'] ?? '',
        $input['cat'] ?? 'ai',
        $ts,
        $input['summary'] ?? '',
        json_encode($input['bullets'] ?? []),
        json_encode($input['links'] ?? []),
        null,
        null
    ]);

    echo json_encode([
        'id' => $id,
        'source' => $input['source'] ?? '',
        'cat' => $input['cat'] ?? 'ai',
        'ts' => $ts,
        'summary' => $input['summary'] ?? '',
        'bullets' => $input['bullets'] ?? [],
        'links' => $input['links'] ?? [],
        'img' => null,
        'imgHash' => null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>
