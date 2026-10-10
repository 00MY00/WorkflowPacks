# Résultats des tests — Workflow Packs 1.1.0

Date : 2026-10-10. Environnements Docker jetables, données de test uniquement (adresses en `.test`).

## Versions effectivement vérifiées

| Composant | Version |
|---|---|
| FreeScout | **1.8.245** (branche `dist` officielle), Laravel 5.5.40 |
| PHP | **8.2.34** (intégration) · **7.1.33** et **8.4.26** (analyse syntaxique et tests unitaires) |
| Base de données | MariaDB **10.11.19** |
| PHPUnit | 11.5.57 |
| Refresh | 1.4.3 (dépôt officiel), pour la vérification visuelle du style |
| Workflows | **bouchon de test** `tests/stubs/Workflows` (0.0.0-stub), reproduisant l'interface publique analysée. Le module officiel, payant, **n'a pas été testé**. |

## 1. Analyse statique

| Vérification | Résultat |
|---|---|
| `php -l` sur les fichiers PHP du module, PHP 7.1.33 | 0 erreur |
| `php -l` sur les fichiers PHP du module, PHP 8.4.26 | 0 erreur |
| `shellcheck install.sh tests/integration/run.sh` | 0 avertissement |
| Schéma JSON (Ajv 8.17.1, draft 2020-12) : pack d'exemple et 5 variantes | 6/6 |

## 2. Tests unitaires (`php tests/unit/run.php`, sans FreeScout)

**29 tests, 2 311 assertions, 0 échec**, sous PHP 7.1.33 et 8.4.26. Les 3 tests ajoutés en 1.1.0 vérifient que les
7 langues ont les mêmes clés et les mêmes paramètres, et que le traducteur fonctionne.

| Thème | Tests |
|---|---|
| ET/OU | `all`/`any` vers les groupes de Workflows, ET dans un OU refusé avec explication, imbrications refusées, aller-retour export/import sans changement de logique |
| Variables | `{{mailbox.id}}` entier, adresse littérale dans une regex (le point n'est plus un joker), échappement HTML, expressions et filtres refusés, variable inconnue ou paramètre manquant, délimiteur de regex en variable refusé |
| Validation | pack d'exemple valide, format, version de schéma, JSON invalide, taille, profondeur, clés inconnues, doublons, regex sans délimiteur ou invalide, règle automatique sans condition, paramètres |
| Compilation | attributs Workflows (valeurs JSON des notes), type, opérateur ou valeur absents du catalogue, nom de plus de 75 caractères, actions sensibles signalées, références locales contrôlées |
| Empreintes | insensibles à l'ordre des clés, à `"1"`/`1` et au JSON des notes ; l'état actif n'en fait pas partie |
| Pack d'exemple | les 6 sujets de l'énoncé, chacun reconnu par une seule règle ; reçu russe comptable et jamais fermé (y compris avec une espace fine) ; noreply, cadeau ou désabonnement ne ferment rien ; faux positifs évités (« recurring », « Download ») ; réponses non classées |

## 3. Tests d'intégration (`bash tests/integration/run.sh`)

FreeScout 1.8.245 + MariaDB 10.11 + bouchon Workflows : **22 tests, 181 assertions, 0 échec**.
`workflowpacks:check` : état **OK**, 15 contrôles.

| Fichier | Tests |
|---|---|
| `ImportTest` | 9 : variables par boîte, désactivé par défaut, options, idempotence, mise à jour avec différences et état actif conservé, désactivation explicite, conflits (conserver, ignorer, remplacer), règle étrangère intacte, choix falsifié refusé, pas de suppression, recréation, paramètres |
| `SafetyTest` | 8 : droits par boîte côté serveur, 403 sans permission, groupe `web` (CSRF), Tags absent, types ajoutés par un module, Workflows absent, **annulation transactionnelle complète**, jeton de prévisualisation |
| `ExportAndPagesTest` | 5 : modèle d'origine exporté, remplacements explicites (y compris dans les regex), secrets retirés, références signalées, boîtes interdites ignorées, parcours HTTP complet avec le pack d'exemple, échappement XSS |

## 4. Script d'installation (dans le conteneur FreeScout de test)

| Scénario | Résultat |
|---|---|
| Mise à jour depuis un dossier (`--source`) | OK, 7/7 étapes, liens et règles conservés (8/8) |
| Source cassée (erreur PHP dans le fournisseur) | erreur à l'étape 5, **version précédente restaurée**, données intactes (8/8) |
| `--dry-run` | aucune modification |
| `--uninstall` | tables de suivi supprimées, règles Workflows conservées, FreeScout démarre |
| Réinstallation depuis une archive `.zip` | OK, lien public créé (CSS servi en 200) |

## 5. Vérifications visuelles (Chromium sans interface, `docs/screenshots/`)

| Page | Constat |
|---|---|
| Liste des packs | vue schématique SI / ET / OU / ALORS, variables surlignées, transfert signalé, puces par boîte (« conforme », « modifiée localement ») |
| Prévisualisation | matrice règles × boîtes avec choix par case, conflit détecté sur la règle modifiée, différences avant/après |
| Avec Refresh 1.4.3 | barre latérale, couleurs et typographie de Refresh, icône du module dans la barre |
| Téléphone (390 px) | 1.1.0 : matrice en cartes, une par règle (`mobile-previsualisation.png`) ; avec Refresh, entrée dans le panneau Profil (`mobile-menu-profil.png`) |
| Diagnostic | contrôles, versions, catalogue Workflows de l'instance |

## 6. À vérifier sur une instance FreeScout réelle

1. **Module Workflows officiel** (version installée, jusqu'à 1.0.96) : lancer `php artisan workflowpacks:check`. Un
   écart de classe, table, colonnes, catalogue ou format bloque l'import et indique le code `WP-WF-…`.
2. Qu'une règle importée apparaisse et s'édite normalement dans l'interface de Workflows, et que son exécution soit
   correcte une fois activée : à tester d'abord sur une boîte de test, avec `freescout:workflows-process`.
3. Les identifiants ajoutés par **Tags** (et Custom Fields) : Diagnostic › Catalogue.
4. Les liens « Ouvrir les workflows de la boîte » (routes `mailboxes.workflows*` de Workflows).
5. La mise à jour automatique depuis GitHub : testée ici uniquement dans sa logique, reprise de RefreshGlobal ; la
   première version publiée sera la 1.0.0.
