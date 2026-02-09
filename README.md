# Anytime Zendesk Integration

Suite d'outils pour la synchronisation et l'automatisation du support Zendesk chez Anytime. Le projet couvre la migration initiale des anciens messages vers Zendesk, la synchronisation continue des nouveaux messages Zendesk vers MariaDB Admin, ainsi que l'automatisation intelligente des tickets via des workflows n8n.

## Structure du projet

```
any-zendesk/
├── Script_Synchro_Zendesk_Admin/     # Zendesk --> MariaDB (cron periodique)
├── Script_Historique_Admin_Zendesk/  # MariaDB --> Zendesk/Widget (one-shot)
├── Workflows_n8n/                    # Workflows n8n (classification, reponse auto, archivage)
└── README.md
```

## Les 2 scripts PHP

### 1. Script_Synchro_Zendesk_Admin - Synchronisation Zendesk vers MariaDB

Synchronise les nouveaux messages de chat Zendesk vers la base MariaDB Admin. Recupere les evenements de tickets via l'API incrementale, extrait les transcripts de chat, et insere les messages parses dans la table `messages`.

| | |
|-|-|
| **Direction** | Zendesk --> MariaDB |
| **Frequence** | Periodique (cron toutes les 5 min recommande) |
| **Stack** | PHP 8.2, Architecture Hexagonale, Symfony Messenger, Guzzle, PDO |

```bash
cd Script_Synchro_Zendesk_Admin
composer install
cp .env.example .env    # Configurer credentials Zendesk + MariaDB
php script.php
```

Documentation :
- [Guide d'Utilisation](Script_Synchro_Zendesk_Admin/docs/Guide_Utilisation.md)
- [Reference Technique](Script_Synchro_Zendesk_Admin/docs/Reference_Technique.md)
- [Guide de Deploiement IT](Script_Synchro_Zendesk_Admin/docs/Guide_Deploiement.md)

### 2. Script_Historique_Admin_Zendesk - Migration historique vers Zendesk

Migre les anciens messages de MariaDB vers Zendesk via Sunshine Conversations (SunCo), afin que les clients puissent consulter leur historique dans le widget Zendesk.

| | |
|-|-|
| **Direction** | MariaDB --> Zendesk/Widget |
| **Frequence** | Execution unique (+ relance si erreurs) |
| **Stack** | PHP 8.2, Architecture Hexagonale, Symfony Messenger, Guzzle, PDO |

```bash
cd Script_Historique_Admin_Zendesk
composer install
cp .env.example .env    # Configurer credentials SunCo + Zendesk + MariaDB
php script.php          # Tous les utilisateurs
php script.php 3119     # Un seul utilisateur
```

Documentation :
- [Guide d'Utilisation](Script_Historique_Admin_Zendesk/docs/Guide_Utilisation.md)
- [Reference Technique](Script_Historique_Admin_Zendesk/docs/Reference_Technique.md)
- [Guide de Deploiement IT](Script_Historique_Admin_Zendesk/docs/Guide_Deploiement.md)

### Ordre de deploiement

Le script d'historique doit etre execute et termine **avant** d'activer le cron de synchronisation :

```
1. Deployer et executer Script_Historique_Admin_Zendesk   (migration one-shot)
2. Verifier que la migration est complete
3. PUIS activer le cron de Script_Synchro_Zendesk_Admin
```

Le tag `historique_admin` pose par le script d'historique empeche le script de synchro de reimporter ces tickets.

## Workflows n8n

Trois workflows automatisent le traitement des tickets Zendesk via Google Vertex AI (Gemini) :

| Workflow | Role | Declencheur |
|----------|------|-------------|
| **Get_Motif_Contact** | Classification automatique du motif de contact | Schedule : toutes les 10 min (9h-18h, lun-ven) |
| **Automatic_Response** | Generation de proposition de reponse (note interne) | Sous-workflow : appele par Get_Motif_Contact |
| **Store_solved_tickets** | Archivage anonymise (RGPD) + import RAG | Schedule : tous les 2 jours a minuit |

Documentation :
- [Vue d'ensemble des workflows](Workflows_n8n/docs/workflows_overview.md)
- [Get_Motif_Contact](Workflows_n8n/docs/get_motif_contact.md)
- [Automatic_Response](Workflows_n8n/docs/automatic_response.md)
- [Store_solved_tickets](Workflows_n8n/docs/store_solved_tickets.md)

## Prerequis

- **PHP 8.2+** avec extensions `pdo_mysql`, `curl`, `json`, `mbstring`
- **Composer** >= 2.x
- **MariaDB** (table `messages`)
- **Credentials Zendesk** (subdomain, email, token API)
- **Credentials Sunshine Conversations** (app ID, key ID, secret) - uniquement pour le script d'historique
- **Google Cloud** (Vertex AI, Cloud Storage) - uniquement pour les workflows n8n
- **n8n** - pour les workflows d'automatisation

## Securite et RGPD

- Credentials stockees dans des fichiers `.env` (non commites) ou dans n8n
- Anonymisation RGPD des tickets archives (noms, emails, IBAN, etc.)
- Tag `historique_admin` pour eviter les boucles de synchronisation entre les scripts
- Tag `saved_ticket` pour eviter les doublons d'archivage