<?php
// Database initialization and connection

function getDB() {
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id          TEXT PRIMARY KEY,
            source      TEXT NOT NULL,
            cat         TEXT NOT NULL,
            ts          TEXT NOT NULL,
            summary     TEXT NOT NULL,
            bullets     TEXT NOT NULL,
            links       TEXT NOT NULL,
            img_path    TEXT,
            img_hash    TEXT,
            created_at  TEXT DEFAULT (datetime('now'))
        )");

        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}

function generateId() {
    return 's' . time() . substr(bin2hex(random_bytes(3)), 0, 6);
}

function getCurrentTimestamp() {
    return (new DateTime())->format('Y-m-d\TH:i');
}
?>
