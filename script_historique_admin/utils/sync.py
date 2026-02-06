"""
Fonction de synchronisation SunCo vers Zendesk
"""

from datetime import datetime, timezone
from .sunco import create_sunco_conversation, delete_sunco_user, ensure_sunco_user
from .zendesk import (
    find_latest_zendesk_ticket_for_user,
    update_zendesk_ticket,
)

def sync_to_zendesk(external_id, messages_list, conversation_title, ticket_title, ticket_status, ticket_tags=None):
    """
    Effectue la synchronisation complète :
    1. Enregistre le timestamp du démarrage
    2. Vérifie/crée l'utilisateur SunCo
    3. Crée une conversation SunCo
    4. Ajoute les messages (SunCo crée automatiquement un ticket Zendesk)
    5. Trouve le ticket créé par SunCo (créé APRÈS le timestamp initial) via l'external_id
    6. Le met à jour avec le titre, statut et tags souhaités
    """
    # Enregistrer le timestamp du démarrage
    start_time = datetime.now(timezone.utc)
    print(f"🚀 Démarrage de la synchronisation pour l'utilisateur {external_id}...")
    print(f"   Timestamp : {start_time.isoformat()}\n")
    
    # 1. Supprimer l'utilisateur SunCo pour repartir de zéro
    if not delete_sunco_user(external_id):
        print("❌ Impossible de supprimer l'utilisateur SunCo.")
        return False

    # 2. S'assurer que l'utilisateur existe dans SunCo
    if not ensure_sunco_user(external_id, f"User {external_id}"):
        print("❌ Impossible de créer/vérifier l'utilisateur SunCo.")
        return False
    
    # 3. Créer la conversation SunCo (cela crée automatiquement un ticket Zendesk)
    conversation_id = create_sunco_conversation(external_id, messages_list, conversation_title)
    
    if not conversation_id:
        print("❌ Impossible de créer la conversation SunCo.")
        return False
    
    # 4. Trouver le ticket créé par SunCo (créé APRÈS start_time via l'external_id)
    ticket_id = find_latest_zendesk_ticket_for_user(external_id, created_after=start_time)
    
    if not ticket_id:
        print("❌ Impossible de trouver le ticket Zendesk créé par SunCo.")
        return False
    
    # 5. Mettre à jour le ticket avec le titre, statut et tags souhaités
    if not update_zendesk_ticket(ticket_id, ticket_title, ticket_status, ticket_tags):
        print("❌ Impossible de mettre à jour le ticket Zendesk.")
        return False
    
    print("=" * 60)
    print(f"✅ Synchronisation terminée avec succès !\n")
    print(f"📋 Conversation SunCo : {conversation_id}")
    print(f"🎫 Ticket Zendesk : #{ticket_id}")
    print(f"   Messages ajoutés et ticket mis à jour\n")
    print("=" * 60)
    
    return True
