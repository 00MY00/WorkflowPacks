# Visual Builder — analyse, architecture et risques (phases 1 à 3)

Statut : **proposition à valider avant toute implémentation**. Base analysée : Workflow Packs 1.1.0.

Légende utilisée tout au long du document :

- **[Natif Workflows]** : fourni par le module officiel Workflows.
- **[Workflow Packs]** : fourni par ce module.
- **[Module requis]** : nécessite un autre module FreeScout (Tags, Custom Fields…).

---

## Phase 1 — Cartographie de l'existant

### 1.1 Pipeline actuel

```
JSON (fichier, collage, pack fourni)
  │  PackValidator           structure, limites, regex, syntaxe des variables       Services/Pack
  │  ConditionTranslator     all/any <-> [[…]] (refuse ce que Workflows ne sait pas évaluer)
  ▼
Planner (par règle × boîte)                                                          Services/Plan
  │  VariableResolver        {{mailbox.*}} {{param.*}}, contextes texte/regex/html
  │  RuleCompiler            contrôle contre le catalogue réel (Bridge::catalog)
  │  Bridge::officialErrors  Workflow::validate()
  │  Fingerprint             empreinte des workflows -> create/update/unchanged/conflict/missing
  │  jeton HMAC              l'état prévisualisé = l'état confirmé
  ▼
Vue preview (matrice règles × boîtes, différences, actions sensibles)
  ▼
Importer                     une transaction pour tout l'import, permissions revérifiées,
                             workflowpacks_links (pack.key + workflow.key + boîte)
```

### 1.2 Éléments réutilisables

| Besoin du Visual Builder | Existant réutilisé |
|---|---|
| Format, variables, limites | `PackValidator`, `VariableResolver`, `ConditionTranslator`, schéma v1 |
| Types, opérateurs et valeurs disponibles | `Bridge::catalog()`, alimenté par les filtres `workflows.conditions_config` et `workflows.actions_config` [Natif Workflows] |
| Contrôle de compilation | `RuleCompiler` (types, opérateurs, valeurs fermées, références locales, regex) |
| Prévisualisation et application | `Planner`, vue `import/preview`, `Importer` (transaction, jeton, permissions) |
| Détection des modifications locales | `Fingerprint`, à renommer `WorkflowFingerprint` en gardant un alias |
| Actions sensibles | `config.workflows.sensitive_actions` + badges du schéma |
| Messages traduits | `Problems::t()` + `lang/*/texts.php` (7 langues) |
| Vérifications | `Checker` (codes WP-…), `workflowpacks:check` |
| Style | `layout.blade.php` (variables Refresh), cartes `rule_schema` |

### 1.3 Points d'extension Workflows confirmés dans le code analysé (copie publique, janvier 2024)

| Filtre | Rôle | Utilisation prévue |
|---|---|---|
| `workflows.conditions_config` | ajouter un type de condition au catalogue | condition « Le message ressemble à… » |
| `workflow.check_condition` | évaluer un type inconnu de Workflows | calcul de la similarité, exécuté par Workflows |
| `workflow.validate_condition` | valider ce type | contrôle de l'empreinte référencée |
| `workflows.actions_config`, `workflow.perform_action`, `workflow.validate_action` | actions supplémentaires | non nécessaire pour l'instant |

C'est le mécanisme que Tags utilise pour ajouter ses conditions et actions.

---

## Phase 2 — Architecture proposée

### 2.1 Format : version 2 du schéma (recommandé)

| Option | Avantages | Inconvénients |
|---|---|---|
| **A.** v1 + `x-…` | aucun changement de version | sélecteurs, ressources et empreintes changent le sens de l'import : une version 1.0 les ignorerait en silence (les `x-` sont acceptés au premier niveau) |
| **B. v2** (recommandé) | une ancienne version refuse le fichier avec un message clair ; documenté dans le schéma | il faut maintenir la conversion v1 → v2 (simple) |

Ce que la v2 ajoute à la v1, sans rien modifier de ce qui existe :

```json
{
  "format": "freescout-workflow-pack", "version": 2, "key": "securite", "name": "Sécurité",
  "mailboxes": { "match": "all" },
  "resources": [
    { "key": "security-tag", "type": "tag", "name": "Sécurité", "on_missing": "ask" }
  ],
  "fingerprints": [
    { "key": "google-security", "fields": ["subject", "from", "text"],
      "fragments": [ { "text": "Security alert", "zone": "important" },
                     { "text": "10 October 2026", "zone": "variable" } ],
      "normalize": ["dates", "times", "uuids", "message_ids", "tokens", "numbers", "tracking", "whitespace"],
      "threshold": 80 }
  ],
  "workflows": [ {
    "key": "alerte-google", "name": "[Sécurité] Google",
    "mailboxes": { "match": "any", "rules": [ { "field": "email", "operator": "contains", "value": "@example.com" } ] },
    "conditions": { "all": [
      { "type": "subject", "operator": "contains", "value": "Security alert" },
      { "type": "workflowpacks_fingerprint", "operator": "similar", "value": "google-security" }
    ] },
    "actions": [ { "type": "<action Tags du catalogue>", "value": "{{resource.security-tag}}" } ],
    "x-visual": { "nodes": { "c1": { "x": 320, "y": 80 } } }
  } ]
}
```

Règles du format :

- **Packs v1** : toujours acceptés. Ils sont convertis en interne vers la v2, sans sélecteur, ressource ni empreinte.
- **Export** : en v1 si aucune fonction v2 n'est utilisée, en v2 sinon.
- **`x-visual`** : uniquement des positions et des préférences d'affichage. Il n'est jamais lu par la compilation et
  n'apparaît jamais dans les conditions envoyées à Workflows.

### 2.2 Sélecteurs de boîtes [Workflow Packs]

- Formes acceptées :
  - `{"match": "all"}` : toutes les boîtes ;
  - `{"match": "any"|"all", "rules": [{field: email|name, operator: contains|not_contains|equal|starts|ends, value}]}`.
- Les sélecteurs sont **évalués localement** à l'étape 2 de l'import, uniquement sur `Bridge::editableMailboxes($user)`.
  Une boîte sans la permission Workflows n'est jamais proposée, même si elle correspond.
- L'écran affiche le sélecteur en clair, puis le résultat local ✓/✗ avec des cases à décocher. Le choix est revérifié
  côté serveur.
- Nouveau service : `MailboxSelector`, en PHP pur et testable. Le `Planner` n'en reçoit que le résultat validé ; il
  ne change pas.

### 2.3 Ressources logiques [Workflow Packs]

- Nouvelle table `workflowpacks_resources` : `pack_key`, `resource_key`, `resource_type`, `local_id`,
  `created_by_pack`, `last_seen_name`, `fingerprint`, horodatages. Index unique sur
  `pack_key + resource_key + resource_type`.
- Variable `{{resource.<key>}}`, résolue par `ResourceResolver` vers l'identifiant local enregistré. Elle n'est
  **jamais** résolue par le nom.
- Une ressource portant le même nom qu'une ressource du pack n'est jamais adoptée automatiquement. Elle est proposée
  et l'utilisateur doit confirmer.
- Aucune suppression ni aucun renommage d'une ressource locale, en aucun cas.

| Type | Résolution | Création automatique |
|---|---|---|
| mailbox | choix dans la liste des boîtes autorisées | **jamais** (connexion IMAP/SMTP, droits) |
| user | choix parmi les utilisateurs ayant accès à la boîte | **jamais** |
| tag [Module requis : Tags] | choix ou création | seulement si `workflowpacks:check` trouve un modèle Tags avec une API de création vérifiée ; sinon « demander » |
| dossier / catégorie | — | **non pris en charge** : aucune action « déplacer vers un dossier » dans le catalogue Workflows analysé |
| champ personnalisé [Module requis : Custom Fields] | choix | jamais |

« Classer dans la destination logique security » se traduit donc par l'action Tags du catalogue (ajouter une
étiquette) ou par une note. S'il n'y a ni Tags ni action équivalente, la règle est bloquée avec « Cette action
nécessite le module Tags ».

### 2.4 Empreinte de message [Workflow Packs, exécutée par Workflows]

Deux services séparés, pour ne pas mélanger les deux notions d'empreinte :

- `WorkflowFingerprint` (l'actuel `Fingerprint`) : détecte les modifications d'une **règle**. Il ne change pas.
- `MessageFingerprint`, avec `EmailNormalizer` et `MessageSimilarity` : calcule la ressemblance d'un **e-mail**.

Fonctionnement :

1. Normalisation déterministe, sans apprentissage automatique :
   - HTML converti en texte ;
   - espaces compactés ;
   - dates, heures, UUID, Message-ID, jetons longs, nombres de 4 chiffres ou plus, URL signées et paramètres de suivi
     (`utm_*`, `token`, `sig`…) remplacés par des marqueurs ;
   - adresses choisies masquées.
2. Découpage en fragments. Chaque fragment est **important**, **variable** (ignoré, mais sa présence est attendue) ou
   **ignoré**.
3. Calcul du score : moyenne pondérée, par champ, des fragments importants retrouvés, avec une comparaison exacte du
   texte normalisé. Le seuil va de **50 % au minimum** (limite de sûreté) à 100 %. Les niveaux affichés : 100 %
   exact, 90 % très strict, 75 % normal, 60 % souple.
4. Explication du résultat :
   ```
   Correspondance 87 %
   Sujet 100 %, Expéditeur 100 %, Contenu important 82 %
   3 fragments identiques, 1 différent, 2 valeurs variables ignorées
   ```
   Ce détail est affiché dans le testeur et dans la prévisualisation.

À l'exécution, WorkflowPacks enregistre le type `workflowpacks_fingerprint` dans le catalogue
(`workflows.conditions_config`), avec ses `triggers`, par exemple `conversation.created_by_customer`. C'est
**Workflows** qui appelle `workflow.check_condition` ; Workflow Packs répond seulement « vrai » ou « faux ». Les règles
qui l'utilisent ont donc `"requires": ["workflowpacks"]`.

Si Workflow Packs est désinstallé, Workflows n'obtient pas de réponse pour ce type. Dans le code analysé, le filtre
renvoie alors `false` : la règle ne se déclenche plus, ce qui est sans danger. Ce comportement est documenté.

### 2.5 Visual Builder [Workflow Packs]

Recommandation : un **canevas à structure contrainte**, plutôt qu'un graphe libre. Les colonnes sont fixes :

```
[SOURCE: boîtes]  →  [CONDITIONS : voies ET ; chaque voie = 1 condition ou 1 groupe OU]  →  [ACTIONS]
                         ┌─ Sujet contient « Security alert » ─────────────┐
   Toutes les boîtes ──► ├─ OU ┬ Sujet contient « invoice »                  ├──► Ajouter l'étiquette security-tag
   (@example.com)        │     └ Sujet contient « facture »                 │    Ajouter une note
                         └─ Le message ressemble à google-security (80 %) ─┘
```

- Cette structure correspond exactement à `ET de groupes OU`. Une imbrication impossible **ne peut pas être
  dessinée** : la connexion est refusée, avec le message « Cette structure ne peut pas être représentée par FreeScout
  Workflows ».
- Les cartes affichent une phrase lisible en mode Simple (« Objet contient Security alert ») et `subject / contains`
  en mode Expert, avec le JSON compilé en direct.
- Les types et opérateurs proposés viennent **uniquement** du catalogue de l'instance (`/workflow-packs/builder/catalog`).
  Rien n'est codé en dur. « existe » et « n'existe pas » ne sont proposés que si le catalogue les offre, ce qui n'est
  pas le cas de l'objet ou du corps dans la version analysée.
- Fonctions du canevas : glisser-déposer, connexions, déplacement, zoom, panoramique, suppression, duplication,
  annuler/rétablir, sélection multiple simple.
- Technique : SVG et JavaScript sans bibliothèque (jQuery est déjà chargé par FreeScout), environ 1 500 lignes, dans
  `Public/js/builder/`. Aucune dépendance npm, licence AGPL conservée.
- Côté serveur : `VisualGraphCompiler` convertit le graphe en règle v2, puis `PackValidator` et `RuleCompiler` le
  contrôlent. Le graphe envoyé par le navigateur est **toujours recompilé et revalidé**. Ensuite, le pipeline est
  inchangé : « Prévisualiser » mène au Planner existant, puis à l'Importer.
- « Tester la règle » : coller un e-mail ou envoyer un `.eml` (taille limitée, analysé avec `mailparse` ou
  l'analyseur MIME de FreeScout). L'affichage indique MATCH ou NO MATCH avec le score, et le résultat de chaque
  condition.
  - Les conditions texte sont **simulées** selon la sémantique documentée de Workflows. L'écran l'indique : seul
    Workflows fait foi.
  - L'empreinte est calculée par le code même qui s'exécutera.

### 2.6 Fichiers

| Nouveaux | Modifiés |
|---|---|
| `Services/Pack/MailboxSelector.php`, `Services/Resources/ResourceResolver.php`, `ResourceRegistry.php`, `Entities/Resource.php`, migration `workflowpacks_resources` | `PackValidator` (v2 + conversion v1), `VariableResolver` (`{{resource.*}}`), `RuleCompiler` (ressources), `Planner` (sélecteurs, ressources dans le plan), `Importer` (création des ressources dans la même transaction) |
| `Services/Message/EmailNormalizer.php`, `MessageFingerprint.php`, `MessageSimilarity.php`, `FingerprintCondition.php` (filtres Workflows) | `Fingerprint` renommé `WorkflowFingerprint` (alias conservé), `Exporter` (v2, `x-visual`, intention portable conservée) |
| `Services/Visual/VisualGraphCompiler.php`, `VisualGraphValidator.php`, `Http/Controllers/BuilderController.php`, `views/builder/*.blade.php`, `Public/js/builder/*.js`, `Public/css/builder.css` | `routes.php`, `ServiceProvider` (filtres de la condition), vue `import/configure` (sélecteurs, ressources), vue `import/preview` (lignes de ressources et colonne de risque), schéma JSON, documentation, `lang/*` |

---

## Phase 3 — Risques de compatibilité avec FreeScout Workflows

| Risque | Gravité | Mesure |
|---|---|---|
| Les filtres `workflow.check_condition` et `workflows.conditions_config` n'ont été vus que dans une copie de 2024 | élevée | contrôle `WP-WF-11` : le type apparaît-il dans `conditionsConfig()` ? Sinon, la condition « ressemble à » est désactivée et les règles qui l'utilisent sont bloquées. Test à faire sur ton instance. |
| Sans `triggers`, une règle automatique avec seulement notre condition ne se déclenche jamais | moyenne | déclarer `conversation.created_by_customer` (et `conversation.moved`), et ajouter `new_or_reply` par défaut dans le builder |
| Coût d'exécution (normalisation du corps à chaque événement) | moyenne | taille analysée bornée (64 Ko), cache par conversation et par empreinte pendant la requête |
| API de création des Tags inconnue | moyenne | `on_missing: create` refusé pour `tag` tant que le contrôle ne valide pas l'API ; « demander » sinon |
| Actions « déplacer vers un dossier » absentes du catalogue | — | non proposé (rien n'est inventé) |
| `.eml` piégé (taille, encodages) | moyenne | limite de 1 Mo, analyse en mémoire, sans enregistrement ni exécution, HTML jamais rendu |
| Graphe falsifié envoyé par le navigateur | élevée | recompilation et validation serveur, mêmes limites que l'import, CSRF, permissions par boîte |
| Packs v1 existants | faible | conversion v1 → v2 couverte par des tests d'aller-retour |

## Découpage proposé (phases 4 à 8)

| Livraison | Contenu | Version |
|---|---|---|
| 1 | Schéma v2 + conversion v1, sélecteurs de boîtes, ressources logiques (table, résolution, prévisualisation, verrous) | 1.2.0 |
| 2 | Visual Builder : canevas contraint, Simple/Expert, JSON en direct, compilation serveur, aller-retour `x-visual` | 1.3.0 |
| 3 | Empreinte de message, zones, seuil, explication, testeur `.eml`, condition Workflows | 1.4.0 |
| 4 | Pack de démonstration, documentation complète, tests de bout en bout | 1.4.x |

Chaque livraison est testée (unitaires, intégration Docker, CI) et publiée séparément.
