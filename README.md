# Workflow Packs pour FreeScout

Module complémentaire de [FreeScout](https://freescout.net) qui automatise le help desk avec des **flux** : des
blocs reliés (déclencheur, conditions, attentes, actions) dessinés dans un éditeur visuel et exécutés par le propre
moteur du module. **Aucun autre module n'est nécessaire** : depuis la version 4.0, Workflow Packs n'utilise plus le
module Workflows.

- **Éditeur de flux** (onglet Flux) : tous les flux sur une grande grille, au doigt sur téléphone et tablette ;
  « Si… » qui propose les variables possibles ; publication par versions ; simulation animée d'un e-mail de test ;
  parcours réel d'un ticket. Voir [docs/flux-internes.md](docs/flux-internes.md).
- **Blocs** : nouvel e-mail ; Si… (sujet, texte, expéditeur, statut, assigné, réponse de l'équipe), Ressemble à…
  (comparaison locale avec des tickets d'exemple), Étiquette e-mail (module RefreshGlobal) ; attendre ; statut,
  assigner, note interne, classer dans un dossier e-mail, notification push (module WebPush), supprimer.
- **Tickets existants** : appliquer un flux aux tickets déjà là (période en jours, statuts), par lots, avec
  estimation et essai à blanc. **Tickets sans action d'un flux** et **flux temporaires** qui traitent une liste
  puis se suppriment.
- **Packs de flux** : importer (avec paramètres) et exporter des ensembles de flux, sans doublon.
- **IA** : un **assistant sans connexion** (dossier de tickets anonymisés pour une IA, sa réponse testée puis
  enregistrée en brouillon, [docs/assistant-ia.md](docs/assistant-ia.md)) et des **agents connectés** (API à jeton
  et connecteur Claude, droits détaillés par agent, [docs/agent-api.md](docs/agent-api.md)).
- **Dossiers e-mail** (option) : ranger l'e-mail reçu dans un dossier `[WP] …` du serveur IMAP, nettoyage
  automatique des dossiers, dossier actuel affiché dans le ticket ([docs/dossiers-email.md](docs/dossiers-email.md)).
- **Pour les autres modules** : ce que font les flux (statistiques de RefreshGlobal) et les dossiers des tickets,
  par Eventy ([docs/api-flux.md](docs/api-flux.md), [docs/api-dossiers.md](docs/api-dossiers.md)).
- **Historique** de ce que fait le module, **Diagnostic** (état, blocs disponibles), **7 langues** (fr, en, de, es,
  it, nl, pt-BR), téléphone et tablette, style du module Refresh quand il est installé.
- **Réservé aux administrateurs** : les flux agissent sur toutes les boîtes.

## Séparation de Workflows (4.0)

Les versions 1 à 3 importaient des **packs de règles** dans le module Workflows. Ces fonctions ont été retirées en
4.0. Si des données de ces packs restent, la page **Séparation de Workflows** s'ouvre à l'accueil du module :

- **télécharger la sauvegarde** (packs, liens, règles créées par les packs, telles qu'elles sont) ;
- **confirmer la suppression** : seules les règles créées par les packs sont supprimées de Workflows, jamais celles
  faites à la main, puis les anciennes tables ; une copie de la sauvegarde est gardée par le module ;
- ou **rester en version 3.20.2** : rien n'est supprimé avant votre confirmation, l'ancienne version peut être
  réinstallée (UpdateGuard, archive de la release, ou `sudo bash install.sh --version 3.20.2`) ;
- ou **décider plus tard** : le module fonctionne normalement en attendant.

## Installation

Prérequis : FreeScout ≥ 1.8.0 (testé avec 1.8.252), PHP ≥ 7.1 (testé jusqu'à 8.5). Modules facultatifs : **RefreshGlobal** (bloc
Étiquette e-mail), **WebPush** (notifications push), **Refresh** (style).

### Script (recommandé)

```bash
curl -fsSLO https://raw.githubusercontent.com/00MY00/WorkflowPacks/main/install.sh
sudo bash install.sh                    # cherche FreeScout dans les dossiers par défaut, sinon demande le chemin
```

Étapes : vérifications, sauvegarde du dossier du module et de ses tables, téléchargement de la dernière release
(empreinte SHA-256 vérifiée), copie, activation, migrations, contrôle avec `php artisan workflowpacks:check`. En cas
d'erreur, la version précédente est remise en place. Options :

- `--version 3.20.2` : installe une release précise (par exemple pour revenir à une version précédente) ;
- `--source fichier.zip|dossier` : installe une archive ou un dossier local ;
- `--path /chemin/vers/freescout`, `--dry-run`, `--yes`, `--lang fr|en`, `--uninstall`.

Le journal est écrit dans `/tmp/workflowpacks-install-*.log` et ne contient aucun mot de passe.

### Manuelle

```bash
cd /var/www/html
sudo -u www-data git clone https://github.com/00MY00/WorkflowPacks.git /tmp/WorkflowPacks
sudo -u www-data cp -R /tmp/WorkflowPacks/WorkflowPacks Modules/WorkflowPacks
sudo -u www-data php artisan module:enable WorkflowPacks
sudo -u www-data php artisan freescout:module-install workflowpacks
sudo -u www-data php artisan workflowpacks:check
```

Le module apparaît dans **Gérer › Workflow Packs** et dans la barre de Refresh.

## Mise à jour du module

`module.json` déclare la dernière version publiée (`latestVersionUrl`, `latestVersionZipUrl`) :

- **Gérer › Modules** de FreeScout propose la mise à jour (sans vérification de l'archive ni retour arrière) ;
- avec **[UpdateGuard](https://github.com/00MY00/UpdateGuard)** (recommandé) : vérification SHA-256, sauvegarde,
  contrôle de santé (`php artisan workflowpacks:health`), observation et **retour arrière automatique**.

`php artisan workflowpacks:health [--json]` vérifie le module installé (fichiers, classes, vues, routes, fichiers
publics, tables, migrations), en lecture seule et sans réseau. `php artisan workflowpacks:check [--json]
[--pack=fichier.json]` donne l'état du module et lit un pack de flux sans l'importer.

Les numéros de version suivent `module.json` ; voir [CHANGELOG.md](CHANGELOG.md).

## Tests

| Série | Commande |
|---|---|
| Unitaires (sans FreeScout) : délais, noms de dossiers, traductions des 7 langues | `php tests/unit/run.php` |
| Intégration (FreeScout réel, MariaDB, serveur IMAP GreenMail) | `bash tests/integration/run.sh` (`FREESCOUT_REF=1.8.252` pour une version précise) |
| Éditeur de flux (JavaScript) | `node tests/js/flows-editor.test.js` |
| Mise à jour depuis les versions précédentes | `bash tests/update/run.sh` |

Résultats et versions vérifiées : [tests/RESULTS.md](tests/RESULTS.md).

## Contributeurs

- [@00MY00](https://github.com/00MY00) — auteur et mainteneur

## Licence

AGPL-3.0, comme FreeScout.
