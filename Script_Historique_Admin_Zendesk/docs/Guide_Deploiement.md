# Guide de Deploiement IT - Migration Historique Admin vers Zendesk

## Contexte global

Ce projet comporte 2 scripts PHP independants, chacun avec un role distinct et un sens de synchronisation unique :

| Script | Direction | Frequence |
|--------|-----------|-----------|
| **`script_historique_admin_php`** (ce script) | MariaDB **-->** Zendesk/Widget | **Une seule fois** (+ relance si erreurs) |
| **`scripts_syncro_uc7_php`** | Zendesk **-->** MariaDB | **Periodiquement** (cron) |

**Ce script doit etre execute et termine avant d'activer le cron de synchro.** Le script de synchro ignore les tickets tagges `historique_admin` (crees par ce script), mais il est preferable que la migration historique soit finalisee avant de demarrer la synchronisation reguliere.

### Ordre de deploiement

```
1. Deployer et executer script_historique_admin_php   (migration one-shot)
2. Verifier que la migration est complete
3. PUIS deployer et activer le cron de scripts_syncro_uc7_php
```

---

## 1. Prerequis systeme

### PHP 8.2+

```bash
php -v   # Verifier la version (>= 8.2)
```

### Extensions PHP requises

| Extension | Usage | Verification |
|-----------|-------|--------------|
| `pdo_mysql` | Connexion MariaDB | `php -m \| grep pdo_mysql` |
| `curl` | Appels HTTP via Guzzle (SunCo + Zendesk) | `php -m \| grep curl` |
| `json` | Encodage/decodage des reponses API | `php -m \| grep json` |
| `mbstring` | Gestion des caracteres UTF-8 | `php -m \| grep mbstring` |

### Composer

```bash
composer --version   # >= 2.x recommande
```

### Acces reseau (HTTPS sortant)

Le serveur doit pouvoir atteindre les endpoints suivants en HTTPS (port 443) :

| Hote | Usage |
|------|-------|
| `api.smooch.io` | API Sunshine Conversations (creation utilisateurs, conversations, messages) |
| `{subdomain}.zendesk.com` | API Zendesk Support (recherche tickets, mise a jour tickets) |

Verifier avec :

```bash
curl -I https://api.smooch.io
curl -I https://{subdomain}.zendesk.com
```

### Acces base de donnees

| Droit | Table | Usage |
|-------|-------|-------|
| `SELECT` | `messages` | Lecture des messages historiques (m_uid, m_content, m_reply_uid, m_date, m_done) |

L'utilisateur DB n'a besoin que d'un acces en lecture.

**Index recommande** (performance) :

```sql
CREATE INDEX idx_messages_uid_date ON messages (m_uid, m_date);
```

---

## 2. Installation

```bash
cd /chemin/vers/script_historique_admin_php

# Installer les dependances
composer install --no-dev --optimize-autoloader

# Configurer l'environnement
cp .env.example .env
```

Editer `.env` avec les credentials de production :

```env
ZENDESK_SUBDOMAIN=anytime-42666
ZENDESK_EMAIL=service@anytime.com
ZENDESK_TOKEN=xxxxxxxxxxxxxxxxxxxx

SUNCO_APP_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
SUNCO_KEY_ID=app_xxxxxxxxxxxxxxxxxxxxxxxx
SUNCO_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

DB_HOST=db-production.internal
DB_PORT=3306
DB_NAME=admin_db
DB_USER=readonly_user
DB_PASSWORD=xxxxxxxxxxxx
```

### Securisation du fichier .env

```bash
chmod 600 .env
chown www-data:www-data .env   # Adapter au user qui execute le script
```

---

## 3. Obtention des credentials

### Token API Zendesk

1. Se connecter a Zendesk Admin Center
2. Aller dans **Apps et integrations > API Zendesk > Token d'API**
3. Creer un nouveau token
4. L'email associe doit avoir le role **Admin** ou **Agent** avec droits d'ecriture sur les tickets

### Credentials Sunshine Conversations (SunCo)

1. Se connecter a Zendesk Admin Center
2. Aller dans **Apps et integrations > Sunshine Conversations > API Keys**
3. Creer une nouvelle cle API
4. Recuperer : `App ID`, `Key ID`, `Secret`

---

## 4. Test avant execution

### Test sur un seul utilisateur

Avant de lancer la migration complete, tester sur un utilisateur connu :

```bash
php script.php 3119
```

Verifier :
- Le script affiche les etapes sans erreur
- Un ticket apparait dans Zendesk avec le tag `historique_admin`
- Les messages sont visibles dans le widget Zendesk pour cet utilisateur
- Le statut du ticket correspond au `m_done` du dernier message client

### Verifier le nombre d'utilisateurs a traiter

```sql
SELECT COUNT(DISTINCT m_uid)
FROM messages
WHERE m_date < '2026-01-22' AND m_uid IS NOT NULL;
```

---

## 5. Execution de la migration

### Lancement

```bash
# Rediriger la sortie vers un fichier de log
php script.php 2>&1 | tee migration_$(date +%Y%m%d_%H%M%S).log
```

### Estimation du temps d'execution

Le script effectue **au minimum 5 appels HTTP par utilisateur** + 1 appel par message. De plus, la recherche du ticket Zendesk cree par SunCo comporte un mecanisme de retry (jusqu'a 15 tentatives, 1s de delai).

| Volume | Estimation |
|--------|------------|
| 100 utilisateurs, ~10 messages chacun | ~30-60 minutes |
| 500 utilisateurs, ~10 messages chacun | ~2-5 heures |
| 1000 utilisateurs, ~20 messages chacun | ~5-12 heures |

Ces estimations dependent du temps de reponse des APIs SunCo et Zendesk, et du nombre de retries necessaires pour trouver les tickets.

**Il est normal que le script prenne plusieurs heures pour des volumes importants.**

### Suivi de la progression en temps reel

```bash
# Nombre d'utilisateurs traites avec succes
wc -l sync_progress.log

# Derniers utilisateurs traites
tail -5 sync_progress.log

# Suivre les logs en temps reel (si lance en background)
tail -f migration_20260206_143000.log
```

### Lancement en arriere-plan (session longue)

```bash
# Avec nohup (survit a la deconnexion SSH)
nohup php script.php > migration_$(date +%Y%m%d_%H%M%S).log 2>&1 &
echo $!   # Note le PID pour suivi

# Ou avec screen/tmux
screen -S migration
php script.php 2>&1 | tee migration.log
# Ctrl+A, D pour detacher
# screen -r migration pour se reconnecter
```

---

## 6. Reprise apres interruption

Le fichier `sync_progress.log` contient les IDs des utilisateurs traites avec succes (un par ligne). En cas d'interruption (crash, timeout, arret manuel) :

```bash
# Simplement relancer le script
php script.php 2>&1 | tee migration_reprise.log
```

Le script :
1. Charge `sync_progress.log`
2. Ignore les utilisateurs deja traites
3. Reprend la ou il s'est arrete

**Ne pas supprimer `sync_progress.log`** tant que la migration n'est pas terminee.

---

## 7. Verification post-migration

### Verification quantitative

```bash
# Nombre total d'utilisateurs traites avec succes
wc -l sync_progress.log

# A comparer avec le nombre attendu
```

```sql
-- Nombre d'utilisateurs a traiter
SELECT COUNT(DISTINCT m_uid) FROM messages
WHERE m_date < '2026-01-22' AND m_uid IS NOT NULL;
```

Si les deux nombres correspondent, la migration est complete.

### Verification du resume

Le script affiche un resume en fin d'execution :

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

- **Echoues = 0** : migration complete
- **Echoues > 0** : relancer le script pour retenter les utilisateurs en echec

### Verification sur Zendesk

1. Rechercher un ticket avec le tag `historique_admin` dans Zendesk
2. Verifier que les messages sont visibles dans le widget
3. Verifier que le statut du ticket correspond (open/solved)

### Traitement des utilisateurs en echec

Si certains utilisateurs restent en echec apres plusieurs relances :

```bash
# Tenter un utilisateur specifique pour diagnostiquer
php script.php {uid_en_echec}
```

Causes possibles :
- Aucun message en base pour cet utilisateur (ignore, pas une erreur)
- Timeout API SunCo (reessayer)
- Erreur 429 (rate limit) : attendre quelques minutes et reessayer
- Utilisateur sans external_id valide cote Zendesk

---

## 8. Nettoyage post-migration

Une fois la migration **completement terminee** et **verifiee** :

```bash
# Archiver le fichier de progression
mv sync_progress.log sync_progress_done_$(date +%Y%m%d).log

# Archiver les logs
gzip migration_*.log

# Le script n'a plus besoin d'etre execute
# Conserver le code source et les logs archives pour tracabilite
```

---

## 9. Troubleshooting

| Symptome | Cause probable | Solution |
|----------|----------------|----------|
| `Connection refused` sur MariaDB | Host/port/credentials incorrects | Verifier `.env` et connectivite reseau |
| `cURL error 28: Operation timed out` | API SunCo/Zendesk injoignable | Verifier firewall, DNS, proxy |
| `cURL error 60: SSL certificate problem` | Certificats CA manquants | `apt install ca-certificates` |
| `401 Unauthorized` sur SunCo | Credentials SunCo invalides | Regenerer les cles API SunCo |
| `401 Unauthorized` sur Zendesk | Token Zendesk invalide | Regenerer le token API Zendesk |
| `404` lors de la recherche du ticket | Le ticket SunCo n'est pas encore cree | Normal : le retry (15 tentatives) gere ce cas |
| Ticket trouve mais pas de tag | Le script s'est arrete entre les etapes 4 et 5 | Relancer le script pour cet utilisateur |
| Script tres lent | 1 appel HTTP par message | Normal, voir estimations de temps |
| `PHP Fatal error: Allowed memory size` | Trop de messages pour un utilisateur | Augmenter `memory_limit` dans php.ini |
| `Erreur suppression utilisateur : 429` | Rate limit API SunCo | Attendre 1-2 minutes et relancer |
