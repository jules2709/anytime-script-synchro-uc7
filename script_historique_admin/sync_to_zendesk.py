"""
Script de synchronisation de l'historique des messages vers Zendesk.

Ce script effectue les opérations suivantes étape par étape :

1. **Récupération de l'ID utilisateur** : Récupère l'ID utilisateur  depuis la constante EXTERNAL_ID définie dans ce fichier.

2. **Chargement des messages depuis MariaDB** : Récupère tous les messages de l'utilisateur
   antérieurs à la date limite (CUTOFF_DATE) stockés dans la base de données.

3. **Suppression de l'utilisateur SunCo** : Efface l'utilisateur SunCo pour repartir de zéro
   et éviter les doublons.

4. **Création/vérification de l'utilisateur SunCo** : S'assure que l'utilisateur existe dans SunCo.

5. **Création de la conversation SunCo** : Crée une conversation SunCo avec tous les messages.
   SunCo crée automatiquement un ticket Zendesk correspondant.

6. **Récupération du ticket Zendesk** : Trouve le ticket Zendesk créé par SunCo en utilisant
   l'ID utilisateur.

7. **Mise à jour du ticket** : Met à jour le ticket Zendesk avec le titre, statut et tags souhaités.

Usage:
    python sync_to_zendesk.py [ID_UTILISATEUR]
    
Exemple:
    python sync_to_zendesk.py 3119
"""

import sys
from dotenv import load_dotenv
from utils import (
    sync_to_zendesk,
)
from utils.db import load_messages_from_db

load_dotenv()

# ⚙️ À modifier selon vos besoins
EXTERNAL_ID = "3119"
CONVERSATION_TITLE = "Historique de vos messages avant le 30/01/2026"
ZENDESK_TICKET_TITLE = "Historique des messages précédent la migration sur Zendesk"
ZENDESK_TICKET_STATUS = "solved"
ZENDESK_TICKET_TAGS = ["historique_admin"]

# Date limite des messages à récupérer
CUTOFF_DATE = "2026-01-22"

if __name__ == "__main__":
    # Récupérer l'ID utilisateur
    if len(sys.argv) > 1:
        external_id = sys.argv[1]
    else:
        external_id = EXTERNAL_ID
    
    if not external_id:
        print("❌ Aucun ID utilisateur spécifié.")
        print("   Modifiez EXTERNAL_ID en haut du fichier ou passez l'ID en argument :")
        print("   python sync_to_zendesk.py 3119")
        sys.exit(1)
    
    # Messages à synchroniser depuis MariaDB
    messages = load_messages_from_db(external_id, CUTOFF_DATE)
    if not messages:
        print("❌ Aucun message à synchroniser.")
        sys.exit(1)
    
    # Exécuter la synchronisation
    success = sync_to_zendesk(
        external_id,
        messages,
        CONVERSATION_TITLE,
        ZENDESK_TICKET_TITLE,
        ZENDESK_TICKET_STATUS,
        ZENDESK_TICKET_TAGS
    )
    
    if not success:
        sys.exit(1)
