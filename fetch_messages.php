<?php
// Connexion à la base de données
$dsn = 'mysql:host=localhost;dbname=chatbox';
$username = 'root';
$password = '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $username, $password, $options);

// Récupère le dernier message ID envoyé par l'AJAX
$lastMessageId = isset($_GET['lastMessageId']) ? (int)$_GET['lastMessageId'] : 0;
$chatroomId = isset($_GET['chatroom_id']) ? (int)$_GET['chatroom_id'] : 0;

// Récupérer les nouveaux messages (ceux qui ont un ID supérieur à $lastMessageId)
$stmt = $pdo->prepare("
    SELECT m.id, m.content, m.timestamp, u.pseudo 
    FROM message m 
    JOIN user u ON m.user_id = u.id 
    WHERE m.chatroom_id = ? AND m.id > ? 
    ORDER BY m.timestamp ASC
");
$stmt->execute([$chatroomId, $lastMessageId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [];

foreach ($messages as $message) {
    // Pour chaque message, on récupère les réactions associées
    $stmt = $pdo->prepare("
        SELECT r.emoji, GROUP_CONCAT(u.pseudo) AS users, COUNT(r.emoji) AS count 
        FROM reaction r 
        JOIN user u ON r.user_id = u.id 
        WHERE r.message_id = ? 
        GROUP BY r.emoji
    ");
    $stmt->execute([$message['id']]);
    $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // On ajoute les réactions à chaque message
    $message['reactions'] = $reactions;
    
    // On ajoute le message complet (avec réactions) au tableau des résultats
    $result[] = $message;
}

// Renvoie les messages en JSON
header('Content-Type: application/json');
echo json_encode($result);