# Documentation - Migration de l'historique Admin vers Zendesk

## Objectif

Ce script migre les anciens messages de la base MariaDB Admin vers Zendesk, afin que les clients puissent consulter leur historique de conversation directement dans le **widget Zendesk** (Web Messaging).

Avant la mise en place de Zendesk, les echanges clients etaient stockes uniquement dans MariaDB. Ce script comble ce manque en reinjectant l'historique via Sunshine Conversations (SunCo).

## Comment ca marche

Le script exploite un mecanisme natif de SunCo : **quand on cree une conversation SunCo, Zendesk cree automatiquement un ticket associe**. On utilise ce mecanisme pour injecter des messages historiques qui apparaissent ensuite dans le widget client.

### Etapes pour chaque utilisateur

```
1. Charger les messages depuis MariaDB (anterieurs a la date limite)
2. Supprimer l'utilisateur SunCo (repartir de zero, eviter les doublons)
3. Recreer l'utilisateur SunCo
4. Creer une conversation SunCo et y injecter les messages un par un
   --> SunCo cree automatiquement un ticket Zendesk a ce moment
5. Retrouver le ticket Zendesk fraichement cree
6. Mettre a jour le ticket (titre, statut, tag "historique_admin")
```

### Statut dynamique du ticket

Le statut du ticket est determine automatiquement par le champ `m_done` du dernier message client en base :

| m_done du dernier message client | Statut du ticket Zendesk |
|----------------------------------|--------------------------|
| 1 (termine)                      | `solved`                 |
| 0 (en cours)                     | `open`                   |
| Aucun message client             | `solved` (par defaut)    |

## Utilisation

### Prerequis

```bash
composer install
cp .env.example .env
# Editer .env avec les credentials SunCo + Zendesk + MariaDB
```

### Lancer le script

```bash
# Migrer TOUS les utilisateurs de la base
php script.php

# Migrer UN seul utilisateur
php script.php 3119
```

### Mode tous les utilisateurs

Le script recupere automatiquement tous les `m_uid` distincts de la table `messages` ayant des messages anterieurs a la date limite, puis les traite un par un.

Un fichier de progression (`sync_progress.log`) enregistre les utilisateurs traites avec succes. En cas d'interruption, il suffit de relancer le script : les utilisateurs deja traites sont automatiquement ignores.

```bash
# Verifier la progression
wc -l sync_progress.log       # Nombre d'utilisateurs traites

# Tout recommencer depuis zero
rm sync_progress.log
php script.php
```

### Resume de fin d'execution

A la fin, le script affiche un resume :

```
============================================================
Resume de la synchronisation globale
============================================================
Reussis  : 142/200
Ignores  : 50/200 (deja traites)
Echoues  : 8/200
   IDs en echec : 3045, 3089, 3102, ...
============================================================
```

## Configuration

### Variables d'environnement (.env)

| Variable | Description |
|----------|-------------|
| `ZENDESK_SUBDOMAIN` | Sous-domaine Zendesk (ex: `anytime-42666`) |
| `ZENDESK_EMAIL` | Email du compte Zendesk |
| `ZENDESK_TOKEN` | Token API Zendesk |
| `SUNCO_APP_ID` | ID de l'application Sunshine Conversations |
| `SUNCO_KEY_ID` | Cle API SunCo |
| `SUNCO_SECRET` | Secret API SunCo |
| `DB_HOST` | Hote MariaDB |
| `DB_PORT` | Port MariaDB |
| `DB_NAME` | Nom de la base de donnees |
| `DB_USER` | Utilisateur MariaDB |
| `DB_PASSWORD` | Mot de passe MariaDB |

### Constantes de synchronisation (script.php)

| Constante | Valeur | Description |
|-----------|--------|-------------|
| `$cutoffDate` | `2026-01-22` | Seuls les messages anterieurs a cette date sont migres |
| `$conversationTitle` | `Historique de vos messages avant le 30/01/2026` | Titre affiche dans SunCo/widget |
| `$ticketTitle` | `Historique des messages precedent la migration sur Zendesk` | Titre du ticket Zendesk |
| `$ticketTags` | `["historique_admin"]` | Tag applique au ticket |

## Points d'attention

### Tag historique_admin

Le tag `historique_admin` est **critique** :
- Il identifie les tickets comme etant de l'historique migre
- Le script PHP de synchronisation Zendesk -> MariaDB (`scripts_syncro_uc7_php`) filtre ces tickets pour ne pas les reimporter
- Ne pas modifier ou supprimer ce tag sous peine de creer une boucle de synchro

### Reprise sur erreur

- Le fichier `sync_progress.log` garantit qu'on peut interrompre et reprendre sans retraiter les utilisateurs deja synchronises
- Les utilisateurs en echec ne sont **pas** ecrits dans le fichier de progression : ils seront retentes au prochain run
- En mode utilisateur unique (`php script.php 3119`), le fichier de progression n'est pas utilise

### Idempotence

Relancer le script pour un utilisateur deja traite (sans fichier de progression) :
- Supprime l'ancien utilisateur SunCo et ses conversations
- Recree tout proprement
- **Les anciens tickets Zendesk precedemment crees ne sont pas supprimes** (ils restent orphelins)

### Dates des messages

Les messages sont injectes dans l'ordre chronologique (`ORDER BY m_date ASC`) mais SunCo leur attribue la date d'injection comme timestamp. L'ordre est preserve, mais les dates affichees dans le widget sont celles du moment de la migration.
