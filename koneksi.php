<?php
declare(strict_types=1);

$host = getenv('REJEKI_DB_HOST') ?: '127.0.0.1';
$database = getenv('REJEKI_DB_NAME') ?: 'rejeki_sore';
$username = getenv('REJEKI_DB_USER') ?: 'root';
$password = getenv('REJEKI_DB_PASSWORD') ?: '';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
