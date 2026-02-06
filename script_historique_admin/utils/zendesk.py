"""
Fonctions pour l'API Zendesk Support
"""

import os
import time
import requests

REQUEST_TIMEOUT = 10

def get_zendesk_auth():
    """Retourne l'authentification Zendesk."""
    z_email = os.getenv("ZENDESK_EMAIL")
    z_token = os.getenv("ZENDESK_TOKEN")
    return (f"{z_email}/token", z_token)

def get_zendesk_base_url():
    """Retourne l'URL de base de l'API Zendesk."""
    z_subdomain = os.getenv("ZENDESK_SUBDOMAIN")
    return f"https://{z_subdomain}.zendesk.com/api/v2"

# NOTE: Cette fonction n'est plus utilisée car on peut rechercher directement 
# l'utilisateur Zendesk via external_id dans find_latest_zendesk_ticket_for_user()
# 
# def get_zendesk_user_id_from_sunco(external_id):
#     """
#     Récupère le zendesk_id (ID utilisateur Zendesk Support) depuis l'utilisateur SunCo.
#     SunCo crée automatiquement un utilisateur dans Zendesk et stocke son ID dans le profil.
#     """
#     ...

def find_latest_zendesk_ticket_for_user(external_id, created_after=None, max_retries=15, retry_delay=1):
    """
    Liste les tickets de l'utilisateur Zendesk et retourne le plus récent créé APRÈS created_after.
    Cette méthode interroge la base de données directement (pas d'indexation).
    
    Args:
        external_id: ID externe de l'utilisateur
        created_after: datetime object - chercher uniquement les tickets créés après ce moment
        max_retries: Nombre maximum de tentatives
        retry_delay: Délai entre les tentatives en secondes
    
    Returns:
        ID du ticket le plus récent si trouvé, None sinon
    """
    auth = get_zendesk_auth()
    base_url = get_zendesk_base_url()
    
    # Rechercher l'utilisateur par external_id
    search_url = f"{base_url}/users/search.json"
    search_params = {"external_id": str(external_id)}
    
    print(f"🔍 Recherche du ticket le plus récent pour l'utilisateur {external_id}...")
    if created_after:
        print(f"   (créé après {created_after.isoformat()})")
    print(f"   (max {max_retries} tentatives, délai de {retry_delay}s entre chaque)\n")
    
    # Trouver le zendesk_user_id
    try:
        search_response = requests.get(search_url, auth=auth, params=search_params, timeout=REQUEST_TIMEOUT)
        if search_response.status_code != 200:
            print(f"❌ Erreur recherche utilisateur : {search_response.text}")
            return None
        
        users = search_response.json().get('users', [])
        if not users:
            print(f"⚠️  Aucun utilisateur Zendesk trouvé avec l'external_id {external_id}")
            return None
        
        zendesk_user_id = users[0]['id']
        print(f"   ✓ Utilisateur Zendesk trouvé : {zendesk_user_id}\n")
    except Exception as e:
        print(f"❌ Erreur recherche utilisateur : {e}")
        return None
    
    # Récupérer les tickets demandés par l'utilisateur (requested tickets)
    url = f"{base_url}/users/{zendesk_user_id}/tickets/requested.json"
    params = {
        "sort_by": "created_at",
        "sort_order": "desc"  # Plus récents d'abord
    }
    
    for attempt in range(1, max_retries + 1):
        try:
            response = requests.get(url, auth=auth, params=params, timeout=REQUEST_TIMEOUT)
            
            if response.status_code == 200:
                tickets = response.json().get('tickets', [])
                
                # Filtrer les tickets créés après created_after
                if created_after:
                    from datetime import datetime
                    created_after_timestamp = created_after.timestamp()
                    filtered_tickets = []
                    for ticket in tickets:
                        ticket_created_at = ticket.get('created_at')
                        if ticket_created_at:
                            # Convertir la date ISO en timestamp
                            ticket_timestamp = datetime.fromisoformat(ticket_created_at.replace('Z', '+00:00')).timestamp()
                            if ticket_timestamp >= created_after_timestamp:
                                filtered_tickets.append(ticket)
                    tickets = filtered_tickets
                
                if tickets:
                    # Prendre le ticket le plus récent (premier dans la liste)
                    ticket_id = tickets[0]['id']
                    print(f"✅ Ticket trouvé au bout de {attempt} tentative(s) : #{ticket_id}\n")
                    return ticket_id
                else:
                    # Aucun ticket pour cet utilisateur, réessayer
                    if attempt < max_retries:
                        print(f"   ⏳ Tentative {attempt}/{max_retries} : aucun ticket trouvé, nouvelle tentative dans {retry_delay}s...")
                        time.sleep(retry_delay)
                    else:
                        print(f"⚠️  Aucun ticket trouvé après {max_retries} tentatives pour l'utilisateur {external_id}.")
                        return None
            else:
                print(f"❌ Erreur récupération tickets (tentative {attempt}) : {response.text}")
                return None
        except Exception as e:
            print(f"❌ Erreur (tentative {attempt}) : {e}")
            if attempt < max_retries:
                time.sleep(retry_delay)
    
    return None

def update_zendesk_ticket(ticket_id, ticket_title, ticket_status, ticket_tags=None):
    """
    Met à jour un ticket Zendesk existant (créé automatiquement par SunCo).
    
    Args:
        ticket_id: ID du ticket à mettre à jour
        ticket_title: Nouveau titre du ticket
        ticket_status: Nouveau statut du ticket
        ticket_tags: Liste des tags à ajouter au ticket (optionnel)
    
    Returns:
        True si succès, False sinon
    """
    auth = get_zendesk_auth()
    base_url = get_zendesk_base_url()
    url = f"{base_url}/tickets/{ticket_id}.json"
    headers = {"Content-Type": "application/json"}
    
    payload = {
        "ticket": {
            "subject": ticket_title,
            "status": ticket_status,
        }
    }
    
    if ticket_tags:
        payload["ticket"]["tags"] = ticket_tags
    
    try:
        print(f"🎫 Mise à jour du ticket Zendesk #{ticket_id}...")
        response = requests.put(url, auth=auth, headers=headers, json=payload, timeout=REQUEST_TIMEOUT)
        
        if response.status_code == 200:
            ticket = response.json()['ticket']
            print(f"✅ Ticket mis à jour : #{ticket_id}")
            print(f"   Titre : {ticket.get('subject')}")
            print(f"   Statut : {ticket.get('status')}")
            if ticket.get('tags'):
                print(f"   Tags : {', '.join(ticket.get('tags'))}")
            print()
            return True
        else:
            print(f"❌ Erreur mise à jour ticket : {response.text}")
            return False
    except Exception as e:
        print(f"❌ Erreur : {e}")
        return False
