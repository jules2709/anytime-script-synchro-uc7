import sys
from dotenv import load_dotenv
from utils import get_user_conversations, delete_conversation

load_dotenv()

# ⚙️ À modifier : mettez ici l'external_id de l'utilisateur
USER_EXTERNAL_ID = "3119"

if __name__ == "__main__":
    # Utiliser l'ID défini en haut du fichier, ou celui passé en argument
    if len(sys.argv) > 1:
        external_id = sys.argv[1]
    else:
        external_id = USER_EXTERNAL_ID
    
    if not external_id:
        print("❌ Aucun ID utilisateur spécifié.")
        print("   Modifiez USER_EXTERNAL_ID en haut du fichier ou passez l'ID en argument :")
        print("   python delete_conv.py 3117")
        sys.exit(1)
    
    # Récupérer toutes les conversations de l'utilisateur
    conversations = get_user_conversations(external_id)
    
    if conversations is None:
        sys.exit(1)
    
    if not conversations:
        print("ℹ️  Aucune conversation à supprimer.")
        sys.exit(0)
    
    # Identifier la conversation par défaut et les autres
    default_conv = None
    non_default_convs = []
    
    for conv in conversations:
        conv_id = conv.get('id')
        is_default = conv.get('isDefault', False)
        display_name = conv.get('displayName', 'Sans titre')
        
        if is_default:
            default_conv = conv
            print(f"🏠 Conversation par défaut : {conv_id} ({display_name})")
        else:
            non_default_convs.append(conv)
            print(f"📌 Conversation à supprimer : {conv_id} ({display_name})")
    
    if not non_default_convs:
        print("\n✅ Aucune conversation à supprimer (seule la conversation par défaut existe).")
        sys.exit(0)
    
    # Demander confirmation
    print(f"\n⚠️  {len(non_default_convs)} conversation(s) sera/seront supprimée(s).")
    if default_conv:
        print(f"   La conversation par défaut ({default_conv.get('id')}) sera conservée.")
    
    confirm = input(f"\n⚠️  Êtes-vous sûr de vouloir supprimer {len(non_default_convs)} conversation(s) ? (oui/non) : ")
    
    if confirm.lower() in ['oui', 'o', 'yes', 'y']:
        print()
        failed_count = 0
        for conv in non_default_convs:
            conv_id = conv.get('id')
            success = delete_conversation(conv_id)
            if not success:
                failed_count += 1
        
        print()
        if failed_count == 0:
            print(f"✅ Toutes les {len(non_default_convs)} conversation(s) ont été supprimées avec succès !")
        else:
            print(f"⚠️  {failed_count}/{len(non_default_convs)} suppression(s) échouée(s).")
            sys.exit(1)
    else:
        print("❌ Suppression annulée.")
        sys.exit(0)
