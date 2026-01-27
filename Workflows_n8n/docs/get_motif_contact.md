## 2. Workflow : Get_Motif_Contact

### 🎯 Objectif
Classifier automatiquement chaque ticket entrant dans une catégorie de "Motif de Contact" prédéfinie pour faciliter le routage et les statistiques.

### 🔄 Fonctionnement

**Déclencheur :** 
- Webhook ou trigger Zendesk sur la création d'un ticket

**Pipeline de traitement :**

1. **Récupération de la liste des motifs**
   - Appel API Zendesk pour obtenir les champs personnalisés
   - Extraction de la liste des motifs de contact autorisés (format : "Nom | Valeur technique")

2. **Agent LLM "Classificateur"**
   - Analyse le sujet et le contenu du ticket
   - Applique des règles strictes :
     - **Zero Hallucination Policy** : Ne peut PAS inventer de catégorie
     - **Fallback** : Si ambiguë, utilise la catégorie `"autre"`
     - Comprend la terminologie bancaire française (KYC, SEPA, AML, IBAN, etc.)
   - Retourne un JSON avec :
     - `category_value` : Tag technique exact (doit être dans la liste fournie)
     - `confidence_score` : Score de confiance (0.0 à 1.0)
     - `reasoning` : Justification en anglais

3. **Mise à jour Zendesk**
   - Met à jour le champ personnalisé "Motif de Contact" (ID: 42153295723665)
   - Le ticket est maintenant catégorisé automatiquement

### 📋 Sortie
Le ticket Zendesk est enrichi avec le motif de contact détecté automatiquement.

### 🔧 Technologies utilisées
- **Google Vertex AI** (Gemini) : Classification par LLM
- **Zendesk API** : Récupération des champs et mise à jour
- **Structured Output Parser** : JSON formaté

### 📊 Cas d'usage
- Routage automatique vers les bonnes équipes
- Statistiques de support (volumes par motif)
- Priorisation basée sur le type de demande

---
