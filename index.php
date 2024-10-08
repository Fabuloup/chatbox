<?php
require 'db_connection.php'; // Include your database connection

// Vérifie si le paramètre "code" est passé
if (!isset($_GET['code'])) {
    die('Code de la chatroom non fourni.');
}

$code = $_GET['code'];

// Vérifie si la chatroom existe, sinon la crée
$stmt = $pdo->prepare("SELECT id FROM chatroom WHERE code = ?");
$stmt->execute([$code]);
$chatroom = $stmt->fetch();

if (!$chatroom) {
    $stmt = $pdo->prepare("INSERT INTO chatroom (code) VALUES (?)");
    $stmt->execute([$code]);
    $chatroom_id = $pdo->lastInsertId();
} else {
    $chatroom_id = $chatroom['id'];
}

// Gestion du cookie de pseudo
if (!isset($_COOKIE['pseudo'])) {
    // Formulaire pour demander le pseudo s'il n'existe pas
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pseudo'])) {
        $stmt = $pdo->prepare("SELECT u.pseudo FROM user u WHERE u.pseudo = ?");
        $stmt->execute([$_POST['pseudo']]);
        $pseudo = $stmt->fetch(PDO::FETCH_COLUMN);

        if(!$pseudo)
        {
            $pseudo = $_POST['pseudo'] . "#" . rand(1000, 9999);

            // Insertion dans la table "user"
            $stmt = $pdo->prepare("INSERT INTO user (pseudo) VALUES (?)");
            $stmt->execute([$pseudo]);
        }


        // Créer un cookie avec une durée de vie de 1 an
        setcookie('pseudo', $pseudo, time() + (365 * 24 * 60 * 60));

        // Redirection après création du cookie
        header("Location: ?code=$code");
        exit;
    } else {
        // Formulaire pour entrer un pseudo
        echo '<form method="POST" action="?code=' . $code . '">';
        echo '<input type="text" name="pseudo" placeholder="Entrez votre pseudo" required>';
        echo '<input type="submit" value="Créer mon pseudo">';
        echo '</form>';
        exit;
    }
}

// Récupérer l'utilisateur par son pseudo
$pseudo = $_COOKIE['pseudo'];
$stmt = $pdo->prepare("SELECT id FROM user WHERE pseudo = ?");
$stmt->execute([$pseudo]);
$user = $stmt->fetch();
if (!$user) {
    die('Utilisateur non trouvé.');
}
$user_id = $user['id'];

function removeScript($texte) {
    // Utilisation d'une expression régulière pour supprimer les balises <script> et leur contenu
    return preg_replace('#<script(.*?)>(.*?)</script>#is', '', $texte);
}

function prepareResponse($texte) {
    return preg_replace("#resp:(message\d+)¤([\s\S]+)¤#is", "<a href='#$1' class='response'>$2</a>", $texte);
}

function prepareImage($texte) {
    // Utilisation d'une expression régulière pour ajouter une image depuis une url
    $texte = preg_replace('#img\:(data:image\/[a-z]+\;base64\,[\S]+)#is', '<img src="$1" />', $texte);
    return preg_replace('#img\:(https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~\#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b(?:[-a-zA-Z0-9()@:%_\+.~\#?&//=]*))#is', '<img src="$1" />', $texte);
}

function prepareCode($texte) {
    // Utilisation d'une expression régulière pour ajouter une image depuis une url
    $texte = preg_replace('#(```)([a-zA-Z]*)?\n([\s\S]*?)\n(```)#is', '<pre><code>$3</code></pre>', $texte);
    return preg_replace('#`([^`]+)`#is', '<code>$1</code>', $texte);
}

// Envoi de nouveaux messages (texte ou image)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $content = removeScript($_POST['message']);

    if(!isset($_GET['pure']))
    {
        $content = htmlspecialchars($content);
    }

    $content = prepareResponse($content);
    $content = prepareImage($content);
    $content = prepareCode($content);
    $content = nl2br($content);

    // Si une image est envoyée
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $imageData = file_get_contents($_FILES['image']['tmp_name']);
        $base64 = 'data:' . $_FILES['image']['type'] . ';base64,' . base64_encode($imageData);
        $content .= '<br><img src="' . $base64 . '">';
    }

    if(!empty($content) && isset($_POST['message_id']))
    {
        // Update the message in the database
        $stmt = $pdo->prepare("UPDATE message SET content = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$content, $_POST['message_id'], $user_id]);
    }
    elseif(!empty($content))
    {
        // Insertion du message en base
        $stmt = $pdo->prepare("INSERT INTO message (chatroom_id, user_id, content) VALUES (?, ?, ?)");
        $stmt->execute([$chatroom_id, $user_id, $content]);
    }

    // Redirection pour éviter le renvoi de formulaire
    if(!isset($_GET['js']))
    {
        header("Location: ?code=$code");
        exit;
    }
    else
    {
        die("Success");
    }
}

// Réagir à un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['react'])) {
    $emoji = $_POST['emoji'];
    $message_id = $_POST['message_id'];

    if(!empty($emoji))
    {
        // On vérifie si la reaction existe déjà
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reaction WHERE message_id = ? AND user_id = ? AND emoji = ?");
        $stmt->execute([$message_id, $user_id, $emoji]);
        $count = $stmt->fetchColumn();

        if($count == 0)
        {
            // Insertion de la réaction
            $stmt = $pdo->prepare("INSERT INTO reaction (message_id, user_id, emoji) VALUES (?, ?, ?)");
            $stmt->execute([$message_id, $user_id, $emoji]);
        }
        else
        {
            // Suppression de la réaction
            $stmt = $pdo->prepare("DELETE FROM reaction WHERE message_id = ? AND user_id = ? AND emoji = ?");
            $stmt->execute([$message_id, $user_id, $emoji]);

            // Fetch the missing reactions (reactions with an ID greater than lastReactionId)
            $stmt = $pdo->prepare("
                SELECT r.id, m.id AS message_id, r.emoji, u.pseudo 
                FROM message m 
                LEFT JOIN reaction r ON r.message_id = m.id
                LEFT JOIN user u ON r.user_id = u.id 
                WHERE m.id = ? 
                ORDER BY r.id ASC
            ");
            $stmt->execute([$message_id]);
            $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($reactions);
            die();
        }

        
        // Redirection après la réaction
        if(!isset($_GET['js']))
        {
            header("Location: ?code=$code");
            exit;
        }
        else
        {
            die("Success");
        }
    }
}

// Epingler un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    $message_id = $_POST['message_id'];

    // On épingle le message
    $stmt = $pdo->prepare("UPDATE message m1 JOIN message m2 ON m1.id = m2.id SET m1.pinned = 1 ^ m2.pinned WHERE m1.id = ?");
    $stmt->execute([$message_id]);
    $count = $stmt->fetchColumn();
    
    // Redirection après la réaction
    if(!isset($_GET['js']))
    {
        header("Location: ?code=$code");
        exit;
    }
    else
    {
        die("Success");
    }
}

// Affichage des messages de la chatroom
$stmt = $pdo->prepare("SELECT m.id, m.content, m.timestamp, u.pseudo FROM message m JOIN user u ON m.user_id = u.id WHERE m.chatroom_id = ? ORDER BY m.timestamp ASC");
$stmt->execute([$chatroom_id]);
$messages = $stmt->fetchAll();

echo "<!DOCTYPE html>";
echo "<head>";
echo "<link rel='stylesheet' href='style.css'>";
echo "<link rel='icon' type='image/png' href='favicon.png' />";
echo "</head>";

echo <<<EOL
<!-- Pop-up pour sélectionner un emoji -->
<div id="emojiPopup" style="display: none;">
    <!-- Liste d'emojis -->
    <div id="emojiList">
        😊 😁 😂 😃 😄 😅 😆 😉 😋 😎 😍 😘 😗 😙 😚 🤗 🤔 🤩 🤨 🤯 🥳 😷 😱 🥵 🥶 😴
        <!-- Ajouter plus d'emojis ici si nécessaire -->
    </div>
</div>
EOL;

echo "<div>";
echo "<h1>Chatbox: $code</h1>";
echo <<<EOL
    <div class="theme-switcher-frame">
        <div class="theme-switcher-button">
        </div
    </div>
EOL;
echo "</div>";
echo "<div id='notifications-parent'>";
echo "<div id='notif-count'><span id='nb-of-notif'>0</span> notifications</div>";
echo "<button type='button' class='clear-notifications' onclick='clearNotifications()'>Vider</button>";
echo "<div id='notifications-container'>";
echo "</div>";
echo "</div>";
echo "<div class='row'>";
echo "<div id='messages-container'>";
$lastMessageId = 0;
$lastReactionId = 0;
foreach ($messages as $message) {
    if($message['id'] > $lastMessageId)
    {
        $lastMessageId = $message['id'];
    }
    echo "<div id='message{$message['id']}'>";
    echo "<div".(($message['pseudo'] == $pseudo) ? " class='self'" : "").">";
    echo "<div class='pseudo-container'><strong class='pseudo'>" . htmlspecialchars($message['pseudo']) . "</strong></div><div class='content'>" . $message['content'] . "</div>";
    echo " <em class='timestamp'>(" . $message['timestamp'] . ")</em>";

    // Add an edit button for the user's own messages
    if ($message['pseudo'] == $pseudo) {
        echo '<button class="edit-button" data-message-id="' . $message['id'] . '">Edit</button>';
    }

    // Récupérer les réactions pour ce message

    $stmt = $pdo->prepare("
        SELECT r.emoji, GROUP_CONCAT(u.pseudo) AS users, COUNT(r.emoji) AS count 
        FROM reaction r 
        JOIN user u ON r.user_id = u.id 
        WHERE r.message_id = ? 
        GROUP BY r.emoji COLLATE utf8mb4_bin
    ");
    $stmt->execute([$message['id']]);
    $reactions = $stmt->fetchAll();

    // Affichage des réactions (emoji + nombre) et utilisateurs au survol
    if (!empty($reactions)) {
        echo "<div class='reactions'>Réactions : ";
        foreach ($reactions as $reaction) {
            echo "<span style='margin-right: 10px;' title='" . htmlspecialchars($reaction['users']) . "'>";
            echo htmlspecialchars($reaction['emoji']) . " " . $reaction['count'];
            echo "</span>";
        }
        echo "</div>";
    }

    // Formulaire pour réagir à un message avec un emoji

    echo '<div class="message-toolbar">';
    echo '<button class="react" data-message-id="' . $message['id'] . '">😄</button>';
    echo '<button class="respond" data-message-id="' . $message['id'] . '">➥</button>';
    echo '<button class="pin" data-message-id="' . $message['id'] . '">📌</button>';
    echo '</div>';

    echo "</div>";
    echo "</div>";
}

echo "</div>";
echo "<div id='pinned-messages-container'>";
echo "</div>";
echo "</div>";

// Get max reaction id
$stmt = $pdo->prepare("
    SELECT MAX(r.id) 
    FROM reaction r
");
$stmt->execute();
$lastReactionId = $stmt->fetchColumn();
?>

<!-- Formulaire pour envoyer un message ou une image -->
<form id="messageForm" method="POST" enctype="multipart/form-data">
    <textarea id="messageInput" name="message" placeholder="Tapez votre message"></textarea>
    <button type="button" class="open-emoji-popup">😄</button>
    <input type="file" name="image"><br>
    <input type="submit" value="Envoyer">
</form>

<script src="jquery-3.7.1.min.js"></script>
<script>
    const appPrefix = "chatbox";
    let lastMessageId = <?php echo $lastMessageId; ?>; // L'ID du dernier message chargé
    let lastReactionId = <?php echo $lastReactionId; ?>; // L'ID de la dernière réaction chargée
    const chatroomId = <?php echo $chatroom_id; ?>; // ID de la chatroom
    const chatroomCode = "<?php echo $code; ?>"; // ID de la chatroom
    const pseudo = "<?php echo $pseudo; ?>"; // Pseudo de l'utilisateur
    // Liste des points de code emoji (de base) que nous voulons afficher
    const emojiRanges = [
        [0x1F600, 0x1F64F], // Smileys & Emotion
        [0x1F300, 0x1F5FF], // Symbols & Pictographs
        [0x1F680, 0x1F6FF], // Transport & Map Symbols
        [0x1F1E6, 0x1F1FF], // Flags
        [0x2600, 0x26FF],   // Miscellaneous Symbols
        [0x2700, 0x27BF]    // Dingbats
    ];

    function truncateMessage(text, size = 30)
    {
        if(text.length > size)
        {
            text = text.substring(0, size-3) + "...";
        }

        return text
    }

    // Fonction pour charger les nouveaux messages via AJAX
    async function loadNewMessages() {
        try {
            const response = await fetch(`fetch_messages.php?chatroom_id=${chatroomId}&lastMessageId=${lastMessageId}`);
            const messages = await response.json();

            if (messages.length > 0) {
                const messagesContainer = document.getElementById('messages-container');
                const notificationsContainer = document.getElementById('notifications-container');
                const notificationsCounter = document.getElementById('nb-of-notif');
                let notificationsCount = parseInt(notificationsCounter.innerText);

                messages.forEach(async message => {
                    // Création de l'élément div pour chaque message
                    const messageDiv = document.createElement('div');

                    // Aligner à droite si le message est de l'utilisateur courant
                    if (message.pseudo === pseudo) {
                        messageDiv.classList.add('self');
                    }

                    // Ajout du contenu du message
                    messageDiv.innerHTML = `<div class="pseudo-container"><strong class="pseudo">${message.pseudo}</strong></div><div class="content">${message.content}</div><em class="timestamp">(${message.timestamp})</em>`;

                    // Ajouter un bouton d'édition si l'utilisateur est l'auteur du message
                    if (message.pseudo === pseudo) {
                        const editButton = document.createElement('button');
                        editButton.textContent = 'Éditer';
                        editButton.classList.add('edit-button');
                        editButton.setAttribute('data-message-id', message.id)

                        messageDiv.appendChild(editButton);
                    }

                    const reactions = message.reactions;

                    // Afficher les réactions sous chaque message
                    if (reactions.length > 0) {
                        const reactionsDiv = document.createElement('div');
                        reactionsDiv.classList.add("reactions");
                        reactionsDiv.innerHTML = "Réactions : ";

                        reactions.forEach(reaction => {
                            const reactionSpan = document.createElement('span');
                            reactionSpan.style.marginRight = "10px";
                            reactionSpan.title = reaction.users.join(", "); // Liste des utilisateurs au survol
                            reactionSpan.textContent = `${reaction.emoji} ${reaction.count}`;
                            reactionsDiv.appendChild(reactionSpan);
                        });

                        messageDiv.appendChild(reactionsDiv);
                    }
                    const toolbar = document.createElement('div');
                    toolbar.classList.add('message-toolbar');

                    // Bouton pour réagir à un message avec un emoji
                    const reactionBtn = document.createElement('button');
                    reactionBtn.classList.add('react');
                    reactionBtn.setAttribute('data-message-id', message.id);
                    reactionBtn.innerHTML = "😄"

                    // Bouton pour répondre à un message
                    const respondBtn = document.createElement('button');
                    respondBtn.classList.add('respond');
                    respondBtn.setAttribute('data-message-id', message.id);
                    respondBtn.innerHTML = "➥"

                    // Bouton pour épingler un message
                    const pinBtn = document.createElement('button');
                    pinBtn.classList.add('pin');
                    pinBtn.setAttribute('data-message-id', message.id);
                    pinBtn.innerHTML = "📌"

                    toolbar.appendChild(reactionBtn);
                    toolbar.appendChild(respondBtn);
                    toolbar.appendChild(pinBtn);
                    messageDiv.appendChild(toolbar);

                    messageParentDiv = document.createElement("div");
                    messageParentDiv.setAttribute("id", `message${message.id}`)
                    messageParentDiv.appendChild(messageDiv)

                    // Ajouter le message dans le conteneur
                    messagesContainer.appendChild(messageParentDiv);

                    // Mettre à jour le dernier message ID
                    lastMessageId = message.id;


                    //---------------
                    // Notifications
                    //---------------

                    if (message.pseudo !== pseudo) {
                        notificationsCount += 1;

                        // On ajoute la notification
                        const notifDiv = document.createElement('div');
                        const messageLink = document.createElement('a');
                        messageLink.innerHTML = `<strong>${message.pseudo}</strong> ${truncateMessage(message.content)}`;
                        messageLink.href = `#message${message.id}`;

                        // On ajoute le lien dans la div
                        notifDiv.appendChild(messageLink);

                        // Ajouter la notification dans le conteneur
                        notificationsContainer.appendChild(notifDiv);

                        if(Notification.permission === "granted")
                        {
                            const notif = new Notification(`${message.pseudo} a envoyé un message.`, {
                                badge: "https://experiments.fabien-richard.fr/experiments/chatbox/favicon.png",
                                icon: "https://experiments.fabien-richard.fr/experiments/chatbox/favicon.png"
                            });

                            let ref = `message${message.id}`;

                            // Handle notification click event
                            notif.onclick = function() {
                                // Focus on the page/tab
                                window.focus();

                                // Scroll to the target section
                                const targetElement = document.getElementById(ref);

                                // Check if target exists and scroll to it
                                if (targetElement) {
                                    targetElement.scrollIntoView({ behavior: "smooth" });
                                }
                            };
                        }
                    }
                });

                notificationsCounter.innerHTML = `${notificationsCount}`;
                if(notificationsCount > 0)
                {
                    document.title = `(${notificationsCount}) Chatbox : ${chatroomCode}`;
                }
            }
        } catch (error) {
            console.error('Erreur lors du chargement des messages:', error);
        }
    }

    // Fonction pour charger les nouveaux messages via AJAX
    async function loadPin() {
        try {
            const response = await fetch(`fetch_pin.php?chatroom_id=${chatroomId}`);
            const messages = await response.json();
            const pinContainer = document.getElementById('pinned-messages-container');
            pinContainer.innerHTML = "";

            if (messages.length > 0) {

                messages.forEach(async message => {
                    // Création de la balise pour chaque message
                    const messageLink = document.createElement('a');

                    // Ajout du contenu du message
                    messageLink.innerHTML = `<strong>${message.pseudo}</strong> ${truncateMessage(message.content, 70)}`;
                    messageLink.href = `#message${message.id}`;

                    // Ajouter le message dans le conteneur
                    pinContainer.appendChild(messageLink);
                });
            }
        } catch (error) {
            console.error('Erreur lors du chargement des messages:', error);
        }
    }

    // Function to load new reactions via AJAX
    async function loadNewReactions() {
        try {
            const response = await fetch(`fetch_reactions.php?chatroom_id=${chatroomId}&lastReactionId=${lastReactionId}`);
            const data = await response.json();
            const reactions = Array.isArray(data) ? data : [];

            if (reactions.length > 0) {
                updateReactions(reactions);

                // Update lastReactionId to the highest id received
                lastReactionId = Math.max(...reactions.map(r => r.id));
            }
        } catch (error) {
            console.error('Error loading reactions:', error);
        }
    }

    function updateReactions(reactions)
    {
        if (reactions.length > 0) {
            // Regroup reactions by message_id
            const reactionsByMessage = reactions.reduce((acc, reaction) => {
                if (!acc[reaction.message_id]) {
                    acc[reaction.message_id] = [];
                }
                acc[reaction.message_id].push(reaction);
                return acc;
            }, {});

            Object.keys(reactionsByMessage).forEach(messageId => {
                const messageDiv = document.getElementById('message' + messageId);
                if (messageDiv) {
                    let reactionsDiv = messageDiv.querySelector('.reactions');
                    if (!reactionsDiv) {
                        // If no reaction container exists, create it
                        reactionsDiv = document.createElement('div');
                        reactionsDiv.classList.add('reactions');
                        messageDiv.firstChild.insertBefore(reactionsDiv, messageDiv.firstChild.querySelector('.message-toolbar'));
                    }

                    // Clear the current content of the reactions div
                    reactionsDiv.innerHTML = 'Réactions : ';

                    // Transformation de la structure
                    const transformedReactions = {};

                    Object.keys(reactionsByMessage).forEach(messageId => {
                        const reactions = reactionsByMessage[messageId];
                        const emojiMap = {};

                        // Grouper les réactions par emoji
                        reactions.forEach(reaction => {
                            if(reaction.id === null)
                            {
                                return;
                            }

                            const emoji = reaction.emoji;

                            if (!emojiMap[emoji]) {
                                // Si cet emoji n'existe pas encore, on l'initialise
                                emojiMap[emoji] = {
                                    count: 0,
                                    users: []
                                };
                            }

                            // Incrémenter le compteur et ajouter l'utilisateur
                            emojiMap[emoji].count += 1;
                            emojiMap[emoji].users.push(reaction.pseudo);
                        });

                        if(Object.keys(emojiMap).length == 0)
                        {
                            reactionsDiv.remove();
                        }
                        else
                        {
                            // Construire la structure finale pour ce message_id
                            transformedReactions[messageId] = Object.keys(emojiMap).map(emoji => ({
                                emoji: emoji,
                                count: emojiMap[emoji].count,
                                users: emojiMap[emoji].users.join(',')
                            }));
                        }
                    });

                    const reactions = transformedReactions[messageId];
                    reactions.forEach(reaction => {
                        const reactionSpan = document.createElement('span');
                        reactionSpan.style.marginRight = "10px";
                        reactionSpan.title = reaction.users
                        reactionSpan.innerHTML = `${reaction.emoji} <span class="count">${reaction.count}</span>`;
                        // Add new reactions for this message
                        reactionsDiv.appendChild(reactionSpan);
                    })
                }
            });
        }
    }

    function sendMessage() {
        const messageInput = document.getElementById('messageInput');
        const message = messageInput.value.trim();

        if(Notification.permission !== "granted" && Notification.permission !== "denied")
        {
            Notification.requestPermission();
        }

        if (message.length > 0) {
            // Envoi du message via AJAX
            const formData = new FormData(document.getElementById('messageForm'));


            fetch('.?code=<?php echo $code ?>&js', {// &pure
                method: 'POST',
                body: formData
            })
            .finally(() => {
                // Effacer le champ de saisie après l'envoi
                document.getElementById('messageForm').reset();
            })
            .catch(error => console.error('Erreur lors de l\'envoi du message:', error));
        }
    }

    // Pop-up d'emoji
    const emojiPopup = document.getElementById('emojiPopup');
    const emojiList = document.getElementById('emojiList');
    let reactMessageId = null;

    // Fonction pour générer les emojis et les ajouter dans emojiList
    function generateEmojis() {
        const emojiList = document.getElementById('emojiList');
        emojiList.innerHTML = ''; // On vide l'élément s'il contient déjà des emojis

        // Parcourir les plages de points de code des emojis
        emojiRanges.forEach(range => {
            for (let codePoint = range[0]; codePoint <= range[1]; codePoint++) {
                const emoji = String.fromCodePoint(codePoint);
                const emojiSpan = document.createElement('span');
                emojiSpan.textContent = emoji;
                emojiSpan.style.fontSize = '24px'; // Agrandir un peu les emojis
                emojiSpan.style.cursor = 'pointer'; // Pour pointer quand on survole
                emojiSpan.style.margin = '5px';
                emojiSpan.title = emoji; // Optionnel, ajoute l'emoji en infobulle
                emojiList.appendChild(emojiSpan);
            }
        });
    }

    function clearNotifications()
    {
        const notifContainer = document.getElementById('notifications-container');
        const notifCount = document.getElementById('nb-of-notif');
        notifContainer.innerHTML = '';
        notifCount.innerHTML = '0';
        document.title = "Chatbox : "+chatroomCode;
    }

    function htmlToMessage(text)
    {
        // Preprocess the content by replacing <code> tags with ` and <img> tags with img:source_of_image
        let processedContent = text;

        // Replace response tags with resp:message_id¤message_content¤
        processedContent = processedContent.replace(/<a href=[\'\"]\#(message[0-9]+)[\'\"][^\>]*>([\s\S]*?)<\/a>/g, 'resp:$1¤$2¤');

        // Replace <code> tags with single backticks
        processedContent = processedContent.replace(/<code>([\s\S]*?)<\/code>/g, '`$1`');

        // Replace <img> tags with img:source_of_image
        processedContent = processedContent.replace(/<img.*?src=["'](.*?)["'].*?>/g, 'img:$1');

        // Replace <br> tags
        processedContent = processedContent.replace(/<\/?br>/g, '');

        return processedContent;
    }

    function htmlToResponse(text)
    {
        // Preprocess the content by replacing <code> tags with ` and <img> tags with img:source_of_image
        let processedContent = text;

        // Replace response tags with resp:message_id¤message_content¤
        processedContent = processedContent.replace(/<a href=[\'\"]\#(message[0-9]+)[\'\"][^\>]*>([\s\S]*?)<\/a>/g, '');

        // Replace <code> tags with single backticks
        processedContent = processedContent.replace(/<code>([\s\S]*?)<\/code>/g, '`$1`');

        // Replace <img> tags with img:source_of_image
        processedContent = processedContent.replace(/<img.*?src=["'](.*?)["'].*?>/g, 'IMAGE');

        // Replace <br> tags
        processedContent = processedContent.replace(/<\/?br>/g, '');

        // Replace line break
        processedContent = processedContent.replace(/\n/g, ' ');

        if(processedContent.length > 50)
        {
            processedContent = processedContent.substring(0, 47) + "...";
        }

        return processedContent;
    }

    function editMessage(messageId)
    {
        const messageDiv = document.getElementById('message' + messageId);
        const originalDivContent = messageDiv.innerHTML;
        const originalContent = messageDiv.querySelector('.content').innerHTML;

        // Preprocess the content by replacing <code> tags with ` and <img> tags with img:source_of_image
        let processedContent = htmlToMessage(originalContent);

        // Clear the message div
        messageDiv.innerHTML = '';

        // Create the form dynamically
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `?code=${chatroomCode}`;

        // Create the textarea for editing the content
        const textarea = document.createElement('textarea');
        textarea.name = 'message';
        textarea.rows = 4;
        textarea.cols = 50;
        textarea.value = processedContent;
        form.appendChild(textarea);

        // Create the hidden input for message_id
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'message_id';
        hiddenInput.value = messageId;
        form.appendChild(hiddenInput);

        // Create the save button (submit button)
        const saveButton = document.createElement('input');
        saveButton.type = 'submit';
        saveButton.name = 'edit_message';
        saveButton.value = 'Enregistrer';
        form.appendChild(saveButton);

        // Create the cancel button
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.classList.add('cancel-edit');
        cancelButton.setAttribute('data-message-id', messageId);
        cancelButton.textContent = 'Annuler';

        // Add the cancel button to the form
        form.appendChild(cancelButton);

        // Add the form to the message div
        messageDiv.appendChild(form);

        // Handle cancel button click
        cancelButton.onclick = function (event) {
            const messageId = event.target.getAttribute('data-message-id');
            const messageDiv = document.getElementById('message' + messageId);
            const originalContent = messageDiv.querySelector('textarea').value;

            // Revert to the original content without saving
            messageDiv.innerHTML = `${originalDivContent}`;
        };
    }

    // Fonction pour afficher la popup d'emojis
    function showEmojiPopup() {
        emojiPopup.style.left = '15vw';
        emojiPopup.style.top = `calc(5vh + ${window.scrollY}px)`;
        emojiPopup.style.display = 'block';
    }

    // Fonction pour fermer la popup d'emojis
    function hideEmojiPopup() {
        emojiPopup.style.display = 'none';
        reactMessageId = null;
    }

    $(document).ready(function() {

        document.getElementById('messageInput').addEventListener('keydown', function(event) {
            // Vérifier si la touche "Entrée" est appuyée
            if (event.key === 'Enter') {
                // Si "Maj" + "Entrée", insérer une nouvelle ligne
                if (event.shiftKey) {
                    // Autoriser le saut de ligne (ne rien faire ici)
                    return;
                } else {
                    // Sinon, empêcher le comportement par défaut (ajout d'une nouvelle ligne)
                    event.preventDefault();

                    // Envoyer le formulaire ou le message via AJAX
                    sendMessage();
                }
            }
        });

        // Quand un emoji est cliqué dans la popup
        emojiList.addEventListener('click', function(event) {
            if (event.target.tagName === 'DIV') return;
            if (reactMessageId) {
                $.ajax({
                    url: '.?code=<?php echo $code ?>&js',
                    type: 'POST',
                    data: {
                        react: true,
                        emoji: event.target.textContent,
                        message_id: reactMessageId
                    },
                })
                .done(function(data) {
                    try
                    {
                        data = JSON.parse(data);
                        if(Array.isArray(data))
                        {
                            updateReactions(data);
                        }
                    }
                    catch(e){

                    }
                })
                .fail(function(error) {
                    console.error('Erreur lors de l\'envoi du message:', error)
                });
            }
            else
            {
                const messageInput = document.getElementById('messageInput');
                // Get the current cursor position
                const startPos = messageInput.selectionStart;
                const endPos = messageInput.selectionEnd;
                let originalText = messageInput.value;
                messageInput.value = originalText.substring(0, startPos) + event.target.textContent + originalText.substring(endPos);
            }
            hideEmojiPopup();
        });

        document.getElementById('messageInput').addEventListener('paste', function(event) {
            // Prevent the default paste behavior
            event.preventDefault();

            // Get the clipboard data
            let clipboardItems = event.clipboardData.items;

            for (let item of clipboardItems) {
                const messageInput = document.getElementById('messageInput');
                // Get the current cursor position
                const startPos = messageInput.selectionStart;
                const endPos = messageInput.selectionEnd;

                // Check if the clipboard item is an image
                if (item.type.indexOf('image') !== -1) {
                    let blob = item.getAsFile(); // Get the image as a blob

                    // Create a FileReader to convert the image to Base64
                    let reader = new FileReader();
                    reader.onload = function(e) {
                        let imgText = "img:"+e.target.result;
                        if(startPos > 0)
                        {
                            imgText = "\n"+imgText;
                        }

                        if(endPos < messageInput.value.length)
                        {
                            imgText = imgText+"\n";
                        }
                        // Insert the Base64 image string into the textarea
                        let originalText = messageInput.value;
                        messageInput.value = originalText.substring(0, startPos) + imgText + originalText.substring(endPos);
                    };

                    // Read the image file as a Data URL (Base64)
                    reader.readAsDataURL(blob);
                }
                else if (item.kind === 'string' && item.type === 'text/plain') { // If the item is text
                    item.getAsString(function(text) {
                        // Append the pasted text to the textarea
                        let originalText = messageInput.value;
                        messageInput.value = originalText.substring(0, startPos) + text + originalText.substring(endPos);
                    });
                }
            }
        });

        // Ajout de l'événement onClick
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('react')) {
                reactMessageId = event.target.getAttribute('data-message-id');
                showEmojiPopup();
            }
            else if(event.target.classList.contains("open-emoji-popup"))
            {
                reactMessageId = null;
                showEmojiPopup();
            }
            else if (!emojiPopup.contains(event.target)) {
                hideEmojiPopup();
            }

            if (event.target.classList.contains('edit-button')) {
                const messageId = event.target.getAttribute('data-message-id');
                editMessage(messageId);
            }
            else if(event.target.classList.contains('respond'))
            {
                const messageId = event.target.getAttribute('data-message-id');
                const messageContent = $(`#message${messageId} .pseudo`).first().text() + " : " + htmlToResponse($(`#message${messageId} .content`).first().html());
                document.getElementById('messageInput').value = `resp:message${messageId}¤${messageContent}¤\n` + document.getElementById('messageInput').value;
                document.getElementById('messageInput').focus();
            }
            else if(event.target.classList.contains('pin'))
            {
                const messageId = event.target.getAttribute('data-message-id');
                $.ajax({
                    url: '.?code=<?php echo $code ?>&js',
                    type: 'POST',
                    data: {
                        pin: true,
                        message_id: messageId
                    },
                })
                .done(function(data) {
                    try
                    {
                        // Faire une méthode pour charger les épinglés
                        loadPin();
                    }
                    catch(e){

                    }
                })
                .fail(function(error) {
                    console.error('Erreur lors de l\'envoi du message:', error)
                });
            }
            else if(event.target.classList.contains('theme-switcher-frame'))
            {
                const body = document.body;
                if(body.classList.contains('night'))
                {
                    body.classList.remove('night');
                    localStorage.removeItem(appPrefix+"-night");
                }
                else
                {
                    body.classList.add('night');
                    localStorage.setItem(appPrefix+"-night", "true");
                }
            }
        });

        // Appelle la fonction toutes les 2 secondes
        setInterval(loadNewMessages, 2000);
        setInterval(loadNewReactions, 4000);
        setInterval(loadPin, 10000);

        // Attach an event listener to detect scroll on the #messages-container
        document.getElementById('messages-container').addEventListener('scroll', () => {
            const container = document.getElementById('messages-container');

            // Check if the user has scrolled to the bottom
            const isScrolledToBottom = container.scrollHeight - container.scrollTop === container.clientHeight;

            // If the user is at the bottom, call clearNotifications
            if (isScrolledToBottom) {
                clearNotifications();
            }
        });

        // scroll en bas au chargement
        var element = document.getElementById("messages-container");
        element.scrollTop = element.scrollHeight;

        // Appeler la fonction lorsque la page est chargée
        generateEmojis();

        // Load pinned messages
        loadPin();

        // Set night mode if required
        if(localStorage.getItem(appPrefix+"-night") === "true")
        {
            const body = document.body;
            body.classList.add('night');
        }

    });

</script>