# Workflow : Get_Motif_Contact

## Objectif

Classifier automatiquement chaque nouveau ticket Zendesk dans une categorie de "Motif de Contact" predifinie. Ce motif alimente le routage, les statistiques et la priorisation des tickets.

**Statut : Actif**

## Declencheur

- **Schedule Trigger** : toutes les 10 minutes
- **Plage horaire** : 9h - 18h, du lundi au vendredi
- **Cron** : `0 */10 9-18 * * 1-5`

## Canaux traites

Le workflow recupere les tickets crees depuis les deux canaux :
- **Email** : sujet et description standards du ticket
- **Native messaging (BAQ)** : sujet et description dans des champs personnalises (IDs `43473213212177` et `42767615870481`)

## Pipeline de traitement

```
Schedule (toutes les 10 min)
    |
    v
[Get many ticket fields]
    --> Recupere tous les champs personnalises Zendesk
    |
    v
[Filter "Motif de contact interne"]
    --> Isole la definition du champ motif de contact
    |
    v
[Format list]
    --> Extrait la liste des motifs autorises (format "Nom | Valeur")
    |
    v
[New created tickets]
    --> Recupere les tickets recents (email + native_messaging)
    |
    v
[Motif de contact interne vide]
    --> Ne conserve que les tickets sans motif deja renseigne
    |
    v
[Switch par canal]
    ├── Email : sujet + description standards
    └── Native messaging : champs personnalises BAQ
    |
    v
[Merge]
    |
    v
[Ticket Analysis] (Agent LLM - Google Vertex Gemini)
    --> Analyse le sujet et la description
    --> Compare avec la liste des motifs autorises
    --> Retourne : {category_value, confidence_score, reasoning}
    |
    v
[Update a ticket]
    --> Met a jour le champ personnalise 42153295723665
    |
    v
[Call 'Automatic_Response']
    --> Declenche le workflow de reponse automatique
```

## Agent LLM : Classificateur de tickets

**Prompt** : `prompts/GetMotifContact_TicketAnalyst.md`

**Role** : Senior Back-Office Automation Specialist

**Regles strictes** :
- **Zero Hallucination Policy** : ne peut PAS inventer une categorie absente de la liste
- **Fallback** : si le ticket est ambigu ou ne correspond a aucune categorie, retourne `"autre"`
- **Terminologie bancaire** : comprend KYC, SEPA, AML, IBAN, etc.
- **Adherence a la liste** : la valeur `category_value` doit etre un match exact d'une valeur de la liste fournie

**Format de sortie** :

```json
{
  "category_value": "ouverture_compte",
  "confidence_score": 0.92,
  "reasoning": "Customer explicitly asks about opening a new account"
}
```

## Sortie

- Le champ personnalise "Motif de contact" (ID `42153295723665`) est renseigne sur le ticket
- Le workflow Automatic_Response est declenche avec le `ticket_id`

## Points d'attention

- Seuls les tickets avec un motif de contact **vide** sont traites (pas de re-classification)
- La liste des motifs est recuperee dynamiquement depuis Zendesk a chaque execution (pas de liste hardcodee)
- Le score de confiance permet de mesurer la fiabilite de la classification
- Le workflow Automatic_Response est un sous-workflow : il n'a pas besoin d'etre "actif" dans n8n pour s'executer quand il est appele
