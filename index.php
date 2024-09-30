<?php
// Connexion à la base de données
$dsn = 'mysql:host=localhost;dbname=chatbox';
$username = 'root';
$password = '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $username, $password, $options);

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

function prepareImage($texte) {
    // Utilisation d'une expression régulière pour supprimer les balises <script> et leur contenu
    return preg_replace('#img\:(https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~\#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b(?:[-a-zA-Z0-9()@:%_\+.~\#?&//=]*))#is', '<img src="$1" />', $texte);
}

// Envoi de nouveaux messages (texte ou image)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $content = removeScript($_POST['message']);

    if(!isset($_GET['pure']))
    {
        $content = htmlspecialchars($content);
    }

    $content = nl2br($content);
    $content = prepareImage($content);

    // Si une image est envoyée
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $imageData = file_get_contents($_FILES['image']['tmp_name']);
        $base64 = 'data:' . $_FILES['image']['type'] . ';base64,' . base64_encode($imageData);
        $content .= '<br><img src="' . $base64 . '">';
    }

    if(!empty($content))
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
        // Insertion de la réaction
        $stmt = $pdo->prepare("INSERT INTO reaction (message_id, user_id, emoji) VALUES (?, ?, ?)");
        $stmt->execute([$message_id, $user_id, $emoji]);
        
        // Redirection après la réaction
        header("Location: ?code=$code");
        exit;
    }
}

// Affichage des messages de la chatroom
$stmt = $pdo->prepare("SELECT m.id, m.content, m.timestamp, u.pseudo FROM message m JOIN user u ON m.user_id = u.id WHERE m.chatroom_id = ? ORDER BY m.timestamp ASC");
$stmt->execute([$chatroom_id]);
$messages = $stmt->fetchAll();

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

echo "<h1>Chatbox: $code</h1>";
echo "<div id='notifications-parent'>";
echo "<div id='notif-count'><span id='nb-of-notif'>0</span> notifications</div>";
echo "<button type='button' class='clear-notifications' onclick='clearNotifications()'>Vider</button>";
echo "<div id='notifications-container'>";
echo "</div>";
echo "</div>";
echo "<div id='messages-container'>";
$lastMessageId = 0;
foreach ($messages as $message) {
    if($message['id'] > $lastMessageId)
    {
        $lastMessageId = $message['id'];
    }
    echo "<div>";
    echo "<div".(($message['pseudo'] == $pseudo) ? " class='self'" : "").">";
    echo "<strong class='pseudo'>" . htmlspecialchars($message['pseudo']) . "</strong></br> " . $message['content'];
    echo " </br>";
    echo " <em class='timestamp'>(" . $message['timestamp'] . ")</em>";

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
        echo "<div>Réactions : ";
        foreach ($reactions as $reaction) {
            echo "<span style='margin-right: 10px;' title='" . htmlspecialchars($reaction['users']) . "'>";
            echo htmlspecialchars($reaction['emoji']) . " " . $reaction['count'];
            echo "</span>";
        }
        echo "</div>";
    }

    // Formulaire pour réagir à un message avec un emoji
    echo '<form method="POST" action="?code=' . $code . '">';
    echo '<input type="hidden" name="message_id" value="' . $message['id'] . '">';
    echo '<input type="text" name="emoji" class="emoji-input" maxlength="1" style="width: 3em;" value="😄" placeholder="😄">';
    echo '<input type="submit" name="react" value="Réagir">';
    echo '</form>';

    echo "</div>";
    echo "</div>";
}
echo "</div>";

?>

<!-- Formulaire pour envoyer un message ou une image -->
<form id="messageForm" method="POST" enctype="multipart/form-data">
    <textarea id="messageInput" name="message" placeholder="Tapez votre message"></textarea>
    <button type="button" class="open-emoji-popup">😄</button>
    <input type="file" name="image"><br>
    <input type="submit" value="Envoyer">
</form>

<script>
    let lastMessageId = <?php echo $lastMessageId; ?>; // L'ID du dernier message chargé
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

    function truncateMessage(text)
    {
        if(text.length > 30)
        {
            text = text.substring(0, 27) + "...";
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
                    notificationsCount += 1;
                    // Création de l'élément div pour chaque message
                    const messageDiv = document.createElement('div');
                    messageDiv.setAttribute("id", `message${message.id}`)

                    // Aligner à droite si le message est de l'utilisateur courant
                    if (message.pseudo === pseudo) {
                        messageDiv.classList.add('self');
                    }

                    // Ajout du contenu du message
                    messageDiv.innerHTML = `<strong class="pseudo">${message.pseudo}</strong></br> ${message.content} <em class="timestamp"></br>(${message.timestamp})</em>`;

                    const reactions = message.reactions;

                    // Afficher les réactions sous chaque message
                    if (reactions.length > 0) {
                        const reactionsDiv = document.createElement('div');
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

                    // Formulaire pour réagir à un message avec un emoji
                    const reactionForm = document.createElement('form');
                    reactionForm.method = "POST";
                    reactionForm.action = `?code=${chatroomCode}`;

                    reactionForm.innerHTML = `
                        <input type="hidden" name="message_id" value="${message.id}">
                        <input type="text" name="emoji" class="emoji-input" maxlength="1" style="width: 3em;" value="😊" placeholder="😊">
                        <input type="submit" name="react" value="Réagir">
                    `;

                    messageDiv.appendChild(reactionForm);

                    messageParentDiv = document.createElement("div");
                    messageParentDiv.appendChild(messageDiv)

                    // Ajouter le message dans le conteneur
                    messagesContainer.appendChild(messageParentDiv);

                    // Mettre à jour le dernier message ID
                    lastMessageId = message.id;


                    //---------------
                    // Notifications
                    //---------------

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
                        new Notification(`${message.pseudo} a envoyé un message.`);
                    }
                });

                notificationsCounter.innerHTML = `${notificationsCount}`;
            }
        } catch (error) {
            console.error('Erreur lors du chargement des messages:', error);
        }
    }

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

    function sendMessage() {
        const messageInput = document.getElementById('messageInput');
        const message = messageInput.value.trim();

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
    let activeEmojiInput = null;

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
        notifContainer.innerHTML = '';
    }

    // Fonction pour afficher la popup d'emojis
    function showEmojiPopup(inputElement = null) {
        if(inputElement !== null && inputElement.classList.contains('emoji-input'))
        {
            activeEmojiInput = inputElement;
            const rect = inputElement.getBoundingClientRect();
            //emojiPopup.style.left = `${rect.left}px`;
            //emojiPopup.style.top = `${rect.bottom + window.scrollY}px`;
        }
        emojiPopup.style.left = '15vw';
        emojiPopup.style.top = `calc(5vh + ${window.scrollY}px)`;
        emojiPopup.style.display = 'block';
    }

    // Fonction pour fermer la popup d'emojis
    function hideEmojiPopup() {
        emojiPopup.style.display = 'none';
        activeEmojiInput = null;
    }

    // Quand un emoji est cliqué dans la popup
    emojiList.addEventListener('click', function(event) {
        if (event.target.tagName === 'DIV') return;
        if (activeEmojiInput) {
            activeEmojiInput.value = event.target.textContent; // Remplace l'emoji dans l'input

            const formData = new FormData(activeEmojiInput.parentNode);

            fetch('.?code=<?php echo $code ?>&js', {// &pure
                method: 'POST',
                body: formData
            })
            .catch(error => console.error('Erreur lors de l\'envoi du message:', error));
        }
        else
        {
            document.getElementById('messageInput').value += event.target.textContent;
        }
        hideEmojiPopup();
    });

    // Ajout de l'événement au clic sur l'input emoji
    document.addEventListener('click', function(event) {
        if (event.target.classList.contains('emoji-input')) {
            showEmojiPopup(event.target);
        }
        else if(event.target.classList.contains("open-emoji-popup"))
        {
            showEmojiPopup();
        }
        else if (!emojiPopup.contains(event.target)) {
            hideEmojiPopup();
        }
    });

    // Appelle la fonction toutes les 2 secondes
    setInterval(loadNewMessages, 2000);

    // scroll en bas au chargement
    var element = document.getElementById("messages-container");
    element.scrollTop = element.scrollHeight;

    // Appeler la fonction lorsque la page est chargée
    document.addEventListener('DOMContentLoaded', generateEmojis);

    if(Notification.permission !== "granted" && Notification.permission !== "denied")
    {
        Notification.requestPermission();
    }
</script>