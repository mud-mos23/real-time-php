<?php
// simple-server.php (version avec typing indicator)

require __DIR__ . '/../vendor/autoload.php';

use RealTimePHP\Server\WebSocketServer;

echo "🚀 Démarrage du serveur WebSocket avec typing indicator...\n";
echo "📡 Serveur en écoute sur ws://localhost:8080\n";
echo "📌 Appuyez sur Ctrl+C pour arrêter\n\n";

$server = new WebSocketServer('0.0.0.0', 8080);
$connectedUsers = [];
$typingUsers = []; // Stocker les utilisateurs en train d'écrire

// Événement de connexion
$server->on('connect', function($connection) use (&$connectedUsers) {
    echo "✅ Nouvelle connexion: " . $connection->getId() . "\n";
    
    // Envoyer un message de bienvenue
    $connection->send([
        'event' => 'welcome',
        'data' => [
            'message' => 'Bienvenue sur le serveur WebSocket!',
            'timestamp' => date('H:i:s'),
            'clientId' => $connection->getId(),
            'connections' => count($connectedUsers) + 1
        ]
    ]);
    
    // Ajouter aux utilisateurs connectés
    $connectedUsers[$connection->getId()] = [
        'id' => $connection->getId(),
        'connected_at' => date('Y-m-d H:i:s'),
        'username' => null,
        'last_active' => time()
    ];
});

// Événement de déconnexion
$server->on('disconnect', function($connection) use (&$connectedUsers, &$typingUsers, $server) {
    $username = $connection->getData('username') ?? 'Anonyme';
    $userId = $connection->getId();
    
    echo "❌ Déconnexion: $username\n";
    
    // Retirer des utilisateurs connectés
    unset($connectedUsers[$userId]);
    
    // Retirer des utilisateurs en train d'écrire
    unset($typingUsers[$userId]);
    
    // Informer les autres utilisateurs
    $server->broadcast('user_left', [
        'userId' => $userId,
        'username' => $username,
        'message' => "$username a quitté le chat",
        'timestamp' => date('H:i:s')
    ], [$userId]);
});

// Inscription d'utilisateur
$server->on('register', function($connection, $data) use ($server, &$connectedUsers) {
    $username = htmlspecialchars($data['username'] ?? 'User' . $connection->getId());
    $userId = $connection->getId();
    
    $connection->setData('username', $username);
    $connection->setData('registered_at', date('Y-m-d H:i:s'));
    
    $connectedUsers[$userId]['username'] = $username;
    $connectedUsers[$userId]['avatar'] = $data['avatar'] ?? substr($username, 0, 2);
    
    echo "📝 Inscription: $username\n";
    
    // Accuser réception
    $connection->send([
        'event' => 'registered',
        'data' => [
            'username' => $username,
            'message' => 'Inscription réussie!'
        ]
    ]);
    
    // Informer les autres utilisateurs
    $server->broadcast('user_joined', [
        'user' => [
            'id' => $userId,
            'username' => $username,
            'avatar' => $connectedUsers[$userId]['avatar']
        ],
        'message' => "$username a rejoint le chat",
        'timestamp' => date('H:i:s')
    ], [$userId]);
});

// Gestion des messages
$server->on('message', function($connection, $data) use ($server, &$typingUsers) {
    $message = htmlspecialchars($data['message'] ?? '');
    $username = $connection->getData('username') ?? 'Anonyme';
    $userId = $connection->getId();
    $chatId = $data['chatId'] ?? 'public';
    
    if (empty($message)) {
        return;
    }
    
    echo "💬 Message de $username dans $chatId: $message\n";
    
    // Arrêter l'indicateur d'écriture
    unset($typingUsers[$userId]);
    
    // Répondre au client
    $connection->send([
        'event' => 'message_received',
        'data' => [
            'status' => 'success',
            'message' => 'Message bien reçu!',
            'timestamp' => date('H:i:s')
        ]
    ]);
    
    // Préparer le message à diffuser
    $messageData = [
        'from' => $username,
        'userId' => $userId,
        'message' => $message,
        'timestamp' => date('H:i:s'),
        'chatId' => $chatId
    ];
    
    // Diffuser selon le type de chat
    if ($chatId === 'public') {
        // Diffuser à tous les autres clients
        $server->broadcast('new_message', $messageData, [$userId]);
    } else {
        // Message privé - envoyer seulement au destinataire
        $recipientConnection = null;
        foreach ($server->getConnectionPool()->getAll() as $conn) {
            if ($conn->getId() === $chatId || $conn->getData('username') === $chatId) {
                $recipientConnection = $conn;
                break;
            }
        }
        
        if ($recipientConnection) {
            $recipientConnection->send([
                'event' => 'new_message',
                'data' => $messageData
            ]);
            
            // Envoyer aussi à l'expéditeur pour confirmation
            $connection->send([
                'event' => 'new_message',
                'data' => array_merge($messageData, ['status' => 'sent'])
            ]);
        }
    }
});

// Indicateur d'écriture
$server->on('typing', function($connection, $data) use ($server, &$typingUsers) {
    $userId = $connection->getId();
    $username = $connection->getData('username') ?? 'Anonyme';
    $chatId = $data['chatId'] ?? 'public';
    
    // Mettre à jour le timestamp du typing
    $typingUsers[$userId] = [
        'username' => $username,
        'chatId' => $chatId,
        'timestamp' => time()
    ];
    
    echo "✍️ $username est en train d'écrire dans $chatId\n";
    
    // Diffuser aux autres utilisateurs du même chat
    foreach ($server->getConnectionPool()->getAll() as $client) {
        if ($client->getId() !== $userId) {
            $client->send([
                'event' => 'typing',
                'data' => [
                    'userId' => $userId,
                    'username' => $username,
                    'chatId' => $chatId,
                    'timestamp' => date('H:i:s')
                ]
            ]);
        }
    }
});

// Arrêt de l'indicateur d'écriture
$server->on('typing_stop', function($connection, $data) use ($server, &$typingUsers) {
    $userId = $connection->getId();
    $chatId = $data['chatId'] ?? 'public';
    
    if (isset($typingUsers[$userId]) && $typingUsers[$userId]['chatId'] === $chatId) {
        unset($typingUsers[$userId]);
        
        echo "⏹️ $userId a arrêté d'écrire\n";
        
        // Informer les autres utilisateurs
        $server->broadcast('typing_stop', [
            'userId' => $userId,
            'chatId' => $chatId
        ], [$userId]);
    }
});

// Commande ping
$server->on('ping', function($connection) {
    echo "🏓 Ping de: " . $connection->getId() . "\n";
    
    $connection->send([
        'event' => 'pong',
        'data' => [
            'timestamp' => microtime(true),
            'server_time' => date('Y-m-d H:i:s')
        ]
    ]);
});

// Obtenir la liste des utilisateurs
$server->on('get_users', function($connection) use (&$connectedUsers, $server) {
    $users = [];
    
    foreach ($connectedUsers as $user) {
        if ($user['username']) {
            $users[] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'avatar' => $user['avatar'] ?? substr($user['username'], 0, 2),
                'connected_at' => $user['connected_at'],
                'last_active' => $user['last_active']
            ];
        }
    }
    
    $connection->send([
        'event' => 'user_list',
        'data' => [
            'users' => $users,
            'count' => count($users),
            'timestamp' => date('H:i:s')
        ]
    ]);
});

// Obtenir la liste des utilisateurs en train d'écrire
$server->on('get_typing_users', function($connection) use (&$typingUsers) {
    $connection->send([
        'event' => 'typing_users',
        'data' => [
            'users' => array_values($typingUsers),
            'count' => count($typingUsers),
            'timestamp' => date('H:i:s')
        ]
    ]);
});

// Nettoyer les anciens indicateurs de typing (toutes les 30 secondes)
$server->on('cleanup_typing', function() use (&$typingUsers) {
    $now = time();
    foreach ($typingUsers as $userId => $typingData) {
        if ($now - $typingData['timestamp'] > 10) { // 10 secondes d'inactivité
            unset($typingUsers[$userId]);
        }
    }
});

// Mise à jour de l'activité utilisateur
$server->on('activity_update', function($connection) use (&$connectedUsers) {
    $userId = $connection->getId();
    if (isset($connectedUsers[$userId])) {
        $connectedUsers[$userId]['last_active'] = time();
    }
});

$server->start();