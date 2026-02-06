"""
Fonctions pour l'API SunCo (Sunshine Conversations)
"""

import os
import requests
from requests.auth import HTTPBasicAuth

REQUEST_TIMEOUT = 10

def get_sunco_auth():
    """Retourne l'authentification HTTPBasicAuth pour SunCo."""
    key_id = os.getenv("SUNCO_KEY_ID")
    secret = os.getenv("SUNCO_SECRET")
    return HTTPBasicAuth(key_id, secret)

def get_sunco_base_url():
    """Retourne l'URL de base de l'API SunCo."""
    app_id = os.getenv("SUNCO_APP_ID")
    return f"https://api.smooch.io/v2/apps/{app_id}"

def delete_sunco_user(external_id):
    """
    Supprime un utilisateur SunCo par external_id.
    
    Args:
        external_id: ID externe de l'utilisateur
    
    Returns:
        True si succès, False sinon
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/users/{external_id}"
    
    print(f"🗑️  Suppression de l'utilisateur SunCo : {external_id}...")
    
    response = requests.delete(url, auth=auth)
    
    if response.status_code == 200:
        print(f"✅ Utilisateur {external_id} supprimé avec succès.")
        return True
    if response.status_code == 404:
        print(f"ℹ️  Utilisateur {external_id} introuvable (déjà supprimé).")
        return True
    
    print(f"❌ Erreur suppression utilisateur : {response.status_code}")
    print(f"   Détails : {response.text}")
    return False

def ensure_sunco_user(external_id, name):
    """
    Vérifie si l'utilisateur existe dans SunCo ou le crée.
    
    Args:
        external_id: ID externe de l'utilisateur (depuis votre base)
        name: Nom de l'utilisateur
    
    Returns:
        ID externe de l'utilisateur si succès, None sinon
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/users"
    
    payload = {
        "externalId": str(external_id),
        "profile": {"givenName": name}
    }
    
    # On tente de créer l'utilisateur (renvoie 409 si déjà existant)
    response = requests.post(url, auth=auth, json=payload)
    
    if response.status_code in [201, 409]:
        print(f"✅ Utilisateur {external_id} ({name}) prêt dans SunCo.")
        return str(external_id)
    else:
        print(f"❌ Erreur création/vérification utilisateur : {response.text}")
        return None

def create_conversation(external_id, status="active", display_name="Historique de vos messages"):
    """
    Crée une nouvelle session de conversation pour l'utilisateur.
    
    Args:
        external_id: ID externe de l'utilisateur
        status: Statut de la conversation ('active' ou 'closed')
        display_name: Nom affiché de la conversation
    
    Returns:
        ID de la conversation si succès, None sinon
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/conversations"
    
    payload = {
        "type": "personal",  # Type standard pour le Web Messaging
        "displayName": display_name,
        "participants": [{"userExternalId": str(external_id)}],
        "metadata": {
            "status": status  # 'active' ou 'closed'
        }
    }
    
    response = requests.post(url, auth=auth, json=payload)
    
    if response.status_code == 201:
        conversation_id = response.json()['conversation']['id']
        print(f"🎉 Conversation créée : {conversation_id}")
        return conversation_id
    else:
        print(f"❌ Erreur création conversation : {response.text}")
        return None

def post_historical_message(conversation_id, author_type, text, external_id=None):
    """
    Poste un message historique dans une conversation.
    
    Args:
        conversation_id: ID de la conversation
        author_type: Type d'auteur ('user' pour client ou 'business' pour agent)
        text: Contenu du message
        external_id: ID externe de l'utilisateur (requis pour les messages de type 'user')
    
    Returns:
        True si succès, False sinon
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/conversations/{conversation_id}/messages"
    
    # Pour les messages client, inclure l'identifiant de l'utilisateur
    author = {"type": author_type}
    if author_type == "user" and external_id:
        author["userExternalId"] = str(external_id)
    
    payload = {
        "author": author,
        "content": {
            "type": "text",
            "text": text
        }
    }
    
    response = requests.post(url, auth=auth, json=payload)
    
    if response.status_code == 201:
        print(f"   ✓ Message de {author_type} ajouté.")
        return True
    else:
        print(f"   ✗ Erreur ajout message : {response.text}")
        return False

def migrate_conversation(external_id, client_name, messages_list, status="closed", display_name="Historique de vos messages"):
    """
    Effectue la migration complète d'une conversation : crée l'utilisateur,
    crée la conversation et injecte l'historique.
    
    Args:
        external_id: ID externe du client
        client_name: Nom du client
        messages_list: Liste de dictionnaires avec 'author_type' et 'text'
        status: Statut de la conversation ('active' ou 'closed', par défaut 'closed' pour l'historique)
        display_name: Nom affiché de la conversation
    
    Returns:
        True si succès, False sinon
    """
    # 1. S'assurer que le client existe dans SunCo
    if not ensure_sunco_user(external_id, client_name):
        return False
    
    # 2. Créer la conversation avec le statut
    conv_id = create_conversation(external_id, status, display_name)
    if not conv_id:
        return False
    
    # 3. Injecter l'historique
    print(f"🔄 Injection de {len(messages_list)} messages...")
    for msg in messages_list:
        # Passer external_id pour les messages user
        post_historical_message(conv_id, msg['author_type'], msg['text'], external_id)
    
    print(f"✅ Migration réussie pour la conversation {conv_id}")
    print(f"📋 ID de la conversation : {conv_id}")
    return conv_id  # Retourne l'ID au lieu de True

def create_sunco_conversation(external_id, messages_list, display_name):
    """
    Crée une conversation SunCo et ajoute les messages.
    
    Args:
        external_id: ID externe de l'utilisateur
        messages_list: Liste des messages à ajouter
        display_name: Nom de la conversation
    
    Returns:
        ID de la conversation si succès, None sinon
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/conversations"
    
    payload = {
        "type": "personal",
        "displayName": display_name,
        "participants": [{"userExternalId": str(external_id)}],
    }
    
    try:
        print(f"🎯 Création de la conversation SunCo : '{display_name}'...")
        response = requests.post(url, auth=auth, json=payload, timeout=REQUEST_TIMEOUT)
        
        if response.status_code == 201:
            conversation_id = response.json()['conversation']['id']
            print(f"✅ Conversation créée : {conversation_id}\n")
            
            # Ajouter les messages
            print(f"🔄 Injection de {len(messages_list)} message(s)...")
            for msg in messages_list:
                post_historical_message(conversation_id, msg['author_type'], msg['text'], external_id)
            print()
            
            return conversation_id
        else:
            print(f"❌ Erreur création conversation : {response.text}")
            return None
    except Exception as e:
        print(f"❌ Erreur : {e}")
        return None

def get_user_conversations(external_id):
    """
    Liste toutes les conversations auxquelles un utilisateur participe.
    
    Args:
        external_id: ID externe de l'utilisateur
    
    Returns:
        Liste des conversations, ou None en cas d'erreur
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/conversations"
    
    params = {
        "filter[userExternalId]": str(external_id)
    }
    
    print(f"🔍 Recherche des conversations pour l'utilisateur {external_id}...\n")
    
    response = requests.get(url, auth=auth, params=params)
    
    if response.status_code == 200:
        data = response.json()
        conversations = data.get('conversations', [])
        
        if not conversations:
            print(f"ℹ️  Aucune conversation trouvée pour l'utilisateur {external_id}.")
            return []
        
        print(f"✅ {len(conversations)} conversation(s) trouvée(s).\n")
        return conversations
    else:
        print(f"❌ Erreur lors de la récupération des conversations : {response.status_code}")
        print(f"   Détails : {response.text}")
        return None

def delete_conversation(conversation_id):
    """
    Supprime une conversation et tous ses messages et pièces jointes.
    
    Args:
        conversation_id: ID de la conversation à supprimer
    
    Returns:
        True si succès, False sinon
    """
    auth = get_sunco_auth()
    base_url = get_sunco_base_url()
    url = f"{base_url}/conversations/{conversation_id}"
    
    print(f"🗑️  Suppression de la conversation {conversation_id}...")
    
    response = requests.delete(url, auth=auth)
    
    if response.status_code == 200:
        print(f"✅ Conversation {conversation_id} supprimée avec succès.")
        return True
    else:
        print(f"❌ Erreur lors de la suppression : {response.status_code}")
        print(f"   Détails : {response.text}")
        return False
