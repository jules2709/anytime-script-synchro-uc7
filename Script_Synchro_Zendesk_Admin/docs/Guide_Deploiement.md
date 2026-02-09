# Guide de Deploiement IT - Synchronisation Zendesk vers MariaDB

## Contexte global

Ce projet comporte 2 scripts PHP independants, chacun avec un role distinct et un sens de synchronisation unique :

| Script | Direction | Frequence |
|--------|-----------|-----------|
| **`script_historique_admin_php`** | MariaDB **-->** Zendesk/Widget | **Une seule fois** (+ relance si erreurs) |
| **`scripts_syncro_uc7_php`** (ce script) | Zendesk **-->** MariaDB | **Periodiquement** (cron) |

**Le script d'historique doit etre execute et termine avant d'activer ce cron.** Ce script ignore les tickets tagges `historique_admin` (crees par le script d'historique), mais il est preferable que la migration historique soit finalisee avant de demarrer la synchronisation reguliere.

### Ordre de deploiement

```
1. Deployer et executer script_historique_admin_php   (migration one-shot)
2. Verifier que la migration est complete
3. PUIS deployer et activer le cron de scripts_syncro_uc7_php (ce script)
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
| `curl` | Appels HTTP via Guzzle (Zendesk) | `php -m \| grep curl` |
| `json` | Encodage/decodage des reponses API | `php -m \| grep json` |
| `mbstring` | Gestion des caracteres UTF-8 | `php -m \| grep mbstring` |

### Composer

```bash
composer --version   # >= 2.x recommande
```

### Acces reseau (HTTPS sortant)

Le serveur doit pouvoir atteindre en HTTPS (port 443) :

| Hote | Usage |
|------|-------|
| `{subdomain}.zendesk.com` | API Zendesk Support (ticket events, tickets bulk, agents, ticket details) |

Verifier avec :

```bash
curl -I https://{subdomain}.zendesk.com
```

### Acces base de donnees

| Droit | Table | Usage |
|-------|-------|-------|
| `INSERT` | `messages` | Ecriture des messages synchronises |

L'utilisateur DB a besoin d'un acces en ecriture sur la table `messages`.

---

## 2. Installation

```bash
cd /chemin/vers/scripts_syncro_uc7_php

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

DB_HOST=db-production.internal
DB_PORT=3306
DB_NAME=admin_db
DB_USER=sync_user
DB_PASSWORD=xxxxxxxxxxxx
```

### Securisation du fichier .env

```bash
chmod 600 .env
chown www-data:www-data .env   # Adapter au user qui execute le cron
```

---

## 3. Obtention des credentials

### Token API Zendesk

1. Se connecter a Zendesk Admin Center
2. Aller dans **Apps et integrations > API Zendesk > Token d'API**
3. Creer un nouveau token
4. L'email associe doit avoir le role **Admin** ou **Agent** avec droits de lecture sur les tickets et les utilisateurs

---

## 4. Test avant mise en production

### Premier lancement manuel

```bash
php script.php
```

Verifier :
- Le script affiche "Lancement de la synchronisation..."
- Les messages s'inserent dans MariaDB
- Le fichier `last_sync.txt` est cree avec un timestamp
- Le resume de fin affiche des compteurs coherents

### Verification en base

```sql
-- Verifier les derniers messages inseres
SELECT m_uid, m_topic, m_source, m_date, m_status, m_done
FROM messages
ORDER BY m_date DESC
LIMIT 10;
```

---

## 5. Mise en production (cron)

### Configuration du crontab

```bash
crontab -e
```

Ajouter :

```bash
# Synchronisation Zendesk -> MariaDB (toutes les 5 minutes)
*/5 * * * * cd /chemin/vers/scripts_syncro_uc7_php && php script.php >> /var/log/zendesk_sync.log 2>&1
```

**Frequence recommandee** : 5 minutes. L'API incrementale Zendesk est concue pour ce type d'interrogation periodique. Un intervalle plus court est possible mais augmente le risque de rate limiting.

### Droits sur le repertoire de logs

```bash
touch /var/log/zendesk_sync.log
chown www-data:www-data /var/log/zendesk_sync.log   # Adapter au user du cron
```

### Rotation des logs (logrotate)

Creer `/etc/logrotate.d/zendesk_sync` :

```
/var/log/zendesk_sync.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

---

## 6. Supervision et monitoring

### Indicateurs de bon fonctionnement

| Indicateur | Commande | Attendu |
|------------|----------|---------|
| `last_sync.txt` se met a jour | `stat last_sync.txt` | Modifie recemment (< 10 min) |
| Pas d'erreur dans les logs | `grep -c "❌" /var/log/zendesk_sync.log` | 0 |
| Messages inseres | `tail -50 /var/log/zendesk_sync.log \| grep "Messages insérés"` | Compteur > 0 ou 0 (pas de nouveaux messages) |

### Detection de panne silencieuse

Si le cron echoue silencieusement (crash PHP, erreur de connexion), `last_sync.txt` cesse d'etre mis a jour. Mettre en place une alerte si le fichier n'a pas ete modifie depuis plus de 15 minutes (3 cycles de cron) :

```bash
# Script de verification (a integrer dans un monitoring)
LAST_MODIFIED=$(stat -c %Y /chemin/vers/scripts_syncro_uc7_php/last_sync.txt)
NOW=$(date +%s)
DIFF=$((NOW - LAST_MODIFIED))

if [ $DIFF -gt 900 ]; then
    echo "ALERTE: zendesk_sync inactif depuis $((DIFF/60)) minutes"
fi
```

### Verification quotidienne rapide

```bash
# Dernier timestamp synchronise
cat /chemin/vers/scripts_syncro_uc7_php/last_sync.txt

# Derniere execution
tail -20 /var/log/zendesk_sync.log
```

---

## 7. Fichiers generes

| Fichier | Role | Gestion |
|---------|------|---------|
| `last_sync.txt` | Timestamp de derniere synchronisation | Automatique, ne pas supprimer |
| `/var/log/zendesk_sync.log` | Logs d'execution | Rotation via logrotate |

### last_sync.txt

- Contient un unique timestamp Unix
- Mis a jour a chaque execution reussie
- **Ne pas supprimer** : le script repartirait sur les dernieres 24h uniquement
- Pour forcer une re-synchronisation depuis une date :

```bash
# Convertir une date en timestamp Unix
date -d "2026-01-15 00:00:00" +%s
# Ecrire dans le fichier
echo "1736899200" > last_sync.txt
```

---

## 8. Comportement en cas d'erreur

| Scenario | Comportement | Impact |
|----------|-------------|--------|
| Erreur connexion DB | Script s'arrete | `last_sync.txt` non mis a jour, prochaine execution reprend au meme point |
| Erreur API Zendesk (incremental) | Script s'arrete | Idem ci-dessus |
| Erreur API Zendesk (show_many) | Batch ignore, continue | Les tickets du batch en erreur ne sont pas traites cette fois |
| Erreur insertion DB | Message non insere, exception | Le script s'arrete, les messages suivants sont traites au prochain run |
| Ticket tag `historique_admin` | Filtre, ignore | Normal et attendu |
| Pas de nouveaux evenements | Script se termine normalement | `last_sync.txt` mis a jour, rien a inserer |

**Point important** : le script est concu pour etre idempotent. Si une execution echoue a mi-chemin, la prochaine reprendra les evenements depuis le dernier timestamp sauvegarde.

---

## 9. Troubleshooting

| Symptome | Cause probable | Solution |
|----------|----------------|----------|
| `Connection refused` sur MariaDB | Host/port/credentials incorrects | Verifier `.env` et connectivite reseau |
| `cURL error 28: Operation timed out` | API Zendesk injoignable | Verifier firewall, DNS, proxy |
| `cURL error 60: SSL certificate problem` | Certificats CA manquants | `apt install ca-certificates` |
| `401 Unauthorized` | Token Zendesk invalide ou expire | Regenerer le token dans Zendesk Admin |
| `429 Too Many Requests` | Rate limit Zendesk atteint | Augmenter l'intervalle du cron (ex: */10) |
| `last_sync.txt` ne se met plus a jour | Le cron ne s'execute plus ou crash silencieux | Verifier `crontab -l`, logs systeme, droits fichier |
| Messages en double dans MariaDB | Script relance avec un `last_sync.txt` trop ancien | Verifier le contenu de `last_sync.txt` |
| `PHP Fatal error: Allowed memory size` | Trop d'evenements en une seule page | Augmenter `memory_limit` dans php.ini |
| Logs trop volumineux | Pas de rotation configuree | Mettre en place logrotate (voir section 5) |
| Beaucoup de "Tickets manquants" | Tickets supprimes sur Zendesk | Normal si tickets ont ete purges, verifier les IDs |

### Commandes de diagnostic

```bash
# Verifier que le cron est actif
crontab -l | grep zendesk

# Verifier que PHP fonctionne
php -v && php -m | grep -E "pdo_mysql|curl|json"

# Tester la connexion Zendesk
curl -u "{email}/token:{token}" "https://{subdomain}.zendesk.com/api/v2/users/me.json"

# Tester la connexion MariaDB
php -r "new PDO('mysql:host={host};port=3306;dbname={db}', '{user}', '{pass}');"

# Verifier les dernieres erreurs dans les logs
grep "❌\|Erreur\|Fatal" /var/log/zendesk_sync.log | tail -20
```
