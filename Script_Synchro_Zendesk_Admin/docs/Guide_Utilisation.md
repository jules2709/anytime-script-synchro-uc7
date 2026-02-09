# Guide d'Utilisation - Synchronisation Zendesk vers MariaDB

## Objectif

Ce script synchronise les nouveaux messages de chat Zendesk vers la base de donnees MariaDB Admin. Il recupere les evenements de tickets incrementaux via l'API Zendesk, extrait les transcripts de chat, et insere les messages parses dans la table `messages`.

## Fonctionnement general

```
API Zendesk (incremental/ticket_events)
    |
    v
Extraction des commentaires "Chat Transcript"
    |
    v
Validation en lot des tickets (show_many, par batch de 100)
    |
    v
Filtrage : exclusion des tickets tagges "historique_admin"
    |
    v
Pour chaque ticket valide :
    - Parsing du transcript (format "(HH:MM:SS) Auteur: message")
    - Identification client/agent via nom
    - Resolution de l'external_id de l'agent (cache)
    - Determination du statut (m_done, m_status, m_done_uid)
    |
    v
Insertion dans MariaDB (table messages)
    |
    v
Sauvegarde du timestamp dans last_sync.txt
```

## Prerequis

```bash
composer install
cp .env.example .env
# Editer .env avec les credentials Zendesk + MariaDB
```

## Lancer le script

```bash
php script.php
```

Le script :
1. Charge le dernier timestamp de synchronisation depuis `last_sync.txt`
2. Recupere les evenements de tickets depuis ce timestamp
3. Traite et insere les messages
4. Sauvegarde le nouveau timestamp

En cas de premier lancement (pas de `last_sync.txt`), le script recupere les evenements des **dernieres 24 heures**.

## Configuration

### Variables d'environnement (.env)

| Variable | Description |
|----------|-------------|
| `ZENDESK_SUBDOMAIN` | Sous-domaine Zendesk (ex: `anytime-42666`) |
| `ZENDESK_EMAIL` | Email du compte Zendesk |
| `ZENDESK_TOKEN` | Token API Zendesk |
| `DB_HOST` | Hote MariaDB |
| `DB_PORT` | Port MariaDB (defaut: 3306) |
| `DB_NAME` | Nom de la base de donnees |
| `DB_USER` | Utilisateur MariaDB |
| `DB_PASSWORD` | Mot de passe MariaDB |

### Fichier last_sync.txt

Fichier texte contenant un unique timestamp Unix. Gere automatiquement par le script.

```bash
# Verifier le dernier timestamp
cat last_sync.txt

# Forcer une re-synchronisation depuis une date specifique (timestamp Unix)
echo "1706000000" > last_sync.txt

# Forcer une re-synchronisation des dernieres 24h
rm last_sync.txt
```

## Mappings de donnees

### Statut Zendesk vers m_status

| Statut Zendesk | m_status |
|-----------------|----------|
| `new`           | 1        |
| `open`          | 1        |
| `pending`       | 0        |
| `solved`        | 2        |
| `closed`        | 3        |

### Priorite Zendesk vers m_report

| Priorite Zendesk | m_report |
|-------------------|----------|
| `low`             | 0        |
| `normal`          | 1        |
| `high`            | 2        |
| `urgent`          | 3        |

### Identification de l'auteur (m_reply_uid)

| Cas | m_reply_uid |
|-----|-------------|
| Message client | `0` |
| Message agent (external_id trouve) | `external_id` de l'agent |
| Message agent (external_id introuvable) | `1` |
| Message non parse (transcript non reconnu) | `null` |

### Champs specifiques

| Champ | Logique |
|-------|---------|
| `m_done` | `1` si ticket `solved` ou `closed`, `0` sinon |
| `m_done_uid` | `external_id` du dernier agent du ticket, uniquement si ticket `solved`/`closed` et si l'agent a un external_id |
| `m_source` | ID du ticket Zendesk (toujours renseigne) |

## Resume de fin d'execution

A la fin, le script affiche un resume :

```
============================================================
Resume de la synchronisation
============================================================
Messages inseres : 85
Tickets filtres (Historique_Admin) : 12
Tickets manquants : 3
   IDs manquants (top 3) : 12345, 12346, 12347
============================================================
```

## Points d'attention

### Tag historique_admin

Les tickets tagges `historique_admin` sont **exclus** de la synchronisation. Ce tag identifie les tickets crees par le script d'historisation (`script_historique_admin_php`) et evite une boucle de synchronisation.

### Cache des agents

Au premier besoin de resolution d'un nom d'agent, le script charge **tous les agents et admins actifs** de Zendesk en cache (nom + alias -> external_id). Ce cache est conserve en memoire pour toute la duree de l'execution.

### Rate limiting

Un delai de 100ms (`usleep(100000)`) est applique entre chaque ticket traite pour eviter de surcharger l'API Zendesk.

### Tickets manquants

Un ticket peut etre "manquant" si :
- Il a ete supprime entre la recuperation des evenements et la validation bulk
- L'API incremental reference un ticket qui n'est plus accessible
- Le ticket est tague `historique_admin` (filtre, pas manquant)

Ces tickets sont comptes separement dans le resume.

### Execution periodique

Ce script est concu pour etre execute periodiquement (cron, scheduler). Grace au fichier `last_sync.txt`, chaque execution ne traite que les nouveaux evenements depuis la derniere synchronisation reussie.

```bash
# Exemple de crontab (toutes les 5 minutes)
*/5 * * * * cd /chemin/vers/scripts_syncro_uc7_php && php script.php >> /var/log/zendesk_sync.log 2>&1
```
