# Guide pour l'IA — écrire un pack Workflow Packs importable

> À donner tel quel à une IA (Claude, ChatGPT…) avec votre demande. Format actuel : `freescout-workflow-pack`,
> schéma **version 1** (version 2 pour les sélecteurs de boîtes et les ressources). Référence complète :
> [format.md](format.md), schéma JSON : [`schema/freescout-workflow-pack.schema.json`](../schema/freescout-workflow-pack.schema.json).

## 1. Ce que l'IA doit produire

Un **seul objet JSON**, sans commentaire ni texte autour, qui respecte ces règles :

1. `format` = `"freescout-workflow-pack"`, `version` = `1` (ou `2` si `mailboxes` / `resources` sont utilisés).
2. `key` du pack et de chaque règle : minuscules, chiffres, `.`, `_`, `-` ; commence par une lettre ou un chiffre ;
   unique. Exemple : `"tri-factures"`.
3. Chaque règle a `key`, `name` (75 caractères au plus), `conditions` (règle automatique) et 1 à 50 `actions`.
4. **Aucune clé inventée** : les clés inconnues sont refusées (seules `$schema` et `x-…` au premier niveau sont
   tolérées).
5. **Pas de données personnelles en clair** : utiliser des paramètres (`{{param.support_email}}`) ou les variables
   de boîte (`{{mailbox.email}}`), jamais une vraie adresse, un nom ou un identifiant d'utilisateur.
6. `settings.apply_to_prev` : laisser `false` (il est de toute façon forcé à `false` à l'import, sauf choix explicite).

## 2. Squelette

```json
{
  "format": "freescout-workflow-pack",
  "version": 1,
  "key": "mon-pack",
  "name": "Mon pack",
  "description": "Ce que fait le pack, en une phrase.",
  "pack_version": "1.0.0",
  "params": [
    { "key": "billing_user", "label": "Responsable facturation", "type": "user", "required": true }
  ],
  "workflows": [
    {
      "key": "regle-1",
      "name": "Factures : classement",
      "type": "automatic",
      "conditions": { "all": [ { "type": "subject", "operator": "contains", "value": "facture" } ] },
      "actions": [ { "type": "assign", "value": "{{param.billing_user}}" } ]
    }
  ]
}
```

## 3. Conditions

- `{"all": [ … ]}` : toutes vraies (ET). Un élément peut être `{"any": [ … ]}` (OU).
- `{"any": [ … ]}` seul : au moins une vraie.
- **Interdit** : `all` ou `any` dans un `any`, `all` dans un `all`, groupe vide, condition sans `operator`.
- Une condition : `{"type": "…", "operator": "…", "value": …}`.

| Type | Exemple | Remarque |
|---|---|---|
| `subject`, `body`, `to`, `cc`, `headers` | `{"type":"subject","operator":"contains","value":"facture"}` | texte ; `regex` possible (2 000 caractères max) |
| `customer_email`, `customer_name` | `{"type":"customer_email","operator":"contains","value":"@fournisseur.test"}` | |
| `new_or_reply` | `{"type":"new_or_reply","operator":"new"}` | `new`, `reply`, `moved`, **sans** `value` |
| `status` | `{"type":"status","operator":"equal","value":"2"}` | 1 actif, 2 en attente, 3 fermé, 4 spam |
| `created`, `waiting`, `user_reply`, `customer_reply` | `{"type":"created","operator":"not_in_last","value":{"number":2,"metric":"d"}}` | **délai** : opérateur `not_in_last` (« plus ancien que ») ou `longer` (pour `waiting`) ; `metric` = `i` (minutes), `h`, `d` ; `number` de 1 à 100 000 |
| `wp_assigned`, `wp_replied` | `{"type":"wp_assigned","operator":"no"}` | conditions du module : `yes` / `no`, sans valeur |
| `wp_mail_tag` | `{"type":"wp_mail_tag","operator":"has","value":"spam"}` | seulement avec RefreshGlobal ≥ 1.11 ; `has` / `has_not` |

Les opérateurs exacts dépendent du module Workflows installé : la liste fait foi dans **Diagnostic › Catalogue
Workflows**. Exemple connu : le corps (`body`) n'a pas de « ne contient pas ». On utilise alors une regex négative
`/^(?![\s\S]*mot)/i`.

## 4. Actions

| Type | Valeur | Exemple |
|---|---|---|
| `status` | `"1"` à `"4"` | `{"type":"status","value":"3"}` (fermer) |
| `assign` | utilisateur ou `-1` (non assigné) | `{"type":"assign","value":"{{param.billing_user}}"}` |
| `note` | objet `{body, to?, cc?, bcc?, subject?}` | `{"type":"note","value":{"body":"Facture reçue"}}` |
| `notification` | liste d'utilisateurs, `assignee` ou `last_user` | `{"type":"notification","value":["assignee"]}` |
| `move` | boîte | `{"type":"move","value":"{{param.target_mailbox}}"}` (paramètre de type `mailbox`) |
| `wp_imap_folder` | chemin du dossier, niveaux séparés par `/` | `{"type":"wp_imap_folder","value":"Factures/Fournisseurs"}` |
| `reply`, `email_customer`, `forward`, `no_autoreply`, `delete`, `delete_forever` | voir le catalogue | à utiliser avec prudence |

## 5. Minuteurs (« après X »)

Un minuteur est une **condition de délai** ajoutée aux conditions de la règle :

```json
{ "key": "fermer-apres-2-jours", "name": "Fermer après 2 jours sans réponse",
  "conditions": { "all": [
    { "type": "subject", "operator": "contains", "value": "facture" },
    { "type": "wp_replied", "operator": "no" },
    { "type": "created", "operator": "not_in_last", "value": { "number": 2, "metric": "d" } } ] },
  "actions": [ { "type": "status", "value": "3" } ] }
```

Pour une suite « classer, puis fermer après 2 jours » : **deux règles avec les mêmes conditions**, la seconde avec le
délai en plus. Le schéma les affiche enchaînées.

## 6. Variables

`{{mailbox.id}}`, `{{mailbox.email}}`, `{{mailbox.name}}`, `{{param.<key>}}` (et `{{resource.<key>}}` en version 2).
**Aucune expression** (`{{ 1+1 }}`, filtres, PHP) n'est acceptée.

Types de paramètres : `string`, `email`, `integer`, `user`, `mailbox`. `default` est interdit pour `user` et
`mailbox`. `sensitive: true` : la valeur n'est jamais conservée ni exportée.

## 7. Vérifier et corriger

- **En ligne de commande** : `php artisan workflowpacks:check --pack=mon-pack.json`. Code de sortie 0 = valide,
  1 = fichier invalide.
- **À l'import** : l'aperçu liste chaque problème.
- Chaque problème a un **chemin** (par exemple `workflows[2].conditions.all[1].operator`), un **code**
  (`WP-RULE-…`, `WP-PARAM-…`) et un message. Donnez-les tels quels à l'IA : elle corrige le JSON en une passe.

Erreurs fréquentes :

| Erreur | Correction |
|---|---|
| `all` dans un `any` | mettre le OU au premier niveau, ou dupliquer la règle |
| condition sans `operator` | ajouter l'opérateur (même pour `new_or_reply`) |
| `value` pour `wp_assigned` / `new_or_reply` | supprimer `value` |
| délai `"2d"` | écrire `{"number": 2, "metric": "d"}` |
| nom de règle trop long ou en double dans la boîte | raccourcir, rendre unique |
| adresse ou identifiant en clair | créer un paramètre et utiliser `{{param.…}}` |

## 8. Modèle de demande à copier

> Écris un pack Workflow Packs (format `freescout-workflow-pack`, version 1) en respectant **strictement** le guide
> ci-joint. Objectif : *[décrire le besoin, par exemple : « les e-mails dont le sujet contient "facture" sont
> assignés au responsable facturation ; sans réponse après 2 jours, ils sont fermés »]*. Utilise des paramètres pour
> toute personne, adresse ou boîte. Réponds uniquement par le JSON. Si je te renvoie des erreurs (chemin + code),
> corrige seulement ce qui est signalé.

Exemples complets : [`tri-emails-multilingue.json`](../WorkflowPacks/Resources/packs/tri-emails-multilingue.json)
(version 1) et [`demo-portable-v2.json`](../WorkflowPacks/Resources/packs/demo-portable-v2.json) (version 2).
