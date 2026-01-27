# Anytime Zendesk Integration

Suite d'outils d'automatisation pour synchroniser et traiter les tickets Zendesk chez Anytime. Ce projet combine un script PHP backend avec des workflows n8n pour offrir une solution complète de gestion des tickets de support.

## 📋 Vue d'ensemble

Le projet se divise en deux composants majeurs :

### 1. **Script_MessageZendesk_Admin** - Synchronisation des messages
Script PHP qui synchronise les messages des transcriptions chat Zendesk vers la base de données historique du client (MariaDB).

**Utilité :** Assurer une synchronisation continue entre Zendesk et la base de données historique Admin pour traçabilité et consultation des conversations passées.

**Stack :** PHP 8.2, Hexagonal Architecture, Symfony Messenger, Guzzle, PDO

**Exécution :** Horaire via CRON (peut être intégré dans un système d'orchestration)

📖 [Documentation détaillée](Script_MessageZendesk_Admin/Documentation_IT.md)

### 2. **Workflows_n8n** - Automatisation intelligente
Trois workflows n8n utilisant Google Vertex AI pour automatiser les tâches de support.

| Workflow | Fréquence | Fonction |
|----------|-----------|----------|
| **Automatic_Response** | Horaire | Génère automatiquement des réponses proposées aux tickets entrants |
| **Get_Motif_Contact** | Horaire | Classifie les tickets dans des catégories prédéfinies |
| **Store_solved_tickets** | Hebdomadaire | Archive et anonymise les tickets résolus pour la base de connaissances |

📖 [Documentation des workflows](Workflows_n8n/Documentation_Workflows.md)

## 🚀 Quick Start

### Prérequis
- PHP 8.2+
- Composer
- MariaDB
- Credentials Zendesk (email, token, subdomain)
- Google Cloud (Vertex AI, Cloud Storage) pour les workflows

### Installation du script PHP

```bash
cd Script_MessageZendesk_Admin
composer install
cp .env.example .env
# Éditer .env avec vos credentials
php script.php
```

### Déploiement des workflows n8n

1. Accédez à votre instance n8n
2. Créez un nouveau workflow ou importez depuis les fichiers JSON
3. Configurez les credentials (Zendesk API, Google Service Account)
4. Activez les workflows avec les triggers programmés

## 🏗️ Architecture

### Script PHP : Hexagonal Architecture

```
Domain (Métier)
    ├── Entities : Message
    └── Repositories : Interfaces ZendeskRepository, MessageRepository
         ↓
Application (Use Cases)
    └── Handler : SyncZendeskMessagesHandler
         ↓
Infrastructure (Implémentation)
    ├── ZendeskApiClient (Guzzle)
    └── MariaDBMessageRepository (PDO)
```

### Workflows n8n : Pipeline IA

```
Zendesk → LLM Analyst (Recherche doc) 
       → LLM Writer (Rédaction) 
       → Zendesk Update (Note interne)
```

## 📊 Flux de données

### Script PHP

1. Récupère les événements Zendesk depuis `last_sync.txt`
2. Filtre les transcriptions chat
3. Parse les messages avec regex pour distinguer client/agent
4. Mappe les données (statut, priorité, utilisateurs)
5. Insère dans la table `messages` de la base de données historique du client
6. Sauvegarde le nouveau timestamp pour la prochaine exécution

### Workflows n8n

1. **Automatic_Response** : Analyse → Recherche → Rédaction → Mise à jour ticket
2. **Get_Motif_Contact** : Classification du motif → Mise à jour champ custom
3. **Store_solved_tickets** : Récupération → Anonymisation RGPD → Cloud Storage

## 🔐 Sécurité & RGPD

- Authentification token Zendesk (pas de credentials en dur)
- Variables d'environnement pour les secrets
- Anonymisation complète des données sensibles (noms, emails, IBAN, etc.)

## 📈 Monitoring

### Script PHP
- Vérifier `last_sync.txt` pour le dernier timestamp
- Logs des insertions en base
- Erreurs de connexion Zendesk/MariaDB

### Workflows n8n
- Dashboard n8n : Succès/Erreurs par workflow
- Taux de confiance des classifications
- Volume de réponses automatiques utilisées

## 🔧 Développement

### Ajouter une nouvelle API Zendesk

1. Implémenter dans `ZendeskRepositoryInterface`
2. Ajouter l'implémentation dans `ZendeskApiClient`
3. Utiliser dans `SyncZendeskMessagesHandler`

### Modifier les transformations de données

Voir `Utils.php` pour :
- `mapZendeskStatusToDb()`
- `mapPriorityToReport()`
- `parseCommentBody()`
- Autres fonctions de mapping

## 📚 Documentation complète

- [Documentation Technique - Script PHP](Script_MessageZendesk_Admin/Documentation_IT.md)
- [Documentation Workflows n8n](Workflows_n8n/docs)

## 🤝 Support

Pour questions ou issues :
1. Consulter la documentation complète
2. Vérifier les logs des exécutions précédentes
3. Valider les credentials Zendesk et Google Cloud

