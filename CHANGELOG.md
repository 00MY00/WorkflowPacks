# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `WorkflowPacks/module.json` ; la version du **schéma** des fichiers de pack est
indépendante (champ `version`, actuellement 1).

## [3.15.0] - 2026-11-18

### Ajouté
- **Simulation : « Générer un e-mail qui passe »** à côté de la saisie manuelle : l'e-mail de test est rempli pour suivre
  le « oui » de chaque condition jusqu'à la fin du flux (mot attendu, expéditeur, statut, assigné, répondu) ; pour
  « Ressemble à… », un texte proche des e-mails d'exemple (mots retirés ou permutés au hasard) dont la ressemblance
  atteint le seuil. Joué aussitôt ; ce qui ne peut pas être réglé depuis un e-mail est indiqué. Le formulaire manuel
  gagne statut, assigné, répondu.
- **Simulation pour les agents** (`simulate_flow`, API `POST /flows/{id}/simulate`) : étapes (bloc, décision,
  changements, moment), **récit** en clair, **diffusion en direct** des étapes (progression MCP, ou événements serveur
  pour l'API) et **adresse de rediffusion** : un administrateur revoit la simulation jouée sur le schéma.
- **Droits des agents sur les autres flux** : « Peut voir les autres flux » (désactivé par défaut ; les agents existants
  le gardent) et « Peut publier et activer les autres flux » de ses boîtes, selon ses droits de publication ou
  d'activation après test (jamais les modifier).
- Schéma : bouton **Tout** dans la barre de l'élément choisi (comme Ctrl+A, utile au doigt).

## [3.14.0] - 2026-11-18

### Ajouté
- **Modifier un agent connecté** sans changer sa clé ni l'adresse du connecteur : nom, boîtes lisibles, anonymisation,
  lecture seule, activation après test, publication, adresses IP ; appliqué tout de suite, noté dans l'Historique
  (avant → après). Pas pour un agent déconnecté (on en crée un nouveau).
- **Supprimer un agent** déconnecté ou expiré : sa clé disparaît définitivement, les flux qu'il a proposés restent comme
  des flux ordinaires, l'Historique garde ses actions. Un agent encore actif doit d'abord être déconnecté.
- Fiche d'un agent réorganisée : état, puis ses actions (durée, déconnecter ou supprimer), ses droits dépliables.

## [3.13.0] - 2026-11-18

### Ajouté
- **Activer un flux après un test réussi** (connecteur `activate_flow`, API `POST /flows/{id}/activate`) : nouveau droit
  par agent « Peut activer après un test réussi », distinct de « Peut publier ». Le flux est publié et activé seulement
  si le dernier `test_flow` de **cette version exacte** a réussi (au moins un ticket testé, aucun en erreur), dans les
  24 h ; modifié depuis : il faut retester (`test_required`). Toujours dans les boîtes de l'agent, jamais en lecture
  seule ; noté dans l'Historique. `test_flow` renvoie maintenant son verdict.
- **Durée d'accès d'un agent modifiable** sur sa fiche (7, 30, 90, 365 jours ou sans limite, à partir du jour même) ;
  un agent expiré peut être prolongé, un agent déconnecté jamais.

### Corrigé
- Claude affichait « configuré pour ne pas nécessiter de connexion, mais le serveur a demandé une connexion (401) »
  avec l'adresse d'un agent déconnecté ou expiré : le 401 lui faisait croire à une connexion OAuth. Le connecteur
  répond maintenant 404 « adresse inconnue, déconnectée ou expirée » (noté dans l'Historique) ; l'API garde son 401.

## [3.12.2] - 2026-11-18

### Sécurité (revue des points d'entrée)
- **Agents connectés : flux bornés à leurs boîtes.** Un agent autorisé à publier mais sans boîte pouvait publier un
  flux s'appliquant à toutes les boîtes ; un flux d'agent pouvait nommer une autre boîte (« classer dans un dossier »)
  ou prendre comme exemples des tickets d'autres boîtes. Refusé désormais (`mailbox_denied`, `examples_denied`) : la
  publication par l'agent exige que le flux ne vise que les boîtes de l'agent.
- **Noms échappés dans les messages** (flux d'un pack ou d'un agent, packs, règles, boîtes, adresse IP saisie) : FreeScout
  filtrait déjà les balises dangereuses, l'échappement est maintenant fait aussi par le module.
- **Requêtes bornées** : lots MCP de 20 messages au plus, corps de plus de 1 Mo refusés aussi par l'API.
- Page Diagnostic : droit d'administrateur vérifié aussi dans le contrôleur (comme les autres pages).
- Vérifié sans changement : chaque action vérifie le rôle (administrateur pour les flux, l'IA, les agents) et, pour le
  schéma des règles, le droit sur la boîte ; CSRF sur les formulaires et appels de l'éditeur ; requêtes SQL paramétrées ;
  packs fournis par liste blanche (pas de chemin) ; sorties des pages échappées.

## [3.12.1] - 2026-11-18

### Modifié
- L'adresse du connecteur Claude ouverte dans un navigateur affichait l'erreur « HTTP ERROR 405 » (normale : le
  connecteur ne répond qu'à Claude) : elle affiche maintenant une page « Le connecteur est joignable » qui explique où
  coller l'adresse, sans rien dire de l'agent. Les clients MCP reçoivent toujours la réponse prévue.
- Documentation : vérifier l'accès depuis Internet, connecteur derrière Cloudflare.

## [3.12.0] - 2026-11-18

### Ajouté
- **Connecteur Claude (chat)** : à la création d'un agent connecté, une adresse à coller dans Claude › Paramètres ›
  Connecteurs. Claude (claude.ai, bureau, mobile) voit alors les flux, les tickets des boîtes permises (anonymisés par
  défaut), propose des flux en brouillon désactivé, les teste à blanc et les modifie ; publie seulement si permis
  (protocole MCP, outils `get_catalog`, `list_flows`, `list_tickets`, `propose_flow`, `update_flow`, `test_flow`,
  `publish_flow`). Vérifié avec le client MCP officiel.
- **Droits des agents** : **lecture seule** ; **durée d'accès** (7, 30 par défaut, 90, 365 jours ou sans limite) ;
  **adresses IP autorisées** ; tickets **facultatifs** (aucune boîte cochée : l'agent ne voit que les flux).
- **Sécurité** de l'API et du connecteur : **https obligatoire** (la connexion réelle est vérifiée ; adresse locale
  seulement pour les tests), **anti-force brute** (20 jetons invalides par minute et par adresse), chaque **lecture de
  tickets** et chaque adresse refusée notées dans l'Historique, dernière adresse affichée, réponses jamais mises en
  cache, requêtes de plus de 1 Mo refusées.
- API : `GET /flows?all=1` lit tous les flux.

### Modifié
- **Tickets existants** d'un flux jamais publié : plus d'impasse. Estimation et simulation à blanc sur le brouillon,
  bouton **Publier et appliquer** (ses problèmes affichés sur la page s'il ne peut pas être publié).

## [3.11.0] - 2026-11-17

### Ajouté
- « Ressemble à… » : **plusieurs tickets cochés et ajoutés ensemble** comme exemples, dans la recherche comme dans le
  résultat du test (toucher la ligne coche le ticket, **Tout**, **Ajouter (n)**, 50 exemples au plus) ; après un test,
  **Cocher les reconnus** coche ceux au-dessus du seuil pour les ajouter et affiner. Le test montre jusqu'à 50 tickets.

### Corrigé
- **Tickets existants** d'un flux qui ne peut pas être publié : « Publier maintenant » montrait ses problèmes dans un
  message qui disparaissait, la page semblait inchangée. Les problèmes restent maintenant affichés sur la page, avec le
  bloc concerné ; le message explique que choisir des e-mails d'exemple se fait dans l'éditeur, sans publier.
- Problème d'un « Ressemble à… » sans exemple dit clairement (« Choisissez de 1 à 50 e-mails d'exemple… »).

## [3.10.0] - 2026-11-17

### Ajouté
- **« Ressemble à… » se règle dans l'éditeur, sans publier** : chercher des e-mails d'exemple parmi les tickets
  (sujet, expéditeur ou numéro, dans les boîtes du flux), les ajouter ou les retirer d'un toucher (affichés avec leur
  sujet, plus des numéros à taper), puis **Tester** sur les 100 derniers tickets : combien seraient reconnus au seuil
  choisi et le score de chacun, pour ajuster seuil et exemples. Rien n'est publié ni modifié.

### Corrigé
- Les champs de « Ressemble à… » s'affichaient sous leur nom technique (examples, threshold…) : ils ont maintenant
  leur libellé dans les 7 langues.

## [3.9.0] - 2026-11-17

### Ajouté
- **Classer dans un dossier** : choix de l'**adresse** (par défaut la boîte où l'e-mail est arrivé ; une autre adresse
  partageant le même compte, un alias), et la liste des **dossiers du module déjà sur cette adresse** à toucher pour
  choisir ; un nouveau nom est créé avec le **préfixe** configurable (« [WP] » par défaut, lien vers le réglage) au
  premier e-mail rangé. Seuls les dossiers portant le préfixe sont utilisés, jamais les autres.
- **Barre de l'élément choisi** sous la grille : **Supprimer** et **Réglages** ; au doigt, un appui long sur un bloc
  permet ainsi de le supprimer.
- **Tickets existants** d'un flux jamais publié : bouton **Publier maintenant** (ses problèmes indiqués s'il ne peut pas
  l'être) au lieu du seul message.

### Corrigé
- Au doigt, toucher « + » ouvrait la liste des blocs, mais le clic envoyé ensuite par le navigateur tombait dessus : un
  bloc s'ajoutait ou la liste se fermait sans laisser le temps de choisir. La liste reste maintenant ouverte.

## [3.8.0] - 2026-11-16

### Ajouté
- **Schéma en grande grille** : tous les flux dessinés à la fois, chacun dans son cadre, rangés en lignes ; le flux
  ouvert se modifie, un clic sur un autre l'ouvre sans que la grille bouge. **Afficher sur la grille** : tous les flux,
  les flux cochés ou le flux ouvert seul. La grille prend la hauteur de l'écran, la barre d'outils tient sur une ligne.
- **Côtés repliables** : à gauche la liste des flux, à droite les réglages du flux (nom, boîtes, une fois par ticket,
  actif, tickets existants, supprimer) puis du bloc choisi ; boutons **☰ Flux** / **⚙ Réglages** sur la grille pour les
  rouvrir, choix mémorisé dans le navigateur ; plus étroits en mode agrandi. Tablette tenue droite et téléphone : les
  côtés s'ouvrent par-dessus la grille, repliés au départ.
- **Flux cochés traités ensemble** : **Publier (n)**, **Activer**, **Désactiver**, **Supprimer (n)** ; les refusés
  sont nommés.
- **Après l'import d'un pack** : publier, activer, et appliquer aux tickets déjà là (période, statuts ; un traitement
  par lots par flux, confirmation obligatoire si le pack supprime des tickets ou envoie des notifications push).

### Modifié
- Le zoom va plus loin en arrière pour voir beaucoup de flux ; l'aide de l'éditeur est dans le panneau des réglages.

## [3.7.0] - 2026-11-16

### Ajouté
- **Liste des flux** dans le Schéma (à la place de la liste déroulante) : tous les flux visibles en même temps, avec
  leur version publiée et leur état ; un clic ouvre un flux. Une case par flux et **Tout** pour tous les choisir ;
  **Supprimer (n)** supprime les flux cochés ensemble, après confirmation (tickets en attente sortis du flux, versions
  effacées). Sur téléphone, la liste est repliée sous son titre, qui nomme le flux ouvert.

## [3.6.2] - 2026-11-16

### Corrigé
- **Chargement d'un pack de flux sans doublon** : un flux déjà présent est reconnu par son pack d'origine, sinon par
  son nom (pack réexporté sous un autre nom, flux créé ici puis exporté, réponse de l'assistant IA collée deux fois).
  Identique : inchangé ; différent : brouillon mis à jour, **boîtes conservées** (elles étaient remplacées) ;
  manquant : créé. L'aperçu et le message final indiquent créés / mis à jour / inchangés.
- L'**accueil** affichait « Aucun pack importé » alors que des flux venaient d'un pack : il liste maintenant les flux
  par pack d'origine (sous le nom du pack, puis « Flux créés ici »), en premier, chacun s'ouvrant dans le Schéma, avec
  **Exporter ce pack** (page d'export avec ces flux cochés).

### Modifié
- Assistant IA : libellés clarifiés, « Votre demande à l'IA (le « prompt », ajouté au dossier) » et « Coller la
  réponse de l'IA » (ce que l'IA répond au dossier de l'étape 1).
- Nouvelle colonne `pack_name` (migration automatique).

## [3.6.1] - 2026-11-15

### Modifié
- **Un seul onglet « Schéma »** : avec le moteur interne (administrateurs), il ouvre directement l'éditeur des flux ;
  l'onglet « Flux » disparaît. Sans Workflows, l'ancien « Schéma » des règles ne pouvait pas fonctionner et renvoyait
  vers « Flux », comme « Importer un pack » et « Exporter » : chaque onglet mène maintenant **directement** à sa page
  (import et export des packs de flux), sans redirection.
- Avec Workflows installé, les onglets Importer / Exporter restent ceux des règles ; l'import et l'export des packs
  de flux et le **schéma des règles Workflows** sont accessibles par des boutons en haut de l'éditeur.
- **Exporter** les flux ouvre une page : flux à inclure (tous cochés), nom du pack, puis **Télécharger** (au lieu d'un
  téléchargement immédiat).
- Les agents (non administrateurs) gardent le schéma des règles, comme avant.

## [3.6.0] - 2026-11-15

### Ajouté
- Éditeur des flux, **souris** : clic gauche glissé pour se déplacer (ou déplacer les blocs) ; **clic droit pour
  choisir** un bloc, encore un clic droit pour en ajouter ou en retirer ; clic droit glissé sur le fond = **rectangle
  de sélection**. Les blocs choisis se déplacent et se suppriment ensemble ; Ctrl+A choisit tout, Échap annule. Le
  menu du navigateur n'apparaît plus sur le schéma.
- **Agrandir / Réduire** : l'éditeur sur toute la page (Échap pour revenir).
- **Au doigt** (téléphone, tablette) : un doigt pour se déplacer, **deux doigts pour zoomer**, appui long pour choisir
  un bloc ou tracer un rectangle ; ronds de sortie et « + » plus faciles à toucher. En plein écran sur téléphone, la
  barre tient sur une ligne qui défile (Réduire et Tout voir en tête) et le panneau n'apparaît que pour un bloc choisi.

### Corrigé
- Sur téléphone, le dessin du schéma ne couvrait que le haut de son cadre (150 px) : un doigt posé plus bas ne
  faisait rien, et le zoom à deux doigts ne marchait pas.

## [3.5.1] - 2026-11-15

### Corrigé
- Une **ligne jaune vide** apparaissait en haut des pages quand le module Workflows est absent et le moteur interne
  activé : les remarques sur Workflows étaient masquées, mais pas leur cadre. Le cadre n'est plus affiché s'il n'a
  rien à montrer.

### Modifié
- Import d'un pack de flux : les boîtes se choisissent avec des **cases à cocher** (et non plus une liste à
  sélection multiple, peu pratique au doigt) ; titre clarifié « Boîtes où les flux s'appliquent ». Aucune cochée =
  toutes les boîtes, y compris celles créées plus tard.

## [3.5.0] - 2026-11-15

### Ajouté
- **Appliquer un flux aux tickets déjà là** (moteur interne) : bouton **Tickets existants** dans l'éditeur, et
  question posée après chaque publication.
  - **Tickets existants** : ceux des boîtes du flux sur une période (7 à 365 jours ou tous) et avec les statuts
    choisis passent dans le flux, comme si leur e-mail venait d'arriver (« une fois par ticket » respecté).
  - **Tickets en cours** : ceux qui attendent dans une ancienne version continuent dans la nouvelle, au même bloc.
  - Estimation (nombre, durée) et **simulation à blanc** sur 20 tickets avant de lancer ; confirmation obligatoire
    si le flux supprime des tickets ou envoie des notifications push.
  - Traitement **par lots échelonnés** (10 à 500 tickets toutes les 1 à 60 min) par la tâche planifiée, avec
    progression, pause, reprise et arrêt ; un seul traitement à la fois par flux ; entrées dans l'Historique.
  - Nouvelle table `workflowpacks_flow_reapply` (migration automatique).

## [3.4.1] - 2026-11-15

### Modifié
- Packs de flux : **plus de limite sur le nombre de flux** (elle était de 50). Seule la taille du fichier reste
  limitée, à 8 Mo au lieu de 512 Ko, pour la mémoire du serveur ; au-delà, un message clair propose de découper le
  pack.
- Un identifiant de pack ou de flux mal écrit (majuscules, espaces, accents) ou absent est **corrigé
  automatiquement** au lieu de refuser le pack ; deux flux avec le même identifiant reçoivent `-2`, `-3`…
- Messages d'erreur séparés : « aucun flux », « fichier trop gros » (l'ancien message mélangeait plusieurs cas).

### Tests
- Tests réels du bloc « Notification push » avec le **vrai module WebPush** (branche principale 1.7.0 et release
  1.2.0) : `tests/integration/webpush/run.sh`. Un faux appareil reçoit la notification réellement chiffrée et
  signée par WebPush, la déchiffre et vérifie titre, texte et lien ; heures calmes (1.7.0) ; module désactivé.

## [3.4.0] - 2026-11-15

### Ajouté
- Bloc **Notification push** pour le moteur interne, avec le module
  [WebPush](https://github.com/altmenorg/freescout-webpush) : notifie l'assigné, les utilisateurs de la boîte ou un
  utilisateur choisi, sur leur téléphone ou ordinateur. Titre (vide = sujet du ticket) et texte avec variables.
  Les heures calmes de WebPush sont respectées ; rien n'est envoyé en simulation. Sans WebPush, le bloc prend sa
  sortie « erreur ». Workflow Packs n'envoie rien lui-même : il passe par le module WebPush.
- Bloc **Supprimer le ticket** : place le ticket dans le dossier **Supprimés**, comme le bouton « Supprimer » de
  FreeScout (récupérable depuis ce dossier tant qu'il n'est pas vidé ; jamais de suppression définitive). C'est le
  dernier bloc de sa branche. Rien n'est supprimé en simulation. Les règles Workflows « Déplacer vers Supprimés »
  peuvent désormais être converties en flux.

## [3.3.1] - 2026-11-15

### Modifié
- Sans le module Workflows, moteur interne activé : plus de message « Les packs de règles Workflows ne sont pas
  disponibles » sur la page des packs. Les onglets **Importer**, **Exporter** et **Schéma** mènent à leur équivalent
  pour les flux (import et export des packs de flux, éditeur des flux) au lieu d'un avertissement. Une seule ligne
  discrète reste tant que d'anciens packs de règles sont listés, pour indiquer qu'on peut les retirer. Les
  utilisateurs sans accès aux flux (non-administrateurs) gardent l'explication.

## [3.3.0] - 2026-11-15

### Ajouté
- **Retirer un pack sans le module Workflows** : sur la page des packs, « Retirer le pack » (administrateurs) efface
  en un clic, après confirmation, ce que Workflow Packs garde du pack (fiche, liens vers ses règles, ressources), avec
  une **sauvegarde téléchargeable** comme pour un retrait normal. Les règles éventuellement restées dans les tables de
  Workflows ne sont pas touchées (le module n'est pas là pour les supprimer proprement). Avec Workflows, le retrait
  habituel (aperçu, règles supprimées, sauvegarde) reste utilisé.
- **Packs de flux** pour le moteur interne (format `workflowpacks-flows`) : Flux › **Importer un pack de flux**
  (pack fourni, fichier ou JSON collé) → aperçu (chaque flux : créé ou mis à jour, problèmes à régler, paramètres du
  pack, boîtes) → flux enregistrés **en brouillon, désactivés**. Un nouvel import du même pack met à jour les
  brouillons sans toucher à la version publiée. **Exporter les flux** télécharge les flux comme pack (sans les
  boîtes, propres à l'installation). Pack fourni : **Tri des e-mails (multilingue) — flux**, équivalent des règles
  du pack Workflows du même nom (le transfert des alertes de sécurité n'existe pas encore comme bloc : la note le
  rappelle).
- Variables `{{mailbox.name}}` et `{{mailbox.email}}` dans les textes des blocs (notes).

### Modifié
- Le **moteur de flux interne est activé par défaut** et n'est plus marqué « expérimental ». Sans flux actif, il ne
  fait rien (aucune requête pour un événement qu'aucun flux n'écoute). Une installation qui l'avait désactivé
  explicitement le garde désactivé. L'onglet s'appelle désormais **Flux**.

## [3.2.2] - 2026-11-15

### Corrigé
- Workflows absent et **moteur interne désactivé** : l'ancien message rouge « ne peut pas fonctionner » s'affichait
  encore. Il est remplacé par le message clair, qui indique d'activer le moteur interne (Gérer › Paramètres ›
  Workflow Packs) pour utiliser le module sans Workflows. Seul un vrai problème (PHP, tables du module) garde le
  message rouge.

## [3.2.1] - 2026-11-15

### Corrigé
- **Sans le module Workflows**, Workflow Packs n'est plus bloqué quand le moteur de flux interne est activé :
  Flux, Assistant IA et agents connectés fonctionnent ; Workflows manquant devient une remarque du diagnostic (plus
  un blocage, aussi pour UpdateGuard). Les pages des packs de règles Workflows (import, export, schéma des règles)
  affichent un message clair au lieu de « ne peut pas fonctionner », avec un lien vers Flux et l'Assistant IA.
- Moteur désactivé et Workflows absent : le message explique qu'il suffit d'activer le moteur interne (Gérer ›
  Paramètres › Workflow Packs), avec un lien direct.

## [3.2.0] - 2026-11-15

### Ajouté
- **Agents IA connectés** ([docs/agent-api.md](docs/agent-api.md)) : Assistant IA › Agents IA connectés.
  - **Jeton** `wpa_…` affiché une seule fois (seule son empreinte SHA-256 est gardée), **usage unique** (fermé après
    la première proposition ou au bout de 24 h) ou **continu**, **Déconnecter** d'un clic.
  - Réglages par agent : boîtes lisibles, **anonymisation activée par défaut** (option pour la désactiver), **droit
    de publier désactivé par défaut** (activé : seulement ses propres flux).
  - **API JSON** `workflow-packs/api/v1` : `me`, `catalog`, `tickets`, `flows` (lire, proposer en brouillon, modifier
    les siens), `test` (à blanc), `publish` (si autorisé). 60 requêtes par minute, aucune session ni cookie,
    réponses d'erreur à code stable.
  - Chaque action de l'agent est notée dans l'**Historique** (type « Agent IA connecté »).

## [3.1.0] - 2026-11-14

### Ajouté
- **Assistant IA, sans connexion** ([docs/assistant-ia.md](docs/assistant-ia.md)), onglet visible quand le moteur
  interne est activé (administrateurs). Le module n'envoie rien.
  - **Dossier pour l'IA** : tickets choisis (boîtes, jours, nombre, mot du sujet) avec leur **numéro** et leur texte,
    **anonymisés par défaut** (adresses, téléphones, IBAN, liens, longs numéros masqués) ; option explicite pour ne
    pas anonymiser, avec avertissement. Avec la demande, les règles de la réponse, le catalogue des blocs et un
    exemple. Copier ou télécharger (.json).
  - **Réponse de l'IA** vérifiée (problèmes avec bloc et champ), puis **jouée à blanc sur les tickets du dossier**,
    résultat groupé par effet ; **création du flux en brouillon désactivé**, ouvert dans l'éditeur.
- **Bloc « Ressemble à… »** : le ticket comparé à des tickets d'exemple désignés par leur numéro, **en local**
  (vecteurs du sujet et du texte, jamais envoyés), seuil en %, poids réglables, variable `similar.score`.

### Sécurité
- Plus aucun motif surveillé par UpdateGuard : le pack passe d'une étape d'import à l'autre en texte simple (plus de
  `base64_decode`) et les appels de fonctions sont directs (plus de `call_user_func`). Aucun appel réseau.

## [3.0.1] - 2026-11-14

### Modifié
- **Nouveau style des pages** (guide « G », [docs/style.md](docs/style.md)) : toute information a une forme (pastille,
  tuile, icône, interrupteur), rien n'est retiré.
  - En-tête : titre, description et actions à droite (un seul bouton principal) ; onglets en contrôle segmenté.
  - **Packs** : une section par pack (pastilles identifiant / version / nombre de règles), description découpée en
    blocs d'info, règles avec catégorie et **SI → ALORS** côte à côte.
  - **Boîtes d'une règle** : une ligne de synthèse (conformes, actives / inactives, tickets existants) écrite une
    seule fois, puis une **tuile par boîte** (initiales colorées, point d'état, horloge « tickets existants »,
    interrupteur). Couleur d'une boîte calculée à partir de son nom.
  - **Barre de sélection flottante**, seulement quand une règle est cochée, avec « Vider la sélection ».
  - Boutons et zones cliquables de 44 px, infobulles et libellés accessibles pour les boutons sans texte, pas
    d'emoji.
- **Pages allégées** (rien de supprimé, replié) : Historique (introduction en description, diagnostic du rangement
  replié sauf problème), Export, Import (packs du module en premier, « Coller le JSON » replié, aide sous « En savoir
  plus », description en blocs d'info, règles du pack repliées).

## [3.0.0] - 2026-11-14

Mise à jour sans risque : le moteur est **désactivé par défaut**. Tant qu'il l'est, rien ne change (règles
Workflows, schéma, packs, historique) ; les nouvelles tables sont seulement créées.

### Ajouté
- **Moteur de flux interne (expérimental à l'époque)** — [docs/flux-internes.md](docs/flux-internes.md). À activer dans Gérer ›
  Paramètres › Workflow Packs ; ajoute l'onglet **Flux (expérimental)** (administrateurs).
  - **Éditeur visuel** : chaque bloc est un objet ; « + » sur une sortie pour ajouter le bloc suivant, tirer une
    sortie vers un bloc pour relier, Suppr pour supprimer ; enregistrement au fil des modifications.
  - **« Si… » intelligent** : seulement les variables des blocs placés avant, opérateurs selon leur type, valeurs
    d'une liste fermée proposées.
  - **Publication par versions** (vérifiée), activation, une fois par ticket, boîtes du flux.
  - **Simulation** façon Packet Tracer : un point suit les liens, chaque étape avec sa décision et ses changements,
    horloge virtuelle ; rien n'est écrit ni envoyé.
  - **Parcours d'un ticket** : blocs traversés allumés, liens colorés, vrais changements, attente en cours.
  - Un ticket arrêté sur une erreur est noté dans **Historique** (type « Flux (moteur interne) »).
- **Fondations du moteur de flux interne** (docs/moteur-autonome.md) : tables des flux, versions publiées, tickets en
  cours et historique ; moteur (bloc après bloc, attentes réveillées chaque minute par lots, réclamation sûre pour
  MySQL et PostgreSQL, nouvelles tentatives, sortie « erreur ») ; registre de blocs extensible (filtre
  `workflowpacks.blocks`) ; validation avant publication (déclencheur unique, blocs reliés, boucle sans attente,
  variables fournies par un bloc précédent) ; simulation en mémoire avec horloge virtuelle.
- Premiers blocs : Nouvel e-mail, Si…, Attendre, Statut, Assigner, Note interne, Classer dans un dossier (textes dans
  les 7 langues).

## [2.17.0] - 2026-11-14

### Ajouté
- **Guide pour l'IA** ([docs/guide-ia.md](docs/guide-ia.md)) : tout ce qu'une IA doit savoir pour écrire un pack
  importable du premier coup (format, conditions, actions, minuteurs, variables, erreurs fréquentes, modèle de
  demande), et comment lui faire corriger les erreurs signalées (chemin + code).
- **Projet de moteur autonome** ([docs/moteur-autonome.md](docs/moteur-autonome.md)) : document de conception de la
  version 3 (chaque bloc = un objet, sans Workflows).

### Modifié
- **Aperçu d'un grand pack plus lisible** : filtre des règles, « Tout déplier / Tout replier » (mémorisé), détail
  des règles replié sauf celles à regarder (conflit, blocage, mise à jour), qui restent dépliées et marquées, matrice
  avec en-tête fixe au-delà de 10 règles.
- **Barre d'action toujours visible en bas de l'écran** (aperçu d'import, d'export et de retrait) : plus besoin de
  défiler jusqu'en bas pour confirmer. Sur téléphone, elle se place au-dessus d'une barre d'onglets du thème (Refresh).

### Compatibilité
- **FreeScout 1.8.250** vérifié. Cette version active `AuthenticateSession` (une session liée au mot de passe de
  l'utilisateur) : aucun effet sur le module ; les tests qui changent d'utilisateur repartent d'une nouvelle session,
  comme un nouveau navigateur.

## [2.16.0] - 2026-11-05

### Modifié
- **Vue d'ensemble = vrai diagramme de flux.** Les règles (⚡) des mêmes e-mails (mêmes conditions, même boîte) se
  suivent — à l'arrivée, puis par délai, puis dans l'ordre de Workflows ; une branche part de la ligne principale.
  La première règle part de sa boîte, chaque suivante vient **après le dernier bloc de la précédente** et y est
  reliée : Support → ⚡ classement → [WP] Factures → ⚡ relance → ⧗ Après 1 jour → Fermé. Avant, toutes les règles
  restaient dans la colonne de gauche, reliées seulement à leur boîte. Une règle après une autre a des blocs à elle ;
  son lien à la boîte reste connu (panneau, mise en évidence) sans être dessiné.
- Les blocs d'un flux enregistrés à gauche de leur bloc parent sont replacés après lui au chargement (une fois,
  enregistré) ; les autres blocs gardent leur place.

## [2.15.0] - 2026-11-05

### Modifié
- **Menus en cascade, comme les menus contextuels de Windows** (clic droit, « + », « Ajouter un bloc ») : une entrée
  marquée › ouvre sa liste à côté d'elle (survol ou clic, à gauche s'il n'y a pas de place, flèches du clavier), au
  lieu de listes dépliées les unes dans les autres avec plusieurs barres de défilement. Sur un téléphone, la liste
  remplace le menu, avec « ‹ titre » en haut pour revenir.

## [2.14.0] - 2026-11-05

### Ajouté
- **Minuteur : plus de délais et durée personnalisée.** 15 et 30 minutes ; 1, 2, 4 et 12 heures ; 1, 2, 3, 7, 14, 30
  et 90 jours, et **Durée personnalisée…** : l'éditeur s'ouvre sur le champ de la durée (nombre + minutes / heures /
  jours). Le nom de la règle (« Après 3 heures ») suit la durée tant qu'il n'est pas changé à la main.

### Modifié
- Panneau d'un bloc, « Ajouter un minuteur » : un seul délai déplié à la fois, la liste reste courte.

## [2.13.0] - 2026-11-05

### Modifié
- **Minuteur relié à son bloc parent** (vue d'ensemble) : un minuteur créé depuis un bloc (dossier « Factures »,
  statut…) part de ce bloc — [WP] Factures → ⧗ Après 1 jour → Fermé → ⧗ Après 2 jours → … Ce qui suit un minuteur est
  fait de blocs à lui, à sa droite, jamais partagés avec d'autres règles ; supprimer l'un d'eux retire cette action.
- **Téléphone — panneau à poignée** : glisser vers le bas pour voir le schéma (le bloc reste sélectionné), vers le
  haut pour la moitié ou tout l'écran ; toucher la poignée replie / déplie. Le bloc sélectionné est agrandi s'il était
  trop petit.
- **Téléphone — Agrandir** : les boutons sur une seule ligne, le schéma prend tout l'écran et est recadré (aussi sur
  ordinateur).

### Corrigé
- **Téléphone — appui long** sur un bloc : le menu (avec **Supprimer**) ne s'affichait pas. Le défilement vers le
  schéma utilisait `window.scrollTo`, remplacé par FreeScout (erreur), puis refermait le menu.

## [2.12.0] - 2026-11-04

### Modifié
- **Minuteur = un bloc à part dans la chaîne** (vue d'ensemble) : règle → ⧗ minuteur → statut / dossier…, le
  minuteur dans sa propre colonne ; les liens partent du minuteur, plus de la règle (fini les lignes croisées).
- **« Ajouter un minuteur » en deux choix** : le délai, puis ce qui suit (statut, assigner, note, dossier…) ; plus de
  minuteur créé sans action.
- **Nouveaux blocs dans leur colonne** : la nouvelle règle dans la colonne des règles, son minuteur dans celle des
  minuteurs, sur la ligne du bloc d'origine — ou la ligne libre la plus proche, ensemble, alignés.

## [2.11.0] - 2026-11-04

### Ajouté
- **Bouton « Agrandir »** du schéma : la barre d'outils et le schéma prennent toute la page ; « Réduire » (ou
  Échap, une fois la sélection levée) revient à la vue normale.

### Modifié
- **Les blocs ajoutés depuis un bloc se placent à sa droite** (« + », « Ajouter un bloc », vue d'ensemble) : sur la
  même ligne, dans l'ordre — un bloc ajouté depuis le bloc 2 vient après le 2 ; si la place est prise, un peu plus
  à droite sur la même ligne. Les autres blocs ne bougent pas. (La vue flux les place déjà dans l'ordre de la chaîne.)

## [2.10.0] - 2026-11-04

### Ajouté
- **Blocs minuteur dans la vue d'ensemble** : une règle avec un délai (« après 30 jours », « en attente depuis 7
  jours »…) a son bloc sablier, relié à la règle. Son panneau montre la règle ; son « + » ajoute une action après
  ce délai ; le supprimer retire le délai (validation, « Annuler la suppression »).
- **« Ajouter un minuteur »** dans le « + », le panneau et « Ajouter un bloc » : après 15 minutes, 1 heure, 1 jour,
  2 jours, 7 jours ou 30 jours — une nouvelle règle des mêmes e-mails (conditions de la règle, du flux ou de la
  boîte), l'action étant choisie dans l'éditeur.

## [2.9.2] - 2026-11-04

### Corrigé
- **Supprimer le bloc d'une règle de pack supprimée dans Workflows** (bloc « manquante ») affichait « Rien à
  supprimer » : son lien est maintenant retiré, le bloc disparaît (avec « Annuler la suppression »). Une règle sans
  délai ni action (son seul bloc dans la vue flux) se supprime aussi. Une règle d'une boîte non gérée donne un
  message sur les droits au lieu de « Rien à supprimer ».
- Une règle de pack supprimée dans Workflows restait invisible dans le schéma quand sa boîte n'avait plus aucune
  règle.

### Amélioré
- **Chargement du schéma plus rapide** (mesuré sur 14 boîtes, 284 règles) :
  - serveur : 600 → 100 ms, 563 → 26 requêtes (réglages des dossiers et noms des personnes lus une fois) ;
  - les flux (71 % des données) ne sont envoyés que pour la vue flux et la simulation ;
  - navigateur : mini-carte en un tracé par type de bloc, « + » dessiné seulement sur le bloc sélectionné, pas de
    poignées inutiles en vue flux (1341 blocs : 1093 → 820 ms).

## [2.9.1] - 2026-11-04

### Corrigé
- **Supprimer un bloc de nettoyage** (« Corbeille après 30 jours ») affichait « Rien à supprimer » : ce bloc est le
  nettoyage automatique du dossier, un réglage et non une action de règle. Sa suppression arrête maintenant ce
  nettoyage (administrateurs), après validation, avec sauvegarde et « Annuler la suppression ».

## [2.9.0] - 2026-11-04

### Ajouté
- **Supprimer un bloc** du schéma : touche **Suppr** (ou Retour arrière) sur la sélection, **clic droit › Supprimer**,
  ou bouton « Supprimer » du panneau.
  - Une **demande de validation** liste ce qui va changer, avec des avertissements (« n'aura plus de délai : elle
    s'appliquera dès l'arrivée », « n'aura plus de condition : elle s'appliquera à tous les e-mails »).
  - Règle : supprimée (une règle de pack : dans chaque boîte gérée, avec ses liens) ; action : retirée de sa règle
    (la règle part si elle n'a plus d'action) ; minuteur : délai retiré ; condition ou test de la vue flux : retiré
    de toutes les règles du flux concernées ; destination (dossier, statut, personne…) : les actions qui y envoient.
  - **Sauvegarde avant la suppression** et bouton **« Annuler la suppression »** (règles recréées, liens au pack
    compris). Une seule transaction ; rien n'est fait si les règles ont changé entre la question et la validation.
  - Boîtes et packs non supprimables ici (un pack : « Retirer le pack »). Seules les boîtes que l'utilisateur gère.

## [2.8.0] - 2026-11-04

### Ajouté
- **Bouton « Ajouter un bloc »** dans la barre d'outils du schéma : ce qui peut suivre le bloc sélectionné (statut du
  ticket, minuteur, toute action), ou une nouvelle règle de la boîte quand rien n'est sélectionné.
- **« Définir le statut du ticket »** en tête de chaque liste (« + », panneau, « Ajouter un bloc ») : Actif, En
  attente, Fermé, Spam — ajouté à la règle, ou nouvelle règle pour les nouveaux e-mails sur une boîte.

## [2.7.0] - 2026-11-04

### Ajouté
- **Toutes les actions dans le « + »** des blocs du schéma (vue d'ensemble et vue flux) : la palette reprend chaque
  action du catalogue de Workflows de la boîte — statut (Actif, En attente, Fermé, Spam), assigner, déplacer vers une
  autre boîte, note, réponse, transfert, e-mail au client, notification, supprimer, tag, dossier e-mail, actions des
  autres modules.
  - **Dossier, statut, personne, tag…** : « Ajouter une action à “règle” » (la règle qui envoie là) ; plusieurs
    règles : on choisit d'abord la règle.
  - **Règle, étape de la vue flux** : « Ajouter une action… ».
  - **Boîte** : « Nouvelle règle pour les nouveaux e-mails, qui… ».
  - L'éditeur s'ouvre avec l'action ajoutée, pour vérifier puis enregistrer.
- Le menu reste dans la fenêtre quand une liste s'ouvre.

## [2.6.0] - 2026-11-04

### Ajouté
- **Vue flux : un bloc par condition**, au lieu d'un seul bloc « Conditions » : statut du ticket (cercle de la
  couleur du statut : Actif, En attente, Fermé, Spam), assigné à (silhouette), sujet / texte / destinataire (lignes de
  texte), client (arobase), nouveau / réponse (boîte de réception), tag (étiquette), date (sablier), autre module
  (entonnoir). Les blocs de condition ont un contour pointillé, les actions un contour plein. La simulation allume
  chaque bloc de condition traversé. Toujours aucun chevauchement (vérifié sur 200 jeux de tailles aléatoires).
- **« + » d'un bloc de condition** : « Si “En attente” depuis 7 jours : marquer comme fermé » (bloc de statut puis
  minuteur).

## [2.5.0] - 2026-11-04

### Modifié
- **Export simplifié** : la page Export montre d'abord **vos packs**, un par carte :
  - les règles **nouvelles** (créées hors pack dans les boîtes du pack) et **modifiées** depuis l'import, cochées ;
  - **« Mettre à jour le pack »** : elles y sont ajoutées (sans être recréées), l'adresse de la boîte devient
    `{{mailbox.email}}`, la version augmente (1.0.0 → 1.0.1) ;
  - **« Télécharger »** : le fichier du pack, tel qu'il est enregistré ; « À jour » quand rien n'est nouveau.
  - Créer un nouveau pack ou choisir les règles une par une : replié sous la liste des packs.

## [2.4.0] - 2026-11-04

### Ajouté
- **Tester une règle** : dans la simulation, « Règle à tester » (ou le bouton « Tester cette règle » du panneau d'une
  règle) prépare tout seul un faux e-mail qui remplit ses conditions : texte exact, contenu, début, fin, exemple tiré
  d'une expression régulière (vérifié), assigné / répondu, tags, statut de départ. Ce qui ne peut pas être rempli
  (condition d'un autre module, « réponse »…) est listé. Le résultat dit si la règle testée s'est appliquée, quand, ou
  pourquoi pas.
- **Statut de départ** du faux e-mail (Actif, En attente, Fermé, Spam).

### Modifié
- **Pages allégées** :
  - règle (accueil, import, export) : un résumé d'une ligne « SI … → ALORS … » avec les noms lisibles, le détail
    complet replié sous « Détails » (déplié pour les différences d'un import) ; actions sensibles toujours visibles ;
  - Diagnostic : seules les vérifications en échec sont affichées, les autres et les versions sont repliées, comme
    le catalogue de Workflows ;
  - Schéma : l'aide (souris, tactile) repliée ; formulaire de simulation plus court (options supplémentaires repliées) ;
  - Export : les informations du rapport repliées, les avertissements (secrets, références locales) restent visibles.

## [2.3.0] - 2026-11-04

### Ajouté
- **Vue flux** du schéma (bouton « Vue flux », gardé par utilisateur) : les règles de chaque boîte en chaînes de
  blocs, **un bloc par instruction** : conditions (entonnoir), test (branche qui se sépare), minuteur (sablier),
  statut, dossier e-mail, personne, note, envoi… Voir [docs/flux.md](docs/flux.md).
  - Les règles aux mêmes conditions forment un flux : « après 1 heure → dossier », « après 2 jours → fermé » sont une
    seule chaîne. « Nouveau » est le départ de la chaîne.
  - Placement en grille : **deux blocs ne se chevauchent jamais** (vérifié sur 200 jeux de tailles aléatoires).
  - Le **+** d'un bloc propose la suite : minuteur puis « marquer comme fermé » ou « classer dans un dossier »,
    branches « pas de réponse » / « non assigné », « ici aussi : marquer comme fermé ». L'éditeur s'ouvre pré-rempli.
- **Conditions « Assigné à quelqu'un » et « Une réponse a été envoyée »** (oui / non), évaluées par Workflows :
  les blocs « test » de la vue flux. Exemple : « après 2 jours, pas de réponse → fermer ».
- **Simulation** (bouton « Simuler ») : un faux e-mail passe dans les règles d'une boîte, **en mémoire** ; son
  parcours s'allume dans la vue flux (animation le long des liens), puis le panneau donne le verdict, pour chaque
  règle appliquée le moment (à l'arrivée, +1 h, +2 j) et ce qui a changé, les règles non appliquées avec la raison,
  et l'état final du ticket.
  - **Rien n'est écrit ni envoyé** : aucun ticket, client, message, tâche ni dossier ; les envois sont affichés
    « serait envoyé à … » ; l'historique ne garde que les compteurs.
  - Une condition ou une action d'un autre module n'est jamais devinée : « non simulée ».
- **Nouvelle règle et pack** : une règle hors pack dans une boîte qui utilise un pack propose « Ajouter au pack … »
  (page Export pré-remplie) ; après l'enregistrement d'une nouvelle règle, le schéma la sélectionne.
- Contrôle de santé (`workflowpacks:health`, utilisé par UpdateGuard) : nouveaux fichiers et classes vérifiés.

## [2.2.0] - 2026-10-16

### Ajouté
- **« Regrouper dans un pack »** (page Export) : les règles choisies deviennent les règles d'un pack, **nouveau ou
  existant**, sans être recréées ni modifiées. Le pack est enregistré et chaque règle y est reliée là où elle est ;
  il se télécharge ensuite comme un pack importé, et le ré-importer ici ne crée aucun doublon.
  - **Version actuelle** des règles, modifications faites depuis un import comprises ; mêmes remplacements par des
    variables et même retrait des secrets que l'export.
  - **Même règle dans plusieurs boîtes** : une seule règle du pack, reliée dans chaque boîte.
  - **Pack existant** : ses autres règles, paramètres et ressources sont gardés, une de ses règles choisie est
    remplacée par sa version actuelle, sa version augmente automatiquement (1.4.2 → 1.4.3).
  - **Règle d'un autre pack** : elle change de pack ; un pack resté sans règle disparaît.
  - **Vérification** par le moteur d'import : le pack recompilé doit redonner chaque règle telle quelle ; sinon
    l'aperçu le signale et la règle apparaîtra « modifiée localement ».
  - **Droits** : un pack existant n'est modifiable que par un administrateur, ou si toutes ses règles sont dans des
    boîtes que l'utilisateur gère.
  - **Historique** : nouvel événement « Regroupement dans un pack ».
- **Schéma** : boutons « Regrouper dans un pack » (plusieurs blocs sélectionnés) et « Regrouper les règles de cette
  boîte » (panneau d'une boîte).

### Modifié
- **Page Export simplifiée** : règles de **toutes les boîtes** à la fois (par défaut) avec « Tout sélectionner »,
  choix « Nouveau pack » / « Ajouter à un pack existant », clé du pack créée à partir du nom, remplacements par des
  variables repliés (facultatifs).
- **Export d'une règle de pack modifiée** : c'est sa version actuelle qui est exportée, et non plus le modèle
  d'origine du pack (les modifications étaient perdues).

## [2.1.1] - 2026-10-15

### Corrigé
- **Schéma sur téléphone : le panneau d'un bloc était trop petit pour travailler.** Il était collé au bas du schéma, qui
  est en partie sous la barre d'outils, et il n'en restait qu'une bande d'environ 110 px visible à l'écran.
  - Le panneau (règle, boîte, dossier, pack, lien, sélection de plusieurs blocs) est maintenant fixé au bas de
    l'**écran** et en prend 58 %. La page défile jusqu'au schéma, et le bloc choisi reste visible au-dessus du panneau.
  - Bouton **« Plein écran »** dans la barre de titre du panneau, pour lui donner tout l'écran (et « Réduire »). La
    barre de titre reste visible quand on fait défiler le panneau.
- **Éditeur de règle sur téléphone** : il prend tout l'écran ; son titre et les boutons **Annuler / Enregistrer**
  restent toujours visibles. La croix de suppression d'une condition ou d'une action est à droite de sa première ligne,
  au lieu d'occuper une ligne à elle seule.

## [2.1.0] - 2026-10-14

### Ajouté
- **Condition « Tag d'e-mail » des règles**, avec RefreshGlobal 1.11.0 ou plus récent : « a le tag » / « n'a pas le
  tag » `classified_spam`, `inbox`, `spam`, `trash`, `sent`, `drafts`, `archive`, `folder:<nom>` ou un tag de
  l'administrateur.
  - **Dans l'éditeur du schéma** : tags standard et tags déjà vus dans la boîte proposés en boutons sous le champ, et
    saisie libre.
  - **Évaluation** : faite par Workflows lui-même, par son filtre `workflow.check_condition`.
  - **Quand RefreshGlobal pose les tags** d'un e-mail, au même moment que Workflows lance les règles, les règles
    actives qui utilisent la condition sont vérifiées à nouveau pour ce ticket. Jamais deux fois, et jamais sur les
    tickets plus anciens que la règle sans « Tickets existants ».
  - **Dans les packs** : utilisable aussi.
  - **Sans RefreshGlobal** : la condition n'est pas proposée.
- **« + » d'une boîte** : « Règle pour les e-mails classés spam ».

## [2.0.0] - 2026-10-13

### Modifié
- **Les mises à jour passent par FreeScout ou par UpdateGuard.** Workflow Packs ne se met plus à jour lui-même.
  - `module.json` déclare `latestVersionUrl` (`version.txt` de la dernière release) et `latestVersionZipUrl`
    (`WorkflowPacks.zip`) : la page **Gérer › Modules** de FreeScout signale et installe les nouvelles versions.
  - Avec le module [UpdateGuard](https://github.com/00MY00/UpdateGuard), la mise à jour est sécurisée (niveau
    « Full Safe Update ») : SHA-256, sauvegarde, contrôle de santé, observation et retour arrière automatique.
    Les migrations sont déclarées compatibles avec la version précédente.
  - Les réglages et le Diagnostic indiquent qui met le module à jour. Sans UpdateGuard, son installation est
    conseillée.
  - Chaque release publie `version.txt`, inclus dans `SHA256SUMS`.

### Ajouté
- `php artisan workflowpacks:health [--json]` : contrôle de santé du module installé, utilisé par UpdateGuard et par
  `install.sh`. Il vérifie fichiers, classes, vues, routes, fichiers publics, tables et migrations, en lecture seule,
  et répond avec le code 0 s'il est sain, 1 sinon.

### Supprimé
- La mise à jour intégrée au module, et tout ce qui en dépendait :
  - le bouton « Mettre à jour maintenant » et le bandeau ;
  - la mise à jour automatique quotidienne ;
  - la commande `workflowpacks:update` ;
  - les routes `update-now`, `check-update` et `auto-update`.
  Les options qu'elle enregistrait sont effacées par une migration.

## [1.17.0] - 2026-10-13

### Modifié
- **Schéma : le nettoyage automatique d'un dossier e-mail est un bloc à part.** Le bloc est rouge, avec une
  corbeille, et indique « Corbeille après 30 jours » ou « Suppression après 30 jours ». Il est placé après son
  dossier et relié par un trait rouge en pointillé, avec une entrée dans la légende. Un clic dessus ouvre son réglage.
  Auparavant, le nettoyage n'était qu'un texte dans le bloc du dossier. Le « + » d'un dossier qui a déjà un
  nettoyage ouvre ce bloc.

## [1.16.0] - 2026-10-13

### Ajouté
- **Schéma : « Ajouter une suite », comme les flèches de draw.io.** Un « + » à droite d'un bloc sélectionné propose
  ce qui peut venir après lui. On le retrouve aussi dans le panneau du bloc et dans ses actions rapides.
  - **Boîte :** nouvelle règle, ranger les nouveaux e-mails dans un dossier, fermer les tickets sans réponse depuis
    7 jours.
  - **Règle :** puis ranger dans un dossier, changer le statut ou assigner.
  - **Dossier e-mail :** supprimer ses e-mails après X jours, ranger une partie dans un sous-dossier.
  - **Statut :** mettre à la corbeille après 30 jours sans message du client, fermer après 7 jours sans réponse.

  Seul ce que Workflows propose pour la boîte est listé. L'éditeur s'ouvre pré-rempli, pour vérifier puis
  enregistrer, et une nouvelle règle est créée désactivée. Voir [docs/schema.md](docs/schema.md).
- **Sur téléphone :** quand un bloc est sélectionné, le schéma se décale pour qu'il reste visible au-dessus du
  panneau, avec son « + ».

## [1.15.0] - 2026-10-12

### Ajouté
- **Schéma sur téléphone et tablette :** les gestes tactiles habituels.
  - un doigt pour se déplacer, avec élan après un geste rapide ;
  - deux doigts pour zoomer autour des doigts ;
  - toucher pour sélectionner ;
  - toucher deux fois pour ouvrir (sur le fond : zoomer) ;
  - appui long sur un bloc pour les actions rapides ;
  - appui long sur le fond puis glisser pour un rectangle de sélection ;
  - un bloc sélectionné se déplace avec le doigt ; un bloc non sélectionné déplace la vue, pour pouvoir parcourir un
    schéma plein ;
  - le ● et le coin d'agrandissement d'un bloc sélectionné ont des zones de toucher élargies.
  Voir [docs/schema.md](docs/schema.md#téléphone-et-tablette).

### Modifié
- **Sur téléphone, le panneau d'un bloc s'ouvre en bas de l'écran** (moitié de la hauteur), le schéma reste visible
  au-dessus. Menus, boutons et liens sont plus grands au doigt, et l'aide sous le schéma décrit les gestes tactiles.
- Le double toucher prend l'heure du geste, pas celle de son traitement : une page occupée après le premier toucher ne
  le fait plus manquer.

## [1.14.1] - 2026-10-12

### Modifié
- **Migrations :** une table déjà présente n'est plus recréée, par exemple après une installation interrompue ou une
  base restaurée. Elle est gardée telle quelle, comme le faisait déjà la table des dossiers des tickets.
- **Documentation :** [docs/api-dossiers.md](docs/api-dossiers.md) liste ce que RefreshGlobal 1.8.0 lit de Workflow
  Packs (tables, routes, ordre de suppression), à garder stable.

## [1.14.0] - 2026-10-12

### Ajouté
- **Dossier e-mail actuel de chaque ticket**, gardé sans limite de durée et effacé seulement avec le ticket
  (table `workflowpacks_ticket_folders`, une ligne par ticket).
  - **Mise à jour :** à chaque rangement (déplacé ou déjà en place) et lors du nettoyage automatique du dossier (état
    « retiré par le nettoyage »).
  - **Reprise :** les rangements déjà présents dans l'historique sont repris à l'installation.
  - **Dans le ticket :** une étiquette « [WP] Factures › Fournisseurs » à côté du sujet, avec dans l'infobulle le
    dossier sur le serveur et la date du rangement.
  - **Pour les autres modules (RefreshGlobal…) :** filtres Eventy `workflowpacks.ticket_folder` et
    `workflowpacks.ticket_folders`, action `workflowpacks.ticket_folder_changed`, et la table en lecture seule avec
    des colonnes et des index stables. Voir [docs/api-dossiers.md](docs/api-dossiers.md).
  - **Suppression :** la ligne d'un ticket est effacée avec lui, dans `conversations.before_delete_forever`, après
    les modules qui écoutent cette action (priorité 1000). Une vérification quotidienne efface celles des tickets
    supprimés autrement.

## [1.13.1] - 2026-10-11

### Corrigé
- **E-mails « introuvables » alors qu'ils sont dans la boîte de réception.** Certains serveurs e-mail ne répondent
  pas, ou répondent vide, à la recherche IMAP par Message-ID. Le rangement ne trouvait alors jamais l'e-mail, ni pour
  les nouveaux e-mails ni avec « Ranger à nouveau ».
  - La recherche essaie maintenant trois méthodes, dans le dossier visé, dans les dossiers relevés par FreeScout et
    dans les autres dossiers :
    1. la recherche par Message-ID ;
    2. la même recherche sans jeu de caractères (serveurs Microsoft) ;
    3. la lecture des en-têtes des e-mails reçus autour de la date du ticket, comparés un par un. Rien n'est marqué
       comme lu.
  - Un rangement « introuvable » indique les dossiers où l'e-mail a été cherché.

### Ajouté
- **« Tester la recherche d'un ticket »** (Historique, administrateurs), par numéro de ticket ou avec le lien
  « Tester » d'une ligne « introuvable » ou « échec ». La page affiche :
  - le Message-ID cherché ;
  - chaque méthode essayée dans chaque dossier, avec la réponse du serveur ;
  - le dossier où l'e-mail a été trouvé ;
  - les derniers e-mails de la boîte de réception avec leur Message-ID, pour comparer.
  Rien n'est modifié.

## [1.13.0] - 2026-10-11

### Modifié
- **« Ranger à nouveau dans les dossiers e-mail » range aussi les tickets existants jamais traités.** En 1.12.0, le
  bouton ne reprenait que les tickets déjà traités par les règles : les e-mails arrivés avant les règles, ou pendant
  qu'elles étaient inactives, n'étaient jamais rangés (« 0 e-mail(s) »).
  - Les tickets de chaque boîte sont maintenant examinés en arrière-plan, 100 à la fois. Ceux qui remplissent les
    conditions d'une règle, vérifiées par Workflows lui-même, ou qu'elle a déjà traités sont rangés dans son dossier.
  - « Nouveau » compte comme rempli pour un ticket existant.
  - Si plusieurs règles conviennent, la dernière dans l'ordre de Workflows l'emporte.
  - Seul le rangement est fait.
  - Une ligne par boîte dans l'historique montre l'avancement et le résultat.
- Avec « Tout sélectionner », les règles sans dossier e-mail ne sont plus listées comme des erreurs : elles sont
  comptées dans le message. Les boîtes sans réception IMAP sont signalées une seule fois.

## [1.12.0] - 2026-10-11

### Ajouté
- **« Ranger à nouveau dans les dossiers e-mail »** : remet dans leur dossier `[WP]` les e-mails des tickets déjà
  traités par les règles choisies, par exemple quand ils ont été déplacés depuis. Disponible dans la liste (règles
  cochées) et dans le schéma (menu du clic droit, panneau de la sélection).
  - Seul le rangement est refait : statut, assignation, transfert… ne sont pas relancés, et les compteurs de Workflows
    ne changent pas.
  - L'e-mail est cherché dans la boîte de réception puis dans les autres dossiers du compte, jamais dans la corbeille.
  - Un e-mail déjà dans son dossier ne bouge pas. L'historique indique « rangement relancé » et le dossier où l'e-mail
    a été retrouvé.

### Modifié
- **Import, option « tickets existants »** : l'ancien libellé « Respecter « conversations précédentes » du pack pour
  les nouvelles règles » devient « Appliquer aussi aux tickets existants les nouvelles règles du pack qui le
  prévoient ». Une explication sous la case dit ce qui se passe cochée ou non, la liste des règles du pack concernées
  est affichée, et la case est grisée quand aucune règle du pack ne le prévoit. Le fonctionnement ne change pas
  (7 langues).

### Corrigé
- **Schéma : plus aucun bloc superposé.** Sur un grand schéma (dizaines de règles et de dossiers, ancienne
  disposition enregistrée, blocs agrandis), des blocs pouvaient rester les uns sur les autres. Le placement anti-
  chevauchement est remplacé par une méthode qui se termine toujours sans superposition : 0 sur 200 grands schémas de
  test au lieu de 49, et 10 fois plus rapide. Il s'applique au chargement, après un déplacement et après un
  redimensionnement.

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
