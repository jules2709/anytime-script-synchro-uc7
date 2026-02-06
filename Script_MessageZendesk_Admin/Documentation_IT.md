# Documentation Technique - Zendesk Integration Service

Cette documentation est destinée aux équipes techniques pour l'intégration, la maintenance et l'évolution du service de synchronisation Zendesk.

## 1. Stack Technique & Patterns

- **PHP 8.2+** (Typage strict activé)
- **Hexagonal Architecture** : Découplage strict entre la logique métier (Domain/Application) et les drivers/adpateurs (Infrastructure).
- **CQRS (Command Query Responsibility Segregation)** : Utilisation de Commandes pour l'orchestration des écritures.
- **Symfony Messenger** : Bus de messages pour le dispatching des commandes.
- **Symfony Dependency Injection** : Inversion de contrôle pour la gestion des services.

## 2. Architecture Logicielle

### Domain Layer (Cœur Métier)
- **Entities** : Objets POPO (Plain Old PHP Objects) représentant le modèle de données (`ZendeskSync\Domain\Entity\Message`).
- **Repositories Interfaces** : Contrats d'abstraction pour la persistence et les APIs externes (`ZendeskSync\Domain\Repository\*`).

### Application Layer (Use Cases)
- **Commands** : Data Transfer Objects (DTO) immutables portant l'intention.
- **Handlers** : Orchestration des use-cases. Ils dépendent uniquement des interfaces du Domain.

### Infrastructure Layer (Implémentation)
- **Adaptateurs** : Implémentations concrètes des interfaces du domaine (Guzzle pour Zendesk, PDO pour MariaDB).
- **Config / DI** : Configuration du container Symfony via `ContainerFactory`.

## 3. Structure et Utilité des Composants

```
📁 Script_MessageZendesk_Admin/
│
├── 📄 script.php
│   └── Point d'entrée : charge l'environnement, initialise le container DI et lance la synchronisation
│
├── 📁 src/
│   │
│   ├── 📄 Utils.php
│   │   ├── parseCommentBody() : Parse les transcriptions chat pour extraire les messages individuels
│   │   ├── mapZendeskStatusToDb() : Convertit les statuts Zendesk (new/open/pending/solved/closed) en codes DB
│   │   ├── mapPriorityToReport() : Convertit les priorités Zendesk (low/normal/high/urgent) en codes DB
│   │   ├── isTicketDone() : Détermine si un ticket est terminé (solved/closed)
│   │   └── saveToMariadb() : Exécute l'insertion SQL dans la table messages
│   │
│   ├── 📁 Application/ (Couche Use Cases)
│   │   ├── 📁 Command/
│   │   │   └── SyncZendeskMessagesCommand.php
│   │   │       └── DTO portant l'intention de synchroniser (avec timestamp optionnel)
│   │   │
│   │   └── 📁 CommandHandler/
│   │       └── SyncZendeskMessagesHandler.php
│   │           ├── Orchestration du processus complet de synchronisation
│   │           ├── Récupère les événements Zendesk via ZendeskRepositoryInterface
│   │           ├── Filtre les événements "Chat Transcript"
│   │           ├── Récupère les détails de chaque ticket
│   │           └── Sauvegarde les messages via MessageRepositoryInterface
│   │
│   ├── 📁 Domain/ (Couche Métier)
│   │   ├── 📁 Entity/
│   │   │   └── Message.php
│   │   │       └── Représente un message avec ses propriétés (uid, topic, content, date, status, etc.)
│   │   │
│   │   └── 📁 Repository/
│   │       ├── MessageRepositoryInterface.php
│   │       │   └── Contrat d'abstraction pour la persistence des messages
│   │       │
│   │       └── ZendeskRepositoryInterface.php
│   │           └── Contrat d'abstraction pour les appels API Zendesk
│   │
│   └── 📁 Infrastructure/ (Couche Adaptateurs)
│       │
│       ├── 📁 Config/
│       │   └── ContainerFactory.php
│       │       └── Configure et instancie le container Symfony DI avec tous les services
│       │
│       ├── 📁 ExternalApi/
│       │   └── ZendeskApiClient.php
│       │       ├── Implémente ZendeskRepositoryInterface
│       │       ├── fetchTicketEvents() : Appel API incremental/ticket_events (avec pagination)
│       │       └── fetchTicketDetails() : Appel API tickets/{id} pour récupérer sujet/statut/priorité/users
│       │
│       └── 📁 Persistence/
│           └── MariaDBMessageRepository.php
│               ├── Implémente MessageRepositoryInterface
│               └── Délègue à Utils::saveToMariadb() pour l'insertion SQL
```

## 4. Intégration dans une infrastructure existante

### Injection de Dépendances
Le service est conçu pour être facilement intégrable dans un container DI existant (Symfony, Laravel, PHP-DI). Il suffit d'enregistrer les implémentations de l'Infrastructure pour les interfaces du Domain.

Exemple de câblage (PSR-11) :
```php
$container->set(ZendeskRepositoryInterface::class, new ZendeskApiClient(...));
$container->set(MessageRepositoryInterface::class, new MariaDBMessageRepository($pdo));
```


### Points de vigilance
- **Rate Limiting** : L'implémentation actuelle respecte les limites de Zendesk via des `usleep()`, mais pour une intégration à grande échelle, un middleware Messenger de rate-limiting (Token Bucket) est recommandé.
- **Transactions** : La persistence MariaDB utilise des transactions PDO au niveau du Repository.

## 5. Flux d'exécution du script

### Étapes principales

```
┌─────────────────────────────────────────────────────────────────┐
│             1. Charge du timestamp de dernière synchro           │
│                (depuis last_sync.txt ou défaut -24h)            │
└────────────────────────┬────────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────────┐
│  2. Appel API Incremental Ticket Events                         │
│     GET /api/v2/incremental/ticket_events.json                  │
│     Paramètres: start_time, include=comment_events              │
│                                                                  │
│  📊 Données reçues :                                            │
│     - ticket_events[] : Événements des tickets depuis le temps   │
│     - end_time : Timestamp de fin de cette plage               │
│     - next_page : URL pour pagination                           │
│     - end_of_stream : Booléen fin des données                   │
└────────────────────────┬────────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────────┐
│  3. Filtrage des événements                                     │
│     - Boucle sur tous les ticket_events                         │
│     - Filtre sur via="Chat Transcript"                          │
│     - Extrait child_events de type "Comment"                    │
│     - Groupe les commentaires par ticket_id                     │
└────────────────────────┬────────────────────────────────────────┘
                         │
         ┌───────────────┴────────────────────┐
         │ Pour chaque ticket contenant        │
         │ des commentaires de chat            │
         │                                    │
┌────────▼────────────────────────────────────────────────────┐
│  4. Appel API Ticket Details                               │
│     GET /api/v2/tickets/{ticketId}.json?include=users      │
│                                                             │
│  📊 Données reçues :                                       │
│     Ticket :                                               │
│       - subject : Sujet du ticket                          │
│       - status : Statut (new/open/pending/solved/closed)   │
│       - priority : Priorité (low/normal/high/urgent)       │
│       - requester_id : ID du demandeur (client)            │
│     Users :                                                │
│       - id : ID Zendesk                                    │
│       - name : Nom de l'utilisateur                        │
│       - external_id : ID externe (clé primaire DB)         │
└────────┬───────────────────────────────────────────────────┘
         │
┌────────▼───────────────────────────────────────────────────┐
│  5. Parsing des commentaires                              │
│     Regex: /\((\d{2}:\d{2}:\d{2})\)\s*([^:]+):\s*(.+?)   │
│                                                             │
│  Format attendu de chaque commentaire:                     │
│     (HH:MM:SS) Nom Auteur: Contenu du message             │
│                                                             │
│  Extraction:                                              │
│     - timestamp : (HH:MM:SS)                               │
│     - author : Nom de l'auteur                             │
│     - content : Texte du message                           │
│     - is_client : Booléen (true si author = nom client)   │
│                                                             │
│  Exemple:                                                  │
│     (14:23:45) Alice Dupont: Bonjour, j'ai un problème   │
│     (14:25:10) Support Thomas: Bonjour, aidez-moi...     │
└────────┬───────────────────────────────────────────────────┘
         │
┌────────▼───────────────────────────────────────────────────┐
│  6. Mapping Agent/Client → External ID                    │
│                                                             │
│  Pour chaque message parsé:                               │
│     - Si is_client = true → m_reply_uid = NULL             │
│     - Si is_client = false → m_reply_uid = 1              │
│       (indique que c'est une réponse d'agent)             │
│                                                             │
│  Autres mappages:                                         │
│     - m_uid : external_id du client (requester)           │
│     - m_topic : subject du ticket                         │
│     - m_content : contenu du message parsé                │
│     - m_date : created_at du commentaire                  │
│     - m_status : mapZendeskStatusToDb(ticket.status)      │
│     - m_report : mapPriorityToReport(ticket.priority)     │
│     - m_done : isTicketDone(ticket.status)                │
└────────┬───────────────────────────────────────────────────┘
         │
┌────────▼───────────────────────────────────────────────────┐
│  7. Insertion en base de données                          │
│     Table: messages                                        │
│     Base: anytime                                          │
│                                                             │
│  Colonnes insérées:                                       │
│     - m_uid : ID client                                   │
│     - m_topic : Sujet                                     │
│     - m_content : Contenu du message                      │
│     - m_date : Date du message                            │
│     - m_status : Statut mappé (1/0/2/3)                   │
│     - m_report : Priorité mappée (0/1/2/3)                │
│     - m_done : Ticket terminé (0/1)                       │
│     - m_reply_uid : 1 si agent, NULL si client            │
└────────┬───────────────────────────────────────────────────┘
         │
┌────────▼───────────────────────────────────────────────────┐
│  8. Rate Limiting & Pagination                            │
│     - usleep(100000) : Pause de 100ms entre chaque        │
│     - Répète depuis l'étape 2 si next_page existe         │
└────────┬───────────────────────────────────────────────────┘
         │
┌────────▼───────────────────────────────────────────────────┐
│  9. Sauvegarde du nouveau timestamp                        │
│     Écrit end_time dans last_sync.txt                     │
│     Permet la prochaine exécution de ne récupérer que      │
│     les événements depuis cette date                      │
└─────────────────────────────────────────────────────────────┘
```

### Mappages de données

#### Statuts Zendesk → Base de données
| Zendesk | DB | Signification |
|---------|-----|-------------|
| new | 1 | Nouveau ticket |
| open | 1 | Ouvert |
| pending | 0 | En attente |
| solved | 2 | Résolu |
| closed | 3 | Fermé |

#### Priorités Zendesk → m_report
| Zendesk | m_report | Signification |
|---------|----------|-------------|
| low | 0 | Basse |
| normal | 1 | Normale |
| high | 2 | Haute |
| urgent | 3 | Urgente |

#### Détermination du type de message (m_reply_uid)
| Type | m_reply_uid | Logique |
|------|-----------|---------|
| Message client | NULL | Envoyé par le demandeur (requester_id) |
| Réponse agent | external_id (ou 1 si pas trouvé) | Envoyé par quelqu'un d'autre (agent support) |

### Gestion des erreurs et cas limites

1. **Commentaires sans structure regex** :
   - Si le commentaire ne match pas la regex, il est sauvegardé comme un seul message (sans parsing)
   - `is_client` est déterminé par le champ `author_id` de Zendesk

2. **Tickets sans utilisateur correspondant** :
   - Utilise le premier utilisateur du ticket pour `external_id` et `name`
   - Permet une continuité même si le requester ne figure pas dans la liste

3. **Pagination API** :
   - Continue automatiquement en cas de résultats paginés
   - Respecte les limites de débit de Zendesk

4. **Relance du script** :
   - Grâce à `last_sync.txt`, chaque relance récupère uniquement les nouveaux événements
   - Pas de doublon si le script est exécuté plusieurs fois

## 6. Maintenance et Tests
Le découplage par interfaces permet une testabilité optimale :
- **Unit Tests** : Mocking des interfaces Repository pour tester le `SyncZendeskMessagesHandler`.