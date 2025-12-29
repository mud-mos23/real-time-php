markdown
# RealTime PHP WebSocket

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)
![License](https://img.shields.io/badge/License-MIT-green)
![WebSocket](https://img.shields.io/badge/Protocol-WebSocket-orange)

Un package PHP moderne et puissant pour créer des applications temps réel avec WebSockets. Parfait pour les chats, les jeux en ligne, les tableaux de bord en temps réel et les systèmes de notification.

## ✨ Fonctionnalités

- ✅ **Serveur WebSocket haute performance** basé sur ReactPHP et Ratchet
- ✅ **Client WebSocket PHP & JavaScript** inclus
- ✅ **Système d'événements** complet avec EventDispatcher
- ✅ **Gestion des salles/rooms** pour les groupes
- ✅ **Indicateur d'écriture** (Typing Indicator) en temps réel
- ✅ **Messages privés** et diffusion groupée
- ✅ **Authentification** intégrée
- ✅ **Gestion d'erreurs** robuste
- ✅ **Interface Messenger** complète incluse
- ✅ **Support Promises** pour les opérations asynchrones
- ✅ **Middleware** personnalisable
- ✅ **Design responsive** prêt pour mobile

## 📦 Installation

### Via Composer
```bash
composer require votre-username/real-time-php
Installation manuelle
bash
git clone https://github.com/votre-username/real-time-php.git
cd real-time-php
composer install
🚀 Démarrage rapide
1. Créer un serveur WebSocket simple
php
<?php
// server.php
require __DIR__ . '/vendor/autoload.php';

use RealTimePHP\Server\WebSocketServer;

$server = new WebSocketServer('0.0.0.0', 8080);

// Quand un client se connecte
$server->on('connect', function($connection) {
    echo "Nouveau client connecté: " . $connection->getId();
    
    $connection->send([
        'event' => 'welcome',
        'data' => ['message' => 'Bienvenue!']
    ]);
});

// Gestion des messages
$server->on('message', function($connection, $data) use ($server) {
    $server->broadcast('new_message', [
        'from' => $connection->getId(),
        'message' => $data['message']
    ], [$connection->getId()]);
});

$server->start();
2. Créer un client PHP
php
<?php
// client.php
require __DIR__ . '/vendor/autoload.php';

use RealTimePHP\Client\WebSocketClient;

$client = new WebSocketClient('ws://localhost:8080');
$client->connect();

$client->on('welcome', function($data) {
    echo "Message de bienvenue: " . $data['message'];
});

$client->emit('message', ['message' => 'Hello World!']);
3. Utiliser l'interface Messenger incluse
bash
# Démarrer le serveur
php examples/chat-server.php

# Dans un autre terminal, démarrer le serveur web
php -S localhost:8000 -t examples/

# Ouvrir dans le navigateur
# http://localhost:8000/chat-client.html
