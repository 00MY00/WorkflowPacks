# Ressources logiques (pack v2)

Une ressource est un élément **local** (utilisateur, boîte, étiquette) qu'un pack désigne par une **clé stable**, sans
jamais dépendre de l'identifiant d'une installation.

```json
"resources": [
  { "key": "billing-user", "type": "user", "name": "Comptabilité", "on_missing": "ask", "use": "id" }
]
…
{ "type": "assign", "value": "{{resource.billing-user}}" }
```

| Champ | Rôle |
|---|---|
| `key` | **identité** de la ressource dans le pack (`^[a-z0-9][a-z0-9_-]*$`) |
| `type` | `user`, `mailbox`, `tag` (le type `tag` nécessite le module Tags) |
| `name` | nom affiché, seulement une **suggestion** : il ne sert jamais à associer |
| `on_missing` | `block` (règle bloquée si non associée), `ask` (par défaut), `create` (voir plus bas) |
| `use` | valeur insérée : `id` (identifiant local, en entier) ou `name` |

## Association à l'import

```
PACK                          INSTALLATION A               INSTALLATION B
billing-user (user)  ──►      utilisateur #14              utilisateur #82
                              (choisi à l'import,          (choisi à l'import,
                               mémorisé)                    mémorisé)
```

- À l'étape 2 de l'import, chaque ressource propose les éléments que vous pouvez utiliser :
  - les **boîtes** dont vous gérez les workflows ;
  - les **utilisateurs** (tous pour un administrateur, sinon ceux des boîtes que vous gérez) ;
  - les **étiquettes**, si le module Tags est actif.
- Un élément qui porte le même nom que la ressource est signalé « même nom : à confirmer ». Il n'est **jamais
  sélectionné automatiquement**.
- Le choix est enregistré dans `workflowpacks_resources` (`pack_key`, `resource_key`, `resource_type`, `local_id`,
  `created_by_pack`, `last_seen_name`, `fingerprint`), dans la même transaction que les règles. Le prochain import le
  reprend.
- Une valeur falsifiée dans le formulaire (élément inexistant, ou non autorisé pour vous) est refusée (`WP-RES-09`).
  Rien n'est alors écrit.
- Une règle qui utilise une ressource non associée est **bloquée**, jamais importée sans son action.
- La prévisualisation affiche, pour chaque ressource, l'action prévue (« Utiliser l'élément existant #14 … ») et son
  niveau de risque.

## Création automatique (« create »)

Elle n'est faite que si le module concerné fournit un moyen fiable et vérifié de créer l'élément. Ce n'est le cas
d'**aucun** type pour l'instant :

- **boîtes** et **utilisateurs** : jamais, car il faut des connexions, des mots de passe et des droits ;
- **étiquettes** : l'API de création du module Tags n'est pas vérifiée.

`on_missing: "create"` se comporte donc comme `ask`, avec un message explicite. Workflow Packs n'écrit jamais
directement dans une table d'un autre module.

## Protection

- Une ressource n'appartient au pack que par son association (`created_by_pack` vaut toujours `false` aujourd'hui).
- Aucune ressource locale n'est jamais **modifiée, renommée, remplacée ni supprimée** par un pack.
- Deux éléments de même nom restent distincts : l'association se fait par identifiant.
- Les **dossiers** ne sont pas proposés : le catalogue Workflows analysé n'a pas d'action « déplacer vers un dossier ».

## Export

- Pour une règle issue d'un pack, les ressources et le sélecteur sont exportés tels quels.
- Pour une autre règle, vous pouvez demander de remplacer un identifiant local par une ressource : par exemple
  l'utilisateur `14` par `{{resource.billing-user}}` (« nouvelle ressource… »). Le pack exporté passe alors en v2.
