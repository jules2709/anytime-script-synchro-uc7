# Vue d'ensemble - Workflows n8n Anytime

## Objectif

Trois workflows n8n automatisent le traitement des tickets de support Zendesk chez Anytime via Google Vertex AI (Gemini).

## Les 3 workflows

| Workflow | Role | Declencheur |
|----------|------|-------------|
| **Get_Motif_Contact** | Classification automatique du motif de contact | Schedule : toutes les 10 min (9h-18h, lun-ven) |
| **Automatic_Response** | Generation de proposition de reponse | Sous-workflow : appele par Get_Motif_Contact |
| **Store_solved_tickets** | Archivage anonymise des tickets resolus | Schedule : tous les 2 jours a minuit |

## Flux global

```
Zendesk (nouveaux tickets)
    |
    v
[Get_Motif_Contact]  (toutes les 10 min, horaires bureau)
    |
    ├─ Classifie le ticket (champ "Motif de contact")
    |
    └─ Appelle [Automatic_Response]  (sous-workflow)
         |
         ├─ Recherche dans Documentation + Tickets resolus (RAG)
         ├─ Agent Analyste --> Agent Redacteur
         └─ Ajoute une note interne au ticket
              |
              v
         Agent humain valide/modifie avant envoi
              |
              v
         Ticket resolu
              |
              v
[Store_solved_tickets]  (tous les 2 jours)
    |
    ├─ Anonymisation RGPD (Agent DPO)
    ├─ Stockage dans Google Cloud Storage (bucket tickets_clos)
    ├─ Import dans le corpus RAG Vertex
    └─ Tag "saved_ticket" pour eviter les doublons
```

## Stack technique

| Composant | Usage |
|-----------|-------|
| **n8n** | Orchestration des workflows |
| **Google Vertex AI (Gemini)** | Agents LLM (classification, analyse, redaction, anonymisation) |
| **Google Vertex RAG Store** | Recherche vectorielle dans documentation et tickets resolus |
| **Google Cloud Storage** | Stockage des tickets anonymises (bucket `tickets_clos`) |
| **Zendesk API** | Lecture/ecriture des tickets et champs personnalises |

## Credentials necessaires dans n8n

| Credential | Type | Usage |
|------------|------|-------|
| Zendesk API | API Token | Lecture/ecriture tickets, champs, commentaires |
| Google Service Account | Service Account | Vertex AI (Gemini) pour les agents LLM |
| Google OAuth2 | OAuth2 | Cloud Storage + RAG Store |

Toutes les credentials sont stockees de maniere securisee dans n8n. Aucune cle n'est hardcodee dans les workflows.

## Ressources Google Cloud

| Ressource | Valeur |
|-----------|--------|
| Projet | `gen-lang-client-0462375962` |
| Region | `europe-west9` |
| Corpus RAG Documentation | `2305843009213693952` |
| Corpus RAG Solved Tickets | `5685794529555251200` |
| Bucket GCS | `tickets_clos` |

## Champs personnalises Zendesk

| Champ | ID | Usage |
|-------|----|-------|
| Motif de contact interne | `42153295723665` | Categorie du ticket (rempli par Get_Motif_Contact) |
| Sujet (BAQ) | `43473213212177` | Sujet des messages natifs (native_messaging) |
| Description (BAQ) | `42767615870481` | Description des messages natifs (native_messaging) |

## Endpoints Zendesk utilises

| Methode | Endpoint | Workflow |
|---------|----------|----------|
| `GET` | `/api/v2/ticket_fields.json` | Get_Motif_Contact, Store_solved_tickets |
| `GET` | `/api/v2/tickets.json?query=...` | Get_Motif_Contact, Store_solved_tickets |
| `GET` | `/api/v2/tickets/{id}.json` | Automatic_Response |
| `GET` | `/api/v2/tickets/{id}/comments` | Store_solved_tickets |
| `PATCH` | `/api/v2/tickets/{id}.json` | Get_Motif_Contact, Store_solved_tickets |
| `PUT` | `/api/v2/tickets/{id}.json` | Automatic_Response (note interne) |

## Prompts LLM

Les prompts des agents sont documentes dans le dossier `prompts/` :

| Fichier | Agent | Workflow |
|---------|-------|----------|
| `Auto_reponse_Analyste.md` | Analyste technique | Automatic_Response |
| `Auto_reponse_Redacteur.md` | Redacteur senior | Automatic_Response |
| `GetMotifContact_TicketAnalyst.md` | Classificateur de tickets | Get_Motif_Contact |
| `SolvedTicket_RGPD_Agent.md` | DPO / Anonymiseur RGPD | Store_solved_tickets |

## Monitoring recommande

| Indicateur | Source |
|------------|--------|
| Taux de succes de classification | Logs Get_Motif_Contact |
| Score de confiance moyen | Champ `confidence_score` des reponses LLM |
| Nombre de reponses auto utilisees par les agents | Zendesk (notes internes) |
| Volume de tickets archives | Bucket GCS `tickets_clos` |
| Erreurs d'execution | Dashboard n8n |
