<?php

// Connexion à la base de données
$dsn = 'mysql:host=localhost;dbname=chatbox';
$username = 'root';
$password = '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $username, $password, $options);