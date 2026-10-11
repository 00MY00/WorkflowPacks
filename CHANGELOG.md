# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `WorkflowPacks/module.json` ; la version du **schéma** des fichiers de pack est
indépendante (champ `version`, actuellement 1).

## [1.11.0] - 2026-10-20

### Ajouté
- **Onglet « Historique »** : ce que le module a fait, du plus récent au plus ancien, filtrable par action, état et
  boîte, conservé 90 jours.
  - **Rangement d'un e-mail** : « demandé » quand Workflows appelle l'action, puis « rangé », « déjà rangé »,
    « introuvable » ou « échec » avec la raison du serveur. Un rangement est « ignoré » avec la raison : option
    désactivée, boîte sans réception IMAP, aucun e-mail du client…
  - Nettoyages de dossiers, imports et retraits de packs, règles activées, désactivées, créées, modifiées, appliquées
    aux tickets existants.
  - Chaque utilisateur voit les boîtes qu'il gère et ses propres actions ; les administrateurs voient tout.
- **Diagnostic du rangement** (administrateurs), en haut de l'historique :
  - état de l'option ;
  - dernier appel de Workflows et dernier e-mail rangé ;
  - rangements en attente ou en échec dans la file de FreeScout, avec une alerte si la file n'avance pas (cron) ;
  - **« Voir les dossiers »** : les dossiers réellement présents sur le serveur e-mail d'une boîte, avec le
    séparateur, l'espace de noms (`NAMESPACE`), l'emplacement des dossiers `[WP]` et la corbeille. C'est une simple
    lecture, rien n'est modifié.
- Réglage **« Créer les dossiers »** : automatique (par défaut, sans changement de comportement), à la racine, ou
  sous la boîte de réception, pour les serveurs atypiques.
- Garde-fou : un dossier `[WP]` n'est jamais créé ni rempli à l'intérieur de la corbeille.

## [1.10.0] - 2026-10-19

### Ajouté
- **Appliquer les règles aux tickets déjà existants** (option `apply_to_prev` de Workflows : les tickets créés avant
  la règle, **seulement ceux qu'elle n'a pas encore traités**).
  - Vue générale : repère « Tickets existants : oui / non » cliquable sur chaque règle et chaque boîte, et boutons
    « Appliquer aux tickets existants » / « Ne plus appliquer aux existants » dans la barre de sélection.
  - Schéma : depuis le menu d'actions rapides et le panneau d'une sélection.
  - Une confirmation est demandée, avec un avertissement renforcé si une règle supprime, transfère ou envoie.
- **Schéma : sélection au rectangle bleu avec le clic gauche**, comme dans draw.io. On glisse sur le fond ; Maj
  ajoute à la sélection. Pour se déplacer : clic droit ou molette maintenus, Espace + glisser, ou un doigt sur
  écran tactile. La molette sert toujours à zoomer.
- **Schéma : menu d'actions rapides** à l'endroit de la souris, au relâchement du rectangle et au clic droit. Il
  ne propose que ce qui a du sens pour la sélection :
  - modifier la règle ;
  - activer / désactiver ;
  - appliquer ou ne plus appliquer aux tickets existants ;
  - appliquer à une boîte (les règles y sont créées désactivées) ;
  - exporter en pack (la page d'export s'ouvre avec les règles cochées) ;
  - isoler la sélection ;
  - aligner à gauche ou en haut, répartir verticalement ou horizontalement (annulable) ;
  - taille automatique ;
  - tout désélectionner.
- Le panneau d'une sélection ne montre plus que les boutons qui changent quelque chose.

## [1.9.0] - 2026-10-18

### Ajouté
- **Règles minuteur**, détaillées dans [docs/minuteurs.md](docs/minuteurs.md).
  - Le module prend en charge les conditions de délai de Workflows : date de création, en attente depuis, dernière
    réponse d'un agent, dernière réponse du client. Le délai a la forme `{ "number": 30, "metric": "d" }`, en
    minutes, heures ou jours. Exemple : « supprimer les tickets fermés **après 30 jours** ».
  - Dans l'éditeur du Schéma, un nombre et une unité remplacent le champ texte, et **« Ajouter un délai »** crée la
    condition en un clic. Le bloc de la règle affiche « Après 30 jour(s) ».
  - À l'import : délai contrôlé et normalisé, et règle bloquée avec le code `WP-TIME-01` si le délai est mal écrit.
    La prévisualisation et la liste affichent « 30 jour(s) ».
- **Nettoyage automatique des dossiers e-mail `[WP]`.**
  - Réglage par dossier : « les e-mails de plus de N minutes, heures ou jours vont à la corbeille », ou sont
    supprimés définitivement. Il se fait dans le panneau du dossier, dans le Schéma (administrateurs). La liste et le
    retrait sont dans les réglages.
  - Exécution toutes les 15 minutes par le planificateur (`workflowpacks:clean-folders`), dans chaque boîte où le
    dossier existe.
  - Garde-fous : seulement les dossiers portant le préfixe ; corbeille et dossiers perso jamais touchés ; purge
    limitée au dossier nettoyé ; dossier absent jamais créé ; 500 e-mails au plus par passage ; journal.
- Icônes SVG du module au lieu d'emojis, pour un affichage identique partout.

### Modifié
- Paramètres nullables explicites (`?callable`, `?array`), sans avertissement PHP 8.4, et toujours compatibles
  PHP 7.1.

## [1.8.0] - 2026-10-17

### Ajouté
- **Schéma : état de chaque règle.** Un symbole en haut à droite de chaque règle indique son état : rond vert plein si
  elle est active dans toutes ses boîtes, rond gris vide si elle est désactivée partout, demi-rond orange si l'état
  est mixte. Le détail s'affiche au survol, par exemple « active dans 1 boîte sur 2 ».
- **Schéma : sélection multiple et activation en lot.**
  - Maj + clic, ou **Maj + glisser sur le fond** pour tracer un rectangle de sélection.
  - Le panneau liste les blocs sélectionnés et les règles concernées : une règle sélectionnée dans toutes ses boîtes,
    une boîte sélectionnée avec toutes ses règles.
  - **Activer (n)** et **Désactiver (n)** s'appliquent à toutes ces règles d'un coup.
- **Vue générale (Workflow Packs) : activer ou désactiver les règles.**
  - Chaque règle porte, pour chaque boîte, un point d'état et un lien **Activer / Désactiver**.
  - Une **case** par règle et par boîte, une case par pack et « Tout sélectionner » alimentent une barre **« Activer
    la sélection » / « Désactiver la sélection »** avec le nombre de règles cochées. Le filtre par boîte est conservé.
- Côté serveur, chaque règle est contrôlée séparément : permission de sa boîte, et une règle incomplète ne peut pas
  être activée. Les autres sont modifiées quand même, et ce qui n'a pas pu l'être est indiqué.

## [1.7.0] - 2026-10-16

### Ajouté
- **Réglages enregistrés automatiquement.** Dans Gérer › Paramètres › Workflow Packs, chaque interrupteur s'enregistre
  dès qu'on le bascule, comme « Ranger dans un dossier e-mail » ou « Mise à jour quotidienne ». Le préfixe des dossiers
  s'enregistre quand on quitte le champ. « Enregistrement… » s'affiche pendant l'opération : plus de changement perdu
  faute d'avoir cliqué sur « Enregistrer ».
- **Import d'un pack qui range dans des dossiers alors que l'option est désactivée** : un message l'explique sous le
  bouton de l'étape. Pour un administrateur, **« Activer l'option et actualiser »** l'active et recalcule aussitôt la
  prévisualisation, sans rien perdre de ce qui a été saisi. Les autres utilisateurs voient qui peut l'activer.
- **Indicateurs de chargement.**
  - Icône qui tourne sur le bouton cliqué pendant que la page travaille (import, retrait, mise à jour, recherche).
    Les boutons se désactivent pour éviter un double envoi.
  - Dans le Schéma, « Chargement… » dans la barre d'outils pendant chaque échange avec le serveur.

### Corrigé
- **Schéma : blocs superposés.** Les blocs qui se chevauchent sont écartés automatiquement, puis enregistrés :
  - au chargement, par exemple quand des positions enregistrées avant que les blocs prennent la taille de leur texte
    se recouvrent, ou pour de nouveaux blocs ;
  - après un bloc lâché sur un autre ;
  - après un bloc agrandi à la poignée.

  Le bloc déplacé ou agrandi reste où il a été mis, ce sont les autres qui s'écartent. Le tout s'annule avec Ctrl+Z.

## [1.6.0] - 2026-10-15

### Ajouté
- **Schéma : les dossiers e-mail forment un flux.** Une règle qui range dans `Factures/Fournisseurs` mène au bloc
  📁 `[WP] Factures`, puis au bloc 📁 `Fournisseurs` : un bloc par niveau, avec une icône de dossier. Les sous-dossiers
  sont placés dans les colonnes suivantes, en face de leur dossier parent.
  - Le panneau d'un dossier liste les règles qui rangent dans ce dossier ou dans un de ses sous-dossiers.
  - Sélectionner une règle met en avant tout son chemin. La vue partielle d'une boîte inclut ses dossiers et
    sous-dossiers.
  - Une règle de rangement apparaît même quand l'option est désactivée ou que le nom du dossier est invalide, avec un
    avertissement sur le bloc et dans le panneau : elle ne passe plus inaperçue.
- **Blocs à la taille de leur texte.**
  - La largeur suit le titre. Au-delà, le texte passe à la ligne et le bloc grandit : plus aucun texte coupé, à
    aucune distance de zoom, ni dans le panneau latéral.
  - **Poignée dans le coin** d'un bloc pour l'agrandir. La taille est enregistrée en direct, pour chaque
    utilisateur, et conservée quand on réorganise. Un double-clic sur la poignée rend la taille automatique.
  - Les colonnes et la grille s'élargissent selon les blocs, sans chevauchement.

## [1.5.1] - 2026-10-14

### Corrigé
- **« Mettre à jour maintenant » restait sans effet, avec le message « another update is running ».**
  - Cause : `install.sh` créait le dossier de travail `storage/app/workflowpacks` en tant que `root` et ne rendait à
    l'utilisateur web que son sous-dossier `backups`. La mise à jour intégrée, lancée par le planificateur sous
    l'utilisateur web, ne pouvait donc pas y créer son verrou, et ce refus était présenté à tort comme « une autre mise
    à jour est en cours ».
  - `install.sh` rend désormais tout le dossier à l'utilisateur web, et répare au passage les installations
    existantes.
  - Si le dossier appartient malgré tout à un autre utilisateur, la mise à jour utilise un dossier de travail de
    secours (`storage/app/workflowpacks-<utilisateur>`).
  - Avant de télécharger quoi que ce soit, la mise à jour vérifie qu'elle peut modifier le dossier du module, son
    dossier parent, le lien public des modules et le cache de configuration. Sinon elle s'arrête sans rien changer,
    avec un message qui nomme l'utilisateur, les dossiers concernés et la commande `chown` qui corrige.
  - L'état « mise à jour en cours » est aussi lu en base de données, quel que soit l'utilisateur système qui affiche
    la page.

## [1.5.0] - 2026-10-14

### Ajouté
- **Retirer un pack** : bouton « Retirer le pack » dans la liste des packs.
  - **Prévisualisation** : règle par règle et boîte par boîte, ce qui sera supprimé, conservé ou nettoyé. On peut
    choisir les boîtes.
  - Seules les règles **créées par le pack** sont supprimées. Les règles faites à la main et les boîtes que
    l'utilisateur ne gère pas ne sont jamais touchées.
  - Les règles **modifiées** depuis l'import sont conservées par défaut. Une case permet de les supprimer aussi.
  - **Sauvegarde automatique** avant toute suppression, en base de données (table `workflowpacks_backups`) : le pack
    d'origine, réimportable tel quel, et chaque règle supprimée telle qu'elle était. Les deux se téléchargent depuis la
    page de résultat.
  - Garde-fous :
    - une seule transaction, avec la sauvegarde ;
    - suppression annulée si les règles ont changé depuis la prévisualisation ;
    - « aucune boîte cochée » ne veut jamais dire « toutes » ;
    - confirmation avant de supprimer ;
    - suppression par le modèle officiel de Workflows, comme son propre bouton « Supprimer ».
  - Le pack disparaît de la liste quand il ne lui reste aucune règle. 7 langues.

## [1.4.1] - 2026-10-13

### Corrigé
- **« Mettre à jour maintenant » absent alors que « Rechercher » annonçait une nouvelle version.** Le résultat de la
  recherche était enregistré dans un fichier de `storage/app/workflowpacks`. Sur les serveurs où le planificateur
  (cron) tourne sous un autre utilisateur système que le serveur web, ce dossier n'était pas modifiable par le site :
  le résultat était perdu, et la page restait sur « Pas encore vérifié ». Il est désormais enregistré dans la base de
  données de FreeScout (table des options), accessible aux deux. L'ancien fichier est encore lu une fois.

## [1.4.0] - 2026-10-13

### Ajouté
- **Rangement des e-mails dans des dossiers de la boîte e-mail** (option désactivée par défaut, Gérer › Paramètres ›
  Workflow Packs), détaillé dans [docs/dossiers-email.md](docs/dossiers-email.md).
  - Nouvelle action Workflows **« Ranger dans un dossier e-mail [WP] »**, par exemple `Factures/Fournisseurs`, avec
    les variables des packs. L'e-mail reçu est déplacé sur le serveur IMAP dans `[WP] Factures/Fournisseurs`, et les
    dossiers manquants sont créés. Le ticket FreeScout ne change pas.
  - Préfixe réglable (`[WP]` par défaut). Le module ne crée et ne remplit que des dossiers portant ce préfixe, et ne
    supprime jamais rien.
  - Fonctionnement :
    - ajout par les filtres d'extension de Workflows, sans modifier Workflows ;
    - déplacement en file d'attente FreeScout, avec 3 tentatives ;
    - client IMAP de FreeScout (OAuth compris), e-mail retrouvé par son Message-ID ;
    - délimiteur `/` ou `.` détecté, espace de noms `INBOX` pris en charge, noms accentués (UTF-7 IMAP) ;
    - commande `MOVE`, ou copie puis original marqué supprimé, sans purge des autres messages ;
    - garde-fous : dossier cible vérifié (préfixe), e-mail déjà rangé laissé tel quel (pas de doublon), dossier créé
      entre-temps réutilisé, nouveaux dossiers abonnés, nouvelle tentative seulement sur erreur temporaire.
  - Import : règle bloquée avec un message clair si l'option est désactivée, et noms de dossier contrôlés.
  - Schéma : le dossier e-mail apparaît comme une destination.
  - 7 langues.
- Tests d'intégration avec un vrai serveur IMAP (GreenMail) : création de dossiers imbriqués, déplacement, e-mail déjà
  rangé ou introuvable, accents, option désactivée, réglages et filtres Workflows.

## [1.3.1] - 2026-10-12

### Modifié
- Le bouton **Mettre à jour maintenant** est toujours affiché dans Gérer › Paramètres › Workflow Packs et sur la page
  Diagnostic. Avant, il n'apparaissait que si la dernière recherche avait trouvé une version plus récente : si cette
  recherche datait d'avant une publication, seul « Rechercher une mise à jour » était visible. La mise à jour vérifie
  d'abord GitHub et ne fait rien si la version installée est déjà la dernière. Le bouton est mis en avant quand une
  version plus récente est connue, et désactivé pendant une mise à jour.
- `install.sh` enregistre la dernière version publiée à la fin de l'installation : la page des réglages affiche « À
  jour » au lieu de « Pas encore vérifié ».

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
