Développe un module complémentaire pour FreeScout nommé **Workflow Packs**, qui permet d’importer, d’exporter et de mettre à jour des ensembles de règles du module officiel **Workflows / Flux de travail**.

L’objectif est de configurer facilement le même tri des e-mails sur plusieurs boîtes, avec des variables remplacées automatiquement pour chaque boîte.

**1. Analyse préalable obligatoire**

Avant de coder, inspecte le code de FreeScout et du module Workflows fourni dans le projet. Vérifie :

- Les versions et les contraintes PHP/Laravel.
- Le format réel des conditions, des actions et des groupes ET/OU.
- Les modèles, validations, permissions et points d’extension disponibles.
- Les paramètres de déclenchement, d’ordre, de répétition et d’application aux anciennes conversations.

N’invente aucune API, table, constante ou fonctionnalité. Si le code nécessaire manque, indique précisément les fichiers à fournir.

**2. Architecture**

Crée un module autonome suivant les conventions FreeScout.

- Ne modifie aucun fichier du cœur ni du module Workflows.
- Le module officiel reste responsable de l’exécution des flux.
- Workflow Packs gère uniquement leurs modèles, leur import, leur export et leur mise à jour.
- Stocke les informations de suivi dans les tables propres au nouveau module.
- Utilise les mécanismes officiels disponibles après inspection du code.
- Vérifie la compatibilité et affiche une erreur compréhensible si une fonctionnalité n’est pas prise en charge.

**3. Format JSON versionné**

Utilise des fichiers `.json` contenant notamment :

- `format` : `freescout-workflow-pack`
- `version` : version du schéma JSON
- `key` : identifiant stable du pack
- `name` et `description`
- `workflows` : liste des règles

Chaque règle contient :

- Une `key` stable dans le pack.
- Un nom et une description.
- Son type automatique ou manuel.
- Ses paramètres d’exécution.
- Ses conditions.
- Ses actions.
- Les dépendances éventuelles, comme Tags.

Les conditions utilisent `all` pour ET et `any` pour OU. N’accepte que les combinaisons réellement représentables par Workflows. Refuse les structures incompatibles avec une explication précise, sans changer silencieusement leur logique.

Définis un schéma JSON et fournis des exemples conformes à l’implémentation réelle.

**4. Variables**

Prends en charge au minimum :

- `{{mailbox.id}}`
- `{{mailbox.email}}`
- `{{mailbox.name}}`

La boîte cible est sélectionnée dans l’interface. Les variables sont remplacées à l’import pour chaque boîte : elles ne sont pas évaluées dynamiquement par Workflows.

Permets aussi de déclarer des paramètres propres au pack, renseignés à l’import, comme une adresse de notification.

Contraintes :

- Aucune exécution de PHP, JavaScript ou expression arbitraire.
- Variable inconnue ou obligatoire manquante : import bloqué avec explication.
- Conservation des types attendus, notamment pour les identifiants numériques.
- Échappement adapté au contexte ; dans une regex, une adresse e-mail doit être insérée comme texte littéral.
- Conservation du modèle original pour pouvoir réappliquer les variables ultérieurement.

**5. Interface d’import**

Prévois un parcours simple :

1. Charger le JSON.
2. Valider le fichier et les dépendances.
3. Choisir une ou plusieurs boîtes autorisées.
4. Renseigner les paramètres du pack.
5. Prévisualiser les règles résolues pour chaque boîte.
6. Afficher les créations, modifications, conflits et éléments inchangés.
7. Confirmer l’import.

Par défaut :

- Les nouvelles règles sont désactivées.
- L’application aux conversations précédentes est désactivée.
- Aucun e-mail n’est envoyé et aucune conversation existante n’est traitée par l’import lui-même.
- Les actions sensibles sont clairement signalées : envoi, transfert, suppression, changement de boîte.

Les droits doivent être contrôlés côté serveur pour chaque boîte et chaque opération.

**6. Mises à jour sans doublons**

Identifie chaque règle par l’association :

`pack.key + workflow.key + boîte cible`

Un second import identique ne doit créer aucun doublon.

Lors d’une mise à jour :

- Affiche les différences avant application.
- Détecte les modifications manuelles intervenues depuis le dernier import.
- Propose de conserver la version locale, de la remplacer ou d’ignorer cette règle.
- Ne modifie jamais une règle étrangère au pack.
- Ne supprime pas automatiquement une règle absente du nouveau fichier.
- Conserve l’état actif/inactif des règles existantes sauf choix explicite.

Utilise des transactions et indique clairement si l’atomicité est garantie par boîte ou pour tout l’import. Une erreur ne doit pas laisser un état partiellement modifié dans le périmètre annoncé.

**7. Export**

Permets d’exporter une sélection de flux ou un pack.

- Pour les règles issues d’un pack, conserve les variables du modèle original.
- Pour les flux existants, propose explicitement de remplacer certaines valeurs par des variables.
- N’essaie pas de deviner automatiquement que toute adresse présente correspond à la boîte courante.
- Signale les références locales à résoudre à l’import : utilisateurs, boîtes, tags et champs personnalisés.
- N’exporte aucun mot de passe, jeton ou secret.

**8. Dépendances et sécurité**

- Vérifie que Workflows est installé et utilisable.
- Vérifie les modules requis par chaque règle.
- Si Tags manque, bloque les règles concernées ou propose de les ignorer explicitement ; ne retire jamais leurs actions silencieusement.
- Valide les opérateurs, valeurs, regex, taille du fichier et profondeur des conditions.
- Respecte les protections CSRF et les permissions FreeScout.
- Échappe les valeurs affichées dans l’interface.
- Ne journalise pas de secrets.

**9. Pack d’exemple**

Fournis un pack de tri d’e-mails multilingue couvrant :

- Alertes de sécurité.
- Codes de connexion.
- Factures et reçus.
- Échecs de paiement.
- Messages demandant une action.
- Alertes techniques.
- Newsletters d’expéditeurs explicitement renseignés.
- Tests FreeScout.

Exemples à reconnaître :

- `Security alert`
- `2-Step Verification turned on`
- `Google Verification Code`
- `Чек + (1) подарок. 1 384,61 ₽`
- `[Action Advised] Review Google Calendar events scheduled after Nov 1, 2026`
- `FreeScout Email de test`

Le reçu russe doit être reconnu comme document comptable même s’il contient une promotion. N’utilise pas `noreply`, « cadeau » ou un lien de désabonnement comme justification suffisante pour fermer un message.

Les règles du pack sont désactivées par défaut et ne suppriment aucun message.

**10. Livrables et validation**

Fournis :

- Le module complet et installable.
- Les migrations nécessaires.
- Le schéma JSON versionné.
- Le pack d’exemple.
- Une documentation en français : installation, import, export, variables, mises à jour et limites.
- Les versions effectivement vérifiées.
- Des tests ciblés sur l’idempotence, les variables, les permissions, les conflits, les dépendances et l’annulation transactionnelle en cas d’erreur.

Commence par analyser le code disponible, explique brièvement l’architecture retenue, puis réalise l’implémentation. Distingue clairement les fonctionnalités testées de celles qui restent à vérifier sur une instance FreeScout.

Ajoute une solution de verification que le module workflow exist, le style Refrech ci il exist, une solution de mise a joure inspire toi du project 'RefreshGlobal' et utilise des numereau de verssion du module et crée un script d'installation pour simplifier l'install

Ajoute une vue shematiser des règle importer et la séléction des boite conserner par les règles avent des les apliquer

Dans le readmy ajoute l'aide a la creation des json pour un humain et une ia