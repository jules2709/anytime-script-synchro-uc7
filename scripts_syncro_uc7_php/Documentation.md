# Documentation - Script de Synchronisation Zendesk (Architecture Hexagonale)

Ce script assure la synchronisation des messages BAQ depuis Zendesk vers la base de données MariaDB. Il a été conçu selon des standards de développement modernes pour garantir sa robustesse et sa facilité d'évolution.

## Architecture du Code

Le projet suit une **Architecture Hexagonale (ou Ports et Adaptateurs)**. L'idée est de séparer le "cœur" métier (ce que doit faire le script) des détails techniques (comment on parle à Zendesk ou à la base de données).

### Structure des dossiers

```text
scripts_uc7_php/
├── src/
│   ├── Domain/                 # LE CŒUR : Contient les règles métier et les modèles de données
│   │   ├── Entity/             # Objets représentant nos données (ex: un Message)
│   │   └── Repository/         # Interfaces (contrats) définissant comment on accède aux données
│   ├── Application/            # L'ORCHESTRE : Coordonne les actions
│   │   ├── Command/            # "Qu'est-ce qu'on veut faire ?" (ex: Synchroniser)
│   │   └── CommandHandler/     # "Comment on le fait ?" (la logique de synchronisation)
│   ├── Infrastructure/         # LA TECHNIQUE : Implémentations concrètes
│   │   ├── ExternalApi/        # Code qui parle à l'API Zendesk
│   │   ├── Persistence/        # Code qui enregistre dans MariaDB
│   │   └── Config/             # Configuration de la "boîte à outils" (Container DI)
│   └── Utils.php               # Fonctions utilitaires partagées
├── script.php                  # Point d'entrée (lanceur du script)
└── composer.json               # Liste des bibliothèques utilisées
```

---

## Concepts Clés (pour les non-développeurs PHP)

### 1. Symfony Messenger (Le "Bureau de Poste")
Imaginez que vous voulez envoyer un colis. Vous ne le livrez pas vous-même à l'adresse finale. Vous le déposez à la poste. 
- **La Commande (Command)** : C'est votre colis. C'est un simple objet qui dit "Je veux synchroniser les messages".
- **Le Bus (Message Bus)** : C'est le service postal qui reçoit votre colis.
- **Le Gestionnaire (Handler)** : C'est le livreur qui sait exactement comment ouvrir le colis et faire le travail.

*Pourquoi ?* Cela permet de décorréler le moment où on demande une action du moment où elle est exécutée. À l'avenir, on pourra facilement déclencher cela via un clic dans une interface ou automatiquement via un Webhook.

### 2. CQRS (Command Query Responsibility Segregation)
On sépare strictement les actions qui **modifient** des données (les "Commandes") des actions qui ne font que **lire** des données (les "Requêtes"). Ici, nous utilisons surtout la partie "Commande" pour l'écriture en base de données.

### 3. Injection de Dépendances (Le "Lego")
Au lieu qu'une classe crée elle-même ses propres outils (ce qui rend le code rigide), elle attend qu'on les lui donne par son constructeur. 
Le fichier `ContainerFactory.php` est comme une notice de montage Lego : il assemble tous les morceaux (API Zendesk, Connexion DB, Messenger) pour créer le script complet prêt à l'emploi.

---

## Détails des Fichiers

| Fichier | utilité |
| :--- | :--- |
| `script.php` | Charge les outils, prépare le "Bureau de Poste" et envoie la commande de synchro. |
| `Message.php` | Représente un message avec ses propriétés (sujet, contenu, date, etc.). |
| `SyncZendeskMessagesHandler.php` | **Le cerveau**. Il boucle sur Zendesk, analyse les messages et demande leur sauvegarde. |
| `ZendeskApiClient.php` | Le spécialiste Zendesk. Il connaît l'URL et comment s'authentifier. |
| `MariaDBMessageRepository.php` | Le spécialiste MariaDB. Il connaît les tables et comment insérer les lignes. |

---

## Comment lancer le script ?

1. Assurez-vous d'avoir installé les bibliothèques : `composer install`.
2. Configurez votre fichier `.env` avec les accès API et DB.
3. Lancez la commande suivante dans votre terminal :
   ```bash
   php script.php
   ```

Le script affichera sa progression et s'arrêtera une fois terminé.
