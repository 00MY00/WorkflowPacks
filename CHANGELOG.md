# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `WorkflowPacks/module.json` ; la version du **schéma** des fichiers de pack est
indépendante (champ `version`, actuellement 1).

## [1.3.1] - 2026-10-12

### Modifié
- Le bouton **Mettre à jour maintenant** est toujours affiché dans Gérer › Paramètres › Workflow Packs et sur la page
  Diagnostic. Avant, il n'apparaissait que si la dernière recherche avait trouvé une version plus récente : si cette
  recherche datait d'avant une publication, seul « Rechercher une mise à jour » était visible. La mise à jour vérifie
  d'abord GitHub et ne fait rien si la version installée est déjà la dernière. Le bouton est mis en avant quand une
  version plus récente est connue, et désactivé pendant une mise à jour.

## [1.3.0] - 2026-10-12

### Ajouté
- **Schéma réseau des règles** (onglet *Schéma*, inspiré de draw.io), détaillé dans [docs/schema.md](docs/schema.md).
  - Contenu : boîtes → règles → destinations, et packs. Une règle de pack n'a qu'un bloc, relié à toutes ses boîtes.
    L'état de chaque lien est affiché : active, désactivée, modifiée localement, supprimée.
  - **Destinations** déduites des actions : dossiers Actives, En attente, Fermées, Spam et Supprimées, personne
    assignée, autre boîte (déplacement), étiquettes. Les icônes sont des icônes modernes au trait.
  - **Dispositions** : flux en colonnes (chaque boîte et chaque destination en face de ses règles), boîtes en cercle,
    grille, libre. Les liens peuvent être à angles droits, courbes ou droits.
  - **Déplacements enregistrés en direct**, par utilisateur (nouvelle table `workflowpacks_layouts`), avec annuler et
    rétablir.
  - **Navigation** :
    - glisser pour se déplacer, molette pour zoomer, mini-carte ;
    - zoom sémantique : noms courts de loin, et de très loin une vue macro où seuls les boîtes, les packs et les
      destinations restent nommés ;
    - raccourcis **Aller à…** vers une boîte, un pack ou une destination avec ses liens, et recherche ;
    - raccourcis clavier `+ − 0 F N Esc Ctrl+Z Ctrl+Y`.
  - **Modifier depuis le schéma** :
    - éditeur SI / ET / OU / ALORS alimenté par le catalogue Workflows de la boîte, avec un mode expert JSON ;
    - activation et désactivation des règles ;
    - application d'une règle à une autre boîte en tirant un lien. Une règle de pack est alors importée seule, avec
      les variables de la nouvelle boîte ; une règle locale est copiée. Dans les deux cas elle arrive désactivée.
  - Mêmes contrôles côté serveur qu'un import :
    - format, catalogue, validation officielle ;
    - permission par boîte ;
    - transaction ;
    - aucune suppression.
  - Disponible sur téléphone et en 7 langues.
- CI : vérification de syntaxe des scripts du schéma et tests des dispositions (`node tests/js/layout.test.js`).

### Modifié
- L'export réutilise le même convertisseur règle Workflows → règle de pack que le schéma (`RuleCodec`).
- Diagnostic, mise à jour intégrée et `install.sh` : la table `workflowpacks_layouts` est contrôlée et sauvegardée.

## [1.2.2] - 2026-10-11

### Modifié
- `install.sh` installe la **dernière version publiée** (release GitHub), après vérification de son empreinte SHA-256,
  comme la mise à jour intégrée. La branche `main` n'est utilisée que si aucune release n'existe.

### Ajouté
- Test de bout en bout de la mise à jour vers la dernière release (`tests/update/run.sh`), relancé automatiquement par
  la CI après chaque release. Il part de 1.0.0, 1.1.0 et 1.2.0, et passe par la commande, le bouton « Mettre à jour
  maintenant » (planificateur) et `install.sh`. Il vérifie la version installée, l'absence de retour arrière, les
  migrations, les données conservées et la réponse HTTP. 37 vérifications sur 37 réussies.

## [1.2.1] - 2026-10-11

### Ajouté
- Bandeau « Workflow Packs X est disponible » avec un bouton **Mettre à jour maintenant**. Il s'affiche pour les
  administrateurs sur les pages du module et dans Gérer › Paramètres › Workflow Packs, dès que la vérification
  quotidienne ou « Rechercher une mise à jour » trouve une version plus récente.
- Dans le bloc de mise à jour, le bouton n'apparaît que si une version est disponible.

## [1.2.0] - 2026-10-11

### Ajouté
- **Format de pack version 2**. La v1 est toujours importée telle quelle, et l'export reste en v1 sans fonction
  v2 :
  - **sélecteurs de boîtes** `mailboxes`, pour le pack ou une règle (« toutes les boîtes », adresse ou nom
    contient / ne contient pas / est égal / commence / finit par…), évalués localement sur les boîtes autorisées,
    avec ✓/✗ et des cases à décocher ;
  - **ressources logiques** `resources` + `{{resource.<clé>}}` (user, mailbox, tag), associées par clé à un
    élément local et mémorisées dans la nouvelle table `workflowpacks_resources`. Un élément de même nom n'est
    jamais adopté automatiquement, et une ressource locale n'est jamais créée, modifiée ni supprimée ;
  - extensions `x-…` (par exemple `x-visual`) conservées à l'aller-retour, jamais compilées.
- Prévisualisation : tableau des ressources (action prévue, risque) et niveau de risque SÛR / ATTENTION / DANGER
  dans chaque case.
- Export : sélecteurs et ressources des packs conservés. Un identifiant local peut être remplacé par une
  ressource (« nouvelle ressource… »).
- Pack de démonstration `demo-portable-v2.json`. Schéma JSON étendu à la v2 (les clés v2 sont refusées en v1).
- Documentation : `docs/format.md` (v2), `docs/resources.md`, `docs/visual-builder-architecture.md`.
- Section **Gérer › Paramètres › Workflow Packs**, présentée comme RefreshGlobal :
  - « Mise à jour automatique » : interrupteur, enregistré par FreeScout ;
  - « Mise à jour du module » : version, état, « Rechercher une mise à jour », « Mettre à jour maintenant ».
  Le même bloc est sur la page Diagnostic, et un onglet « Réglages » est ajouté dans le module.

## [1.1.0] - 2026-10-10

### Ajouté
- Interface et messages des services en 7 langues : fr, en, de, es, it, nl, pt-BR. La langue suit le profil
  FreeScout, avec l'anglais par défaut ; les traductions sont vérifiées par un test.
- Téléphone avec Refresh : entrée « Workflow Packs » dans le panneau Profil (menu burger) au lieu de la barre
  d'onglets.
- Téléphone : la matrice règles × boîtes s'affiche en cartes, les différences sont empilées, les onglets défilent.
- `install.sh` : demande le dossier de FreeScout s'il n'est dans aucun dossier par défaut ; messages en
  français ou en anglais (`--lang`).
- Contributeur : @00MY00.

### Corrigé
- `install.sh` : condition explicite (shellcheck SC2015, signalé par la CI).
- `tools/build-release.sh` : la version de `install.sh` était lue avec son commentaire.

## [1.0.0] - 2026-10-10

### Ajouté
- Import de packs en 4 étapes :
  - validation du format, des variables, des regex, des limites et des dépendances ;
  - choix des boîtes et des paramètres ;
  - prévisualisation : vue schématique, matrice règles × boîtes, différences, actions sensibles ;
  - confirmation recalculée et protégée par un jeton.
- Mises à jour sans doublon, avec l'identité `pack.key + workflow.key + boîte` :
  - détection des modifications manuelles ;
  - choix « conserver / remplacer / ignorer » ;
  - jamais de suppression ;
  - état actif conservé.
- Une transaction unique pour tout l'import.
- Export d'un pack (modèle d'origine) ou d'une sélection de règles :
  - remplacements explicites par des variables ;
  - références locales signalées ;
  - secrets retirés.
- Vérification de compatibilité avec le module Workflows (`php artisan workflowpacks:check`) et page Diagnostic avec
  le catalogue Workflows de l'instance.
- Style du module Refresh quand il est installé.
- Mise à jour du module avec contrôle SHA-256 et retour arrière automatique (`php artisan workflowpacks:update`).
- Script `install.sh` : installation, mise à jour, `--dry-run`, `--uninstall`, retour arrière en cas d'erreur.
- Schéma JSON versionné et pack d'exemple « Tri des e-mails (multilingue) ».
