# Workflow : Automatic_Response

## Objectif

Generer automatiquement une proposition de reponse pour un ticket Zendesk entrant afin d'accelerer le traitement par les agents humains. La reponse est ajoutee comme **note interne** : l'agent humain valide ou modifie avant envoi au client.

**Type : Sous-workflow** (declenche par Get_Motif_Contact via le noeud "Execute Workflow"). Dans n8n, un sous-workflow n'a pas besoin d'etre mis en "actif" : il s'execute automatiquement lorsque le workflow appelant le declenche.

## Declencheur

- **Execute Workflow Trigger** : appele par le workflow Get_Motif_Contact
- **Parametre d'entree** : `ticket_id` (number)

Ce workflow n'a pas de schedule propre. Il est declenche a la demande apres chaque classification de ticket.

## Canaux traites

Le workflow gere deux canaux de communication :
- **Email** : sujet et description standards du ticket
- **Native messaging (BAQ)** : sujet et description dans des champs personnalises Zendesk

## Pipeline de traitement

```
ticket_id (depuis Get_Motif_Contact)
    |
    v
[New ticket]
    --> GET /api/v2/tickets/{ticket_id}.json
    --> Recupere les details complets du ticket
    |
    v
[Switch par canal]
    ├── Email : extrait subject + description
    └── Native messaging : extrait champs BAQ
    |
    v
[Merge]
    |
    v
[Analyste] (Agent LLM #1 - Google Vertex Gemini)
    |   Outils disponibles :
    |   ├── Documentation (RAG) : procedures bancaires officielles
    |   └── Solved tickets (RAG) : historique des conversations resolues
    |
    |   --> 1 recherche dans Documentation + 1 recherche dans Solved tickets
    |   --> Retourne : {official_procedure, past_precedents, missing_info}
    |
    v
[Redacteur] (Agent LLM #2 - Google Vertex Gemini)
    |   --> Recoit les notes techniques de l'Analyste
    |   --> Detecte le segment client (Freelancer / Entreprise / Association)
    |   --> Redige la reponse en francais
    |   --> Retourne : {reponse}
    |
    v
[Code JavaScript]
    --> Convertit le markdown en HTML
    |
    v
[HTTP Request]
    --> Ajoute une note interne au ticket Zendesk
```

## Agent LLM #1 : Analyste

**Prompt** : `prompts/Auto_reponse_Analyste.md`

**Role** : Technical Support Analyst

**Regles operationnelles** :
- **Single Pass Only** : exactement 1 recherche dans Documentation + 1 dans Solved tickets
- **Pas de retry** : si les resultats sont vides, retourne `"NO_RELEVANT_INFORMATION"`
- **Priorite** : en cas de contradiction, la Documentation prime sur les Solved tickets

**Format de sortie** :

```json
{
  "official_procedure": "Selon la doc 'Limites', le plafond standard est de 5000EUR/30 jours...",
  "past_precedents": "Dans le ticket #4402, nous avons demande une facture pro-forma...",
  "missing_info": "Le client n'a pas precise s'il a essaye via l'App."
}
```

## Agent LLM #2 : Redacteur

**Prompt** : `prompts/Auto_reponse_Redacteur.md`

**Role** : Senior Customer Support Specialist

**Adaptation par segment** :
- **Freelancers** : ton direct, efficace, "straight-to-the-point"
- **Entreprises / Associations** : ton structure, rassurant, formel

**Structure de la reponse** :
1. Salutation professionnelle
2. Explication claire (avec bullet points si necessaire)
3. Prochaines etapes
4. Cloture standard Anytime

**Securite** : si l'Analyste retourne `"NO_RELEVANT_INFORMATION"`, le Redacteur retourne :
```json
{"reponse": "Pas de réponse automatique pertinente"}
```

## Sortie

- **Si pertinent** : note interne au ticket avec la reponse proposee (HTML)
- **Si non pertinent** : note interne `"Pas de réponse automatique pertinente"`

L'agent humain retrouve cette note dans le ticket et peut la valider, modifier ou ignorer avant d'envoyer une reponse au client.

## Points d'attention

- Ce workflow est un **sous-workflow** : il n'apparait pas comme "actif" dans n8n mais s'execute bien quand Get_Motif_Contact l'appelle
- Les deux agents LLM utilisent le Vertex RAG Store pour rechercher dans la documentation et les tickets resolus
- La reponse est convertie en HTML avant d'etre postee comme note interne
- La reponse n'est jamais envoyee directement au client : elle passe obligatoirement par un agent humain
