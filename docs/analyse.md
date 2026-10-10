# Analyse préalable et architecture

## 1. Sources examinées

| Élément | Source | Version |
|---|---|---|
| FreeScout | dépôt officiel `freescout-helpdesk/freescout`, branche `dist` | **1.8.245** — Laravel **5.5.40**, PHP **≥ 7.1** (`composer.json`) |
| Module Workflows (payant) | documentation publique freescout.net/module/workflows et son journal des modifications (dernière version **1.0.96**) | — |
| Module Workflows, code | copie publique **partielle** publiée par un tiers (dépôt `karrierekick-dev/freescout-custom-workflow-user`, janvier 2024) : `Entities/Workflow.php`, `Http/Controllers/WorkflowsController.php` | version du module inconnue (≤ 1.0.96) |
| Module Refresh | dépôt officiel `altmenorg/freescout-refresh` | 1.4.3 |
| Mécanisme de mise à jour | module RefreshGlobal (même auteur) | 1.5.0 |

Le code complet du module Workflows **n'était pas disponible**. Aucune partie de ce code n'est reprise dans
Workflow Packs : seuls des noms (classe, constantes, méthodes, colonnes, identifiants du catalogue) et la forme des
données sont utilisés, puis **vérifiés à l'exécution** sur l'instance (`workflowpacks:check`). Pour un contrôle complet,
il faudrait disposer de `Modules/Workflows/Entities/Workflow.php`, `Http/Controllers/WorkflowsController.php`,
`Database/Migrations/*` et `Providers/WorkflowsServiceProvider.php` de la version installée.

## 2. Ce que Workflows expose (copie analysée)

- Modèle Eloquent `Modules\Workflows\Entities\Workflow` (alias `\Workflow`), table `workflows`.
  - Champs remplissables : `name`, `type`, `max_executions`, `apply_to_prev`, `active`, `conditions`, `actions`.
  - Autres colonnes utilisées : `mailbox_id`, `sort_order`, `complete`.
  - `$casts` : `conditions` et `actions` sont des tableaux, stockés en JSON.
- Constantes : `TYPE_AUTOMATIC = 1`, `TYPE_MANUAL = 2`, `PERM_EDIT_WORKFLOWS = 6`, `ASSIGNEE_CURRENT = -10`.
- **Conditions** : liste de groupes ET, chaque groupe étant une liste de conditions OU `{type, operator, value}`.
  `checkConditions()` exige un résultat vrai dans chaque groupe et s'arrête à la première condition vraie d'un groupe.
  `[[a, b], [c]]` signifie donc `(a OU b) ET c`. Aucune autre imbrication n'existe.
- **Actions** : liste de listes de `{type, operator?, value}`, exécutées dans l'ordre. Les valeurs de `reply`,
  `email_customer`, `forward` et `note` sont des **chaînes JSON** (`body`, `to`, `cc`, `bcc`, `subject`), décodées par
  `performActions()`.
- **Catalogue** : `Workflow::conditionsConfig($mailbox_id)` et `Workflow::actionsConfig($mailbox_id)` renvoient les
  types, opérateurs et valeurs proposés. Ils passent par les filtres `workflows.conditions_config` et
  `workflows.actions_config`, que les modules Tags, Custom Fields et autres complètent. Les types inconnus sont
  évalués par le filtre `workflow.check_condition` et exécutés par `workflow.perform_action`.
- **Opérateurs texte** : `equal`, `contains`, `not_contains`, `not_equal`, `starts`, `ends`, `regex`.
  - La comparaison se fait en minuscules, sauf pour `regex`, qui appelle `preg_match($valeur, $texte)` tel quel :
    délimiteurs obligatoires, sensible à la casse sans `i`.
- **Déclenchement** : une règle automatique sans condition de date s'exécute sur les événements listés dans
  `triggers`, par exemple `conversation.created_by_customer`. La condition `new_or_reply` (`new`, `reply`, `moved`)
  ne prend pas de valeur.
- **Paramètres d'exécution** :
  - `apply_to_prev` : si désactivé, les conversations antérieures à la création de la règle sont ignorées ;
  - `max_executions` : 1 par défaut ;
  - `sort_order` : `setSortOrderLast()` place la règle en dernier ;
  - `maybeProcessInBackground()` traite les anciennes conversations quand `active` et `apply_to_prev` sont activés.
    Workflow Packs ne l'appelle jamais.
- **Validation** :
  - `validate()` contrôle les conditions, les actions et l'existence des utilisateurs et des boîtes référencés ;
  - `checkComplete()` marque la règle `complete` et la désactive si elle est invalide ;
  - le formulaire exige un nom de 75 caractères au plus, unique dans la boîte.
- **Permission** : `Workflow::canEditWorkflows($user, $mailbox_id)` est vraie pour un administrateur, ou pour un
  utilisateur qui a accès à la boîte **et** la permission 6.
- **Routes** de l'interface : `mailboxes.workflows` et `mailboxes.workflows.update`, avec les paramètres `mailbox_id`
  et `id`. Elles servent seulement à créer des liens, et seulement si elles existent.

Points d'extension FreeScout utilisés (vérifiés dans le code 1.8.245) :

- `menu.manage.after_mailboxes` (`layouts/app.blade.php`) ;
- `mailboxes.settings.menu` (`mailboxes/settings_menu.blade.php`) ;
- filtre `schedule` (`app/Console/Kernel.php`) ;
- `refresh.rail_items` (module Refresh) ;
- `App\Module::isActive()` ;
- `User::hasAccessToMailbox()` et `User::hasPermission()`.

## 3. Architecture retenue

```
WorkflowPacks/
  Services/Pack/        format du pack, sans Laravel (testé seul) :
    PackValidator       structure, limites, regex, syntaxe des variables
    ConditionTranslator all/any <-> [[…]] de Workflows (refus de ce qui n'est pas représentable)
    VariableResolver    {{mailbox.*}} / {{param.*}}, contextes texte / regex / html, types entiers conservés
    RuleCompiler        règle + boîte -> attributs Workflows, contrôlés contre le catalogue réel
    Fingerprint         empreinte et différences des champs gérés par le pack
  Services/Workflows/Bridge   seul point de contact avec le module Workflows
  Services/Compat/Checker     vérifications (codes WP-…) avant import / export
  Services/Plan/Planner       ce que ferait l'import (rien n'est écrit)
  Services/Plan/Importer      application en une transaction
  Services/Export/Exporter    export (modèles d'origine, remplacements explicites, secrets retirés)
  Services/Update/Updater     mise à jour du module (mécanisme de RefreshGlobal)
```

Tables propres au module :

- `workflowpacks_packs` : modèle d'origine de chaque pack ;
- `workflowpacks_links` : identité `pack_key + workflow_key + mailbox_id`, plus l'identifiant de la règle créée, son
  modèle, ses paramètres non sensibles et l'empreinte de ce qui a été écrit ;
- `workflowpacks_imports` : historique des imports.

Écriture dans Workflows : uniquement par le modèle Eloquent officiel, avec `setSortOrderLast()` et `checkComplete()`,
comme le fait son propre formulaire, mais **sans** `maybeProcessInBackground()`.

**Atomicité** : un import = une transaction de base de données pour toutes les boîtes. La confirmation recalcule le
plan et compare un jeton HMAC de l'état prévisualisé. Pendant l'import, chaque règle modifiée est verrouillée
(`lockForUpdate`) et son empreinte est vérifiée à nouveau.
