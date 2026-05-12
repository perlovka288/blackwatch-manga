<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        getenv('DB_HOST'),
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME')
    );

    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $count = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
    echo "✅ Подключение к Neon успешно! В базе манги: $count";
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
