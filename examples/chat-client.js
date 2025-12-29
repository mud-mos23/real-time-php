// examples/chat-client.js

class ChatClient {
    constructor(url) {
        this.ws = new WebSocket(url);
        this.setupEventListeners();
    }

    setupEventListeners() {
        this.ws.onopen = () => {
            console.log('Connecté au serveur');
            this.register(prompt('Entrez votre nom:'));
        };

        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleEvent(data.event, data.data);
        };

        this.ws.onclose = () => {
            console.log('Déconnecté');
        };
    }

    handleEvent(event, data) {
        switch(event) {
            case 'welcome':
                console.log(data.message);
                break;
            case 'new_message':
                this.displayMessage(data.user, data.message, data.timestamp);
                break;
            case 'user_joined':
                this.showNotification(data.message);
                break;
            case 'user_left':
                this.showNotification(data.message);
                break;
        }
    }

    register(username) {
        this.send('register', { username });
    }

    sendMessage(message) {
        this.send('chat_message', { message });
    }

    send(event, data) {
        this.ws.send(JSON.stringify({ event, data }));
    }

    displayMessage(user, message, timestamp) {
        const chat = document.getElementById('chat');
        const msg = document.createElement('div');
        msg.innerHTML = `<strong>${user}</strong> [${timestamp}]: ${message}`;
        chat.appendChild(msg);
    }

    showNotification(message) {
        console.log(`Notification: ${message}`);
    }
}

// Utilisation
const client = new ChatClient('ws://localhost:8080');