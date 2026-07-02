<?php
$host = '127.0.0.1';
$user = 'root';
$pass = 'Ironman@2026';

try {
    $pdo = new PDO("mysql:host={$host};port=3306", $user, $pass);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS maths_foundation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "Database maths_foundation ready.\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
