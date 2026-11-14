# Assistant IA (sans connexion)

**Workflow Packs › Assistant IA** (administrateurs, moteur de flux interne activé) fait écrire un flux par une IA
(Claude, ChatGPT…), **sans aucune connexion** : le module n'envoie rien. Vous copiez un dossier dans l'IA, puis vous
collez sa réponse dans le module.

## 1. Dossier pour l'IA

- Choisissez les **boîtes**, les **derniers jours**, le **nombre de tickets** (200 au plus) et, si besoin, un mot du
  sujet ; puis écrivez votre **demande** en une phrase, par exemple « les e-mails peu importants (newsletters,
  notifications) : fermer après 2 jours ».
- **Préparer le dossier** l'affiche, avec un bouton **Copier**. **Télécharger** donne le fichier `.json`.
- Le dossier contient :
  - la demande ;
  - les règles de la réponse attendue (format, blocs permis) ;
  - le catalogue des blocs, généré à partir du module, donc toujours à jour ;
  - un exemple complet ;
  - les tickets.
- Pour chaque ticket : son **numéro**, son sujet, le début du premier e-mail (sans citation ni signature), le domaine
  de l'expéditeur, le statut et des indices (liste de diffusion, réponse automatique, types de pièces jointes).

**Anonymisation, activée par défaut** : les adresses e-mail, téléphones, IBAN, liens (seul le domaine est gardé) et
longs numéros sont remplacés par des marqueurs (`[email]`, `[phone]`, `[link:domaine]`…), et l'adresse de
l'expéditeur n'est pas incluse. La case **« Ne pas anonymiser »** envoie le texte complet : un avertissement le
rappelle, car ce sont des données personnelles qui partent vers le service d'IA que vous utilisez.

## 2. Réponse de l'IA

Collez la réponse (un objet JSON, même entouré de ```json … ```), puis **Vérifier et tester** :

- la réponse est **vérifiée** comme un flux avant publication (blocs connus, champs complets, liens valides, aucune
  boucle sans attente). Chaque problème est listé avec le bloc et le champ concernés : renvoyez ces lignes à l'IA,
  elle corrige son JSON en une passe ;
- le flux est **joué à blanc sur les tickets du dossier** : le résultat est groupé par effet (« 12 tickets : Statut :
  Fermé », « 31 tickets : aucune action »), avec le lien de chaque ticket. Rien n'est modifié ;
- **Créer le flux** l'enregistre en **brouillon désactivé** et l'ouvre dans l'éditeur, où vous pouvez l'ajuster, le
  simuler, le publier et l'activer.

## Ce que l'IA peut écrire

- **Des conditions lisibles** : bloc « Si… » sur le sujet, le texte, l'expéditeur, le statut… avec « contient » ou une
  expression (regex, sans délimiteurs, insensible à la casse).
- **Le bloc « Ressemble à… »** : l'IA désigne des **tickets d'exemple par leur numéro** (`"examples": [101, 117]`) et
  un **seuil** en % (75 pour commencer). Workflow Packs calcule lui-même, en local, la ressemblance de chaque nouveau
  ticket avec ces exemples (sujet et texte, poids réglables). **Aucun vecteur ni aucun texte ne quitte le serveur.**
  La variable `similar.score` (en %) est ensuite disponible pour les blocs suivants.

## Agent connecté

Un agent peut aussi faire tout cela lui-même, par l'API du module : jeton, usage unique ou continu, déconnexion d'un
clic, publication seulement si elle est autorisée. Voir [agent-api.md](agent-api.md).
