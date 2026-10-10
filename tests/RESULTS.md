# Résultats des tests — Workflow Packs 1.4.0

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

## 1.4.0 — rangement dans les dossiers e-mail (IMAP)

| Série | Résultat |
|---|---|
| Unitaires (`php tests/unit/run.php`, PHP 8.4.26 local) | **41 tests, 4 035 assertions, 0 échec**. `ImapFolderTest` (4) : chemin → niveaux sous le préfixe (délimiteurs `/` et `.`, espace de noms `INBOX`), noms refusés (vide, `*`, `%`, `"`, `\`, contrôle, point initial, longueur, profondeur), préfixe nettoyé, action compilée avec variables, message clair si l'option est désactivée |
| Intégration (Docker local : FreeScout 1.8.247 + MariaDB 10.11 + bouchon Workflows + **GreenMail 2.1.13**, PHP 8.2.34) | **50 tests, 362 assertions, 0 échec**. `ImapFolderTest` (6) : action présente dans le catalogue seulement si l'option est activée, validation par `workflow.validate_action` ; réglages enregistrés par FreeScout ; `workflow.perform_action` met en file le dernier e-mail du client ; **sur le serveur IMAP** : `[WP] Factures` et `[WP] Factures.Fournisseurs` créés, e-mail déplacé (commande `MOVE`), les autres e-mails restent dans la boîte de réception, deuxième passage « déjà rangé » ; noms accentués (UTF-7 IMAP) ; e-mail introuvable ; option désactivée avant l'exécution : rien n'est créé ni déplacé |

À vérifier sur une vraie instance : l'appel de l'action par le module Workflows officiel (filtre `workflow.perform_action`,
vu dans sa copie publique) et le serveur IMAP réel (Gmail, Microsoft 365, Dovecot…).

## 1.3.0 — schéma réseau

| Série | Résultat |
|---|---|
| Unitaires (`php tests/unit/run.php`, PHP 8.4.26 local) | **37 tests, 3 909 assertions, 0 échec**, dont `graph.php` complet dans les 7 langues (mêmes clés, mêmes paramètres) |
| Intégration (Docker local : FreeScout 1.8.245 + MariaDB 10.11 + bouchon Workflows, PHP 8.2.34) | **44 tests, 337 assertions, 0 échec**. `GraphTest` (7) : données limitées aux boîtes autorisées, avec destinations ; règle de pack = un bloc relié à deux boîtes (synchronisée / modifiée) ; positions enregistrées, fusionnées, validées (clé, coordonnées, choix) et propres à chaque utilisateur ; règle créée désactivée, variables résolues, nom en double / type inconnu / boîte falsifiée refusés ; activation et détail soumis à la permission ; copie d'une règle locale (nom libre, désactivée) ; règle de pack appliquée seule à une autre boîte, avec son lien |
| Dispositions (`node tests/js/layout.test.js`) | **7 tests** : flux en colonnes, ordre par barycentre, boîte et destination alignées sur leurs règles, cercle et grille sans chevauchement (y compris 7 règles partagées par 2 boîtes), placement des nouveaux blocs, cadre |
| Navigateur (Chrome sans interface, données de démonstration : 4 boîtes, 1 pack sur 2 boîtes, 5 règles locales) | aucune erreur JavaScript ni réponse HTTP en erreur ; colonnes, cercle, panneau d'une boîte, éditeur (double-clic), vue macro, téléphone 390 px vérifiés à l'image ; un bloc déplacé garde sa place après rechargement |

Corrigé pendant ces vérifications :
- le double-clic n'ouvrait pas l'éditeur, car avec la capture du pointeur le navigateur adresse `dblclick` au canevas ;
  le double-clic est désormais détecté dans `pointerup` ;
- en disposition cercle, des blocs se chevauchaient : le rayon s'adapte maintenant à la plus haute colonne de règles
  partagées, et la séparation des blocs est symétrique ;
- en colonnes, les boîtes et les destinations sont placées en face de leurs règles ;
- le résumé d'une règle affiche le nom de la personne ou de la boîte au lieu de son identifiant.

## Mise à jour directe vers la dernière version (`tests/update/run.sh`)

Release v1.2.1 publiée sur GitHub, téléchargée par chaque scénario ; FreeScout officiel + MariaDB 10.11 + bouchon
Workflows, avec les données de démonstration. **37 vérifications, 0 échec.**

| Départ | Chemin | Résultat |
|---|---|---|
| 1.0.0 | `php artisan workflowpacks:update` | 1.2.1 installée, migration 1.2 faite, 1 pack / 8 liens / 8 règles conservés, check OK |
| 1.1.0 | `php artisan workflowpacks:update` | idem |
| 1.2.0 | bouton « Mettre à jour maintenant » puis `schedule:run` | demande traitée au passage suivant du planificateur, 1.2.1 installée, données conservées |
| 1.0.0 | `install.sh` de la release | idem, aucun retour arrière |
| 1.1.0 | nouvel `install.sh` (1.2.2) | archive de la release vérifiée (SHA-256), 1.2.1 installée, données conservées |

Point relevé : le premier `schedule:run` peut rester occupé par la file d'e-mails de FreeScout (`queue:work`, qui ne
s'arrête jamais). La mise à jour demandée part donc au passage suivant, une minute plus tard, comme sur un serveur
réel.

## 1.2.0 — format v2 (sélecteurs, ressources)

| Série | Résultat |
|---|---|
| Unitaires (`php tests/unit/run.php`, PHP 8.4.26 local, 7.1 / 8.2 / 8.4 en CI) | **36 tests, 2 739 assertions, 0 échec**. Ajouts : sélecteurs (toutes les boîtes, adresse contient / ne contient pas, nom, ET/OU, formes refusées), v2 (pack de démonstration, clés v2 refusées en v1, ressources : dossier refusé, clés, doublons, variables), `{{resource.clé}}` résolu en entier, aller-retour v1/v2 → modèle → JSON sans perte |
| Intégration (CI GitHub : FreeScout officiel + MariaDB + bouchon Workflows) | **31 tests, 245 assertions, 0 échec**. `PortableTest` (9) : sélecteur évalué localement (✓ pré-coché, « hors sélecteur »), aucune boîte sans permission, ressource associée par clé et réutilisée, homonyme seulement suggéré, identifiants falsifiés ou interdits refusés, Tags absent bloquant, associations annulées avec l'import, export portable, prévisualisation (ressources, risques) |
| Schéma (Ajv) | pack v1, pack v2 et 11 variantes (dont les clés v2 refusées en v1, le type dossier refusé et `x-visual` accepté) |

Les tests d'intégration de la 1.2.0 ont tourné dans la CI GitHub, car le Docker local était indisponible.

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
