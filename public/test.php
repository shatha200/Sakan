<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../config/bootstrap.php';

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$conn = $em->getConnection();

$sql = "SELECT id, titre, photo_principale FROM annonce LIMIT 5";
$stmt = $conn->executeQuery($sql);
$annonces = $stmt->fetchAllAssociative();

echo "<pre>";
print_r($annonces);
echo "</pre>";
