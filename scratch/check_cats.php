<?php
$pdo = new PDO('mysql:host=localhost;dbname=library_db;charset=utf8mb4', 'root', '');
$cats = $pdo->query('SELECT DISTINCT category FROM books')->fetchAll(PDO::FETCH_COLUMN);
foreach($cats as $c) {
    echo $c . "\n";
}
