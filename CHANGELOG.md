# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `WorkflowPacks/module.json` ; la version du **schéma** des fichiers de pack est
indépendante (champ `version`, actuellement 1).

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
