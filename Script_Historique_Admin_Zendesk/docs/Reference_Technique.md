# Documentation Technique - Migration Historique Admin vers Zendesk

## 1. Stack Technique & Patterns

- **PHP 8.2+** (typage strict)
- **Architecture Hexagonale** (Ports & Adaptateurs)
- **CQRS** via Symfony Messenger
- **Symfony Dependency Injection**
- **Guzzle 7** pour les appels HTTP (SunCo + Zendesk)
- **PDO** pour MariaDB

## 2. Architecture Logicielle

```
script.php (Entrypoint)
    |
    v
ContainerFactory --> MessageBus --> SyncHistoryToZendeskCommand
                                            |
                                            v
                                    SyncHistoryToZendeskHandler
                                    /           |           \
                                   v            v            v
                        MessageRepository  SuncoRepository  ZendeskRepository
                        (Interface)        (Interface)      (Interface)
                            |                  |                |
                            v                  v                v
                        MariaDB            SunCo API        Zendesk API
                        (PDO)              (Guzzle)         (Guzzle)
```

### Domain Layer

| Fichier | Role |
|---------|------|
| `Entity/HistoricalMessage.php` | Entite immuable : `authorType` (user/business), `text`, `done` |
| `Repository/MessageRepositoryInterface.php` | Contrat : `getAllUserIds()`, `loadMessages()` |
| `Repository/SuncoRepositoryInterface.php` | Contrat : `deleteUser()`, `ensureUser()`, `createConversation()` |
| `Repository/ZendeskRepositoryInterface.php` | Contrat : `findLatestTicketForUser()`, `updateTicket()` |

### Application Layer

| Fichier | Role |
|---------|------|
| `Command/SyncHistoryToZendeskCommand.php` | DTO immutable portant l'intention et la configuration |
| `CommandHandler/SyncHistoryToZendeskHandler.php` | Orchestration : boucle sur les users, progression, delegation aux repos |

### Infrastructure Layer

| Fichier | Role |
|---------|------|
| `Config/ContainerFactory.php` | Cablage DI : instancie les repos et configure le MessageBus |
| `ExternalApi/SuncoApiClient.php` | Implémente `SuncoRepositoryInterface` via API smooch.io (Guzzle) |
| `ExternalApi/ZendeskApiClient.php` | Implémente `ZendeskRepositoryInterface` via API Zendesk (Guzzle) |
| `Persistence/MariaDBMessageRepository.php` | Implémente `MessageRepositoryInterface` via SQL (PDO) |

## 3. Flux d'execution detaille

### Phase 1 : Initialisation (script.php)

```
.env --> Dotenv --> Config arrays
Config --> ContainerFactory --> PDO + SuncoApiClient + ZendeskApiClient + Handler
Handler --> MessageBus
CLI $argv[1] --> SyncHistoryToZendeskCommand(externalId: ?string)
MessageBus --> dispatch(Command)
```

### Phase 2 : Orchestration (Handler)

**Mode tous les utilisateurs** (`externalId = null`) :

```
1. loadProgress()           --> Charge sync_progress.log dans $processedIds
2. getAllUserIds(cutoff)     --> SELECT DISTINCT m_uid WHERE m_date < cutoff
3. Pour chaque uid :
   a. isset($processedIds[uid]) ? --> skip
   b. processUser(uid)
   c. Si succes --> appendProcessedId(uid)  [fwrite + fflush]
4. Affiche resume (reussis / ignores / echoues)
```

### Phase 3 : Traitement par utilisateur (processUser)

```
1. loadMessages(externalId, cutoff)
   --> SELECT m_content, m_reply_uid, m_date, m_done
       WHERE m_uid = ? AND m_date < ?
       ORDER BY m_date ASC
   --> Mapping : m_reply_uid NULL|0 = "user", autre = "business"

2. Determination du statut ticket
   --> Filtre messages "user", prend le dernier
   --> done=1 ? "solved" : "open"

3. Enregistrement startTime (UTC)

4. deleteUser(externalId)
   --> DELETE /v2/apps/{app}/users/{externalId}
   --> 200 = supprime, 404 = deja absent (OK)

5. ensureUser(externalId, "User {externalId}")
   --> POST /v2/apps/{app}/users {externalId, profile: {givenName}}
   --> 201 = cree, 409 = deja existant (OK)

6. createConversation(externalId, messages, title)
   --> POST /v2/apps/{app}/conversations {type: "personal", displayName, participants}
   --> 201 = conversation creee, retourne conversationId
   --> Pour chaque message :
       POST /v2/apps/{app}/conversations/{id}/messages
       {author: {type: "user"|"business", userExternalId?}, content: {type: "text", text}}

7. findLatestTicketForUser(externalId, startTime)
   --> GET /api/v2/users/search.json?external_id=X  --> zendesk_user_id
   --> GET /api/v2/users/{id}/tickets/requested.json?sort_by=created_at&sort_order=desc
   --> Filtre tickets ou created_at >= startTime
   --> Retry : 15 tentatives, 1s entre chaque

8. updateTicket(ticketId, title, status, tags)
   --> PUT /api/v2/tickets/{id}.json
   --> {ticket: {subject, status, tags: ["historique_admin"]}}
```

## 4. APIs externes

### Sunshine Conversations (SunCo)

| Methode | Endpoint | Usage |
|---------|----------|-------|
| `DELETE` | `/v2/apps/{app}/users/{externalId}` | Suppression utilisateur |
| `POST` | `/v2/apps/{app}/users` | Creation utilisateur |
| `POST` | `/v2/apps/{app}/conversations` | Creation conversation |
| `POST` | `/v2/apps/{app}/conversations/{id}/messages` | Injection message |

- Base URL : `https://api.smooch.io`
- Auth : HTTP Basic (`SUNCO_KEY_ID` : `SUNCO_SECRET`)
- Timeout : 10s par requete

### Zendesk Support

| Methode | Endpoint | Usage |
|---------|----------|-------|
| `GET` | `/api/v2/users/search.json?external_id=X` | Recherche utilisateur |
| `GET` | `/api/v2/users/{id}/tickets/requested.json` | Liste tickets du user |
| `PUT` | `/api/v2/tickets/{id}.json` | Mise a jour ticket |

- Base URL : `https://{subdomain}.zendesk.com`
- Auth : `{email}/token` : `{token}`
- Timeout : 10s par requete
- Retry recherche ticket : 15 tentatives, 1s de delai

## 5. Requetes SQL

### getAllUserIds

```sql
SELECT DISTINCT m_uid
FROM messages
WHERE m_date < :cutoff AND m_uid IS NOT NULL
ORDER BY m_uid ASC
```

### loadMessages

```sql
SELECT m_content, m_reply_uid, m_date, m_done
FROM messages
WHERE m_uid = :uid AND m_date < :cutoff
ORDER BY m_date ASC
```

**Mapping type d'auteur :**

| m_reply_uid | authorType |
|-------------|------------|
| NULL        | `user` (client) |
| 0           | `user` (client) |
| autre       | `business` (agent) |

**Index recommande** : `(m_uid, m_date)` sur la table `messages` pour les deux requetes.

## 6. Fichier de progression (sync_progress.log)

Fichier texte append-only, un `m_uid` par ligne.

```
3045
3089
3102
3119
```

| Operation | Moment |
|-----------|--------|
| Lecture (`loadProgress`) | Au demarrage du mode "tous les utilisateurs" |
| Ecriture (`appendProcessedId`) | Apres chaque utilisateur traite avec succes |
| Flush | Immediat (`fflush`) : persiste meme en cas de crash PHP |

Le fichier n'est **pas utilise** en mode utilisateur unique.

## 7. Gestion des erreurs

Chaque etape de `processUser` est encadree par un test de retour. En cas d'echec :
- Le traitement de l'utilisateur s'arrete (`return false`)
- L'utilisateur n'est **pas** ecrit dans `sync_progress.log`
- L'utilisateur suivant est traite normalement
- L'ID est ajoute a la liste `$failedIds` pour le resume final

Les erreurs HTTP (Guzzle) sont capturees par des `try/catch GuzzleException`.

## 8. Integration et evolution

### Intégration dans un container DI existant

```php
$container->set(MessageRepositoryInterface::class, new MariaDBMessageRepository($pdo));
$container->set(SuncoRepositoryInterface::class, new SuncoApiClient($appId, $keyId, $secret));
$container->set(ZendeskRepositoryInterface::class, new ZendeskApiClient($subdomain, $email, $token));
```

### Evolution vers l'asynchrone (Webhooks)

L'utilisation de Symfony Messenger permet de passer en mode asynchrone :
1. Configurer un transport (RabbitMQ, Redis)
2. Router `SyncHistoryToZendeskCommand` vers ce transport
3. Lancer un worker : `php bin/console messenger:consume async`

### Testabilite

Le decouplage par interfaces permet un mocking complet :
- **Unit tests** : mocker les 3 interfaces pour tester le Handler en isolation
- **Integration tests** : utiliser une base SQLite/Docker pour valider `MariaDBMessageRepository`
