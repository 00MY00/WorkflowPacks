# Flux du moteur interne

Le moteur interne exécute des **flux** sans le module Workflows. Chaque bloc est un objet indépendant : un
déclencheur, des conditions, des attentes et des actions, reliés entre eux. Ce que montre le schéma est exactement
ce qui s'exécute. Conception complète : [moteur-autonome.md](moteur-autonome.md).

## Activer

**Gérer › Paramètres › Workflow Packs › Moteur de flux interne** : interrupteur, **activé par défaut**
depuis 3.3 (sans flux actif, il ne fait rien). L'onglet **Flux** apparaît pour les administrateurs. Une installation
qui l'avait désactivé explicitement le garde désactivé.

**Sans le module Workflows** : activez ce moteur. Le module fonctionne alors entièrement (Flux, Assistant IA, agents
connectés) ; seules les pages des packs de règles Workflows (import, export, schéma des règles) indiquent qu'elles ont
besoin de Workflows. Pour les administrateurs, les onglets **Importer**, **Exporter** et **Schéma** mènent alors à
l'import et à l'export des packs de flux et à l'éditeur des flux, sans message. Le diagnostic (et UpdateGuard) le
note comme une simple remarque.

Tant qu'il est désactivé, rien ne change : les règles Workflows fonctionnent comme avant, aucune tâche planifiée
du moteur ne tourne, et les événements de FreeScout ne coûtent rien. Les deux peuvent fonctionner en même temps :
un flux et une règle Workflows qui visent les mêmes tickets agissent **tous les deux**.

## Créer un flux

1. **Nouveau flux** : il commence par son déclencheur, **Nouvel e-mail**.
2. Le **« + »** placé sur une sortie ajoute le bloc suivant, relié et placé à droite. On peut aussi tirer le rond
   d'une sortie vers un bloc pour les relier. Un clic sur un lien le sélectionne ; **Suppr** supprime la sélection.
3. Un clic sur un bloc ouvre ses réglages dans le panneau. Le bloc **Si…** ne propose que les **variables des blocs
   placés avant lui** (sujet, expéditeur, statut…). Les opérateurs s'adaptent à leur type, et les valeurs d'une
   liste fermée (les statuts) se choisissent dans une liste.
4. **Boîtes** : celles où le flux s'applique (aucune cochée = toutes). **Une fois par ticket** : un ticket déjà passé
   par le flux n'y entre plus.
5. Le brouillon est enregistré au fil des modifications. Un bloc orange indique ce qui manque.
6. **Publier** fige une version, après vérification : un seul déclencheur, tous les blocs reliés, aucune boucle sans
   attente, des variables fournies par un bloc placé avant. Les tickets déjà en cours gardent leur version.
7. **Actif** : le flux s'exécute (il faut l'avoir publié).

| Bloc | Rôle | Sorties |
|---|---|---|
| Nouvel e-mail | départ : un client écrit un nouvel e-mail dans une boîte du flux | suite |
| Si… | teste le ticket ou le résultat d'un bloc précédent | oui / non |
| Ressemble à… | compare le ticket à des tickets d'exemple (leurs numéros), en local ; seuil en %, poids sujet / texte ; variable `similar.score` | oui / non |
| Attendre | 15 minutes … 100 000 jours ; le ticket attend, puis continue | suite |
| Statut | ouvert, en attente, fermé, indésirable | suite / erreur |
| Assigner | à un utilisateur ou à personne | suite / erreur |
| Note interne | texte ; `{{ticket.subject}}` est remplacé | suite / erreur |
| Classer dans un dossier | dossier du serveur de messagerie (option « Classer dans un dossier e-mail ») | suite / erreur |
| Supprimer le ticket | — ; dossier **Supprimés** de FreeScout (récupérable tant qu'il n'est pas vidé), dernier bloc de la branche | erreur |
| Notification push | destinataires (l'assigné, les utilisateurs de la boîte ou un utilisateur), titre (vide = sujet), texte ; variables remplacées. Nécessite le module [WebPush](https://github.com/altmenorg/freescout-webpush) | suite / erreur |

### Se déplacer et choisir dans le schéma

| | Souris | Au doigt (téléphone, tablette) |
|---|---|---|
| Se déplacer | clic gauche glissé sur le fond (ou bouton du milieu) | un doigt glissé sur le fond |
| Zoomer | molette | deux doigts (pincer, écarter) |
| Déplacer un bloc | clic gauche glissé sur le bloc ; s'il fait partie des blocs choisis, ils bougent ensemble | un doigt glissé sur le bloc |
| Choisir un bloc | clic droit (encore un clic droit pour en ajouter ou en retirer un ; Maj ou Ctrl + clic gauche aussi) | appui long sur le bloc (de même pour en ajouter ou en retirer) |
| Choisir une zone | clic droit glissé sur le fond : rectangle de sélection (Maj ou Ctrl : ajouté aux blocs déjà choisis) | appui long sur le fond, puis glisser |
| Ne plus rien choisir | clic sur le fond, ou **Échap** | toucher le fond |

**Suppr** supprime les blocs choisis (le déclencheur reste), **Ctrl+A** les choisit tous. **Agrandir** affiche
l'éditeur sur toute la page (**Réduire** ou **Échap** pour revenir) ; sur téléphone, la barre tient alors sur une
ligne qui défile, et le panneau n'apparaît que quand un bloc est choisi.

## Appliquer un flux aux tickets déjà là

Un flux s'applique d'abord aux **nouveaux** e-mails. Pour l'appliquer aussi aux tickets déjà présents : bouton
**Tickets existants** de l'éditeur, ou **OK** à la question posée juste après chaque **Publier**.

| Choix | Effet |
|---|---|
| **Tickets existants** | les tickets des boîtes du flux, sur une période (7, 30, 90, 365 jours ou tous, selon leur date de création) et avec les statuts cochés (ouvert, en attente, fermé), passent dans le flux comme si leur e-mail venait d'arriver. « Une fois par ticket » reste respecté : un ticket déjà passé n'y repasse pas. |
| **Tickets en cours** | les tickets en attente dans une ancienne version continuent dans la version publiée, au même bloc. Si ce bloc n'existe plus, le ticket sort du flux (rien n'est annulé). |

1. **Estimer et simuler** : le nombre de tickets concernés, la durée prévue et une **simulation à blanc** sur les
   20 tickets les plus récents (ce que chacun ferait, regroupé par résultat ; rien n'est exécuté).
2. Si le flux contient **Supprimer le ticket** ou **Notification push**, un avertissement s'affiche et il faut cocher
   une confirmation.
3. **Lancer** : le traitement se fait **par lots** (10 à 500 tickets, un lot toutes les 1 à 60 minutes), par la tâche
   planifiée du moteur. La charge reste plafonnée, même pour des milliers de tickets.
4. Suivi sur la même page : barre de progression, **Pause**, **Reprendre**, **Arrêter** (les tickets déjà traités le
   restent). Un seul traitement à la fois par flux ; il reprend où il s'était arrêté, même après un redémarrage.
   Le début et la fin sont notés dans l'Historique.

Le flux n'a pas besoin d'être activé pour être appliqué aux tickets existants : c'est la version publiée qui est
utilisée.

## Notifications push (module WebPush)

Le bloc **Notification push** confie la notification au module **WebPush** (FreeScout installé comme application
sur téléphone et ordinateur). Workflow Packs n'envoie rien lui-même : WebPush chiffre et livre la notification aux
appareils des agents, et ses **heures calmes** sont respectées. Un clic sur la notification ouvre le ticket.

- Seuls les utilisateurs actifs qui ont accès à la boîte du ticket sont notifiés.
- Sortie **erreur** si le module WebPush est absent ou désactivé, ou s'il n'y a personne à notifier (ticket non
  assigné, utilisateur sans accès).
- En simulation et en test à blanc, rien n'est envoyé : le parcours indique seulement qui serait notifié.
- Exemple : *Nouvel e-mail › Si le sujet contient « urgent » › Notification push aux utilisateurs de la boîte*.

## Packs de flux (import, export)

**Flux › Importer un pack de flux** : un pack fourni avec le module, un fichier `.json` (8 Mo au plus, sans limite de nombre de flux) ou le JSON
collé. L'aperçu montre chaque flux (créé ou brouillon mis à jour), ses problèmes éventuels, les **paramètres** du
pack à renseigner et les boîtes concernées (aucune cochée = toutes). Les flux sont enregistrés **en brouillon,
désactivés** : vérifiez-les, publiez-les puis activez-les un par un. Réimporter le même pack met à jour les
brouillons ; la version publiée continue de tourner jusqu'à la prochaine publication.

**Exporter les flux** télécharge tous les flux comme pack (blocs et liens, sans les boîtes).

Format :

```json
{"format": "workflowpacks-flows", "version": 1, "key": "mon-pack", "name": "Mon pack", "description": "…",
 "pack_version": "1.0.0",
 "params": [{"key": "newsletter_sender", "label": "Expéditeur", "description": "…", "required": false}],
 "flows": [{"key": "factures", "name": "Factures", "once": true,
            "blocks": [{"id": "b1", "type": "trigger.new_mail"}, {"id": "b2", "type": "action.note",
                        "params": {"body": "Facture de {{param.newsletter_sender}} — {{mailbox.name}}"}}],
            "links": [{"from": "b1", "port": "next", "to": "b2"}]}]}
```

- `key` (pack et flux) : minuscules, chiffres, `.`, `_`, `-` ; c'est elle qui retrouve un flux déjà importé. Écrite
  autrement (ou absente), elle est corrigée à partir du texte donné ou du nom ; en double, elle reçoit `-2`, `-3`…
- `{{param.<clé>}}` est remplacé, à l'import, par la valeur saisie (texte simple).
- Un flux seul (réponse de l'Assistant IA) est accepté comme pack d'un flux.
- Positions des blocs facultatives : ils sont placés automatiquement.

**Passer d'un pack de règles Workflows au pack de flux** (exemple : « Tri des e-mails (multilingue) ») :

1. Packs › **Retirer le pack** (une sauvegarde est proposée).
2. Flux › **Importer un pack de flux** › *tri-emails-multilingue-flux.json* ; renseignez l'expéditeur de newsletter.
3. Ouvrez chaque flux, vérifiez-le, **Publier**, puis activez-le.

Le transfert par e-mail des alertes de sécurité n'existe pas encore comme bloc : la note ajoutée le rappelle.

## Simuler

**Simuler** : saisissez un sujet, un expéditeur et un texte, puis **Lancer** (ou **Pas à pas**). Un point suit les
liens du schéma, chaque bloc traversé s'allume, et la liste montre l'heure virtuelle, la décision (« Sujet « PR #42 »
contient « PR » → oui ») et les changements (« Statut : Ouvert → Fermé »). Les attentes passent instantanément.
**Rien n'est écrit, rien n'est envoyé.**

## Parcours d'un ticket

Sans bloc sélectionné, le panneau propose **Parcours d'un ticket** : saisissez son numéro. Les blocs traversés
s'allument, les liens empruntés sont colorés, et la liste donne les vrais changements, jusqu'à l'état actuel ou
l'heure de réveil d'une attente.

## Fonctionnement et ressources

- Un nouvel e-mail démarre les flux qui l'écoutent. Un index en cache évite toute requête quand aucun flux n'écoute.
- Une attente est enregistrée avec son **heure de réveil**. Chaque minute, `workflowpacks:engine-tick` réveille
  seulement les tickets dont l'heure est passée : une requête indexée, 200 tickets au plus. Ce n'est jamais un
  parcours de tous les tickets.
- Un même ticket n'est jamais traité deux fois en même temps, même avec deux planificateurs (MySQL et PostgreSQL).
- **Erreur passagère** : nouvel essai après 1, 10 puis 60 minutes. Sinon, la sortie **erreur** si elle est reliée,
  ou l'arrêt du ticket, noté dans **Historique** (type « Flux (moteur interne) »).
- Les changements sont faits au nom de la personne qui a publié la version, avec les méthodes de FreeScout (le ticket
  affiche les lignes habituelles).
- L'historique des blocs ne contient aucun corps d'e-mail : seulement la décision résumée (60 caractères au plus) et
  les changements.

## Prévu ensuite

Autres déclencheurs (réponse du client ou de l'équipe, statut, assignation), autres blocs (réponse automatique,
notifications, similarité, analyse de sécurité…), alertes par e-mail, réapplication d'une nouvelle version, packs au
format 2 et convertisseur depuis Workflows : voir le plan dans [moteur-autonome.md](moteur-autonome.md).
