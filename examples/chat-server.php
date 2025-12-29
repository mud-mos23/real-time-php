<?php
// chat-server.php

require __DIR__ . '/../vendor/autoload.php';

use RealTimePHP\Server\WebSocketServer;
use RealTimePHP\Server\ConnectionPool;
use RealTimePHP\Handler\EventHandler;
use RealTimePHP\Handler\MessageHandler;

echo "🚀 Démarrage du serveur de chat...\n";
echo "📡 Serveur en écoute sur ws://localhost:8080\n";
echo "💬 Client web disponible sur: http://localhost:8000\n";
echo "📌 Appuyez sur Ctrl+C pour arrêter\n\n";

// Initialiser les composants
$connectionPool = new ConnectionPool();
$eventHandler = new EventHandler($connectionPool);
$messageHandler = new MessageHandler($connectionPool, $eventHandler);

// Configurer le serveur
$server = new WebSocketServer('0.0.0.0', 8080);

// Statistiques
$stats = [
    'connections' => 0,
    'messages' => 0,
    'started' => date('Y-m-d H:i:s')
];

// Événement de connexion
$server->on('connect', function($connection) use (&$stats) {
    $stats['connections']++;
    $connection->setData('connected_at', date('Y-m-d H:i:s'));
    
    echo "👤 Nouvel utilisateur connecté: " . $connection->getId() . "\n";
    echo "   Total connectés: " . $stats['connections'] . "\n";
});

// Événement de déconnexion
$server->on('disconnect', function($connection) use (&$stats) {
    $stats['connections']--;
    $username = $connection->getData('username') ?? 'Anonyme';
    
    echo "👋 Déconnexion: $username\n";
    echo "   Restants: " . $stats['connections'] . "\n";
});

// Inscription
$server->on('register', function($connection, $data) use ($server) {
    $username = htmlspecialchars(substr($data['username'] ?? '', 0, 20));
    
    if (empty($username)) {
        $connection->send([
            'event' => 'error',
            'data' => ['message' => 'Nom d\'utilisateur requis']
        ]);
        return;
    }
    
    $connection->setData('username', $username);
    $connection->setData('color', sprintf('#%06X', mt_rand(0, 0xFFFFFF)));
    
    echo "📝 Inscription: $username\n";
    
    // Accuser réception
    $connection->send([
        'event' => 'registered',
        'data' => [
            'username' => $username,
            'color' => $connection->getData('color'),
            'message' => 'Bienvenue dans le chat!'
        ]
    ]);
    
    // Annoncer aux autres
    $server->broadcast('user_joined', [
        'username' => $username,
        'message' => "$username a rejoint le chat",
        'timestamp' => date('H:i:s')
    ], [$connection->getId()]);
    
    // Envoyer la liste des utilisateurs
    $users = [];
    foreach ($server->getConnectionPool()->getAll() as $conn) {
        if ($name = $conn->getData('username')) {
            $users[] = [
                'id' => $conn->getId(),
                'username' => $name,
                'color' => $conn->getData('color')
            ];
        }
    }
    
    $connection->send([
        'event' => 'user_list',
        'data' => ['users' => $users]
    ]);
});

// Messages de chat
$server->on('chat_message', function($connection, $data) use ($server, &$stats) {
    $stats['messages']++;
    $message = htmlspecialchars(substr($data['message'] ?? '', 0, 500));
    $username = $connection->getData('username') ?? 'Anonyme';
    
    if (empty($message)) {
        return;
    }
    
    echo "💬 $username: $message\n";
    echo "   Total messages: " . $stats['messages'] . "\n";
    
    // Diffuser le message
    $server->broadcast('new_message', [
        'id' => uniqid('msg_'),
        'user' => $username,
        'color' => $connection->getData('color'),
        'message' => $message,
        'timestamp' => date('H:i:s'),
        'clientId' => $connection->getId()
    ], [$connection->getId()]);
});

// Messages privés
$server->on('private_message', function($connection, $data) use ($server) {
    $to = $data['to'] ?? null;
    $message = htmlspecialchars(substr($data['message'] ?? '', 0, 500));
    $fromUsername = $connection->getData('username') ?? 'Anonyme';
    
    if (!$to || empty($message)) {
        return;
    }
    
    // Trouver le destinataire
    $recipient = null;
    foreach ($server->getConnectionPool()->getAll() as $conn) {
        if ($conn->getId() === $to || $conn->getData('username') === $to) {
            $recipient = $conn;
            break;
        }
    }
    
    if ($recipient) {
        $recipient->send([
            'event' => 'private_message',
            'data' => [
                'from' => $connection->getId(),
                'fromUsername' => $fromUsername,
                'message' => $message,
                'timestamp' => date('H:i:s')
            ]
        ]);
        
        $connection->send([
            'event' => 'private_sent',
            'data' => [
                'to' => $recipient->getData('username'),
                'message' => $message
            ]
        ]);
        
        echo "🔒 Message privé de $fromUsername à " . $recipient->getData('username') . "\n";
    }
});

// Commande /help
$server->on('command', function($connection, $data) {
    $command = $data['command'] ?? '';
    
    switch ($command) {
        case '/help':
            $connection->send([
                'event' => 'help',
                'data' => [
                    'commands' => [
                        '/help - Afficher cette aide',
                        '/users - Liste des utilisateurs',
                        '/clear - Effacer la conversation',
                        '/me <action> - Décrire une action',
                        '/msg <user> <message> - Message privé'
                    ]
                ]
            ]);
            break;
            
        case '/users':
            $users = [];
            foreach ($connection->getConnectionPool()->getAll() as $conn) {
                if ($name = $conn->getData('username')) {
                    $users[] = $name;
                }
            }
            
            $connection->send([
                'event' => 'user_list',
                'data' => ['users' => $users, 'count' => count($users)]
            ]);
            break;
            
        case '/me':
            $action = $data['args'] ?? '';
            if ($action) {
                $username = $connection->getData('username') ?? 'Anonyme';
                $connection->broadcast('action', [
                    'user' => $username,
                    'action' => $action,
                    'timestamp' => date('H:i:s')
                ], [$connection->getId()]);
            }
            break;
    }
});

// Ping automatique toutes les 30 secondes
$server->on('ping', function($connection) {
    $connection->send(['event' => 'pong', 'data' => ['time' => time()]]);
});

// Démarrer le serveur
$server->start();