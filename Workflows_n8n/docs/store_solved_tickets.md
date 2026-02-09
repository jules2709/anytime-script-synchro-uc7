# Workflow : Store_solved_tickets

## Objectif

Archiver les tickets resolus dans Google Cloud Storage apres anonymisation RGPD, puis les importer dans le corpus RAG de Vertex AI pour enrichir la base de connaissances utilisee par les autres workflows.

**Statut : Actif**

## Declencheur

- **Schedule Trigger** : tous les 2 jours a minuit
- **Cron** : `0 0 0 */2 * *`
- **Raison de la frequence** : Zendesk ferme automatiquement les tickets "solved" apres 4 jours. Un cycle de 2 jours permet de capturer les tickets avant leur fermeture.

## Pipeline de traitement

```
Schedule (tous les 2 jours a minuit)
    |
    v
[Generate GCP object id]
    --> Genere le nom de l'objet : tickets_YYYY-MM-DD_{execution_id}
    |
    v
[Get many ticket fields]
    --> Recupere tous les champs personnalises Zendesk
    |
    v
[Get id "motif de contact"]
    --> Extrait l'ID du champ "Motif de contact"
    |
    v
[New solved tickets]
    --> Recupere tous les tickets avec statut "solved"
    |
    v
[Filter]
    --> Exclut les tickets deja tagges "saved_ticket"
    |
    v
[Get ticket messages]
    --> GET /api/v2/tickets/{id}/comments
    --> Recupere tous les commentaires/messages de chaque ticket
    |
    v
[Get Motif de contact]
    --> Extrait la valeur du motif de contact depuis les champs personnalises
    |
    v
[Format conversation]
    --> Parse les messages bruts en format structure :
        [{role: "client"|"agent", text: "..."}]
    |
    v
[RGPD Agent] (Agent LLM - Google Vertex Gemini)
    --> Anonymise les donnees personnelles
    --> Retourne la conversation anonymisee
    |
    v
[Gather fields]
    --> Assemble : {ticket_id, motif_de_contact, conversation}
    |
    v
[Create JSON]
    --> Convertit en fichier JSON
    |
    v
[Create an object] (Google Cloud Storage)
    --> Bucket : tickets_clos
    --> Nom : tickets_YYYY-MM-DD_{execution_id}.json
    |
    v
[HTTP Request] (Google API)
    --> Import dans le corpus RAG Vertex
    --> POST /ragCorpora/{corpus_id}/ragFiles:import
    |
    v
[Update a ticket]
    --> Ajoute le tag "saved_ticket" au ticket Zendesk
```

## Agent LLM : DPO / Anonymiseur RGPD

**Prompt** : `prompts/SolvedTicket_RGPD_Agent.md`

**Role** : Expert en Protection des Donnees (DPO)

**Regles d'anonymisation** :

| Donnee | Token |
|--------|-------|
| Noms / Prenoms | `[Prenom]`, `[Nom]` |
| Coordonnees | `[Email]`, `[Telephone]`, `[Adresse]` |
| Identifiants bancaires | `[IBAN]`, `[Numero de Carte]` |
| Donnees de compte | `[Numero de commande]`, `[Identifiant Client]`, `[Mot de passe]` |
| Localisation | `[Ville]`, `[Code Postal]` |

**Garanties** :
- Structure JSON preservee
- Ton original conserve
- Tokenisation coherente (meme nom = meme token tout au long de la conversation)
- Aucune suppression de message

## Format de sortie (exemple)

```json
{
  "ticket_id": "32",
  "motif_de_contact": "ouverture_compte",
  "conversation": [
    {"role": "client", "text": "Bonjour, j'ai un probleme avec mon compte"},
    {"role": "agent", "text": "Bonjour [Prenom], comment puis-je vous aider ?"},
    {"role": "client", "text": "Mon IBAN est [IBAN] et je ne recois pas mes virements"}
  ]
}
```

## Mecanisme anti-doublon

Le tag `saved_ticket` est ajoute a chaque ticket traite. Au prochain cycle :
- Le workflow recupere les tickets `solved`
- Le filtre exclut ceux deja tagges `saved_ticket`
- Seuls les nouveaux tickets resolus sont traites

Cela garantit qu'un ticket n'est archive qu'une seule fois.

## Destinations du fichier archive

1. **Google Cloud Storage** : bucket `tickets_clos`, fichier JSON nomme par date et ID d'execution
2. **Vertex RAG Store** : import automatique dans le corpus des tickets resolus (ID `5685794529555251200`) pour enrichir les recherches du workflow Automatic_Response

## Points d'attention

- Les tickets `closed` ne sont pas recuperes (uniquement `solved`)
- L'anonymisation est effectuee par un LLM : bien que fiable, une verification humaine periodique est recommandee
- Le nom du premier message client est utilise pour identifier les roles dans la conversation
- Si l'import RAG echoue, le fichier est quand meme stocke dans GCS et le ticket est tague
- Le motif de contact est inclus dans l'archive pour permettre des analyses par categorie
