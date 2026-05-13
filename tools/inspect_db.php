<?php
// Script pour inspecter la structure de la base de données
require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__ . '/../.env', __DIR__ . '/../.env.local');

$connectionParams = [
    'driver'   => 'pdo_mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'user'     => 'root',
    'password' => '',
    'dbname'   => 'sakan_paiement',
    'charset'  => 'utf8mb4'
];

$conn = DriverManager::getConnection($connectionParams);
$schemaManager = $conn->createSchemaManager();

echo "=== TABLES DANS sakan_paiement ===\n\n";

$tables = $schemaManager->listTables();
foreach ($tables as $table) {
    $tableName = $table->getName();
    echo "TABLE: {$tableName}\n";
    
    $columns = [];
    foreach ($table->getColumns() as $column) {
        $columns[] = $column->getName();
    }
    echo "  Colonnes: " . implode(', ', $columns) . "\n\n";
}

echo "=== FIN ===\n";
