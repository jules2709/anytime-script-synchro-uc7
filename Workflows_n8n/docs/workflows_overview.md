# Documentation Workflows n8n - Anytime Zendesk Integration

Cette documentation décrit les trois workflows n8n déployés pour automatiser la gestion des tickets de support Zendesk chez Anytime.

---

## Vue d'ensemble

Les workflows s'articulent autour de trois objectifs principaux :
1. **Automatic_Response** : Génération automatique de réponses aux tickets entrants
2. **Get_Motif_Contact** : Classification automatique du motif de contact
3. **Store_solved_tickets** : Archivage et anonymisation des tickets résolus pour la base de connaissances

Tous les workflows utilisent Google Vertex AI pour le traitement du langage naturel et s'intègrent avec l'API Zendesk.


## 📊 Schéma de flux global

```
┌─────────────────────────────────────────────────────────────┐
│                    ZENDESK (Source)                         │
└────────┬────────────────────────────────┬──────────────────┘
         │                                │
         │ Nouveau ticket                 │ Ticket résolu
         │                                │
    ┌────▼─────────┐              ┌───────▼──────────┐
    │ Motif        │              │ Archivage        │
    │ Contact      │              │ Anonymisé        │
    │ (horaire)    │              │ (hebdo)          │
    └────┬─────────┘              └───────┬──────────┘
         │                                │
         │ + Catégorie                    │
         │                                ▼
    ┌────▼─────────┐              ┌──────────────────┐
    │ Réponse      │              │ Google Cloud     │
    │ Auto         │              │ Storage          │
    │ (horaire)    │              │ (tickets_clos)   │
    └────┬─────────┘              └──────────────────┘
         │                                
         │ + Note interne                 
         │                                
    ┌────▼─────────────────────────────────────┐
    │          Agent humain                    │
    │  (Valide/Modifie avant envoi)            │
    └──────────────────────────────────────────┘
```

---

## 🔑 Points clés d'intégration

### Credentials nécessaires
- **Zendesk API** : Authentification pour lecture/écriture tickets
- **Google Service Account** : Accès Vertex AI et Cloud Storage
- **Google OAuth2** : Pour l'API Google Cloud Storage

### Endpoints utilisés

**Zendesk :**
- `GET /api/v2/tickets.json?query=...` : Recherche de tickets
- `PATCH /api/v2/tickets/{id}.json` : Mise à jour de ticket
- `GET /api/v2/tickets/{id}/comments` : Récupération des commentaires
- `GET /api/v2/ticket_fields.json` : Liste des champs personnalisés

**Google Cloud :**
- Vertex AI RAG Store (région : europe-west9)
- Cloud Storage bucket : `tickets_clos`

### Variables d'environnement
Les workflows n8n utilisent des credentials stockés de manière sécurisée dans n8n. Aucune clé n'est hardcodée.

---

## 🚀 Déploiement et maintenance

### Fréquences d'exécution
- **Automatic_Response** : Toutes les heures
- **Get_Motif_Contact** : Toutes les heures
- **Store_solved_tickets** : Hebdomadaire

### Monitoring recommandé
- Taux de succès de classification (Get_Motif_Contact)
- Score de confiance moyen des catégorisations
- Nombre de réponses automatiques utilisées par les agents
- Volume de tickets archivés chaque semaine


---