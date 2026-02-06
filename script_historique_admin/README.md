# Script Histo v2 - Documentation

## Structure du projet

### Dossier `utils/`
Le dossier `utils/` contient toutes les fonctions réutilisables organisées par domaine:

#### `sunco.py`
Fonctions pour l'API Sunshine Conversations (SunCo):
- `get_sunco_auth()` - Authentification SunCo
- `get_sunco_base_url()` - URL de base SunCo
- `ensure_sunco_user()` - Création/vérification d'un utilisateur
- `create_conversation()` - Création de conversation
- `post_historical_message()` - Ajout de messages
- `migrate_conversation()` - Migration complète d'une conversation
- `create_sunco_conversation()` - Création conversation avec messages
- `get_user_conversations()` - Récupération des conversations d'un utilisateur
- `delete_conversation()` - Suppression d'une conversation

#### `zendesk.py`
Fonctions pour l'API Zendesk Support:
- `get_zendesk_auth()` - Authentification Zendesk
- `get_zendesk_base_url()` - URL de base Zendesk
- `get_zendesk_user_id_from_sunco()` - Récupération du zendesk_id depuis SunCo
- `find_latest_zendesk_ticket_for_user()` - Recherche du ticket le plus récent
- `update_zendesk_ticket()` - Mise à jour d'un ticket

#### `sync.py`
Fonction de synchronisation SunCo vers Zendesk:
- `sync_to_zendesk()` - Synchronisation complète

#### `__init__.py`
Exporte toutes les fonctions pour un import facile

### Scripts principaux

#### `delete_conv.py`
Supprime toutes les conversations sauf celle par défaut.
```bash
python delete_conv.py [external_id]
```

#### `get_conv.py`
Liste toutes les conversations d'un utilisateur.
```bash
python get_conv.py [external_id]
```

#### `sync_to_zendesk.py`
Synchronise une conversation SunCo vers Zendesk.
```bash
python sync_to_zendesk.py [external_id]
```

#### `delete_sunshine_user.py`
Supprime un utilisateur de SunCo.
```bash
python delete_sunshine_user.py
```

## Configuration

Les fichiers utilisent des variables d'environnement définies dans `.env`:
- `SUNCO_APP_ID` - ID de l'application SunCo
- `SUNCO_KEY_ID` - Clé API SunCo
- `SUNCO_SECRET` - Secret API SunCo
- `ZENDESK_EMAIL` - Email Zendesk
- `ZENDESK_TOKEN` - Token API Zendesk
- `ZENDESK_SUBDOMAIN` - Sous-domaine Zendesk

## Imports

Tous les scripts importent depuis le package `utils`:

```python
from utils import (
    get_user_conversations,
    delete_conversation,
    sync_to_zendesk,
    # etc.
)
```
