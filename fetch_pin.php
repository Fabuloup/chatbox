<?php
require 'db_connection.php'; // Include your database connection

// Récupère le dernier message ID envoyé par l'AJAX
$chatroomId = isset($_GET['chatroom_id']) ? (int)$_GET['chatroom_id'] : 0;

// Récupérer les nouveaux messages (ceux qui ont un ID supérieur à $lastMessageId)
$stmt = $pdo->prepare("
    SELECT m.id, m.content, m.timestamp, u.pseudo 
    FROM message m 
    JOIN user u ON m.user_id = u.id 
    WHERE m.chatroom_id = ? AND m.pinned = 1 
    ORDER BY m.timestamp ASC
");
$stmt->execute([$chatroomId, $lastMessageId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Renvoie les messages en JSON
header('Content-Type: application/json');
echo json_encode($messages);