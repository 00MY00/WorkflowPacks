# Moteur de flux autonome — proposition (version 3.0)

> Document de conception, à relire avant de commencer. Rien n'est encore codé.

## 1. En bref

- **Chaque bloc du schéma est un objet indépendant** : déclencheur, condition, attente, action. Le schéma n'est plus
  une *vue* calculée à partir de règles Workflows : **il est le programme**. Ce qu'on voit est exactement ce qui
  s'exécute.
- **Workflow Packs devient autonome** : il a son propre moteur. Le module Workflows (payant) n'est plus nécessaire. Il
  reste utilisable pendant la transition, avec un convertisseur.
- **Beaucoup plus léger que Workflows.** Workflows revérifie régulièrement chaque règle à délai sur les tickets. Notre
  moteur, lui, **retient chaque ticket en attente avec son heure de réveil**. Toutes les minutes, une seule requête
  indexée ressort uniquement les tickets dont l'heure est passée. Sans attente arrivée à échéance, le coût est quasi
  nul.
- Le travail est découpé en **étapes livrables** (2.17, puis 3.0 à 3.6), chacune testée et utilisable. Le nouveau moteur démarre
  derrière une option « expérimental » : rien ne change pour les règles actuelles tant qu'on ne l'active pas.

## 2. Pourquoi

| Aujourd'hui (Workflows) | Conséquence |
|---|---|
| Une règle = conditions + actions, toujours ensemble | Un minuteur ou une action ne peuvent pas exister seuls, d'où des blocs « règle » et « minuteur » en double dans le schéma |
| Un délai est une **condition** (« créé il y a plus de 2 h ») revérifiée par le planificateur | Coût qui grandit avec le nombre de tickets × règles ; pas d'ordre garanti entre deux règles |
| Pas d'enchaînement « après A, faire B » | Le schéma doit **deviner** les suites (mêmes conditions, délais croissants) |
| Module payant, non disponible ici | Dépendance externe, tests sur un bouchon |

## 3. Principes

1. **Un bloc = un objet** : il a un identifiant, un type, des paramètres, une position, des **sorties** reliées à
   d'autres blocs. On peut l'ajouter, le déplacer, le désactiver ou le supprimer seul.
2. **Le schéma est la vérité** : pas de traduction cachée. Le panneau d'un bloc montre ses paramètres, sans « règle
   derrière ».
3. **Sûr par défaut** :
   - un flux s'écrit en **brouillon** et ne s'exécute qu'une fois **publié** ;
   - chaque publication crée une **version figée** ;
   - par défaut, les tickets déjà en cours gardent la version avec laquelle ils ont commencé ; à la publication, on
     peut aussi **réappliquer** la nouvelle version aux tickets en cours, voire aux tickets passés (voir 7.4).
4. **Léger** :
   - aucun parcours de tous les tickets ;
   - des requêtes indexées ;
   - les tâches lourdes (e-mail, dossier IMAP) passent en file d'attente ;
   - les journaux sont purgés automatiquement.
5. **Compatible** : FreeScout 1.8.x, PHP 7.1 à 8.4, UpdateGuard (`workflowpacks:health`), 7 langues, PC, tablette et
   téléphone.

## 4. Les blocs

Chaque bloc a des **sorties nommées** : par exemple « oui / non » pour une condition, « suite / délai dépassé » pour
une attente d'événement. Une sortie non reliée signifie « fin du parcours » sur cette branche.

### Déclencheurs (point de départ, une ou plusieurs boîtes)
| Bloc | Quand |
|---|---|
| Nouvel e-mail | une conversation créée par un client |
| Réponse du client | un client répond |
| Réponse de l'équipe | un agent répond |
| Statut changé | vers un statut donné (ou n'importe lequel) |
| Assignation changée | assigné / désassigné / à une personne |
| Tag ajouté | un tag donné (module Tags) |
| Manuel | bouton « Lancer ce flux » sur un ticket |
| Planifié | chaque jour à une heure : parcourt **une** recherche limitée (ex. « en attente depuis > 7 j »), par lots |

### Conditions (aiguillage)
- **Contenu et origine** : sujet, corps, expéditeur, destinataire, en-têtes, client connu.
- **État du ticket** : statut, assigné ou non, l'équipe a répondu ou non, tag présent.
- **Heure** : jour ou heure (heures ouvrées), nombre de messages.
- **Plusieurs voies** : « Selon » ouvre une sortie par valeur (par statut, par expéditeur…) au lieu d'empiler les
  blocs « si ».

### Attentes
| Bloc | Effet |
|---|---|
| Attendre une durée | 15 min … 90 j ou personnalisée |
| Attendre jusqu'à | prochaine heure ouvrée, tel jour / telle heure |
| Attendre un événement | « jusqu'à réponse du client », avec **délai maximum** : sorties « reçu » et « délai dépassé » |

En option sur chaque attente : **annuler si** le ticket est fermé, supprimé, ou a reçu une réponse. C'est le cas
classique des relances.

### Actions
- **Sur le ticket** : statut, assigner, ajouter ou retirer un tag, note interne, déplacer vers une autre boîte,
  supprimer.
- **Messages** : répondre au client (modèle avec variables), notifier un agent ou une adresse, transférer.
- **Classement** : classer dans un dossier IMAP (réutilise l'existant).
- **Organisation** : « Lancer le flux… », un sous-flux réutilisable.

### Utilitaires
- **Rejoindre** : plusieurs branches convergent.
- **Fin** : explicite, pour la lisibilité.

## 4 bis. Similarité des e-mails (bloc « Ressemble à »)

But : **remplacer des dizaines de conditions** (« sujet contient … ou … ou … ») par une seule question : « cet
e-mail ressemble-t-il à ces exemples, et à combien de % ? ». Le flux se sépare ensuite selon la réponse.

### Plusieurs vecteurs par e-mail
Chaque e-mail est transformé en **un vecteur par partie**, pour pouvoir pondérer et affiner :

| Partie | Ce qui est pris | Exemple d'usage |
|---|---|---|
| Sujet | mots + morceaux de mots (3 lettres) | « Facture n° 123 » ≈ « Votre facture 456 » |
| Corps | début du texte, **sans** les citations ni la signature | demandes de même nature, même formulées autrement |
| Expéditeur | domaine, adresse, nom affiché | les e-mails d'un fournisseur ou d'un outil |
| En-têtes | List-Id, X-Mailer, Auto-Submitted… | notifications automatiques, newsletters |
| Pièces jointes | types et noms (sans le contenu) | « PDF nommé facture_*.pdf » |

### Calcul local, sans service externe
- **Méthode** : sacs de mots et de trigrammes, hachés sur 512 dimensions par partie (TF-IDF haché), normalisés.
  Ressemblance = **cosinus**, affiché en %.
- C'est du PHP pur, sans bibliothèque ni GPU, **indépendant de la langue** et rapide : environ 1 à 3 ms par e-mail.
- **Par défaut, rien ne quitte le serveur** : aucun contenu d'e-mail n'est envoyé à un service d'IA. Un moteur
  externe peut être activé en option (voir plus bas).
- **Calculé une seule fois**, à l'arrivée de l'e-mail, et seulement si au moins un flux utilise la similarité.
  Sinon, il n'y a aucun coût.
- **Stockage compact** : table `mail_vectors` (conversation, message, partie, vecteur compressé en 8 bits, version du
  calcul), soit environ 0,5 Ko par partie. Elle est purgée avec le journal. Si la méthode change, la version permet
  de recalculer par lots.

### Moteur externe d'« embeddings » (option, désactivé par défaut)
Pour une ressemblance **par le sens**, et pas seulement par les mots (« je n'arrive pas à me connecter » ≈ « mot de
passe refusé »), l'administrateur peut brancher un service d'embeddings **compatible OpenAI** : OpenAI, Mistral,
Azure, ou un serveur local comme Ollama.

- **Activation explicite** (Paramètres › Similarité) : un interrupteur, puis un **avertissement clair sur les données
  personnelles**, indiquant quelles parties seront envoyées, à qui, et que le service peut les conserver. La case
  « J'ai compris » est obligatoire. L'activation est notée dans l'historique : qui, et quand.
- **Réglages** :
  - adresse du service, modèle, clé d'API ;
  - délai maximal (5 s par défaut) et nombre d'envois par minute ;
  - bouton **« Tester la connexion »**, avec un texte fictif, jamais un vrai e-mail.
- **Clé d'API** : chiffrée en base (chiffrement Laravel). Elle n'est jamais affichée en entier, jamais écrite dans
  les journaux, jamais exportée dans un pack.
- **Choix par partie** : local ou externe pour chacune. Par exemple, l'expéditeur et les en-têtes restent toujours
  en local ; seuls le sujet et le corps partent au service.
- **Minimisation avant envoi** (activée par défaut) :
  - les adresses e-mail, numéros de téléphone, IBAN, URL et signatures sont remplacés par des marqueurs
    (`[email]`, `[tél]`…) ;
  - le texte est tronqué (2 000 caractères par défaut) ;
  - aucune pièce jointe n'est envoyée.
- **Ressources et coût** :
  - le calcul est fait **une seule fois** par e-mail, à l'arrivée, **dans la file d'attente** : jamais dans la
    requête de l'agent ;
  - les envois sont groupés par lots quand le service le permet ;
  - un compteur affiche le nombre d'appels du mois, avec une estimation du coût.
- **Fiabilité** : en cas d'erreur, de délai dépassé ou de quota atteint, **repli automatique sur le calcul local**,
  pour que le flux ne soit jamais bloqué. Le problème est signalé dans le diagnostic.
- **Cohérence** :
  - chaque vecteur garde le **modèle** qui l'a produit ;
  - un bloc compare uniquement des vecteurs du même modèle ;
  - si l'on change de modèle, les profils sont recalculés par lots (avec une estimation et un échelonnement, comme
    en 7.4).
- **Désactivation** : on revient au calcul local. Les vecteurs externes sont supprimés au choix (proposé par défaut).

### Le bloc de condition « Ressemble à »
- **Exemples** : choisis par l'utilisateur dans ses tickets, positifs et éventuellement négatifs. Le bloc garde un
  **profil** par partie (le centre des exemples). La comparaison se fait contre ces profils seulement, jamais contre
  tous les e-mails : le coût est constant.
- **Poids par partie**, réglés avec des curseurs (par exemple sujet 40 %, corps 40 %, expéditeur 20 %). Une partie
  à 0 % est ignorée.
- **Seuil** réglable (par exemple 75 %). Sorties : **« ressemble (≥ 75 %) »** et **« ne ressemble pas »**.
- Variante **« Le plus proche de… »** : plusieurs groupes d'exemples (Factures, Commandes, Réclamations) et **une
  sortie par groupe**, plus « aucun » sous le seuil. Un seul bloc aiguille ainsi tout le flux.
- **Transparence** :
  - le score s'affiche dans la simulation, le test à blanc et le journal (« 87 % : sujet 92, corps 81, expéditeur
    100 ») ;
  - le panneau du bloc montre les 10 derniers e-mails passés avec leur score, ce qui aide à régler le seuil.
- **Affiner** : sur un ticket, « aurait dû passer » ou « n'aurait pas dû passer » l'ajoute aux exemples positifs ou
  négatifs. Le profil se recalcule à la publication suivante.

### Outil « Trouver les e-mails similaires »
- Depuis un ticket ou un bloc : la liste des tickets récents qui lui ressemblent, avec leur %.
- La recherche est limitée à une période (30 j par défaut) et faite par lots.
- **« Créer un flux pour ceux-ci »** : les tickets cochés deviennent les exemples d'un nouveau bloc « Ressemble à ».
  C'est la façon la plus simple de construire un tri.

### Données personnelles
- Les vecteurs sont dérivés des e-mails : ils sont traités comme des données personnelles, avec la même purge, sans
  export par défaut.
- Un pack peut contenir un **profil** (le centre haché, sans aucun texte), seulement si l'exportateur coche l'option.
  Les exemples, eux, ne sont jamais exportés.

## 4 ter. Analyse de sécurité (bloc « Analyser ») et bloc « Lot »

### Bloc « Analyser les liens / pièces jointes »
- **Désactivé tant qu'un fournisseur n'est pas configuré** (Paramètres › Analyse de sécurité). Le bloc n'apparaît
  dans le menu « + » qu'une fois l'option activée, avec la même activation explicite et le même avertissement que le
  moteur externe de similarité.
- **Fournisseurs** : VirusTotal en premier. Le registre de fournisseurs permet d'en ajouter d'autres (Google Safe
  Browsing / Web Risk pour les liens, etc.), sur le même principe que les blocs. La clé d'API est chiffrée, jamais
  affichée ni exportée.
- **Paramètres du bloc** : quoi analyser (liens, pièces jointes ou les deux), délai maximum d'attente du résultat.
- **Sorties** : **« sain »**, **« suspect »**, **« malveillant »**, **« inconnu / erreur »**, **« délai dépassé »**.
- **Résultat captable** dans les blocs suivants : variables `scan.verdict`, `scan.malicious` (nombre de moteurs qui
  détectent), `scan.suspicious`, `scan.items` (liens ou fichiers concernés). Le bloc de condition **« Résultat
  d'analyse »** permet d'écrire par exemple « si au moins 3 moteurs détectent → spam + note interne ».
- **Asynchrone, sans bloquer** : l'analyse d'un lien ou d'un fichier nouveau prend de quelques secondes à quelques
  minutes. Le bloc soumet, puis met le ticket **en attente** (même mécanisme que les attentes, 7.2) et revient
  chercher le résultat. Le flux reprend à la bonne sortie. Rien ne ralentit l'arrivée des e-mails.

**Économiser les appels (toujours actif, sans réglage)**
- **Fichiers** : on interroge d'abord l'empreinte SHA-256, qui suffit pour les fichiers déjà connus et n'envoie
  **pas** le fichier. L'envoi du fichier lui-même est une option **désactivée par défaut**.
- **Cache partagé** : un même lien ou fichier vu dans 50 e-mails n'est analysé **qu'une fois**. Le résultat est
  gardé 24 h par défaut (réglable).
- **Liste blanche** : les domaines de confiance (les vôtres, ceux de vos outils) ne sont jamais envoyés.
- **Liens dédoublonnés** par e-mail, et liens de désabonnement ou de suivi ignorés si on le souhaite.

**Option « économie » (regrouper plusieurs e-mails en une seule requête)**
- **VirusTotal (API v3) ne le permet pas** : une requête = un lien ou un fichier. Pour lui, l'option n'est donc
  **pas proposée**. Les économies réelles viennent du cache, de l'empreinte et de la liste blanche, ci-dessus.
- Pour un fournisseur qui accepte plusieurs éléments par requête (Google Safe Browsing : jusqu'à 500 liens par
  appel), l'option apparaît automatiquement. Le fournisseur déclare cette capacité (`batch`, avec sa taille
  maximale), et le bloc « Lot » ci-dessous lui fournit des paquets.

**Avertissements affichés à l'activation (et dans la documentation)**
- **La version gratuite ne suffira probablement pas.** L'API publique de VirusTotal est limitée à 4 requêtes par
  minute et 500 par jour, et son usage est réservé à un cadre **non commercial**. Une boîte support reçoit vite plus
  de liens que ça. Le module affiche le quota restant, met en attente ce qui dépasse (au lieu d'échouer) et propose
  de limiter l'analyse aux e-mails déjà filtrés par une condition.
- **Confidentialité** :
  - un fichier ou un lien envoyé à VirusTotal devient visible pour ses clients payants (communauté de sécurité) ;
  - les liens peuvent contenir des jetons personnels (réinitialisation de mot de passe, suivi). D'où, par défaut :
    les paramètres d'URL sont retirés avant envoi, les pièces jointes ne sont pas envoyées (empreinte seulement), et
    la liste blanche s'applique.

### Suivi des quotas et bascule automatique vers une autre solution
Le module **suit l'utilisation de chaque fournisseur** et peut **basculer automatiquement** sur une autre solution
d'analyse quand le premier est épuisé ou en panne.

- **Suivi de l'utilisation** :
  - **compteur local** de chaque appel (par minute, heure, jour et mois), toujours exact pour ce que le module
    envoie ;
  - **quota réel lu chez le fournisseur** quand il le permet. VirusTotal donne l'utilisation d'une clé (quotas
    horaire, journalier et mensuel) : elle est relue toutes les 15 minutes, ce qui couvre aussi les appels faits
    avec la même clé par d'autres outils ;
  - les réponses « quota dépassé » (HTTP 429) et les erreurs répétées sont détectées immédiatement.
- **Affichage** : une jauge par fournisseur dans les paramètres et sur le bloc (« VirusTotal : 412 / 500
  aujourd'hui »), avec l'heure de remise à zéro.
- **Chaîne de fournisseurs**, réglable dans le bloc ou dans les paramètres : par exemple **VirusTotal → Google Safe
  Browsing → filtres DNS (gratuit, local) → « inconnu »**. Pour un fichier, seuls les fournisseurs qui analysent des
  fichiers sont proposés dans la chaîne.
- **Quand basculer** (au choix) :
  - quota utilisé au-delà d'un seuil (90 % par défaut), pour garder une marge ;
  - quota épuisé (429) ;
  - N erreurs ou délais dépassés d'affilée (3 par défaut) ;
  - temps de réponse trop long.
- **Retour automatique** au fournisseur principal à la remise à zéro du quota, ou dès qu'un essai de santé réussit.
  C'est un « disjoncteur » : il s'ouvre en cas de problème, se referme seul, et teste avec un seul appel avant de
  tout renvoyer.
- **Si aucune solution n'est disponible**, trois choix : **attendre** la remise à zéro du quota (le ticket reste en
  attente, comme dans un « Lot »), **continuer** par la sortie « inconnu », ou **passer** par la sortie « erreur ».
- **Résultats comparables** : chaque fournisseur est traduit dans le même verdict (sain / suspect / malveillant /
  inconnu). La variable **`scan.provider`** indique qui a répondu, et `scan.fallback` si c'était une solution de
  secours. Les « Si » suivants peuvent en tenir compte (« si analysé par le secours, ajouter une note »).
- **Prévenir** : une bascule déclenche une alerte (4 septies), regroupée, avec la cause (« quota journalier
  VirusTotal atteint à 15 h 20, bascule sur Google Safe Browsing jusqu'à minuit »).
- **Simulation** : on peut simuler « quota épuisé » ou « fournisseur en panne » pour vérifier la chaîne (8 bis).

### Bloc « Lot » (conteneur)
Un bloc qui **accumule les tickets** puis les **déverse** dans le bloc suivant. On peut l'imaginer comme un bac qui
se remplit.

- **Libérer quand** : **N tickets** atteints (par exemple 20), **ou** un délai maximal écoulé (par exemple 1 h). Ce
  délai empêche qu'un ticket reste bloqué si le lot ne se remplit jamais.
- **Ordre de sortie** : arrivée (le plus ancien d'abord), le plus récent d'abord, par expéditeur ou domaine (les
  e-mails d'un même expéditeur ensemble), ou par boîte.
- **Débit de sortie** : **tout d'un coup** (en paquet, pour un bloc qui accepte les paquets), ou **X par minute**.
  Ce second mode est très utile pour respecter un quota : par exemple « 4 par minute » pour l'API gratuite de
  VirusTotal.
- **Affichage sur le schéma** : le bloc montre son remplissage (« 12 / 20 ») et l'heure de libération prévue. Le
  bouton **« Vider maintenant »** libère tout de suite.
- **Fonctionnement** : les tickets du lot sont des runs en attente sur ce bloc (`batch_block`), sans nouvelle
  mécanique. À la libération, ils reçoivent un identifiant de paquet commun. Un bloc qui implémente `runBatch()`
  (14.1), comme « Analyser » avec un fournisseur `batch`, traite le paquet en un appel ; les autres blocs traitent
  les tickets un par un, dans l'ordre choisi.
- Il sert aussi hors analyse, par exemple pour **regrouper des notifications** (un résumé toutes les 20 demandes au
  lieu de 20 e-mails).

## 4 quater. Réponses automatiques, liens interdits (filtres DNS) et réputation de l'expéditeur

### Bloc « Réponse automatique »
- Envoie une **réponse type** au client, dans le ticket, comme une vraie réponse FreeScout.
- **Bibliothèque de réponses** : un écran dans le module pour créer, modifier et réutiliser les réponses types. Elles
  ont des variables (`{{customer.first_name}}`, `{{ticket.number}}`, `{{extract.order}}`…) et une version par langue
  (choisie selon la langue de la boîte, voir 8 quater). Si le module 🧩 Saved Replies est installé, ses réponses sont
  proposées aussi.
- **Aperçu** avec un vrai ticket avant de publier (sans envoi).
- **Garde-fous anti-boucle et anti-spam**, actifs par défaut :
  - jamais de réponse à un e-mail automatique (`Auto-Submitted`, absence du bureau, bounce), à une liste de
    diffusion (`List-Id`, `Precedence: bulk`) ni à une adresse `no-reply` ;
  - **au plus une réponse automatique par client** et par période (24 h par défaut) ;
  - l'en-tête `Auto-Submitted: auto-replied` est ajouté, pour que les autres robots ne répondent pas ;
  - la limite globale d'e-mails par heure (section 10) s'applique.
- Usages typiques : accusé de réception, « nous sommes fermés, réponse lundi » (avec le bloc heures ouvrées),
  demande d'informations manquantes.

### Bloc « Liens interdits » (filtres DNS)
Teste les **domaines** des liens d'un e-mail (et, en option, celui de l'expéditeur) auprès de **résolveurs DNS
filtrants**. Un domaine bloqué par le filtre est signalé. C'est gratuit, rapide, et seuls les noms de domaine sont
envoyés, jamais l'URL complète.

| Liste proposée (au choix, plusieurs possibles) | Bloque | Signe d'un blocage |
|---|---|---|
| Cloudflare 1.1.1.2 / 1.0.0.2 | logiciels malveillants | réponse `0.0.0.0` |
| Cloudflare 1.1.1.3 / 1.0.0.3 | malveillants + contenu adulte | réponse `0.0.0.0` |
| Quad9 9.9.9.9 | logiciels malveillants, hameçonnage | « domaine inexistant » alors qu'il existe ailleurs |
| AdGuard (standard / famille) | publicité et pistage / + adulte | réponse `0.0.0.0` |
| OpenDNS FamilyShield | contenu adulte | réponse vers une page de blocage connue |
| **Personnalisée** | — | adresse du serveur (ou URL DNS-over-HTTPS) + règle de blocage (`0.0.0.0`, « inexistant », adresse précise) |

- **Comparaison** avec un résolveur normal : un domaine vraiment inexistant n'est pas confondu avec un domaine
  bloqué.
- **Catégorie déduite** : bloqué par 1.1.1.2 donne « malveillant » ; bloqué seulement par 1.1.1.3 donne « adulte ».
- **Transport** : DNS-over-HTTPS par défaut (passe les pare-feu des hébergeurs, chiffré), ou DNS classique (UDP 53)
  si le serveur le permet. Les requêtes DNS sont **faites par le module lui-même** (petit client intégré, sans
  bibliothèque), car le DNS de PHP ne permet pas de choisir le serveur.
- **Ressources** :
  - délai de 1,5 s par requête ;
  - au plus 20 domaines par e-mail (réglable) ;
  - domaines dédoublonnés ;
  - **cache** de 24 h par domaine et par liste : un domaine vu 1 000 fois n'est testé qu'une fois par jour ;
  - liste blanche partagée avec le bloc « Analyser ».
- **Sorties** : « aucun lien interdit » / « lien interdit » / « erreur ».
- **Variables** : `dns.blocked` (oui / non), `dns.categories` (malveillant, adulte, hameçonnage, publicité…),
  `dns.domains` (domaines bloqués), `dns.count`.

### Bloc « Réputation de l'expéditeur » (listes de spam)
Vérifie si le **serveur d'envoi** ou le **domaine** de l'expéditeur figure sur des listes noires.

- **Authentification de l'expéditeur** : local, gratuit, sans requête externe. Lecture des résultats SPF, DKIM et
  DMARC posés par votre serveur de réception (`Authentication-Results`). Variables `sender.spf`, `sender.dkim`,
  `sender.dmarc` : pass, fail, none…
- **Listes DNSBL du serveur d'envoi** : l'adresse IP du premier serveur externe est lue dans les en-têtes
  `Received`, puis vérifiée sur les listes choisies (Spamhaus ZEN, SpamCop, Barracuda, UCEPROTECT…, ou une liste
  personnalisée).
- **Listes de domaines** (Spamhaus DBL, SURBL, URIBL) : pour le domaine de l'expéditeur et, en option, ceux des
  liens.
- **Codes de réponse traduits** : `127.0.0.2` sur ZEN donne « source de spam (SBL) », etc. Les codes d'erreur des
  listes sont reconnus et donnent la sortie « erreur », jamais un faux positif.
- **Avertissements affichés** :
  - certaines listes **refusent les requêtes passées par les DNS publics** (Spamhaus avec 1.1.1.1 ou 8.8.8.8) ;
  - certaines exigent une **inscription ou une clé** (Spamhaus DQS, Barracuda) ;
  - un usage commercial ou à fort volume peut être **payant** ;
  - le module indique pour chaque liste si elle répond correctement depuis votre serveur (« Tester »).
- **Cache** : 6 h par adresse IP ou domaine, même client DNS, mêmes délais que les liens interdits.
- **Sorties** : « propre » / « listé » / « erreur ».
- **Variables** : `sender.listed` (oui / non), `sender.lists` (listes où il figure), `sender.score` (nombre de
  listes), `sender.ip`, `sender.spf` / `dkim` / `dmarc`.

## 4 quinquies. Variables typées : les « Si » proposent automatiquement les choix possibles

Chaque bloc **déclare les variables qu'il produit**, avec leur **type** et, si c'est une liste fermée, **toutes ses
valeurs possibles** :

| Bloc | Variable | Type | Valeurs proposées dans le « Si » |
|---|---|---|---|
| Analyser | `scan.verdict` | choix | sain · suspect · malveillant · inconnu |
| Analyser | `scan.malicious` | nombre | (opérateurs ≥, ≤, =) |
| Liens interdits | `dns.categories` | liste de choix | malveillant · adulte · hameçonnage · publicité (selon les listes cochées dans le bloc) |
| Réputation | `sender.dmarc` | choix | pass · fail · none |
| Réputation | `sender.lists` | liste de choix | les listes cochées dans le bloc (Spamhaus ZEN, SpamCop…) |
| Ressemble à | `similar.group` | choix | les groupes d'exemples définis dans le bloc |
| Extraire une valeur | `extract.<nom>` | texte | (contient, commence par, expression) |

Dans le bloc « Si » placé après eux :
1. Le menu **« Variable »** liste uniquement les variables des **blocs situés avant** dans le flux, calculées en
   remontant les liens. Une variable produite seulement sur certaines branches est marquée « peut être vide ».
2. Les **opérateurs s'adaptent au type** : choix (est, n'est pas, est l'un de), nombre (≥, ≤, =, entre), oui / non,
   liste (contient, ne contient pas), texte (contient, expression).
3. Pour un choix, les **valeurs possibles sont proposées en liste** : rien à taper, aucune faute de frappe possible.
   Elles dépendent des réglages du bloc d'origine (les listes DNS cochées, les groupes d'exemples créés…).
4. Le bloc **« Selon »** crée automatiquement **une sortie par valeur** (« sain », « suspect », « malveillant »).
5. Si le bloc d'origine est supprimé ou déplacé après le « Si », celui-ci devient **orange** (« variable plus
   disponible ») et la publication est refusée tant que ce n'est pas corrigé.

Techniquement, chaque bloc a une méthode `variables(array $params)` (14.1). Le navigateur calcule la liste à partir
du registre et des liens, sans requête. Le serveur refait la même vérification à la publication et à l'import d'un
pack. Les noms et valeurs sont traduits (8 quater) ; seules les clés stables (`scan.verdict`, `malicious`) sont
enregistrées.

## 4 sexies. Autres blocs proposés

Les **6 prioritaires** (locaux, peu coûteux, gain important) sont prévus en 3.2 :
1. **Réponse automatique / message d'erreur reçu** (condition anti-boucle : absence du bureau, bounce, `Auto-Submitted`)
2. **Doublon** (même expéditeur, sujet proche, X dernières heures) + action **Fusionner**
3. **Validation humaine** (le flux attend « Valider / Refuser » d'un agent sur le ticket)
4. **Assignation tournante** (à tour de rôle, au moins chargé, aux agents disponibles)
5. **Extraire une valeur** (n° de commande ou de facture vers une variable)
6. **Limiteur** (au plus N passages par client et par jour)

**Liste d'idées pour plus tard** (chacune = un fichier grâce au registre) :
- **Déclencheurs** : risque de SLA, client important, webhook entrant signé 🔑, note de satisfaction 🧩.
- **Conditions** : heures ouvrées et jours fériés, langue détectée, historique du client, pièce jointe (type,
  taille), répartition en %, liste partagée (clients importants, liste noire).
- **Actions** : reporter (rouvrir à une date), webhook sortant (Slack, Teams, Jira) 🔑, champ personnalisé 🧩,
  IA 🔑 (résumé, traduction, brouillon jamais envoyé seul).
- **Utilitaires** : variable, sous-flux, note de schéma (post-it), rapport périodique par e-mail.

(🔑 : service externe, désactivé par défaut avec avertissement · 🧩 : autre module FreeScout, proposé seulement s'il
est installé.)

## 4 septies. Notifications par d'autres modules, et alertes quand une action échoue

### Bloc « Notifier » (modules de notification connus)
- **Toujours disponible** : la notification FreeScout elle-même (dans l'application et par e-mail aux agents
  choisis).
- **Modules de notification reconnus** 🧩 : Slack, Microsoft Teams, Telegram, Webhooks, application mobile…
  - chacun est branché par un petit **adaptateur** (un fichier, même principe que les blocs), proposé seulement si
    le module est **installé, actif et dans une version testée** ;
  - le bloc ne liste que les canaux réellement utilisables ;
  - un autre module peut aussi fournir son propre adaptateur, par le filtre `workflowpacks.notifiers`.
- **Paramètres** : canal, destinataires (agents, équipe, canal Slack…), message (modèle avec variables et versions
  par langue), lien vers le ticket ajouté automatiquement.
- **Données** : le message ne contient que ce que l'on y met (variables) ; jamais le corps de l'e-mail par défaut.

### Quand une action n'a pas pu se faire
Exemples : module de notification devenu incompatible après une mise à jour, serveur mail ou IMAP injoignable,
quota d'API atteint, droit manquant, ticket supprimé entre-temps.

1. **Nouvelles tentatives** pour une erreur passagère : 1 min, 10 min, 1 h (7.3). Une erreur définitive, comme un
   module incompatible, n'est pas retentée inutilement.
2. **Sortie « erreur »** sur chaque bloc d'action. Si on la relie, le flux continue par un autre chemin, par exemple
   « si Slack échoue, envoyer un e-mail ». Si on ne la relie pas, c'est la règle d'alerte du flux qui s'applique.
3. **Alerte par e-mail**, réglable **par flux** (avec une valeur par défaut dans les paramètres) :
   - **à qui** : un ou plusieurs agents, ou une adresse ;
   - **depuis quelle boîte** l'e-mail part ;
   - **quand** : immédiatement, ou **résumé groupé** (toutes les heures ou une fois par jour).
   - **Contenu** : flux, bloc, ticket (lien), cause en clair (« Le module Slack 2.3 n'est plus compatible, version
     testée ≤ 2.1 »), nombre de tickets touchés. Aucun contenu d'e-mail du client.
   - **Anti-avalanche** : une même cause n'envoie qu'**une alerte par heure**, avec le compteur des tickets touchés,
     au lieu d'un e-mail par ticket. Un plafond d'alertes par jour s'applique aussi.
4. **Visible partout** :
   - le bloc devient **rouge** sur le schéma (« 12 échecs depuis 9 h »), avec la liste des tickets concernés ;
   - l'historique et le diagnostic (`workflowpacks:health`, UpdateGuard) signalent l'adaptateur incompatible
     **avant** même qu'un flux n'échoue : la compatibilité est vérifiée après chaque mise à jour de FreeScout ou d'un
     module.
5. **Rejouer** : une fois le problème corrigé, « Relancer les tickets en échec » refait le bloc pour eux (avec
   l'estimation et l'échelonnement de 7.4 s'ils sont nombreux).

## 5. Votre exemple

```
[Nouvel e-mail · Support] → [Si : sujet contient « PR »] —oui→ [Dossier : Pull Requests] → [⧗ Attendre 2 h] → [Statut : Fermé]
                                                        —non→ (fin)
```

Cinq blocs, cinq objets : on peut supprimer « Attendre 2 h » seul (le statut se fait alors tout de suite), ou
désactiver « Statut : Fermé » seul. Il n'y a plus de règle cachée ni de doublon.

## 6. Données (nouvelles tables, préfixe `workflowpacks_`)

| Table | Contenu | Index |
|---|---|---|
| `flows` | id, nom, boîtes, actif, version publiée, brouillon (json), créé par | — |
| `flow_versions` | id, flow_id, numéro, blocs + liens (json figé), publié le / par | flow_id |
| `flow_runs` | un ticket dans un flux : conversation_id, flow_id, version, bloc courant, état (`waiting` / `running` / `done` / `failed` / `cancelled`), **wake_at**, événement attendu, lot (`batch_block`, `batch_id`), essais | **(state, wake_at)**, (conversation_id, state), (batch_block, state) |
| `flow_events` | historique des flux (8 quinquies) : run_id, conversation_id, bloc, sortie prise, décision résumée, changements (avant → après), durée, niveau, horodatage (sans contenu d'e-mail) | run_id, (conversation_id, created_at), created_at ; purge par lots |
| `engine_log` | journal technique (8 quinquies) : niveau, composant, message, durée, contexte court | created_at, niveau ; purge par lots |
| `flow_reapply` | réapplication en cours (7.4) : flux, version, portée, curseur, total, faits, lot, intervalle, état | flow_id |
| `dns_cache` | filtres DNS et réputation (4 quater) : liste, empreinte du domaine ou de l'IP, résultat, catégorie, expire le | (liste, empreinte) ; purge à expiration |
| `flow_tests` | scénarios de simulation (8 bis) : flux, nom, e-mail de test, événements injectés, réponses simulées, résultat attendu | flow_id |
| `flow_alerts` | alertes d'échec (4 septies) : flux, bloc, empreinte de la cause, tickets touchés, dernier envoi | (flow_id, cause) |
| `replies` | bibliothèque de réponses (4 quater) : nom, versions par langue, variables, créée par | — |
| `provider_usage` | quotas (4 ter) : fournisseur, période (minute / heure / jour / mois), appels, quota lu chez le fournisseur, état du disjoncteur, remise à zéro | (fournisseur, période) |
| `scan_cache` | analyse (4 ter) : fournisseur, empreinte du lien ou du fichier (jamais l'URL complète en clair), verdict, nombre de moteurs, vérifié le, expire le | (fournisseur, empreinte) ; purge à expiration |
| `mail_vectors` | similarité (4 bis) : conversation, message, partie, vecteur compressé, version du calcul — seulement si un flux l'utilise | conversation_id ; purge |

Remarques :
- **Pas de table par bloc** : le flux publié est un seul JSON figé (quelques Ko), chargé une fois par exécution et mis
  en cache. Le schéma lit le brouillon. Positions et tailles restent dans `layouts` (existant).
- **Une seule ligne par ticket** en cours dans un flux. 10 000 tickets en attente ne posent aucun problème avec
  l'index `(state, wake_at)`.

## 7. Exécution

### 7.1 Événements
Un écouteur unique, branché sur les actions Eventy de FreeScout (nouvelle conversation, réponse client, réponse agent,
changement de statut ou d'assignation), procède ainsi :
1. Il cherche dans un **index en cache** (boîte + type d'événement → flux) les flux concernés. Il n'y a aucune requête
   si aucun flux n'écoute cet événement.
2. Pour chaque flux trouvé, il crée un `run` et l'exécute **tout de suite** jusqu'à la première attente ou la fin.
   Une limite de 50 blocs par passage évite les boucles.
3. Les actions lentes (envoi d'e-mail, IMAP) partent dans la **file Laravel** de FreeScout. Si aucune file ne tourne,
   elles s'exécutent au passage suivant du planificateur. Le navigateur de l'agent n'attend jamais.
4. Il **réveille** les runs qui attendaient cet événement sur ce ticket (requête par conversation_id, indexée).

Objectif : **moins de 5 ms** par événement quand aucun flux ne correspond.

### 7.2 Attentes (planificateur, chaque minute, via le filtre `schedule` déjà utilisé)
```sql
UPDATE flow_runs SET state='running', claimed_at=NOW() WHERE state='waiting' AND wake_at <= NOW() ORDER BY wake_at LIMIT 200
```
Puis :
- seules les lignes **réclamées** sont traitées : deux planificateurs simultanés ne prennent jamais le même ticket,
  sans verrou global ;
- au réveil, le moteur **revérifie le ticket** : supprimé ou fusionné, il annule ; si une condition « annuler si » est
  vraie, il annule aussi ;
- puis il continue jusqu'à la prochaine attente ;
- s'il reste plus de 200 tickets à réveiller, le passage suivant les prend : la charge est lissée.

Objectif : **moins de 50 ms** par minute quand rien n'est à réveiller.

### 7.3 Fiabilité
- **Une fois par ticket** (défaut) ou **à chaque fois**, réglable par flux. Cela évite de relancer un flux sur un
  ticket qui y est déjà passé.
- Erreur passagère (serveur mail, IMAP) : jusqu'à 3 nouvelles tentatives (1 min, 10 min, 1 h), puis état `failed`,
  visible dans l'historique et sur le bloc.
- À la publication, le flux est refusé s'il contient une boucle sans attente, un bloc non relié au déclencheur, ou une
  action incomplète.
- Mettre un flux **en pause** ne perd pas les attentes ; elles reprennent à la réactivation. **Supprimer** un flux
  annule ses runs, après confirmation et avec une sauvegarde (Backup existant).

### 7.4 Réappliquer un flux modifié (tickets en cours et passés)

Quand on publie une nouvelle version, une fenêtre simple propose **trois choix** :

| Choix | Effet |
|---|---|
| **Nouveaux tickets seulement** (par défaut) | rien ne change pour les tickets déjà dans le flux |
| **Aussi les tickets en cours** | les tickets en attente passent à la nouvelle version, au même bloc (repéré par son identifiant). Un bloc supprimé : « continuer au bloc suivant », « reprendre au début » ou « arrêter » (au choix, une fois pour tous) |
| **Aussi les tickets passés** | les tickets déjà passés par le flux (ou qui l'auraient déclenché) sur une période choisie (7 j, 30 j, 90 j, personnalisée) refont le flux avec la nouvelle version |

**Avant de confirmer, une estimation claire** est calculée côté serveur, avec des requêtes de comptage seulement :
```
Tickets concernés : 1 240 · Opérations : ~3 700 (3 blocs par ticket en moyenne)
⚠ 1 240 e-mails seraient envoyés aux clients (bloc « Répondre au client »)
Charge : ●●○ moyenne — environ 25 min en lots de 50 toutes les minutes
[ Échelonner : 50 tickets / 1 min  ◀━━●━━━▶ ]      [ Simulation à blanc ]   [ Annuler ]  [ Réappliquer ]
```

- **Niveau de charge** (léger / moyen / lourd) = tickets × blocs, pondéré par le coût des actions : un e-mail ou un
  classement IMAP compte plus qu'un changement de statut. Les **e-mails aux clients** sont signalés à part et
  demandent une case à cocher de confirmation.
- **Échelonnement** :
  - un curseur règle la taille des lots (10 à 500 tickets) et l'intervalle (1 à 60 min) ;
  - au-dessus d'un seuil (réglable, 200 tickets par défaut), l'échelonnement est imposé ;
  - la durée estimée se met à jour en direct.
- **Suivi** : une barre de progression s'affiche sur le flux (« 350 / 1 240 »), avec **Pause**, **Reprendre** et
  **Arrêter**. Les tickets déjà traités restent faits ; un arrêt n'annule rien en arrière.
- **Simulation à blanc** : montre, sur un échantillon de 20 tickets, ce que chaque ticket ferait, sans rien exécuter.
- **Garde-fous** :
  - un ticket ne refait jamais un bloc d'e-mail déjà exécuté pour la même version ;
  - les limites d'e-mails par heure s'appliquent ;
  - une seule réapplication à la fois par flux ;
  - une sauvegarde est faite avant le lancement.

Côté technique :
- une table `flow_reapply` stocke : flux, version, portée, curseur (dernier ticket traité), total, faits, taille de
  lot, intervalle, état ;
- le planificateur traite **un lot par passage**, avec la même réclamation atomique que les attentes ;
- la charge reste donc plafonnée et prévisible, même pour des milliers de tickets ;
- le curseur permet de reprendre exactement où on s'était arrêté, même après un redémarrage.

## 8. Le schéma (éditeur)

On garde l'existant :
- vue et gestes tactiles ;
- menus en cascade et panneau à poignée ;
- annuler / rétablir et pleine page ;
- mini-carte ;
- placement sans chevauchement.

Ce qui change ou s'ajoute :
- **Ports** de sortie visibles (« oui », « non », « délai dépassé ») : on tire un lien d'un port vers un bloc, et
  « + » sur un port propose le bloc suivant.
- **Validation en direct** : un bloc orange signale ce qui manque.
- **Brouillon / Publier** : un bandeau indique « modifications non publiées » ; « Publier » montre ce qui change.
  L'historique des versions permet de **revenir en arrière**.
- **Compteurs sur les blocs** (option) : nombre de tickets passés sur 7 j et nombre de tickets **en attente ici**. Un
  clic montre la liste des tickets concernés.
- **Simulation façon Packet Tracer** : voir la section 8 bis.
- **Tester sur un ticket réel** : parcours à blanc, sans aucune action exécutée.
- Une seule vue : la « vue d'ensemble » et la « vue flux » fusionnent, puisque le schéma *est* le flux. Un filtre par
  boîte et par flux remplace la différence entre les deux vues.

## 8 bis. Simulation façon Packet Tracer

But : **voir un e-mail parcourir le flux**, bloc après bloc, avec ce qui change à chaque étape, pour vérifier que
tout fonctionne comme prévu **avant** de publier. Tout se passe **en mémoire** : rien n'est écrit, aucun e-mail
n'est envoyé, aucun service externe n'est appelé.

### Ce qu'on voit
- **Un paquet** (une enveloppe) **suit les liens** du schéma, d'un bloc à l'autre. Il s'arrête sur chaque bloc, qui
  s'allume, puis prend la sortie choisie (« oui », « non », « délai dépassé »…). Les liens empruntés restent
  colorés.
- **Liste des événements**, comme celle de Packet Tracer, sur le côté (ou en bas sur téléphone) :

  | Heure (virtuelle) | Bloc | Décision | Changements |
  |---|---|---|---|
  | 0 s | Nouvel e-mail | déclenché (boîte Support) | — |
  | 0 s | Si : sujet contient « PR » | **oui** (« PR #42 corrigée ») | — |
  | 0 s | Dossier IMAP | classé | dossier : — → Pull Requests |
  | 0 s | Attendre 2 h | ⏩ avance de 2 h | — |
  | 2 h | Statut | fait | statut : Ouvert → **Fermé** |

  - Cliquer un événement montre le bloc et **l'état complet du ticket à ce moment-là** : statut, assigné, tags,
    dossier, variables.
  - **Pourquoi** : chaque décision affiche la valeur testée, par exemple « sujet = "PR #42 corrigée" contient "PR" →
    oui » ou « similarité 87 % ≥ 75 % ».
- **Différences** : chaque changement est montré « avant → après », en couleur.

### Commandes
- **Lecture** : ▶ temps réel (animation), ⏭ **pas à pas** (un bloc à la fois), ⏩ jusqu'au prochain arrêt, ⏹ arrêter,
  vitesse réglable.
- **Horloge virtuelle** : les attentes (2 h, 3 jours…) sont **avancées instantanément**, et l'heure virtuelle
  s'affiche. Les blocs « heures ouvrées » tiennent compte de cette heure.
- **Points d'arrêt** : un clic sur un bloc arrête la simulation quand le paquet l'atteint.
- **Plusieurs paquets** : envoyer 5 ou 20 e-mails différents en même temps, pour voir le bloc « Lot » se remplir
  puis se vider, ou les branches se répartir.

### Le scénario de test
- **L'e-mail** : généré automatiquement pour un flux choisi (le générateur actuel, adapté aux conditions du flux),
  écrit à la main, ou **copié d'un vrai ticket** (en mémoire seulement).
- **Événements à injecter** sur la ligne du temps, pour tester les branches qui attendent : « le client répond à
  +1 h », « un agent s'assigne à +30 min », « le statut passe à En attente à +2 h ».
- **Blocs externes simulés** (Analyser, Liens interdits, Réputation, Notifier, moteur d'IA) : **on choisit leur
  réponse** (sain / malveillant, listé / propre, échec du module…) pour tester chaque branche, y compris la sortie
  « erreur » et l'alerte (affichée, non envoyée). Aucun appel ni quota consommé.

### Vérifier automatiquement (tests de flux)
- **Résultat attendu** : on note l'état final voulu, par exemple « statut = Fermé, dossier = Pull Requests, aucun
  e-mail envoyé ». La simulation affiche **vert / rouge** pour chaque point.
- **Scénarios enregistrés** avec le flux : à chaque modification, puis à la publication, **tous les scénarios sont
  rejoués**. Un scénario rouge est signalé avant de publier, ce qui évite de casser sans le voir ce qui marchait.
- **Couverture** : les blocs et sorties **jamais atteints** par les scénarios sont grisés sur le schéma. On voit
  ainsi d'un coup d'œil les branches non testées.

### Fonctionnement et ressources
- Le parcours est calculé **côté serveur, en une seule requête** : chaque bloc exécute sa méthode `simulate()`
  (14.1), la même logique que pour de vrai mais sans écrire. Le serveur renvoie toute la trace (événements, états,
  décisions), et le navigateur ne fait plus qu'animer, sans autre requête.
- **Limites** : 500 étapes, 20 paquets, 1 an d'horloge virtuelle par simulation, pour qu'un flux en boucle ne puisse
  pas surcharger le serveur.
- Disponible sur PC, tablette et téléphone. Sur téléphone, la liste des événements est dans le panneau à poignée.
- Dans les 7 langues, comme le reste ; les décisions sont expliquées en phrases.
- Les scénarios sont stockés avec le flux (table `flow_tests`). Ils sont exportables dans un pack (sans données
  réelles : un e-mail copié d'un vrai ticket est anonymisé à l'export).

## 8 ter. Interfaces simplifiées (tout le module)

Principe : **l'essentiel visible, le détail à un clic**. Aucune information n'est supprimée ; elle est repliée.

### Listes longues repliées (exemple : aperçu d'un grand pack)
- En haut, un **résumé** : « 42 règles · 3 boîtes · 2 avertissements ». Les avertissements sont toujours dépliés,
  pour ne jamais les manquer.
- Les règles sont **regroupées et repliées** (par boîte ou par famille : tri, relances, fermetures…). Un clic
  déplie un groupe, et « Tout déplier / Tout replier » est disponible.
- Une **recherche** filtre la liste.
- Les **boutons d'action** (« Importer », « Annuler ») sont dans une **barre fixée en bas de l'écran**, toujours
  visible : plus besoin de défiler jusqu'en bas ou de chercher le bouton au milieu.
- Le choix (déplié / replié) est **mémorisé** par utilisateur.
- Le même traitement s'applique à l'historique, au diagnostic, à l'export et à la liste des flux.

### Réglages par interrupteurs (Gérer › Paramètres › Workflow Packs)
Une page courte, en sections repliables. Chaque réglage est un **interrupteur** (ou un curseur pour une durée), avec
une phrase d'explication et une valeur par défaut sûre.

| Section | Réglages |
|---|---|
| Affichage | listes repliées par défaut · compteurs sur les blocs · animation de la simulation · aide sous le schéma |
| Moteur | moteur interne (expérimental) · « une fois par ticket » par défaut · réveil des attentes (1 à 15 min) · taille des lots |
| Réapplication | choix proposé par défaut à la publication · lot par défaut (10 à 500) · intervalle (1 à 60 min) · seuil d'échelonnement imposé (200) |
| Sécurité | limite d'e-mails par ticket et par heure · confirmation avant suppression · sauvegarde avant publication |
| Données | durée de l'historique et du journal technique (7 à 365 j) · mode détaillé (coupé après 24 h) · export anonymisé (oui) · purge des sauvegardes anciennes |
| Filtres DNS et listes | listes DNS cochées par défaut · DNS-over-HTTPS ou UDP · listes DNSBL et clés éventuelles · délai par requête · domaines max par e-mail · durée du cache · bouton « Tester » par liste |
| Alertes | destinataires par défaut (agents ou adresse) · boîte d'envoi · immédiat ou résumé (heure / jour) · plafond par jour |
| Réponses automatiques | une réponse par client et par période (24 h) · ne jamais répondre aux listes de diffusion et aux `no-reply` |
| Analyse de sécurité | activer · fournisseurs et clés d'API · **chaîne de secours** et seuil de bascule (90 %) · erreurs avant bascule (3) · si rien n'est disponible (attendre / inconnu / erreur) · quota (par minute / par jour) · envoyer les fichiers (non par défaut) · retirer les paramètres d'URL (oui) · durée du cache (24 h) · liste blanche de domaines |
| Dossiers IMAP | (existant) activé · préfixe · nettoyage |

- **Défauts sûrs** : rien de risqué n'est activé sans action de l'administrateur.
- Chaque réglage modifié propose un bouton **« Rétablir par défaut »**.
- Les réglages techniques (taille des lots, fréquence) sont dans une sous-section « Avancé », repliée.

### Règles d'écran
- Au plus **une action principale** par écran (bouton coloré) ; les autres sont secondaires ou dans un menu « … ».
- Des textes courts ; le détail est dans une info-bulle « ? » ou dans la documentation.
- La même présentation sur PC, tablette et téléphone ; la barre d'action fixe est aussi présente sur téléphone.

## 8 quater. Multilingue (noms des blocs compris)

Les 7 langues du module (fr, en, de, es, it, nl, pt-BR) s'appliquent partout, **y compris aux noms des blocs**.

- **Les noms automatiques ne sont jamais enregistrés en texte.** Un bloc stocke seulement `{type, params}` ; son
  titre est **composé à l'affichage** dans la langue de la personne qui regarde : « Attendre 2 h », « Wait 2 h »,
  « 2 Std. warten »… Un agent allemand et un agent français voient donc le même flux, chacun dans sa langue.
  Changer la langue de FreeScout change immédiatement tous les titres.
- **Le texte des titres** vient d'un modèle traduit par type de bloc, avec des variables :
  `'action.status' => 'Statut : :status'`. Les valeurs (statuts, unités, jours) sont elles aussi traduites. Les
  pluriels sont gérés : « 1 heure » / « 2 heures », avec `trans_choice` de Laravel.
- **Nom personnalisé** (facultatif) : si l'utilisateur renomme un bloc, ce nom est affiché tel quel, avec le titre
  automatique en petit dessous pour garder le sens. On peut le **vider** pour revenir au titre automatique traduit.
- **Packs** :
  - les blocs y sont décrits par `{type, params}`, sans texte : un pack s'affiche dans la langue de celui qui
    l'importe ;
  - le nom et la description d'un flux ou d'un pack peuvent contenir plusieurs langues
    (`{"fr": "…", "en": "…"}`), avec repli sur l'anglais puis sur la première langue disponible ;
  - c'est déjà le principe des noms de règles des packs actuels.
- **Textes envoyés aux clients** (réponse, notification) : une version par langue est possible. Elle est choisie
  selon la langue de la boîte, avec repli sur la version par défaut. L'éditeur montre les langues manquantes.
- **Nouveau bloc** : ses textes (titre, champs, sorties, aide) doivent exister dans les 7 langues. Le test de contrat
  (14.4) **échoue s'il en manque une**, ce qui empêche d'oublier une langue.
- **Dates et heures** (attentes, journal, estimation) : format de la langue et fuseau de l'utilisateur ; les durées
  sont écrites en toutes lettres.

## 8 quinquies. Journaux, parcours d'un e-mail et purge de l'historique

### Deux journaux
| Journal | Contenu | Pour qui |
|---|---|---|
| **Historique des flux** | chaque passage d'un ticket dans un bloc : heure, flux et version, bloc, **sortie prise**, décision résumée (« sujet contient "PR" → oui », « similarité 87 % »), **changements** (statut Ouvert → Fermé, tag + facture, dossier — → Pull Requests), durée, erreur éventuelle | administrateurs et agents des boîtes concernées |
| **Journal technique** | fonctionnement du moteur : passages du planificateur (durée, tickets réveillés), files d'attente, appels aux fournisseurs (durée, code de réponse, quota), bascules, alertes envoyées, erreurs avec leur cause | administrateurs |

- **Niveaux** : informations, avertissements, erreurs, et un mode **détaillé** (débogage) activable temporairement :
  il se coupe tout seul après 24 h pour ne pas remplir la base.
- **Filtres** : période, boîte, flux, bloc, niveau, résultat (réussi / échec / en attente), recherche par n° de ticket
  ou expéditeur.
- **Export** CSV ou JSON pour le support, anonymisé par défaut (adresses et sujets masqués).
- **Données minimales** : aucun corps d'e-mail. Les décisions montrent la valeur testée tronquée (60 caractères),
  et les journaux suivent la durée de conservation réglée (30 j par défaut).

### Parcours d'un e-mail (« comment en est-il arrivé là ? »)
Depuis l'historique (ou depuis la page du ticket, via le lien « Voir son parcours », aussi proposé à Refresh /
RefreshGlobal par Interop, 13 bis), on choisit un e-mail ou un ticket :

- Le **schéma s'ouvre avec les blocs par lesquels il est passé allumés** et les **liens empruntés colorés**, dans
  l'ordre, jusqu'à **son état actuel**. Les blocs non traversés sont grisés.
- **Code couleur** des liens : vert = passage normal, orange = attente (avec la durée réellement attendue), rouge =
  erreur ou sortie « erreur », bleu = bloc où le ticket **attend encore** (avec l'heure de réveil prévue).
- **Numéros d'étape** sur les blocs (1, 2, 3…) et, au survol ou au toucher, l'heure, la décision et les changements
  de l'étape.
- **Plusieurs flux** : si le ticket est passé par plusieurs flux (ou plusieurs versions d'un flux), un sélecteur les
  montre un par un, ou tous à la suite sur une ligne du temps.
- **Revoir en animation** : le même lecteur que la simulation (8 bis), avec les vraies données : le paquet refait
  le chemin, et la liste des événements montre les vrais changements « avant → après ».
- **État actuel** en fin de parcours (statut, assigné, tags, dossier, variables), et si le ticket a été modifié **à
  la main** entre deux blocs, c'est indiqué (« modifié par un agent à 14 h 02 »).
- La version du flux affichée est **celle qui a été exécutée**, même si le flux a changé depuis.

### Purger l'historique
- **Par période** : « supprimer l'historique **de plus de X jours** » ou **entre deux dates**, avec un filtre
  optionnel (boîte, flux, seulement les réussis…).
- **Par sélection** : cocher des lignes ou des tickets dans la liste, puis « Supprimer la sélection ».
- **Avant de supprimer** : le nombre de lignes concernées est affiché, puis une confirmation est demandée. Une
  grosse purge est faite **par lots** en arrière-plan (comme 7.4), avec une barre de progression : aucune requête
  lourde ne bloque le serveur.
- **Jamais** les tickets en cours d'attente : leur ligne `flow_runs` est conservée tant que le flux n'est pas fini,
  sinon ils perdraient leur place.
- **Réservé aux administrateurs**, et noté dans le journal technique (qui, quand, combien).
- **Automatique** : la purge selon la durée de conservation tourne chaque nuit, par lots. Un ticket supprimé
  définitivement dans FreeScout emporte son historique (même accroche que l'existant, priorité 1000).

## 9. Ressources et optimisation

| Point | Mesure |
|---|---|
| Événements sans flux concerné | index en cache, zéro requête |
| Attentes | une requête indexée par minute, lots de 200, pas de parcours des tickets |
| Déclencheur « planifié » | recherche limitée + lots + reprise au lot suivant |
| Flux publié | JSON figé en cache (invalidé à la publication) |
| Journal | lignes courtes, sans contenu d'e-mail, purge à 30 j (réglable) |
| Schéma | chargement à la demande (déjà en place), positions enregistrées par lots de 500 ms |
| Actions lentes | file d'attente, jamais dans la requête de l'agent |
| Expressions régulières | longueur limitée, `pcre.backtrack_limit` réduit pendant l'évaluation (pas de blocage sur un motif piège) |
| Front | aucune bibliothèque ajoutée ; le code par bloc est en modules déjà découpés (`view`, `layout`, `menu`…) |

## 10. Sécurité et données personnelles

- **Droits** : les mêmes qu'aujourd'hui. Un administrateur gère tout ; un agent avec le droit gère seulement les flux
  de ses boîtes. Tout passe par le serveur (CSRF, contrôle des boîtes).
- **Paramètres** : liste blanche par type de bloc, validée côté serveur. Aucun code ni SQL ne vient de
  l'utilisateur.
- **E-mails sortants** : limite par ticket et par heure, pour éviter qu'un flux mal réglé n'arrose les clients.
  Variables échappées.
- **Journal minimal** : identifiants et résultats seulement, aucun contenu d'e-mail ni adresse.
- **Export de pack** : remplace les adresses et identifiants par des variables (déjà le cas avec `VariableResolver`).
- **Audit** : qui a publié quelle version, et quand ; qui a activé le moteur externe de similarité.
- **Analyse de sécurité** (option, 4 ter) : désactivée par défaut, activation explicite avec avertissement (gratuit
  insuffisant, confidentialité), clé chiffrée, empreinte avant fichier, paramètres d'URL retirés, liste blanche.
- **Moteur externe de similarité** (option, 4 bis) : désactivé par défaut, activation explicite avec avertissement,
  clé d'API chiffrée, minimisation des données avant envoi, repli local en cas d'erreur.

## 11. Packs (format 2)

- Un pack contient des **flux** (blocs + liens + variables) au lieu de règles. Le validateur de schéma est étendu.
- Les packs au format 1 restent **importables** : ils sont convertis automatiquement en flux (voir 12).
- Export, regroupement et mise à jour d'un pack fonctionnent comme aujourd'hui, mais sur des flux.

## 11 bis. Guide pour l'IA (créer des JSON importables)

But : qu'une IA (Claude, ChatGPT…) puisse **écrire un pack valide du premier coup**, et le corriger seule si ce
n'est pas le cas.

- **`docs/guide-ia.md`**, un guide court et structuré, à donner tel quel à une IA :
  - le format du pack, champ par champ ;
  - les règles à respecter : identifiants, liens entre blocs, variables, pas de données personnelles ;
  - 5 exemples complets commentés (tri par sujet, relance après 2 jours, fermeture après 7 jours sans réponse,
    aiguillage par similarité, branche « non assigné ») ;
  - les erreurs fréquentes et leur correction ;
  - un **modèle de demande** à copier (« Écris un pack Workflow Packs qui… »).
- **Catalogue des blocs généré automatiquement** depuis le registre (14.1). Il liste, pour chaque type : les champs
  (type, valeurs permises, défaut, obligatoire), les sorties et un exemple.
  - La commande `php artisan workflowpacks:catalog --format=md|json` le produit.
  - Le guide l'inclut, ce qui le garde **toujours à jour** quand on ajoute un bloc. Un test vérifie que le guide
    publié correspond au registre.
- **Schéma JSON officiel** (`docs/pack.schema.json`), utilisable par les éditeurs et les IA pour l'autocomplétion
  et la vérification.
- **Validation avec des messages utiles à une IA** :
  - commande `workflowpacks:validate fichier.json`, et même contrôle à l'import ;
  - chaque erreur a un **chemin** (`flows[0].blocks[3].params.delay`), un **message clair** et une **suggestion**
    (« valeurs permises : i, h, d ») ;
  - une IA peut donc corriger son JSON en une passe.
- **Données personnelles** : le guide impose les variables (`{{support_email}}`, `{{team_lead}}`) à la place des
  adresses et des noms. Le validateur signale une adresse e-mail en clair.
- **Tout de suite** : une première version du guide pour le format actuel (format 1) peut être faite dès la 2.17.
  Elle sera mise à jour pour le format 2 en 3.3.

## 12. Transition depuis Workflows

1. **Convertisseur** règle → flux :
   - le type de déclenchement donne le déclencheur ;
   - les conditions donnent des blocs « si » (une voie OU = un bloc « l'une de ») ;
   - le délai « créé il y a plus de X » donne « Attendre X depuis l'arrivée » ;
   - les actions donnent des blocs action dans l'ordre.

   Les règles qui partagent les mêmes conditions deviennent **un seul flux** enchaîné, comme le fait déjà la vue
   flux.
2. **Rapport à blanc** avant toute écriture : ce qui sera créé, et ce qui n'a pas d'équivalent (signalé, jamais perdu).
3. Les flux convertis sont créés **inactifs**. Quand on en active un, la règle Workflows d'origine est **désactivée**
   dans le même geste, pour éviter une double exécution. Une sécurité détecte un ticket traité par les deux moteurs
   et prévient.
4. Workflows devient facultatif : le module fonctionne seul. Le diagnostic indique « moteur interne » et ne réclame
   plus Workflows.

## 13. Ce qui est réutilisé

- **Moteur** : `ConditionEvaluator` (conditions en mémoire), `Simulator` / `SimulationService` / `SampleMail`,
  `Timer`, `FlowLabels`.
- **Actions et maintenance** : `ImapMover` / `Retention` / `Refiler`, `History`, `Backup`, `LayoutStore`, commande
  `health`.
- **Interface** : tout le JS du schéma (`view`, `layout`, `menu`, `inspector`, `editor`, `sim`, `gestures`, `minimap`).

## 13 bis. Refresh et RefreshGlobal

Le module fonctionne **avec ou sans** chacun d'eux (4 cas testés : aucun, Refresh, RefreshGlobal, les deux). Les
intégrations existantes sont conservées, et le moteur s'ajoute par les mêmes voies **publiques et documentées**,
sans couplage au DOM.

### Refresh (apparence)
- **Couleurs et polices** : toutes les nouvelles pages (flux, réglages à interrupteurs, fenêtre de réapplication,
  listes repliées) utilisent les variables de Refresh (`--fd-*`), avec repli sur le style standard de FreeScout. Le
  mode sombre suit Refresh.
- **Entrée du module** : dans la barre de gauche de Refresh (`refresh.rail_items`) et, sur téléphone, dans le
  panneau « Profil » (existant).
- **Téléphone** : Refresh affiche une **barre d'onglets fixe en bas**. La barre d'action fixe (« Importer »,
  « Publier ») et le panneau à poignée du schéma se placent **au-dessus** d'elle, avec un décalage mesuré : jamais
  un bouton caché sous les onglets. C'est vérifié par un test navigateur avec Refresh actif.

### RefreshGlobal (fonctions)
- **Contrat stable conservé** ([api-dossiers.md](api-dossiers.md)) : l'action « Classer dans un dossier IMAP » du
  moteur continue d'écrire `workflowpacks_ticket_folders` exactement comme aujourd'hui. Les étiquettes, filtres et
  comptages par dossier de RefreshGlobal marchent donc sans changement, ainsi que l'ordre de suppression (priorités
  20 / 1000), `workflowpacks_retention`, les routes et `userCanUse()`.
- **Tags d'e-mail** : la condition « Tag d'e-mail » (inbox, spam, `folder:<nom>`…) devient un bloc, enregistré
  **seulement si RefreshGlobal ≥ 1.11 est actif**. C'est un bon exemple du registre de blocs : sans RefreshGlobal,
  le bloc n'existe pas, sans erreur.
- **Nouveaux déclencheurs** depuis ses événements publics : « Tag d'e-mail posé » (`refreshglobal.mail_tagged`) et
  « E-mail déplacé de dossier » (`refreshglobal.mail_moved`).

  Cela règle proprement le problème de synchronisation d'aujourd'hui : les tags étaient posés au moment où
  Workflows lançait déjà ses règles. Le flux démarre maintenant **après** le tag, au lieu de revérifier des règles.
- **FreeScout Interop** (protocole 1.0, `docs/INTEROPERABILITY.md` de RefreshGlobal) :
  - un manifeste `interop.json` déclare ce que Workflow Packs fournit et consomme, de façon versionnée et testée,
    comme RefreshGlobal ;
  - `fsinterop.modules` : Workflow Packs s'annonce (version, capacités) ;
  - `fsinterop.conversation.context` : sous `modules['workflowpacks']` uniquement, les flux en cours sur le ticket
    (« Relance factures · attend jusqu'à demain 9 h »), dans le respect des droits de l'utilisateur. Refresh ou
    RefreshGlobal peuvent l'afficher sur la page du ticket ;
  - une intention `workflowpacks.run-flow` (« Lancer un flux… ») est proposée dans la barre d'outils du ticket et
    dans le panneau d'actions sur téléphone, quand le module hôte la prend en charge ;
  - le pont navigateur `fs-interop.js` est la copie de référence partagée, sans dépendance.
- **Diagnostic** (`workflowpacks:health`, comme `refreshglobal:health`) : il affiche le mode de coopération avec
  chacun (absent, natif, dégradé) et vérifie que les points d'accroche utilisés existent encore après une mise à
  jour de FreeScout. C'est compatible avec UpdateGuard.

## 14. Architecture du code (propre, maintenable, extensible)

Objectif : **ajouter un bloc = ajouter un fichier PHP (et ses textes)**, sans toucher au moteur, au schéma ni au
format de pack.

### 14.1 Un bloc = une classe
```
Services/Engine/
  Engine.php            exécute un run : bloc courant → résultat → sortie → bloc suivant (ne connaît aucun type de bloc)
  Scheduler.php         réveil des attentes (requête indexée, lots, réclamation atomique)
  EventListener.php     événements FreeScout → index en cache → runs
  BlockRegistry.php     liste des types de blocs (filtre Eventy « workflowpacks.blocks » : un autre module peut en ajouter)
  Context.php           ce qu'un bloc reçoit : ticket, run, variables, services (pas d'accès global)
  Result.php            ce qu'un bloc rend : sortie choisie | attendre jusqu'à… | attendre un événement | erreur
Blocks/
  Contracts/Block.php   l'interface commune
  Triggers/NewMail.php, CustomerReplied.php, …
  Conditions/Subject.php, Status.php, Assigned.php, …
  Waits/Duration.php, Until.php, Event.php
  Actions/SetStatus.php, Assign.php, Note.php, ImapFolder.php, …
```

L'interface qu'implémente chaque bloc, et rien d'autre :
```php
interface Block
{
    public static function type(): string;          // 'action.status' (clé stable, utilisée dans les packs)
    public static function family(): string;        // trigger | condition | wait | action | util
    public static function fields(): array;         // paramètres décrits : type, valeurs, défaut, obligatoire
    public static function outputs(): array;        // sorties : ['next'] ou ['yes', 'no'] ou ['received', 'timeout']
    public static function title(array $params, string $locale): string; // titre composé dans la langue (« Attendre 2 h »)
    public static function variables(array $params): array; // variables produites : clé, type, valeurs possibles (selon les réglages)
    public function validate(array $params): array; // problèmes (texte traduit), [] si valide
    public function run(Context $ctx, array $params): Result;
    public function simulate(Context $ctx, array $params): Result; // même logique, sans écrire (simulation, test à blanc)
}

// facultatif : un bloc qui sait traiter un paquet de tickets en une fois (« Analyser » avec un fournisseur batch)
interface BatchBlock extends Block
{
    public function runBatch(array $contexts, array $params): array; // un Result par ticket, dans le même ordre
}
```

Les adaptateurs de notification suivent le même principe : une interface `Notifier` (`id()`, `available()` qui
vérifie que le module est actif et dans une version testée, `send()`), un registre, et le filtre
`workflowpacks.notifiers` pour en ajouter depuis un autre module.

### 14.2 Tout le reste se déduit de la description du bloc
- **Panneau et éditeur** : les champs sont générés à partir de `fields()` (liste, nombre + unité, texte, personne,
  dossier…). Pas de formulaire écrit à la main par bloc.
- **Schéma** : les ports viennent de `outputs()`. L'icône et la couleur viennent de la famille, avec une icône propre
  possible. Le menu « + » liste les blocs du registre, rangés par famille.
- **Validation** : `validate()` sert à la fois au serveur (publication, import de pack) et au panneau (bloc orange).
- **Packs** : un bloc est sérialisé en `{type, params}`. Un nouveau bloc est donc exportable sans changer le format.
- **Textes** : un fichier de langue `blocks.php` dans les 7 langues, avec des clés dérivées de `type()` (titre composé à l'affichage, champs, sorties, aide ; voir 8 quater). Une méthode `title(array $params, $locale)` du bloc compose son titre traduit.
- **Côté JS** : un registre `WPGraph.blocks` reçoit la description depuis le serveur (un seul appel, en cache). Le
  JS ne contient aucune liste de types en dur.

### 14.3 Règles de code
- **Moteur sans connaissance des blocs** : il ne fait que suivre les sorties. Un bloc n'appelle jamais un autre bloc.
- **Effets de bord isolés** : un bloc ne modifie le ticket que via de petits services (`TicketActions`, `Mailer`,
  `ImapMover`). Cela les rend faciles à remplacer dans les tests et à rendre compatibles avec une autre version de
  FreeScout.
- **Pas d'état global ni de requête dans les boucles** : tout passe par `Context`, et les données sont chargées en
  une fois.
- **Petites classes** : environ 100 lignes au plus par bloc, une responsabilité chacune. Commentaires sur le
  « pourquoi », comme aujourd'hui.
- **Compatibilité PHP 7.1** : pas de types de retour `mixed` ni de propriétés typées. Les signatures ci-dessus sont
  indicatives et seront adaptées à PHP 7.1.

### 14.4 Tests intégrés à la structure
- **Test de contrat commun**, exécuté automatiquement sur **chaque** bloc du registre :
  - `type()` est unique ;
  - les champs sont valides ;
  - les sorties sont non vides ;
  - les textes existent dans les 7 langues ;
  - `simulate()` n'écrit rien en base.

  Un bloc ajouté est donc testé d'office.
- **Tests du moteur avec de faux blocs** : enchaînement, attente, réveil, réclamation concurrente, limite anti-boucle,
  nouvelle tentative.
- **Un test par bloc**, pour son comportement propre.

### 14.5 Ajouter un bloc, concrètement
1. Créer `Blocks/Actions/MonAction.php` (l'interface ci-dessus).
2. L'enregistrer dans `BlockRegistry` (une ligne), ou via le filtre `workflowpacks.blocks` depuis un autre module.
3. Ajouter ses textes dans les 7 langues.
4. Écrire son test : le test de contrat passe tout seul.

Le schéma, le menu « + », le panneau, la validation, la simulation et les packs le prennent en charge sans autre
modification.

## 15. Plan par étapes

| Version | Contenu | Livrable |
|---|---|---|
| **2.17** (avant le moteur, utile tout de suite) | interfaces simplifiées : listes repliées avec résumé et recherche, barre d'action fixe en bas (aperçu d'import en premier), page de réglages par interrupteurs ; **guide IA pour le format actuel** (+ messages de validation avec chemin et suggestion) | les grands packs se lisent sans défiler jusqu'en bas |
| **3.0** (expérimental, option à cocher) | tables, moteur (événements + planificateur), **sortie « erreur » + alerte e-mail en cas d'échec** (destinataires, boîte, anti-avalanche), **variables typées** (le « Si » liste les variables et valeurs des blocs précédents), blocs : Nouvel e-mail, Si (sujet / expéditeur / statut), Attendre une durée, Statut, Assigner, Note, Dossier IMAP ; brouillon / publier ; schéma avec ports ; **historique + parcours d'un e-mail** (blocs allumés, liens colorés jusqu'à l'état actuel) ; **simulation façon Packet Tracer** (paquet animé, liste des événements avec décisions et changements, pas à pas, horloge virtuelle) ; tests | votre exemple « PR → dossier → 2 h → fermé » fonctionne sans Workflows |
| **3.1** | tous les déclencheurs, conditions et actions (e-mail au client, tags, déplacer, supprimer), **bloc « Notifier »** (FreeScout + adaptateurs des modules de notification installés), « Selon » (une sortie par valeur), heures ouvrées, **réponse automatique + bibliothèque de réponses** (garde-fous anti-boucle) | parité fonctionnelle avec Workflows |
| **3.2** | **les 6 blocs prioritaires** (4 sexies), **journal technique, filtres, export, purge par période ou par sélection**, **simulation complète** (événements injectés, plusieurs paquets, points d'arrêt, scénarios enregistrés rejoués à la publication, couverture), attente d'événement + « annuler si », compteurs sur les blocs, liste des tickets en attente, versions et retour arrière, **réappliquer aux tickets en cours / passés** (estimation, échelonnement, pause, suivi) | relances propres, changements appliqués à l'existant |
| **3.3** | guide IA + catalogue généré + `pack.schema.json` + `workflowpacks:validate` (format 2), packs format 2, convertisseur Workflows avec rapport à blanc, activation sans double exécution | migration des règles existantes |
| **3.4** | moteur interne par défaut, Workflows facultatif, nettoyage du code de compatibilité | module autonome |
| **3.5** | **similarité** : vecteurs par partie (local) **et moteur externe compatible OpenAI en option** (avertissement, clé chiffrée, minimisation, repli local), bloc « Ressemble à » / « Le plus proche de… », poids et seuil, outil « Trouver les e-mails similaires », affiner par exemples | tri simplifié par ressemblance |
| **3.6** | **analyse de sécurité** (VirusTotal, registre de fournisseurs, cache, empreintes, quotas, avertissements, **suivi des quotas + bascule automatique vers une autre solution**), condition « Résultat d'analyse », **liens interdits (filtres DNS au choix)**, **réputation de l'expéditeur** (SPF / DKIM / DMARC, DNSBL), **bloc « Lot »** (seuil, délai max, ordre, débit) | liens et pièces jointes analysés dans le flux |

Chaque étape : tests unitaires, d'intégration (Docker, instance neuve) et navigateur (PC / téléphone, **sans / avec
Refresh, sans / avec RefreshGlobal**), 7 langues, documentation, CHANGELOG, UpdateGuard.

## 16. Risques et points à vérifier

- **Événements FreeScout** : `conversation.created_by_customer` est confirmé (RefreshGlobal s'y accroche déjà). Les
  autres (réponses, statut, assignation) sont à confirmer sur 1.8.x avant de coder : c'est la première tâche de la 3.0.
- **Envoi d'e-mails** : il faut réutiliser le mécanisme de FreeScout (fils de conversation, signatures, modules de
  traduction) pour que les réponses apparaissent normalement dans le ticket.
- **Planificateur absent** chez certains : le diagnostic le détecte et l'affiche, car les attentes en dépendent.
- **Ampleur** : la 3.0 représente plusieurs PR. On avance version par version, et l'existant (Workflows) continue de
  fonctionner pendant ce temps.

## 17. Questions pour vous

1. **Option expérimentale d'abord** (recommandé), ou remplacement direct dès la 3.0 ?
2. Un flux pour **plusieurs boîtes** à la fois (pratique pour les packs), ou un flux par boîte (plus simple) ?
3. **Une fois par ticket** par défaut (recommandé), ou à chaque événement ?
4. Durée de conservation du journal : **30 jours** par défaut, ça vous va ?
5. Les compteurs sur les blocs (tickets passés / en attente) : affichés par défaut, ou sur demande ?
6. Réappliquer : seuil d'échelonnement imposé à **200 tickets**, lots de **50 par minute** par défaut, ça vous va ?
7. Similarité externe : **minimisation activée par défaut** (adresses, téléphones, signatures masqués ; 2 000 caractères max), ça vous va ?
8. Similarité : poids par défaut **sujet 40 % · corps 40 % · expéditeur 20 %** et seuil **75 %**, ça vous va ?
9. Analyse de sécurité : cache des résultats **24 h**, pièces jointes **jamais envoyées** par défaut (empreinte seulement), ça vous va ?
10. Filtres DNS : **Cloudflare 1.1.1.2 + 1.1.1.3** cochés par défaut (malveillant + adulte), en DNS-over-HTTPS, ça vous va ?
11. Alertes d'échec : **résumé toutes les heures** par défaut (plutôt qu'un e-mail immédiat), envoyé aux administrateurs, ça vous va ?
12. Bascule d'analyse : seuil à **90 %** du quota, et si plus rien n'est disponible, **attendre** la remise à zéro (plutôt que « inconnu »), ça vous va ?
13. Faire d'abord la **2.17 (interfaces simplifiées)** sur le module actuel, avant le moteur ? (recommandé : gain immédiat, et ces écrans serviront au moteur)
