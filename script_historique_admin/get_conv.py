import sys
from dotenv import load_dotenv
from utils import get_user_conversations as get_convs_list

load_dotenv()

# ⚙️ À modifier : mettez ici l'ID externe (ou userId) de l'utilisateur
USER_EXTERNAL_ID = "3119"

def display_conversations(conversations):
    """
    Affiche les détails des conversations.
    
    Args:
        conversations: Liste des conversations
    """
    if not conversations:
        print(f"ℹ️  Aucune conversation à afficher.")
        return
    
    print(f"✅ {len(conversations)} conversation(s) trouvée(s) :\n")
    
    for conv in conversations:
        conv_id = conv.get('id')
        display_name = conv.get('displayName', 'Sans titre')
        status = conv.get('metadata', {}).get('status', 'N/A')
        created_at = conv.get('createdAt', 'N/A')
        
        print(f"\n  📌 ID : {conv_id}")
        print(f"     Titre : {display_name}")
        print(f"     Statut : {status}")
        print(f"     Créée : {created_at}")

if __name__ == "__main__":
    # Utiliser l'ID défini en haut du fichier, ou celui passé en argument
    if len(sys.argv) > 1:
        external_id = sys.argv[1]
    else:
        external_id = USER_EXTERNAL_ID
    
    if not external_id:
        print("❌ Aucun ID utilisateur spécifié.")
        print("   Modifiez USER_EXTERNAL_ID en haut du fichier ou passez l'ID en argument :")
        print("   python get_conv.py 3117")
        sys.exit(1)
    
    conversations = get_convs_list(external_id)
    
    if conversations is None:
        sys.exit(1)
    
    display_conversations(conversations)
