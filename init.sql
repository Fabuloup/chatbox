CREATE TABLE chatroom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(255) UNIQUE NOT NULL
);

CREATE TABLE user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pseudo VARCHAR(255) UNIQUE NOT NULL
);

CREATE TABLE message (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chatroom_id INT NOT NULL,
    user_id INT NOT NULL,
    content LONGTEXT NOT NULL,
    pinned BIT(1) NOT NULL DEFAULT 0,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chatroom_id) REFERENCES chatroom(id),
    FOREIGN KEY (user_id) REFERENCES user(id)
);

CREATE TABLE reaction (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(10) NOT NULL,
    FOREIGN KEY (message_id) REFERENCES message(id),
    FOREIGN KEY (user_id) REFERENCES user(id)
);
