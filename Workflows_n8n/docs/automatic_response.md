## 1. Workflow : Automatic_Response

### 🎯 Objectif
Générer automatiquement une proposition de réponse pour les tickets Zendesk entrants afin d'accélérer le traitement par les agents humains.

### 🔄 Fonctionnement

**Déclencheur :** 
- Exécution toutes les heures (Schedule Trigger)
- Récupère les tickets créés par email dans les 1h20 précédentes

**Pipeline de traitement :**

1. **Agent "Analyste"** (Premier LLM)
   - Analyse le sujet et la description du ticket
   - Effectue EXACTEMENT UNE recherche dans deux sources :
     - `Documentation` : Procédures bancaires officielles, limites, règles de conformité
     - `Solved tickets` : Historique des conversations agent/client
   - Retourne un JSON structuré avec :
     - `official_procedure` : Procédure officielle applicable
     - `past_precedents` : Précédents similaires
     - `missing_info` : Informations manquantes détectées
   
2. **Agent "Rédacteur"** (Second LLM)
   - Reçoit les notes techniques de l'Analyste
   - Détecte le segment client (Freelancers, Entreprises, Associations)
   - Adapte le ton de la réponse :
     - **Freelancers** : Direct, efficace, "straight-to-the-point"
     - **Entreprises/Associations** : Structuré, rassurant, formel
   - Génère une réponse en français avec :
     - Salutation professionnelle
     - Explication claire (avec bullet points si nécessaire)
     - Prochaines étapes
     - Clôture standard Anytime

3. **Mise à jour Zendesk**
   - Ajoute la réponse générée comme **note interne** au ticket
   - L'agent humain peut la valider/modifier avant envoi au client

### 📋 Sortie
- **Si pertinent** : Note interne avec la réponse proposée
- **Si non pertinent** : `"Pas de réponse automatique pertinente"`

### 🔧 Technologies utilisées
- **Google Vertex AI** (Gemini) : 2 agents LLM
- **Vertex RAG Store** : Recherche dans la documentation et les tickets résolus
- **Zendesk API** : Récupération et mise à jour des tickets
- **Structured Output Parser** : Garantit des réponses JSON bien formatées

---