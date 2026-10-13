# Résultats des tests — Workflow Packs 1.15.0

Date : 2026-10-12. Environnements Docker jetables, données de test uniquement (adresses en `.test`).

## Versions effectivement vérifiées

| Composant | Version |
|---|---|
| FreeScout | **1.8.245** (branche `dist` officielle), Laravel 5.5.40 |
| PHP | **8.2.34** (intégration) · **7.1.33** et **8.4.26** (analyse syntaxique et tests unitaires) |
| Base de données | MariaDB **10.11.19** |
| PHPUnit | 11.5.57 |
| Refresh | 1.4.3 (dépôt officiel), pour la vérification visuelle du style |
| Workflows | **bouchon de test** `tests/stubs/Workflows` (0.0.0-stub), reproduisant l'interface publique analysée. Le module officiel, payant, **n'a pas été testé**. |

## 1.15.0 — schéma au doigt (téléphone, tablette)

| Série | Résultat |
|---|---|
| JavaScript | `gestures.test.js` (4) : pincement (zoom autour des doigts, limites, doigts confondus), vitesse et élan (s'arrête, même trajet à 30 ou 60 images/s), toucher et double toucher (temps, distance, même cible) |
| Navigateur, téléphone simulé (390 × 844, vrais événements tactiles, 3 passages identiques) | un doigt : déplacement, la page ne défile pas ; élan après un geste rapide ; deux doigts : zoom 0,8 → 2,4 ; doigt sur un bloc non sélectionné : la vue bouge, pas le bloc ; toucher : sélection et panneau en bas ; bloc sélectionné déplacé au doigt ; appui long : actions rapides ; appui long + glisser : rectangle (21 blocs) ; double toucher sur le fond : zoom × 2, sur une règle : éditeur ; ● tiré au doigt (à côté du point) vers une boîte : règle appliquée ; aide tactile affichée ; aucune erreur |
| Navigateur, ordinateur | 84 blocs : déplacement, rectangle, menu, aucune superposition, comme avant |

## 1.14.1 — migrations sans recréation de table

| Série | Résultat |
|---|---|
| Intégration (87 tests) | installation complète (toutes les migrations) puis suite entière, inchangée ; migrations relancées sur une base déjà installée : aucune erreur, tables gardées |

## 1.14.0 — dossier e-mail actuel de chaque ticket

| Série | Résultat |
|---|---|
| Unitaires (44 tests) | dossier de la règle retrouvé depuis le chemin du serveur ou le libellé de l'historique (délimiteurs `/` et `.`, sous INBOX) |
| Intégration (87 tests, 652 assertions) | `ImapFolderTest` (+1, **serveur IMAP réel**) : dossier enregistré au rangement (dossier, libellé, chemin, état), filtres Eventy, action « changé » une seule fois, étiquette dans le ticket ; rangé à nouveau : même ligne ; nettoyage automatique : état « retiré », date, étiquette. `TicketFoldersTest` (2) : un module à l'écoute de la suppression (priorité 20) lit encore le dossier, puis la ligne est effacée avec le ticket, les autres gardées ; vérification quotidienne (une fois par jour) ; reprise de l'historique (dossier de la règle, sinon du chemin ; ticket déjà connu gardé ; « introuvable » ignoré) |
| Navigateur (Chrome sans interface) | page d'un ticket : étiquette « [WP] Factures › Fournisseurs » sous le sujet, infobulle avec le dossier du serveur et la date ; aucune erreur |

## 1.13.1 — e-mails trouvés même quand la recherche du serveur ne répond pas

| Série | Résultat |
|---|---|
| Intégration (84 tests, 629 assertions) | `ImapFolderTest` (+2, **serveur IMAP réel**) : serveur simulé dont la recherche par Message-ID ne répond rien ; e-mail trouvé par la lecture des en-têtes autour de sa date et rangé ; « déjà rangé » vu de la même façon ; e-mail absent « introuvable » avec les dossiers où il a été cherché. « Tester la recherche d'un ticket » : par lien (id) et par numéro affiché, étapes et réponse du serveur, dossier trouvé, derniers e-mails ; ticket inconnu signalé ; refusé aux non-administrateurs |

## 1.13.0 — ranger les tickets existants selon les conditions des règles

| Série | Résultat |
|---|---|
| Intégration (82 tests, 614 assertions) | `ImapFolderTest` (réécrits, **serveur IMAP réel**) : facture jamais traitée rangée selon les conditions ; ticket ordinaire rangé par la règle « Nouveau » ; la dernière règle l'emporte ; règle « Réponse » jamais appliquée ; ticket déjà traité dont le sujet ne correspond plus, déplacé à la main dans « Archive », remis en place ; corbeille jamais reprise ; statut non retouché ; avancement puis résultat dans l'historique (4 examinés, 4 envoyés) ; deuxième passage « déjà rangé ». Règles sans dossier comptées, boîte sans IMAP signalée une fois, boîtes d'autres refusées, option désactivée. Bouchon Workflows complété : `checkConditions()` (« Nouveau » sauté hors événement, comme le module réel) et table `conversation_workflow` |

## 1.12.0 — ranger à nouveau, schéma sans superposition, option « tickets existants » plus claire

| Série | Résultat |
|---|---|
| Intégration (82 tests, 614 assertions) | `ImapFolderTest` (+2, **serveur IMAP réel**) : e-mail rangé puis déplacé à la main dans un autre dossier, remis dans son dossier par « Ranger à nouveau » (trouvé dans « Archive ») ; e-mail mis à la corbeille jamais repris ; statut du ticket non retouché ; deuxième passage « déjà rangé » ; refus pour les boîtes d'autres et les règles sans dossier, option désactivée, `files` du schéma. `ExportAndPagesTest` (+1) : explication sous la case de l'import, case grisée quand aucune règle ne le prévoit, sinon liste des règles concernées (noms échappés) |
| JavaScript | `layout.test.js` (+1) : 30 grands schémas (83 blocs, blocs empilés et agrandis, 2 blocs fixes) sans aucune superposition. Essai de charge : 0 schéma sur 200 avec superposition (avant : 49), 6 ms au plus |
| Navigateur (Chrome sans interface) | 84 blocs : ancienne disposition empilée, flux en colonnes, radial, bloc lâché sur les autres : 0 superposition ; menu « Ranger à nouveau dans les dossiers (3) » seulement pour les règles qui rangent dans un dossier ; bouton dans la liste ; aucune erreur |

## 1.11.0 — historique et diagnostic du rangement

| Série | Résultat |
|---|---|
| Intégration (79 tests, 581 assertions) | `HistoryTest` (3) : import et changements de règles écrits et affichés, filtres, diagnostic pour les administrateurs ; un utilisateur ne voit que ses boîtes, ni diagnostic ni dossiers du serveur ; purge des entrées de plus de 90 jours. `ImapFolderTest` (+3, **serveur IMAP réel**) : rangement « demandé » puis « rangé » (même ligne complétée par le travail en file), « ignoré » avec la raison ; dossiers du serveur listés (gérés, corbeille, séparateur) ; jamais de dossier `[WP]` dans la corbeille |
| Navigateur (Chrome sans interface) | onglet Historique, diagnostic, « Voir les dossiers » (6 dossiers, `[WP]` marqués gérés, corbeille repérée), filtres ; raisons traduites ; aucune erreur |

## 1.10.0 — tickets existants, rectangle de sélection, actions rapides

| Série | Résultat |
|---|---|
| Intégration (73 tests, 545 assertions) | `BulkToggleTest` (+1) : « Tickets existants » par règle (seulement `apply_to_prev`, l'activation n'est pas touchée), cochées ensemble, sélection du schéma, boîtes d'autres refusées, état transmis au schéma, demande invalide refusée |
| Navigateur (Chrome sans interface) | rectangle bleu au clic gauche glissé (5 blocs), menu d'actions rapides à l'endroit de la souris, désactivation depuis le menu, alignement à gauche, clic droit sur une règle hors sélection : elle seule, clic droit glissé : la vue se déplace sans menu, export de la sélection : page d'export avec les règles cochées ; menu opaque, panneau sans bouton inutile ; aucune erreur |

## 1.9.0 — règles minuteur, nettoyage automatique des dossiers e-mail

| Série | Résultat |
|---|---|
| Unitaires (43 tests, 4 678 assertions) | `TimerTest` (2) : délai contrôlé et normalisé (« 30 » → 30, unités i/h/d, formes refusées), secondes, libellé ; règle « créée il y a plus de 30 jours ET fermée → supprimer » compilée avec un nombre entier, `WP-TIME-01` sinon |
| Intégration (72 tests, 528 assertions) | `TimerTest` (2) : import (« 30 jour(s) » dans la prévisualisation, valeur écrite comme Workflows l'attend, délai invalide bloqué) ; éditeur (délais proposés, enregistrement, unité invalide refusée) ; schéma (« Après 2 heure(s) » sur le bloc). `ImapFolderTest` (+3) : réglage du nettoyage réservé aux administrateurs, contrôlé, normalisé, affiché sur le bloc et dans les réglages, retiré ; **serveur IMAP réel** : seul l'e-mail de plus de 7 jours de `[WP] Newsletters` va à la corbeille, dossier perso du même nom et corbeille intacts ; mode définitif (48 heures), dossier absent jamais créé, option désactivée : rien |
| Navigateur (Chrome sans interface) | « Ajouter un délai » dans l'éditeur (nombre + unité), règle enregistrée, bloc « Après … jour(s) » ; nettoyage réglé dans le panneau d'un dossier, bloc « nettoyé après … » ; icônes SVG au lieu d'emojis |

## 1.8.0 — état des règles, sélection multiple, activation en lot

| Série | Résultat |
|---|---|
| Intégration (67 tests, 494 assertions) | `BulkToggleTest` (3) : liste avec état, case et lien par règle ; un clic ne change que cette règle ; règles cochées changées ensemble, boîte d'un autre utilisateur non modifiée (signalée), aucune case cochée : message, filtre conservé ; sélection du schéma : changées ensemble, refus signalés, déjà dans l'état demandé comptées à part, demande invalide refusée |
| Navigateur (Chrome sans interface, données de démonstration) | symboles d'état (actives, désactivées, mixtes) ; Maj + glisser : 4 blocs sélectionnés, « Activer (2) » : les 2 désactivées activées ; liste : boutons grisés sans case cochée, compteur, 2 règles désactivées ensemble, 1 activée d'un clic ; aucune erreur |

## 1.7.0 — réglages enregistrés automatiquement, activation depuis l'import, chargement, chevauchements

| Série | Résultat |
|---|---|
| Intégration (64 tests, 465 assertions) | `ImapFolderTest` : import d'un pack qui range dans des dossiers, option désactivée → message et bouton pour un administrateur, « demandez à un administrateur » sinon ; bouton sans effet pour un non-administrateur ; administrateur : option activée et prévisualisation recalculée (règle plus bloquée) ; réglages : enregistrement au changement présent |
| Node (`tests/js`) | `layout` (9) : bloc agrandi ou lâché fixe, les blocs recouverts s'écartent, aucun chevauchement ; `textfit` (5) |
| Navigateur (Chrome sans interface) | interrupteur basculé sans « Enregistrer » : enregistré ; import : message, bouton, option activée, message disparu ; blocs empilés au chargement, bloc agrandi, bloc lâché sur un autre, annulation : 0 chevauchement ; Schéma : « Chargement… » pendant une requête lente, masqué après ; bouton du module : icône qui tourne et désactivé juste après le clic, formulaire bien envoyé |

## 1.6.0 — dossiers e-mail dans le schéma, blocs à la taille du texte

| Série | Résultat |
|---|---|
| Intégration (62 tests, 453 assertions) | `GraphTest` : dossiers en flux (un bloc par niveau, dossier du haut partagé, liens dossier → sous-dossier, règles de chaque dossier), avertissement si l'option est désactivée ou si le nom est invalide ; tailles enregistrées, contrôlées (bornes, clés), conservées en réorganisant, remises en automatique ; `UpdatePermissionsTest` (1.5.1) |
| Node (`tests/js`) | `textfit` (5) : largeur selon le texte, retour à la ligne sans texte perdu, mot trop long coupé, taille manuelle bornée et jamais plus petite que le texte, place pour l'avertissement ; `layout` (8) : sous-dossiers en colonnes successives face à leur parent, blocs larges sans chevauchement |
| Navigateur (Chrome sans interface, données de démonstration) | flux `[WP] Factures` → `Clients` → `2026` et `Fournisseurs`, panneau d'un dossier, vue partielle avec les sous-dossiers ; aucun texte plus large que son bloc ; bloc agrandi à la poignée, taille conservée après rechargement ; aucune erreur |

## 1.5.0 — retirer un pack

| Série | Résultat |
|---|---|
| Intégration (`RemoveTest`, 4 tests ; 57 tests, 419 assertions au total) | règles inchangées supprimées, règle modifiée conservée (puis supprimée avec la case), règle déjà supprimée : lien nettoyé, règle faite à la main jamais touchée, pack retiré de la liste quand il ne reste rien ; boîte d'un autre utilisateur ni touchée ni nommée ; « aucune boîte cochée » ne supprime rien ; changement après la prévisualisation ou jeton falsifié : rien n'est supprimé ni sauvegardé ; sauvegarde téléchargeable (pack d'origine et règles supprimées), refusée à un autre utilisateur, pack d'origine réimporté : règles recréées |
| Navigateur (Chrome sans interface, données de démonstration) | bouton dans la liste, prévisualisation (14 suppressions, 1 règle modifiée conservée), confirmation affichée, résultat avec les deux téléchargements ; aucune erreur |

## 1.4.0 — rangement dans les dossiers e-mail (IMAP)

| Série | Résultat |
|---|---|
| Unitaires (`php tests/unit/run.php`, PHP 8.4.26 local) | **41 tests, 4 035 assertions, 0 échec**. `ImapFolderTest` (4) : chemin → niveaux sous le préfixe (délimiteurs `/` et `.`, espace de noms `INBOX`), noms refusés (vide, `*`, `%`, `"`, `\`, contrôle, point initial, longueur, profondeur), préfixe nettoyé, action compilée avec variables, message clair si l'option est désactivée |
| Intégration (Docker local : FreeScout 1.8.247 + MariaDB 10.11 + bouchon Workflows + **GreenMail 2.1.13**, PHP 8.2.34) | **52 tests, 369 assertions, 0 échec**. `ImapFolderTest` (8) : action présente dans le catalogue seulement si l'option est activée, validation par `workflow.validate_action` ; réglages enregistrés par FreeScout ; `workflow.perform_action` met en file le dernier e-mail du client ; **sur le serveur IMAP** : `[WP] Factures` et `[WP] Factures.Fournisseurs` créés, e-mail déplacé (commande `MOVE`), les autres e-mails restent dans la boîte de réception, deuxième passage « déjà rangé » ; noms accentués (UTF-7 IMAP) ; e-mail introuvable ; dossier déjà créé réutilisé et nouveaux dossiers abonnés (`LSUB`) ; valeurs invalides jamais envoyées au serveur, sans nouvelle tentative ; boîte sans IMAP : rien en file ; option désactivée avant l'exécution : rien n'est créé ni déplacé |

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
