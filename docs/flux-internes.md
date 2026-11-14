# Flux du moteur interne (expérimental, 3.0)

Le moteur interne exécute des **flux** sans le module Workflows. Chaque bloc est un objet indépendant : un
déclencheur, des conditions, des attentes et des actions, reliés entre eux. Ce que montre le schéma est exactement
ce qui s'exécute. Conception complète : [moteur-autonome.md](moteur-autonome.md).

## Activer

**Gérer › Paramètres › Workflow Packs › Moteur de flux interne** : interrupteur, désactivé par défaut. Une fois
activé, l'onglet **Flux (expérimental)** apparaît, pour les administrateurs.

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
| Attendre | 15 minutes … 100 000 jours ; le ticket attend, puis continue | suite |
| Statut | ouvert, en attente, fermé, indésirable | suite / erreur |
| Assigner | à un utilisateur ou à personne | suite / erreur |
| Note interne | texte ; `{{ticket.subject}}` est remplacé | suite / erreur |
| Classer dans un dossier | dossier du serveur de messagerie (option « Classer dans un dossier e-mail ») | suite / erreur |

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
