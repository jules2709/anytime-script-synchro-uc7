# CLAUDE.md - Anytime Zendesk Integration

## Projet

Synchronisation bidirectionnelle des messages entre Zendesk et la base MariaDB Admin d'Anytime.
Deux taches distinctes, un ensemble de workflows IA (n8n).

## Les 2 taches de synchronisation

### Tache 1 : Zendesk -> MariaDB (PHP)

**Dossier :** `scripts_syncro_uc7_php/`
**Entrypoint :** `php script.php`
**Execution :** CRON horaire

Flux : API incrementale Zendesk (`ticket_events`) -> filtre "Chat Transcript" -> parse regex `(HH:MM:SS) Auteur: Message` -> INSERT table `messages` MariaDB -> sauvegarde timestamp dans `last_sync.txt`.

Filtre les tickets tagges `historique_admin` (provenant de la tache 2) pour eviter les doublons.

### Tache 2 : MariaDB -> Zendesk/Widget (Python)

**Dossier :** `script_historique_admin/`
**Entrypoint :** `python sync_to_zendesk.py [external_id]`
**Execution :** Manuelle, par utilisateur

Flux : SELECT messages avant cutoff (2026-01-22) -> cree user SunCo -> cree conversation SunCo avec messages -> SunCo cree auto un ticket Zendesk -> update ticket (titre, status=solved, tag=historique_admin).

Ce script Python est voue a migrer en PHP.

## Structure des dossiers

```
scripts_syncro_uc7_php/        # [ACTIF] PHP - Zendesk -> MariaDB
  script.php                   # Entrypoint CLI
  src/
    Application/Command/       # SyncZendeskMessagesCommand (DTO)
    Application/CommandHandler/ # SyncZendeskMessagesHandler (logique)
    Domain/Entity/             # Message (entite metier)
    Domain/Repository/         # Interfaces (MessageRepository, ZendeskRepository)
    Infrastructure/Config/     # ContainerFactory (DI Symfony)
    Infrastructure/ExternalApi/ # ZendeskApiClient (Guzzle)
    Infrastructure/Persistence/ # MariaDBMessageRepository (PDO)
    Utils.php                  # Parsing, mapping statuts/priorites, DB

script_historique_admin/       # [ACTIF] Python - MariaDB -> Zendesk/SunCo
  sync_to_zendesk.py           # Script principal
  delete_conv.py               # Utilitaire : supprime conversations SunCo
  delete_sunshine_user.py      # Utilitaire : supprime user SunCo
  get_conv.py                  # Utilitaire : liste conversations
  utils/
    db.py                      # Connexion MariaDB + requete messages
    sunco.py                   # API Sunshine Conversations
    zendesk.py                 # API Zendesk Support
    sync.py                    # Orchestration de la synchro

Script_MessageZendesk_Admin/   # [OBSOLETE] Ancienne version du script PHP
                               # Remplace par scripts_syncro_uc7_php

Workflows_n8n/                 # Workflows n8n + Vertex AI
  Automatic_Response/          # Reponse auto (horaire)
  Get_Motif_Contact/           # Classification motif (horaire)
  Store_solved_tickets/        # Archivage RGPD (hebdomadaire)
```

## Architecture PHP (scripts_syncro_uc7_php)

Architecture hexagonale, CQRS, Symfony Messenger.

- **Domain** : `Message` entity + interfaces `MessageRepositoryInterface`, `ZendeskRepositoryInterface`
- **Application** : `SyncZendeskMessagesCommand` -> `SyncZendeskMessagesHandler`
- **Infrastructure** : `ZendeskApiClient` (Guzzle), `MariaDBMessageRepository` (PDO), `ContainerFactory` (DI)
- **Utils.php** : `parseCommentBody()`, `mapZendeskStatusToDb()`, `mapPriorityToReport()`, `isTicketDone()`, `saveToMariadb()`, `getDbConnection()`, `loadLastSync()`, `saveLastSync()`

## Mappings de donnees (PHP)

| Zendesk Status | DB m_status | | Zendesk Priority | DB m_report |
|---------------|-------------|---|-----------------|-------------|
| new/open      | 1           | | low             | 0           |
| pending       | 0           | | normal          | 1           |
| solved        | 2           | | high            | 2           |
| closed        | 3           | | urgent          | 3           |

- `m_done` : 1 si solved/closed, 0 sinon
- `m_reply_uid` : NULL (client), external_id agent ou 1 (agent inconnu), 0 (client dans parsed)
- `m_done_uid` : external_id du dernier agent si ticket done

## Schema DB (table messages)

Colonnes utilisees : `m_uid`, `m_topic`, `m_content`, `m_date`, `m_status`, `m_report`, `m_done`, `m_reply_uid`, `m_done_uid`

## APIs utilisees

- **Zendesk Incremental** : `GET /api/v2/incremental/ticket_events.json?start_time=X&include=comment_events`
- **Zendesk Bulk** : `GET /api/v2/tickets/show_many.json?ids=X,Y,Z`
- **Zendesk Ticket** : `GET /api/v2/tickets/{id}.json?include=users`
- **Zendesk Users** : `GET /api/v2/users.json?role=agent|admin`
- **SunCo** : `POST /v2/apps/{app_id}/users`, `/conversations`, `/messages`

## Differences entre scripts_syncro_uc7_php et Script_MessageZendesk_Admin

`scripts_syncro_uc7_php` (nouveau) ajoute par rapport a l'ancien :
- Validation bulk via `showMany()` (lots de 100 tickets au lieu de requetes individuelles)
- Filtrage des tickets tagges `historique_admin`
- Champ `doneUid` dans l'entite Message
- Statistiques de synchro (inseres, filtres, manquants)
- INSERT dynamique (champs construits dynamiquement vs if/else)

## Configuration

Chaque dossier a son `.env` (non commite). Variables :
- `ZENDESK_SUBDOMAIN`, `ZENDESK_EMAIL`, `ZENDESK_TOKEN`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- `SUNCO_APP_ID`, `SUNCO_KEY_ID`, `SUNCO_SECRET` (Python uniquement)

## Commandes

```bash
# Tache 1 : Synchro Zendesk -> MariaDB
cd scripts_syncro_uc7_php && php script.php

# Tache 2 : Synchro historique -> Zendesk
cd script_historique_admin && python sync_to_zendesk.py [external_id]

# Utilitaires Python
python delete_conv.py [external_id]
python get_conv.py [external_id]
python delete_sunshine_user.py
```
