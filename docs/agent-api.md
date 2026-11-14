# API des agents IA connectés

Un agent (Claude, un script, un autre outil) peut lire des tickets et proposer des flux au moteur interne, **sans**
accès à FreeScout lui-même : un jeton, des droits limités, et une déconnexion d'un clic.

## Connecter un agent

**Workflow Packs › Assistant IA › Agents IA connectés › Connecter un agent** (administrateurs, moteur interne activé) :

| Réglage | Effet |
|---|---|
| Nom | affiché dans la liste et dans l'historique |
| Usage | **unique** : le jeton ferme après la **première proposition** ou au bout de **24 h** ; **continu** : jusqu'à la déconnexion |
| Boîtes | les seules dont l'agent peut lire les tickets ; ses flux s'appliquent à ces boîtes |
| Ne pas anonymiser | décoché par défaut : adresses, téléphones, IBAN, liens et longs numéros masqués |
| Peut publier | **désactivé par défaut**. Activé, l'agent peut publier et activer **ses propres** flux, jamais ceux des autres |

Le **jeton** (`wpa_…`) est affiché **une seule fois**, à la création. Seule son empreinte SHA-256 est gardée.
**Déconnecter** le rend inutilisable immédiatement. Chaque action de l'agent est notée dans **Historique** (type
« Agent IA connecté »).

## Utiliser l'API

Adresse : `https://votre-freescout/workflow-packs/api/v1`. Le jeton va dans l'en-tête
`Authorization: Bearer wpa_…`. Les réponses sont en JSON, et l'API accepte 60 requêtes par minute et par agent.
Elle répond `404` tant que le moteur interne est désactivé. Utilisez HTTPS.

| Méthode | Chemin | Rôle |
|---|---|---|
| GET | `/me` | l'agent : usage, boîtes, anonymisation, droit de publier, expiration |
| GET | `/catalog` | les règles d'un flux, les blocs (champs, sorties, variables) et un exemple, comme dans le dossier de l'assistant |
| GET | `/tickets?mailbox_id=&days=&limit=&search=` | tickets de ses boîtes : numéro, sujet, extrait, domaine, statut, indices (anonymisés selon le réglage) |
| GET | `/flows` | ses flux : brouillon, version publiée, actif |
| POST | `/flows` | propose un flux (même JSON que la réponse à l'assistant) : enregistré en **brouillon désactivé** |
| PUT | `/flows/{id}` | modifie le brouillon d'un de **ses** flux (la version publiée continue de tourner) |
| POST | `/flows/{id}/test` | joue le brouillon à blanc sur des tickets (`ticket_ids`, ou les 100 derniers de ses boîtes) : effet par ticket |
| POST | `/flows/{id}/publish` | publie (et active avec `activate=1`) — seulement si « Peut publier » est accordé |

Erreurs : `401 invalid_token` (jeton inconnu, révoqué ou expiré), `403 mailbox_denied` / `publish_denied`,
`404 not_found` (pas son flux), `422 invalid_flow` (avec `problems` : bloc, champ, message), `429 too_many_requests`.

```bash
curl -H "Authorization: Bearer wpa_…" https://votre-freescout/workflow-packs/api/v1/tickets?limit=50
curl -X POST -H "Authorization: Bearer wpa_…" -H "Content-Type: application/json" \
     --data @flux.json https://votre-freescout/workflow-packs/api/v1/flows
```

## Sécurité

- Aucune session ni aucun cookie : ces routes sont hors du groupe « web » de FreeScout. Le jeton est vérifié à
  chaque requête.
- L'agent ne voit que ses boîtes, ne modifie que ses flux, et ne publie que si on le lui a permis.
- Les vecteurs de ressemblance sont calculés sur le serveur ; l'agent désigne des tickets par leur numéro.
- Le module ne fait lui-même **aucun appel réseau** : c'est l'agent qui appelle l'API.

Prévu ensuite : la même API exposée comme serveur **MCP**, pour que Claude s'y connecte directement.
