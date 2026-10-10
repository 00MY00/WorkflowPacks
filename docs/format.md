# Format `freescout-workflow-pack` — schéma version 1

Schéma JSON : [`schema/freescout-workflow-pack.schema.json`](../schema/freescout-workflow-pack.schema.json).
Les clés inconnues sont refusées, pour qu'une faute de frappe ne soit pas ignorée sans bruit. Seules exceptions :
`$schema` et les clés `x-…` au premier niveau.

## Pack

| Clé | Obligatoire | Description |
|---|---|---|
| `format` | oui | `"freescout-workflow-pack"` |
| `version` | oui | version du **schéma**, entier ; `1` |
| `key` | oui | identifiant stable du pack : `^[a-z0-9][a-z0-9._-]{0,99}$` |
| `name`, `description` | `name` oui | textes |
| `pack_version` | non | version du contenu, par exemple `1.2.0`, affichée et conservée |
| `requires` | non | alias de modules requis par toutes les règles, par exemple `["tags"]` |
| `params` | non | paramètres renseignés à l'import |
| `workflows` | oui | 1 à 200 règles |

## Paramètre

| Clé | Description |
|---|---|
| `key` | nom utilisé dans `{{param.key}}` |
| `label`, `description` | textes affichés |
| `type` | `string`, `email`, `integer`, `user` (identifiant d'utilisateur), `mailbox` (identifiant de boîte) |
| `required` | `true` : import bloqué si vide |
| `default` | valeur par défaut. Interdite pour `user` et `mailbox`, qui sont propres à chaque instance |
| `sensitive` | `true` : valeur jamais conservée par Workflow Packs ni exportée |

Un paramètre facultatif laissé vide bloque **les seules règles qui l'utilisent**, avec une explication. On peut ensuite
les ignorer explicitement.

## Règle

| Clé | Description |
|---|---|
| `key` | identifiant stable dans le pack |
| `name` | 75 caractères au plus après remplacement des variables, unique dans la boîte |
| `description` | texte |
| `type` | `automatic` (défaut) ou `manual` |
| `settings.apply_to_prev` | conversations précédentes. Forcé à `false` à l'import, sauf option explicite |
| `settings.max_executions` | 1 à 1000, 1 par défaut |
| `requires` | modules requis par cette règle |
| `conditions` | obligatoire pour une règle automatique |
| `actions` | 1 à 50 actions `{type, operator?, value?}` |

## Conditions

```json
{ "all": [ C1, { "any": [C2, C3] }, C4 ] }     → (C1) ET (C2 OU C3) ET (C4)
{ "any": [ C1, C2 ] }                          → (C1 OU C2)
```

Une condition s'écrit `{"type": "...", "operator": "...", "value": ...}`. Sont refusés, avec un message :

- un `all` ou un `any` à l'intérieur d'un `any` : Workflows ne sait pas évaluer un ET dans un OU ;
- un `all` dans un `all` : il faut le mettre à plat ;
- un groupe vide ;
- une condition sans `operator`.

Limites : 20 groupes ET par règle, 50 conditions par groupe, profondeur JSON 32, fichier de 512 Ko, texte de 20 000
octets, regex de 2 000 caractères.

## Types, opérateurs et valeurs

Ils sont vérifiés **contre le catalogue de Workflows de l'instance**, par boîte. Ce catalogue est visible dans
Diagnostic › Catalogue Workflows. Identifiants trouvés pendant l'analyse :

- **Conditions** : `customer_name`, `customer_email`, `user_action`, `type`, `status`, `state`, `user`, `to`, `cc`,
  `subject`, `body` (`customer`, `note`, `regex`), `headers`, `attachment`, `bounce`, `customer_viewed`,
  `new_or_reply` (`new`, `reply`, `moved`, sans valeur), `channel`, `waiting`, `user_reply`, `customer_reply`,
  `created` (valeur `{number, metric}`).
- **Actions** : `notification` (liste d'utilisateurs, `assignee` ou `last_user`), `reply`, `email_customer`,
  `forward`, `note` (objet `{body, to, cc, bcc, subject}`), `no_autoreply`, `status` (1 actif, 2 en attente,
  3 fermé, 4 spam), `assign` (utilisateur, ou -1 pour non assigné), `move` (boîte), `delete`, `delete_forever`.

Contrôles appliqués :

- **Type inconnu** : la règle est bloquée et les types disponibles sont listés.
- **Valeurs** : pour `type`, `status`, `state` et `channel`, la valeur doit figurer dans la liste proposée par Workflows.
- **Utilisateurs et boîtes référencés** : leur existence est vérifiée, ainsi que l'accès de l'utilisateur à la boîte.
- **Validation officielle** : `Workflow::validate()` doit accepter la règle.

## Variables

| Variable | Valeur |
|---|---|
| `{{mailbox.id}}` | identifiant de la boîte cible ; seul dans une valeur, il reste un **entier** |
| `{{mailbox.email}}` | adresse de la boîte cible |
| `{{mailbox.name}}` | nom de la boîte cible |
| `{{param.<key>}}` | valeur saisie à l'import. Les types `integer`, `user` et `mailbox` restent des entiers quand ils sont seuls |

Le contexte détermine l'échappement :

- **regex** (opérateur `regex`) : `preg_quote()` avec le délimiteur du motif, pour que `a.b@x.test` ne corresponde
  qu'à lui-même. Le délimiteur ne peut pas être une variable.
- **HTML** (champ `body` des notes, réponses et transferts) : `htmlspecialchars()`.
- **texte** (tout le reste) : la valeur telle quelle.

Aucune expression n'est évaluée. `{{ 1+1 }}`, `{{mailbox.email|upper}}` ou du PHP sont refusés. Le modèle d'origine,
avec ses variables, est conservé : un nouvel import ou un export repart de ce modèle.
