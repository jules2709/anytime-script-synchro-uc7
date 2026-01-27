## 3. Workflow : Store_solved_tickets

### 🎯 Objectif
Archiver les tickets résolus dans Google Cloud Storage après anonymisation RGPD pour alimenter la base de connaissances et améliorer les recherches RAG.

### 🔄 Fonctionnement

**Déclencheur :** 
- Exécution hebdomadaire (Schedule Trigger)
- Récupère tous les tickets résolus depuis la dernière exécution

**Pipeline de traitement :**

1. **Récupération des tickets résolus**
   - Filtre sur le statut "solved" ou "closed"
   - Pour chaque ticket, récupère tous les commentaires/messages via l'API

2. **Agent "RGPD - DPO"** (LLM Anonymiseur)
   - Transforme les conversations en format structuré :
     ```json
     {
       "ticket_id": "123",
       "motif_de_contact": "ouverture_compte",
       "conversation": [
         {"role": "client", "text": "..."},
         {"role": "agent", "text": "..."}
       ]
     }
     ```
   - **Anonymisation stricte** :
     - Noms/Prénoms → `[Prénom]`, `[Nom]`
     - Coordonnées → `[Email]`, `[Téléphone]`, `[Adresse]`
     - Identifiants bancaires → `[IBAN]`, `[Numéro de Carte]`
     - Données de compte → `[Numéro de commande]`, `[Identifiant Client]`
     - Localisation → `[Ville]`, `[Code Postal]`
   - Préserve la structure JSON et le ton original

3. **Stockage dans Google Cloud Storage**
   - Bucket : `tickets_clos`
   - Nom du fichier : `tickets_YYYY-MM-DD` (date ISO)
   - Format : JSON Lines (un ticket par ligne)

### 📋 Sortie
Fichiers JSON anonymisés stockés dans GCS, prêts pour :
- Réindexation dans le Vertex RAG Store
- Analyse de tendances
- Formation continue des modèles LLM

### 🔧 Technologies utilisées
- **Google Vertex AI** (Gemini) : Anonymisation intelligente
- **Zendesk API** : Récupération des conversations
- **Google Cloud Storage** : Stockage persistant
- **Structured Output Parser** : JSON bien formaté

### 🔒 Conformité RGPD
Ce workflow garantit :
- Suppression de toutes les données personnelles identifiables
- Conservation de la valeur informative pour le support
- Tokenisation cohérente (même nom = même token)

---
