<?php
require_once 'config.php';
require_once 'db.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM posts ORDER BY ts DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $posts = [];
        foreach ($rows as $row) {
            $posts[] = [
                'id' => $row['id'],
                'source' => $row['source'],
                'cat' => $row['cat'],
                'ts' => $row['ts'],
                'summary' => $row['summary'],
                'bullets' => json_decode($row['bullets'], true) ?: [],
                'links' => json_decode($row['links'], true) ?: [],
                'img' => $row['img_path'] ? UPLOADS_URL . $row['img_path'] : null,
                'imgHash' => $row['img_hash']
            ];
        }

        echo json_encode($posts);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch posts: ' . $e->getMessage()]);
    }

} elseif ($method === 'PUT') {
    try {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id parameter']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE posts SET source = ?, cat = ?, summary = ?, bullets = ?, links = ? WHERE id = ?");
        $stmt->execute([
            $input['source'] ?? '',
            $input['cat'] ?? 'ai',
            $input['summary'] ?? '',
            json_encode($input['bullets'] ?? []),
            json_encode($input['links'] ?? []),
            $id
        ]);

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update post: ' . $e->getMessage()]);
    }

} elseif ($method === 'DELETE') {
    try {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id parameter']);
            exit;
        }

        // Get image path before deleting
        $stmt = $pdo->prepare("SELECT img_path FROM posts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['img_path']) {
            $imagePath = UPLOADS_DIR . $row['img_path'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }

        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete post: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
