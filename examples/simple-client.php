<?php
// simple-client.php

require __DIR__ . '/../vendor/autoload.php';

use RealTimePHP\Client\WebSocketClient;
use RealTimePHP\Client\EventDispatcher;

function connectWebSocket() {
    try {
        echo "🔗 Connexion au serveur ws://localhost:8080...\n";
        
        $client = new WebSocketClient('ws://localhost:8080');
        $client->connect();
        
        $dispatcher = new EventDispatcher();
        
        // Configurer les handlers
        $client->on('welcome', function($data) {
            echo "🎉 Bienvenue: " . $data['message'] . "\n";
            echo "📋 Votre ID: " . $data['clientId'] . "\n";
        });
        
        $client->on('message_received', function($data) {
            echo "✅ Serveur a reçu votre message\n";
            echo "📝 Réponse: " . json_encode($data) . "\n";
        });
        
        $client->on('new_message', function($data) {
            echo "📨 Nouveau message diffusé:\n";
            echo "   De: " . $data['from'] . "\n";
            echo "   Message: " . $data['message'] . "\n";
            echo "   À: " . $data['timestamp'] . "\n\n";
        });
        
        $client->on('pong', function($data) {
            echo "🏓 Pong reçu à: " . $data['timestamp'] . "\n";
        });
        
        echo "✅ Connecté avec succès!\n";
        echo "📝 Commandes disponibles:\n";
        echo "   1. message <texte>  - Envoyer un message\n";
        echo "   2. ping             - Tester la connexion\n";
        echo "   3. echo <texte>     - Test d'echo\n";
        echo "   4. quit             - Quitter\n\n";
        
        // Boucle principale
        while (true) {
            echo "> ";
            $input = trim(fgets(STDIN));
            
            if (empty($input)) {
                continue;
            }
            
            $parts = explode(' ', $input, 2);
            $command = $parts[0];
            $argument = $parts[1] ?? '';
            
            switch ($command) {
                case 'message':
                    if (!empty($argument)) {
                        $client->emit('message', ['message' => $argument]);
                        echo "📤 Message envoyé: '$argument'\n";
                    } else {
                        echo "❌ Veuillez spécifier un message\n";
                    }
                    break;
                    
                case 'ping':
                    $client->emit('ping', []);
                    echo "🏓 Ping envoyé\n";
                    break;
                    
                case 'echo':
                    if (!empty($argument)) {
                        $client->emit('echo', ['text' => $argument]);
                        echo "📤 Echo envoyé: '$argument'\n";
                    } else {
                        echo "❌ Veuillez spécifier un texte\n";
                    }
                    break;
                    
                case 'quit':
                case 'exit':
                    echo "👋 Déconnexion...\n";
                    $client->close();
                    return;
                    
                default:
                    echo "❌ Commande inconnue: $command\n";
                    echo "📋 Commandes: message, ping, echo, quit\n";
                    break;
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Erreur: " . $e->getMessage() . "\n";
        echo "💡 Vérifiez que le serveur est démarré\n";
    }
}

// Lancer le client
connectWebSocket();