# Reference Technique - Synchronisation Zendesk vers MariaDB

## 1. Stack Technique & Patterns

- **PHP 8.2+** (typage strict)
- **Architecture Hexagonale** (Ports & Adaptateurs)
- **CQRS** via Symfony Messenger
- **Symfony Dependency Injection**
- **Guzzle 7** pour les appels HTTP (Zendesk)
- **PDO** pour MariaDB

## 2. Architecture Logicielle

```
script.php (Entrypoint)
    |
    v
ContainerFactory --> MessageBus --> SyncZendeskMessagesCommand
                                            |
                                            v
                                    SyncZendeskMessagesHandler
                                    /                         \
                                   v                           v
                        MessageRepository              ZendeskRepository
                        (Interface)                    (Interface)
                            |                              |
                            v                              v
                        MariaDB                        Zendesk API
                        (PDO)                          (Guzzle)
```

### Domain Layer

| Fichier | Role |
|---------|------|
| `Entity/Message.php` | Entite immuable : `uid`, `topic`, `content`, `date`, `status`, `report`, `done`, `replyUid`, `doneUid`, `source` |
| `Repository/MessageRepositoryInterface.php` | Contrat : `save(Message)` |
| `Repository/ZendeskRepositoryInterface.php` | Contrat : `fetchTicketEvents()`, `fetchTicketDetails()`, `getAgentExternalId()`, `showMany()`, `saveLastSync()`, `loadLastSync()` |

### Application Layer

| Fichier | Role |
|---------|------|
| `Command/SyncZendeskMessagesCommand.php` | DTO immutable portant l'intention. Parametre optionnel : `startTime` |
| `CommandHandler/SyncZendeskMessagesHandler.php` | Orchestration : pagination, extraction, validation bulk, filtrage, parsing, insertion |

### Infrastructure Layer

| Fichier | Role |
|---------|------|
| `Config/ContainerFactory.php` | Cablage DI : instancie PDO, repos et configure le MessageBus |
| `ExternalApi/ZendeskApiClient.php` | Implemente `ZendeskRepositoryInterface` via API Zendesk (Guzzle) |
| `Persistence/MariaDBMessageRepository.php` | Implemente `MessageRepositoryInterface` via SQL (PDO) |

### Utils

| Fichier | Role |
|---------|------|
| `Utils.php` | Fonctions statiques : `parseCommentBody()`, `getDbConnection()`, `loadLastSync()`, `saveLastSync()`, `mapZendeskStatusToDb()`, `mapPriorityToReport()`, `isTicketDone()`, `saveToMariadb()` |

## 3. Flux d'execution detaille

### Phase 1 : Initialisation (script.php)

```
.env --> Dotenv --> Config arrays (Zendesk + DB)
Config --> ContainerFactory --> PDO + ZendeskApiClient + MariaDBMessageRepository + Handler
Handler --> MessageBus
MessageBus --> dispatch(SyncZendeskMessagesCommand)
```

### Phase 2 : Recuperation incrementale (Handler)

```
1. loadLastSync()         --> Charge timestamp depuis last_sync.txt (ou time()-86400)
2. startTime = lastSync + 1
3. Boucle de pagination :
   a. fetchTicketEvents(startTime)
      --> GET /api/v2/incremental/ticket_events.json?start_time=X&include=comment_events
   b. extractComments(events)
      --> Filtre via='Chat Transcript', extrait les child_events de type 'Comment'
      --> Retourne commentsByTicket[ticketId] = [{body, created_at, author_id, ...}]
   c. Si events non vides :
      showMany(ticketIds)
      --> GET /api/v2/tickets/show_many.json?ids=X,Y,Z (batch de 100)
      --> Retourne {tickets: {id: ticket}, users: {id: user}}
   d. Filtrage des tickets tagges "historique_admin"
   e. Traitement de chaque ticket (Phase 3)
   f. Verifie end_of_stream pour continuer ou arreter
4. saveLastSync(finalTimestamp)
```

### Phase 3 : Traitement par ticket

```
Pour chaque ticket valide :
1. Identifier le requester (requester_id --> users bulk)
2. Extraire external_id et userName du client

3. Pour chaque commentaire du ticket :
   a. parseCommentBody(body, userName)
      --> Regex : (HH:MM:SS) Auteur: message
      --> Si match : split en messages individuels avec is_client
      --> Si pas de match : message unique brut (non parse)

   b. Pour chaque message parse :
      - Si client  : replyUid = 0
      - Si agent   : getAgentExternalId(author)
                      replyUid = external_id ?? 1
      - Creation de l'entite Message

   c. Pour les messages non parses :
      - Creation d'un Message sans replyUid

4. Reconstruction du doneUid (tickets solved/closed) :
   --> Parcours inverse des messages
   --> Premier agent avec agentExternalId != null --> doneUid

5. Insertion de chaque Message via messageRepository->save()
6. usleep(100ms) entre chaque ticket
```

## 4. API Zendesk

### Incremental Ticket Events

| Methode | Endpoint | Usage |
|---------|----------|-------|
| `GET` | `/api/v2/incremental/ticket_events.json?start_time=X&include=comment_events` | Recuperation incrementale des evenements |

- Pagination via `next_page` et `end_of_stream`
- Retourne `ticket_events[]`, chaque event contient `child_events[]` (Comments)
- Filtre applique : `via === 'Chat Transcript'`

### Bulk Tickets

| Methode | Endpoint | Usage |
|---------|----------|-------|
| `GET` | `/api/v2/tickets/show_many.json?ids=X,Y,Z` | Validation en lot (max 100 IDs par requete) |

- Retourne les tickets avec leurs metadonnees (status, subject, tags, priority)
- Retourne aussi les `users` associes

### Ticket Details (fallback)

| Methode | Endpoint | Usage |
|---------|----------|-------|
| `GET` | `/api/v2/tickets/{id}.json?include=users` | Recuperation unitaire si le bulk ne retourne pas les users |

### Agents

| Methode | Endpoint | Usage |
|---------|----------|-------|
| `GET` | `/api/v2/users.json?role=agent` | Chargement des agents actifs |
| `GET` | `/api/v2/users.json?role=admin` | Chargement des admins actifs |

- Charge en cache tous les agents/admins actifs et non suspendus
- Mappe `name` et `alias` vers `external_id`
- Pagination automatique via `next_page`

### Authentification

- Base URL : `https://{subdomain}.zendesk.com`
- Auth : `{email}/token` : `{token}`
- Headers : `Accept: application/json`

## 5. Schema SQL

### Insertion (table messages)

```sql
INSERT INTO messages (m_uid, m_topic, m_content, m_date, m_status, m_report, m_done, m_source [, m_reply_uid] [, m_done_uid])
VALUES (:m_uid, :m_topic, :m_content, :m_date, :m_status, :m_report, :m_done, :m_source [, :m_reply_uid] [, :m_done_uid])
```

### Colonnes renseignees

| Colonne | Type | Source | Toujours present |
|---------|------|--------|:----------------:|
| `m_uid` | string/null | `external_id` du requester Zendesk | Oui |
| `m_topic` | string | `subject` du ticket | Oui |
| `m_content` | string | Corps du message (parse ou brut) | Oui |
| `m_date` | string | `created_at` du commentaire | Oui |
| `m_status` | int | Statut Zendesk mappe (0-3) | Oui |
| `m_report` | int | Priorite Zendesk mappee (0-3) | Oui |
| `m_done` | int | 1 si solved/closed, 0 sinon | Oui |
| `m_source` | string/int | ID du ticket Zendesk | Oui |
| `m_reply_uid` | string/int/null | ID agent ou 0 (client) ou 1 (agent inconnu) | Non (messages non parses) |
| `m_done_uid` | string/null | `external_id` du dernier agent | Non (uniquement dernier agent sur ticket solved) |

## 6. Parsing des transcripts de chat

### Format attendu

```
(14:32:15) Jean Dupont: Bonjour, j'ai un probleme avec mon compte
(14:33:02) Agent Martin: Bonjour Jean, je vais vous aider
(14:35:47) Jean Dupont: Merci beaucoup
```

### Regex

```
/\((\d{2}:\d{2}:\d{2})\)\s*([^:]+):\s*(.+?)(?=\(\d{2}:\d{2}:\d{2}\)|$)/s
```

- **Groupe 1** : timestamp (HH:MM:SS)
- **Groupe 2** : nom de l'auteur
- **Groupe 3** : contenu du message

### Identification client/agent

L'auteur est considere **client** si son nom correspond exactement au `userName` (nom du requester du ticket). Tous les autres auteurs sont consideres **agents**.

Si le transcript ne matche pas la regex (format non reconnu), le commentaire entier est insere comme un message unique sans `m_reply_uid`.

## 7. Cache des agents

Le cache est charge au premier appel a `getAgentExternalId()` et conserve en memoire :

```
Requete : GET /api/v2/users.json?role=agent (puis role=admin)
Filtrage : active=true AND suspended=false
Mapping :
    user.name   --> user.external_id
    user.alias  --> user.external_id
```

Le cache permet de resoudre les noms d'agents extraits des transcripts de chat vers leurs `external_id` pour le champ `m_reply_uid`.

## 8. Gestion des erreurs

| Composant | Strategie |
|-----------|-----------|
| Connexion DB | PDOException propagee (arret du script) |
| Insertion DB | PDOException propagee apres log |
| API Zendesk (ticket events) | GuzzleException propagee |
| API Zendesk (show_many) | Exception catchee, batch ignore, continue |
| API Zendesk (agent cache) | Exception catchee par role, continue avec mapping partiel |
| Fichier last_sync.txt | Fallback vers timestamp actuel - 24h |

## 9. Integration et evolution

### Execution periodique (cron)

```bash
*/5 * * * * cd /chemin/vers/scripts_syncro_uc7_php && php script.php >> /var/log/zendesk_sync.log 2>&1
```

### Evolution vers l'asynchrone (Webhooks)

L'utilisation de Symfony Messenger permet de passer en mode asynchrone :
1. Configurer un transport (RabbitMQ, Redis)
2. Router `SyncZendeskMessagesCommand` vers ce transport
3. Lancer un worker : `php bin/console messenger:consume async`
4. Declencher la commande via un Webhook Zendesk au lieu d'un cron

### Testabilite

Le decouplage par interfaces permet un mocking complet :
- **Unit tests** : mocker `ZendeskRepositoryInterface` et `MessageRepositoryInterface` pour tester le Handler en isolation
- **Integration tests** : utiliser une base SQLite/Docker pour valider `MariaDBMessageRepository`
