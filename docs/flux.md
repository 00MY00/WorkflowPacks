# Vue flux et simulation

Dans **Workflow Packs › Schéma**, le bouton **Vue flux** affiche les règles de chaque boîte comme des chaînes de
blocs, **un bloc par instruction**. Le bouton **Vue d'ensemble** revient au schéma habituel (boîtes → règles →
destinations). Le choix est gardé pour chaque utilisateur.

Les règles de Workflows restent la seule référence : la vue flux est une lecture de ces règles, rien de plus n'est
enregistré.

## Les blocs

| Bloc | Symbole | Ce qu'il représente |
|---|---|---|
| Boîte | boîte de réception | la boîte où arrivent les e-mails |
| Condition (une par bloc, contour pointillé) | selon ce qu'elle regarde : cercle du statut (statut du ticket), silhouette (assigné à), lignes de texte (sujet, texte, destinataire), arobase (client), boîte de réception (nouveau / réponse), étiquette (tag), sablier (date), entonnoir (autre module) | les e-mails concernés par le flux (« Sujet contient facture », « Statut = En attente »…), ou « Tous les e-mails » ; un groupe OU est un seul bloc « a OU b » |
| Test (branche) | branche qui se sépare, contour en pointillés | « Assigné à quelqu'un : oui / non », « Une réponse a été envoyée : oui / non » |
| Minuteur | sablier | « Après 1 heure · depuis l'arrivée » |
| Statut | cercle (coche pour Fermé) | « Fermé », « En attente », « Actif », « Spam » |
| Dossier e-mail | dossier | le dossier du serveur de messagerie où l'e-mail est classé |
| Personne | silhouette | assignation |
| Note, envoi, suppression, tag, boîte… | icône de l'action | les autres actions |

Les blocs sont placés en grille : une ligne par voie, une colonne par étape, chaque case aussi grande que son plus
grand bloc. **Deux blocs ne se chevauchent jamais**, quelle que soit la longueur des textes. En vue flux, les blocs ne
se déplacent pas à la main : leur position est calculée.

## Comment les règles deviennent un flux

- Les règles qui ont **les mêmes conditions** (minuteurs et tests mis à part) forment **un seul flux**.
  « Après 1 heure → classer dans Factures » et « Après 2 jours → marquer comme fermé » sont deux règles de
  Workflows, mais une seule chaîne dans la vue flux.
- La condition « Nouveau » seule est le **départ** de la chaîne (l'arrivée de l'e-mail) : une règle « à l'arrivée →
  ajouter une note » est dans le même flux que ses règles à minuteur.
- Dans un flux, les règles avec les mêmes tests forment une **voie** (une ligne), triée par minuteur : d'abord les
  règles à l'arrivée, puis par délai, puis dans l'ordre de Workflows.
- Un **minuteur** est une condition de date « plus ancien que » : créé, en attente, dernière réponse de l'équipe ou
  du client, « pas dans les derniers X ». Les délais comptent **depuis l'arrivée de l'e-mail** par défaut. Workflows
  les vérifie régulièrement par sa tâche planifiée : il faut que la **tâche planifiée de FreeScout (cron)** tourne.
- Une règle désactivée a des liens en pointillés.

## Ajouter une étape

Le **+** d'un bloc (ou la liste « Ajouter une étape » de son panneau) propose ce qui peut suivre. Rien n'est enregistré
directement : l'éditeur s'ouvre, déjà rempli, pour vérifier puis enregistrer (la nouvelle règle est désactivée).

- Sur un bloc **de condition** :
  - « Si “En attente” depuis 7 jours : marquer comme fermé » ;
  - « Branche “pas de réponse” : fermer après 2 jours » ;
  - « Branche “non assigné” : assigner après 1 heure » ;
  - « Puis, après 1 heure : marquer comme fermé / classer dans un dossier ».
- Sur un **minuteur** ou une **action** :
  - « Puis, après … : marquer comme fermé » ou « classer dans un dossier », avec un délai plus long que celui de
    l'étape (1 heure, puis 1 jour, 2 jours, puis un jour de plus) ;
  - « Ici aussi : marquer comme fermé », « classer dans un dossier », quand la règle ne le fait pas encore.

- Partout (règle, étape, dossier, statut, personne, tag, boîte) : **toutes les actions du catalogue** de Workflows,
  dans une liste « Ajouter une action… » (statut, assigner, déplacer, note, réponse, transfert, notification,
  supprimer, tag, dossier, actions des autres modules). Sur un dossier ou une autre destination, l'action est ajoutée
  à la règle qui envoie là (plusieurs règles : on choisit la règle).

Le nouveau bloc garde les conditions du flux et de sa branche : il s'ajoute à la même chaîne.

## Les tests « assigné » et « réponse envoyée »

Ce sont deux conditions de Workflow Packs, proposées dans le catalogue de Workflows (groupe « État du ticket ») et
**évaluées par Workflows lui-même**, comme la condition « Tag d'e-mail » :

| Condition | Vraie quand |
|---|---|
| Assigné à quelqu'un : oui | le ticket a une personne assignée |
| Une réponse a été envoyée : oui | le ticket a un message publié écrit par l'équipe (les notes et les brouillons ne comptent pas) |

« non » est l'inverse. Une seule requête légère par vérification. Sans Workflow Packs, Workflows n'obtient pas de
réponse pour ces conditions : la règle ne se déclenche plus, ce qui est sans danger.

## Simuler

Le bouton **Simuler** envoie un **faux e-mail** dans les règles d'une boîte : sujet, expéditeur, texte, et au besoin
« déjà assigné », « l'équipe a déjà répondu », les tags d'e-mail (avec RefreshGlobal). Les règles désactivées sont
simulées aussi (case cochée par défaut), pour tester un pack avant de l'activer.

**Tester une règle** : choisissez-la dans « Règle à tester » (ou cliquez sur **Tester cette règle** dans son panneau).
Le faux e-mail est préparé pour remplir ses conditions :
- « est égal à » : la valeur ; « contient », « commence par », « finit par » : la valeur dans un court texte ;
- expression régulière : un exemple tiré de ses premières alternatives, vérifié avant d'être proposé ;
- « assigné », « réponse envoyée », tags d'e-mail, statut : les options correspondantes ;
- dans un groupe OU, la première condition qui peut être remplie.

Ce qui ne peut pas être rempli (condition d'un autre module, « Réponse », une personne précise…) est listé sous le
choix. Les minuteurs ne concernent pas l'e-mail : la simulation attend leur délai. Le résultat commence par la règle
testée : appliquée (et quand) ou non (et pourquoi).

Le parcours s'allume ensuite dans la vue flux, bloc après bloc (une « énergie » verte suit les liens) :

- **vert** : le faux e-mail est passé par ce bloc ;
- **grisé** : la règle ne s'est pas appliquée ;
- **orange en pointillés** : la règle utilise une condition d'un autre module, qui n'a pas pu être simulée.

Le panneau donne le verdict (« E-mail traité : 3 règles appliquées » ou « Aucune règle ne s'est appliquée »), puis
pour chaque règle appliquée : **quand** (à l'arrivée, +1 h, +2 j…), ses actions et **ce qu'elles ont changé**
(« Statut : Actif → Fermé », « Dossier e-mail : — → Factures »). Les règles non appliquées sont listées avec la raison
et leurs conditions (✓ / ✗ / ?). L'état final du ticket termine le rapport. Un clic sur un bloc montre ce que le faux
e-mail y a fait.

### Ce que la simulation garantit

- **Rien n'est écrit, rien n'est envoyé** : le faux e-mail et son ticket n'existent qu'en mémoire, le temps du
  calcul, puis disparaissent. Aucun ticket, client, message ni tâche n'est créé, aucun dossier du serveur de
  messagerie n'est touché. Les réponses, transferts et notifications sont affichés « serait envoyé à … ».
- L'historique note seulement qu'une simulation a eu lieu, avec ses compteurs, jamais le contenu du faux e-mail.
- Il faut pouvoir gérer les workflows de la boîte.
- Les textes du faux e-mail sont bornés (sujet 255 caractères, texte 20 000).

### Comment elle calcule

Le moteur de Workflows (module payant) n'est pas disponible pour Workflow Packs. La simulation suit donc le
comportement **documenté** de Workflows (voir [analyse.md](analyse.md)) :

1. **à l'arrivée** : les règles sans condition de date, dans l'ordre de Workflows ;
2. **puis à chaque délai atteint** (délai + 1 minute, comme la tâche planifiée) : les règles avec une condition de
   date qui ne se sont pas encore appliquées ;
3. une règle s'applique au plus une fois par ticket ;
4. textes comparés en minuscules (sauf les expressions régulières), « Nouveau » vrai seulement à l'arrivée,
   « dernière réponse de l'équipe » fausse tant que personne n'a répondu ; une réponse envoyée par une règle compte
   comme une réponse pour la suite ;
5. une condition ou une action d'un autre module n'est jamais devinée : elle est signalée « non simulée ».

Les règles manuelles (lancées par un agent depuis un ticket) ne sont pas simulées.

## Une nouvelle règle et son pack

Une règle créée hors pack dans une boîte qui utilise un pack affiche, dans son panneau, **« Ajouter au pack … »**.
Après l'enregistrement d'une nouvelle règle, le schéma la sélectionne : le bouton est donc visible tout de suite. Il
ouvre la page Export avec le pack et la règle choisis : l'aperçu, puis **« Regrouper dans … »**, et la règle fait
partie du pack (voir « Exporter ou regrouper dans un pack » dans le [README](../README.md)).
