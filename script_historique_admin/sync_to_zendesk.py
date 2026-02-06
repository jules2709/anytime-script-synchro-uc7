"""
Script de synchronisation de l'historique des messages vers Zendesk.

Ce script migre les messages historiques de TOUS les utilisateurs trouvés dans la table
messages (ou d'un utilisateur spécifique si un ID est passé en argument).

Pour chaque utilisateur :
1. Chargement des messages depuis MariaDB (avant CUTOFF_DATE)
2. Suppression de l'utilisateur SunCo pour repartir de zéro
3. Création/vérification de l'utilisateur SunCo
4. Création de la conversation SunCo avec tous les messages
   (SunCo crée automatiquement un ticket Zendesk)
5. Récupération du ticket Zendesk créé par SunCo
6. Mise à jour du ticket (titre, statut dynamique depuis m_done, tags)

Usage:
    python sync_to_zendesk.py              # Tous les utilisateurs
    python sync_to_zendesk.py 3119         # Un seul utilisateur
"""

import sys
from dotenv import load_dotenv
from utils import (
    sync_to_zendesk,
)
from utils.db import load_messages_from_db, get_all_user_ids

load_dotenv()

# ⚙️ Configuration
CONVERSATION_TITLE = "Historique de vos messages avant le 30/01/2026"
ZENDESK_TICKET_TITLE = "Historique des messages précédent la migration sur Zendesk"
ZENDESK_TICKET_TAGS = ["historique_admin"]

# Date limite des messages à récupérer
CUTOFF_DATE = "2026-01-22"


def process_user(external_id):
    """
    Traite la synchronisation pour un utilisateur donné.

    Returns:
        True si succès, False sinon
    """
    print(f"\n{'=' * 60}")
    print(f"👤 Traitement de l'utilisateur : {external_id}")
    print(f"{'=' * 60}\n")

    # Messages à synchroniser depuis MariaDB
    messages = load_messages_from_db(external_id, CUTOFF_DATE)
    if not messages:
        print(f"⏭️  Aucun message pour l'utilisateur {external_id}, ignoré.")
        return True

    # Déterminer le statut du ticket depuis le dernier message client
    last_client_messages = [m for m in messages if m["author_type"] == "user"]
    if last_client_messages:
        ticket_status = "solved" if last_client_messages[-1]["done"] == 1 else "open"
    else:
        ticket_status = "solved"

    print(f"📌 Statut du ticket déterminé depuis m_done : {ticket_status}")

    # Exécuter la synchronisation
    return sync_to_zendesk(
        external_id,
        messages,
        CONVERSATION_TITLE,
        ZENDESK_TICKET_TITLE,
        ticket_status,
        ZENDESK_TICKET_TAGS
    )


if __name__ == "__main__":
    if len(sys.argv) > 1:
        # Mode utilisateur unique
        success = process_user(sys.argv[1])
        if not success:
            sys.exit(1)
    else:
        # Mode tous les utilisateurs
        user_ids = get_all_user_ids(CUTOFF_DATE)
        if not user_ids:
            print("❌ Aucun utilisateur trouvé dans la base.")
            sys.exit(1)

        print(f"\n🚀 Lancement de la synchronisation pour {len(user_ids)} utilisateur(s)...\n")

        success_count = 0
        fail_count = 0
        failed_ids = []

        for i, uid in enumerate(user_ids, 1):
            print(f"\n[{i}/{len(user_ids)}]", end="")
            if process_user(uid):
                success_count += 1
            else:
                fail_count += 1
                failed_ids.append(uid)

        # Résumé final
        print(f"\n\n{'=' * 60}")
        print(f"📊 Résumé de la synchronisation globale")
        print(f"{'=' * 60}")
        print(f"✅ Réussis  : {success_count}/{len(user_ids)}")
        print(f"❌ Échoués  : {fail_count}/{len(user_ids)}")
        if failed_ids:
            print(f"   IDs en échec : {', '.join(failed_ids)}")
        print(f"{'=' * 60}")

        if fail_count > 0:
            sys.exit(1)
