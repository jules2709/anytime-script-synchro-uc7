# Documentation - Script Historique Admin

## Objectif

Migrer l'historique des messages de la base MariaDB Admin vers Zendesk, de sorte que le client puisse consulter ses anciens echanges directement dans le **widget Zendesk** (Web Messaging).

Le script est execute **par utilisateur** : on lui fournit un `external_id` et il reconstitue toute la conversation historique de cet utilisateur sur Zendesk.

## Contexte fonctionnel

Avant la mise en place de Zendesk, les messages clients etaient stockes uniquement dans la base MariaDB Admin. Apres migration vers Zendesk, ces anciens messages n'apparaissent pas dans le widget client. Ce script comble ce manque en reinjectant l'historique via Sunshine Conversations (SunCo).

**Lien avec la tache 1 (scripts_syncro_uc7_php) :**
Les tickets crees par ce script recoivent le tag `historique_admin`. Ce tag est utilise par le script PHP de synchronisation pour **exclure** ces tickets et eviter de les re-importer dans MariaDB (boucle infinie).

## Principe de fonctionnement

Le script exploite un comportement natif de SunCo : **quand on cree une conversation SunCo, Zendesk cree automatiquement un ticket associe**. On utilise ce mecanisme pour injecter des messages historiques qui apparaissent ensuite dans le widget.

### Flux complet

```
MariaDB (messages ou m_date < CUTOFF_DATE)
  |
  | 1. SELECT m_content, m_reply_uid, m_date
  |    WHERE m_uid = external_id AND m_date < cutoff
  |    ORDER BY m_date ASC
  v
Determination du type d'auteur
  |  m_reply_uid NULL ou 0 --> author_type = "user"   (client)
  |  m_reply_uid autre     --> author_type = "business" (agent)
  v
Suppression de l'utilisateur SunCo (reset complet)
  |  DELETE /v2/apps/{app_id}/users/{external_id}
  |  (200 = supprime, 404 = deja absent -> OK dans les 2 cas)
  v
Creation de l'utilisateur SunCo
  |  POST /v2/apps/{app_id}/users
  |  body: { externalId, profile: { givenName } }
  |  (201 = cree, 409 = deja existant -> OK dans les 2 cas)
  v
Creation de la conversation SunCo
  |  POST /v2/apps/{app_id}/conversations
  |  body: { type: "personal", displayName, participants: [{userExternalId}] }
  |
  |  --> SunCo cree automatiquement un ticket Zendesk a ce moment
  v
Injection des messages un par un
  |  POST /v2/apps/{app_id}/conversations/{conv_id}/messages
  |  body: { author: {type: "user"|"business"}, content: {type: "text", text} }
  |  (pour les messages "user", on ajoute userExternalId dans author)
  v
Recherche du ticket Zendesk cree par SunCo
  |  GET /api/v2/users/search.json?external_id=X      --> zendesk_user_id
  |  GET /api/v2/users/{id}/tickets/requested.json     --> filtrage par created_after
  |  (retry jusqu'a 15 fois avec 1s de delai, le ticket met un instant a apparaitre)
  v
Mise a jour du ticket
  |  PUT /api/v2/tickets/{id}.json
  |  body: { ticket: { subject, status: "solved", tags: ["historique_admin"] } }
  v
Resultat : le client voit son historique dans le widget
```

## Configuration

### Constantes (sync_to_zendesk.py)

| Constante | Valeur par defaut | Description |
|-----------|-------------------|-------------|
| `EXTERNAL_ID` | `"3119"` | ID utilisateur par defaut (surchargeable via CLI) |
| `CONVERSATION_TITLE` | `"Historique de vos messages avant le 30/01/2026"` | Titre affiche dans SunCo |
| `ZENDESK_TICKET_TITLE` | `"Historique des messages precedent la migration sur Zendesk"` | Titre du ticket Zendesk |
| `ZENDESK_TICKET_STATUS` | `"solved"` | Statut final du ticket |
| `ZENDESK_TICKET_TAGS` | `["historique_admin"]` | Tags appliques au ticket |
| `CUTOFF_DATE` | `"2026-01-22"` | Date limite : seuls les messages anterieurs sont migres |

### Variables d'environnement (.env)

```
# Sunshine Conversations
SUNCO_APP_ID=...
SUNCO_KEY_ID=...
SUNCO_SECRET=...

# Zendesk Support
ZENDESK_EMAIL=...
ZENDESK_TOKEN=...
ZENDESK_SUBDOMAIN=...

# MariaDB
DB_HOST=...
DB_PORT=...
DB_NAME=...
DB_USER=...
DB_PASSWORD=...
```

## Scripts

### sync_to_zendesk.py - Script principal

Point d'entree de la migration. Orchestre l'ensemble du processus pour un utilisateur.

```bash
python sync_to_zendesk.py [external_id]
```

**Deroulement :**

1. Charge les messages depuis MariaDB (`load_messages_from_db`)
2. Supprime l'utilisateur SunCo existant (evite les doublons)
3. Recree l'utilisateur SunCo
4. Cree la conversation SunCo avec tous les messages
5. Retrouve le ticket Zendesk cree automatiquement
6. Met a jour le ticket (titre, statut `solved`, tag `historique_admin`)

**Pourquoi supprimer l'utilisateur avant de le recreer ?**
SunCo cree une conversation "par defaut" a la creation d'un utilisateur. En supprimant puis recreant, on repart d'un etat propre. Sans cela, relancer le script pour un meme utilisateur creerait des conversations en doublon.

### delete_conv.py - Nettoyage des conversations

Supprime toutes les conversations d'un utilisateur **sauf la conversation par defaut**.

```bash
python delete_conv.py [external_id]
```

- Demande une confirmation interactive avant suppression
- Utile pour nettoyer des conversations de test sans supprimer l'utilisateur

### get_conv.py - Consultation des conversations

Liste les conversations d'un utilisateur avec leurs details (ID, titre, statut, date de creation).

```bash
python get_conv.py [external_id]
```

- Utile pour debugger ou verifier l'etat d'un utilisateur

### delete_sunshine_user.py - Suppression d'un utilisateur SunCo

Supprime completement un utilisateur de Sunshine Conversations.

```bash
python delete_sunshine_user.py
```

- Demande l'`external_id` en input interactif (pas d'argument CLI)
- Apres suppression, le prochain passage du client sur le widget repartira de zero

## Modules utilitaires (utils/)

### db.py - Acces MariaDB

| Fonction | Description |
|----------|-------------|
| `_get_db_config()` | Lit la config DB depuis les variables d'environnement |
| `get_db_connection()` | Etablit la connexion MariaDB |
| `load_messages_from_db(external_id, cutoff_date)` | Charge les messages anterieurs a la cutoff date |

**Logique de typage auteur :**
```python
author_type = "user" if (m_reply_uid is None or int(m_reply_uid) == 0) else "business"
```
- `m_reply_uid` NULL ou 0 → message client (`"user"`)
- `m_reply_uid` autre valeur → message agent (`"business"`)

### sunco.py - API Sunshine Conversations

| Fonction | Endpoint | Description |
|----------|----------|-------------|
| `delete_sunco_user(external_id)` | `DELETE /users/{id}` | Supprime un utilisateur |
| `ensure_sunco_user(external_id, name)` | `POST /users` | Cree ou verifie l'existence d'un utilisateur |
| `create_conversation(external_id, ...)` | `POST /conversations` | Cree une conversation personnelle |
| `post_historical_message(conv_id, ...)` | `POST /conversations/{id}/messages` | Poste un message dans la conversation |
| `create_sunco_conversation(external_id, messages, title)` | Cree conv + injecte messages | Fonction tout-en-un utilisee par sync |
| `migrate_conversation(...)` | Compose ensure + create + post | Migration complete (non utilisee par sync_to_zendesk) |
| `get_user_conversations(external_id)` | `GET /conversations?filter` | Liste les conversations d'un utilisateur |
| `delete_conversation(conv_id)` | `DELETE /conversations/{id}` | Supprime une conversation |

**Note :** `migrate_conversation` est une fonction autonome qui fait user+conv+messages mais n'est pas utilisee par le flux principal (`sync.py` fait chaque etape separement pour gerer les erreurs et la recherche de ticket).

### zendesk.py - API Zendesk Support

| Fonction | Endpoint | Description |
|----------|----------|-------------|
| `find_latest_zendesk_ticket_for_user(external_id, created_after)` | `GET /users/search.json` + `GET /users/{id}/tickets/requested.json` | Recherche le ticket cree par SunCo |
| `update_zendesk_ticket(ticket_id, title, status, tags)` | `PUT /tickets/{id}.json` | Met a jour titre, statut et tags |

**Mecanisme de retry :**
Apres la creation de la conversation SunCo, le ticket Zendesk n'apparait pas immediatement. La fonction `find_latest_zendesk_ticket_for_user` fait jusqu'a **15 tentatives** espacees de **1 seconde** pour le trouver. Le filtre `created_after` (timestamp du debut de l'execution) garantit qu'on trouve bien le ticket fraichement cree et pas un ancien.

### sync.py - Orchestration

Fonction unique `sync_to_zendesk()` qui enchaine les 5 etapes dans l'ordre, avec gestion d'erreur a chaque etape (retourne `False` si une etape echoue).

## Points d'attention

### Execution par utilisateur
Le script traite **un seul utilisateur** par execution. Pour migrer plusieurs utilisateurs, il faut l'appeler en boucle ou le scripter.

### Idempotence
Le script **n'est pas idempotent au sens strict** : le relancer pour un meme utilisateur supprimera l'ancien utilisateur SunCo (et ses conversations) puis recreeera tout. Le resultat final est correct mais les anciens ticket(s) Zendesk precedemment crees ne sont pas supprimes (ils restent orphelins dans Zendesk).

### Pas de date dans les messages SunCo
Les messages sont postes dans l'ordre chronologique (`ORDER BY m_date ASC`) mais SunCo leur attribue la date d'injection comme timestamp, pas la date originale. L'ordre est preserve, mais les dates affichees dans le widget sont celles du moment de la migration.

### Tag historique_admin
Le tag `historique_admin` est critique :
- Il identifie les tickets comme etant de l'historique migre
- Le script PHP (tache 1) filtre ces tickets pour ne pas les reimporter dans MariaDB
- Ne pas modifier ou supprimer ce tag sous peine de creer une boucle de synchro

### Rate limiting
Aucun rate limiting explicite cote SunCo (les messages sont postes sequentiellement mais sans `sleep`). Le timeout par requete est de 10 secondes (`REQUEST_TIMEOUT`).
