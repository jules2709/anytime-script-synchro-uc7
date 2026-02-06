import os
import requests
from requests.auth import HTTPBasicAuth
from dotenv import load_dotenv

# Charger les credentials (APP_ID, KEY_ID, SECRET)
load_dotenv()

def delete_user_by_external_id():
    # Récupération des accès
    app_id = os.getenv("SUNCO_APP_ID")
    key_id = os.getenv("SUNCO_KEY_ID")
    secret = os.getenv("SUNCO_SECRET")

    if not all([app_id, key_id, secret]):
        print("❌ Erreur : Credentials manquants dans le .env")
        return

    # Demander l'external_id à l'utilisateur
    external_id = input("Entrez l'external_id de l'utilisateur à supprimer : ").strip()

    if not external_id:
        print("❌ Erreur : L'ID ne peut pas être vide.")
        return

    # L'URL accepte directement votre external_id ici
    url = f"https://api.smooch.io/v2/apps/{app_id}/users/{external_id}"
    
    print(f"🔄 Tentative de suppression de l'utilisateur : {external_id}...")

    try:
        response = requests.delete(
            url,
            auth=HTTPBasicAuth(key_id, secret)
        )
        
        if response.status_code == 200:
            print(f"✅ Succès ! L'utilisateur '{external_id}' a été supprimé de Sunshine.")
            print("💡 Son prochain passage sur le widget repartira de zéro.")
        elif response.status_code == 404:
            print(f"⚠️ Erreur 404 : Aucun utilisateur trouvé avec l'id '{external_id}'.")
        else:
            print(f"❌ Erreur {response.status_code} : {response.text}")
            
    except Exception as e:
        print(f"Une erreur réseau est survenue : {e}")

if __name__ == "__main__":
    delete_user_by_external_id()