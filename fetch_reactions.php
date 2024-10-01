<?php
require 'db_connection.php'; // Include your database connection

if (isset($_GET['chatroom_id']) && isset($_GET['lastReactionId'])) {
    $chatroom_id = (int)$_GET['chatroom_id'];
    $lastReactionId = (int)$_GET['lastReactionId'];

    // Fetch the missing reactions (reactions with an ID greater than lastReactionId)
    $stmt = $pdo->prepare("
        SELECT r.id, r.message_id, r.emoji, u.pseudo 
        FROM reaction r 
        JOIN user u ON r.user_id = u.id 
        JOIN message m ON r.message_id = m.id
        WHERE m.chatroom_id = ? AND m.id IN (SELECT m2.id
        	FROM reaction r2
	        JOIN message m2 ON r2.message_id = m2.id
	        WHERE r2.id > ?)
        ORDER BY r.id ASC
    ");
    $stmt->execute([$chatroom_id, $lastReactionId]);
    $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($reactions);
}
?>
