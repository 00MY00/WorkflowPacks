# Ranger les e-mails dans des dossiers de la boîte e-mail

Option de Workflow Packs (1.4.0), **désactivée par défaut**. Elle ajoute aux règles Workflows l'action **« Ranger dans
un dossier e-mail [WP] »**.

Quand une règle s'applique, l'e-mail reçu est **déplacé sur le serveur de messagerie (IMAP)**, par exemple de la
boîte de réception vers `[WP] Factures/Fournisseurs`. Le dossier est créé s'il n'existe pas. La boîte e-mail reste
ainsi rangée quand on l'ouvre dans Outlook, Thunderbird ou un webmail. **Le ticket dans FreeScout ne change pas** :
pour lui changer de statut, l'assigner ou l'étiqueter, ajoute les actions habituelles à la même règle.

## Activer

**Gérer › Paramètres › Workflow Packs › Rangement dans les dossiers e-mail** :

| Réglage | Effet |
|---|---|
| Action « Ranger dans un dossier e-mail » | ajoute l'action au catalogue Workflows de toutes les boîtes. Si l'option est désactivée, l'action disparaît, les règles qui l'utilisent deviennent invalides pour Workflows et les déplacements en attente ne sont pas faits |
| Préfixe des dossiers (`[WP]` par défaut) | ajouté devant chaque dossier créé, pour montrer qu'il est géré par le module. Lettres, chiffres, espaces, crochets, parenthèses, `-` et `_`, 20 caractères au plus |

## Écrire le dossier dans une règle

La valeur de l'action est un chemin avec `/` entre les niveaux :

| Valeur dans la règle | Dossier sur le serveur |
|---|---|
| `Factures` | `[WP] Factures` |
| `Factures/Fournisseurs` | `[WP] Factures` › `Fournisseurs` |
| `Clients/{{mailbox.name}}` (dans un pack) | `[WP] Clients` › `Support` pour la boîte « Support » |

- Au plus 4 niveaux et 60 caractères par niveau. Accents, espaces, `&` et points sont acceptés.
- Sont refusés : `*`, `%`, `"`, `\`, les caractères de contrôle et les noms commençant par un point.
- Le préfixe ne concerne que le dossier du haut. Les sous-dossiers sont créés dedans.

Exemple de règle dans un pack :

```json
{
  "key": "factures-fournisseurs",
  "name": "Factures fournisseurs",
  "type": "automatic",
  "conditions": { "all": [ { "type": "subject", "operator": "contains", "value": "facture" } ] },
  "actions": [
    { "type": "wp_imap_folder", "value": "Factures/Fournisseurs" },
    { "type": "status", "value": "3" }
  ]
}
```

Si l'option est désactivée sur l'instance qui importe le pack, la prévisualisation bloque la règle avec le message
« l'action … est désactivée : activez-la dans Gérer › Paramètres › Workflow Packs ».

## Comment ça marche

1. Workflows applique la règle et appelle l'action par son filtre d'extension `workflow.perform_action`. Le module
   Workflows n'est pas modifié.
2. Workflow Packs met le déplacement dans la **file d'attente de FreeScout**, ce qui ne ralentit jamais la réception
   des e-mails.
3. Le travail en file se connecte au serveur avec les réglages IMAP de la boîte, avec le même client que FreeScout,
   OAuth compris. Il :
   - crée les dossiers manquants, niveau par niveau ;
   - retrouve l'e-mail par son **Message-ID** dans les dossiers que FreeScout relève (boîte de réception par défaut) ;
   - le déplace avec la commande IMAP `MOVE`. Si le serveur ne la connaît pas, il copie l'e-mail puis marque
     l'original comme supprimé, sans purge (`EXPUNGE`) : les autres messages marqués supprimés dans la boîte ne sont
     pas touchés.
4. Le résultat est écrit dans le journal de FreeScout (`[WorkflowPacks] [imap]`) :
   - `moved` : e-mail déplacé ;
   - `already` : il était déjà dans le dossier ;
   - `not_found` : introuvable, par exemple déjà déplacé à la main ;
   - `failed` : le serveur ne répond pas ou refuse. Dans ce cas, le module réessaie 5 minutes plus tard, 3 tentatives
     au total.

Le délimiteur du serveur (`/` ou `.`) est détecté automatiquement. Les serveurs qui rangent tous les dossiers sous
`INBOX` (délimiteur `.`, par exemple Courier) reçoivent `INBOX.[WP] Factures`.

## Sécurité

- Désactivé par défaut, réservé aux administrateurs (réglage), et le préfixe est nettoyé à la lecture.
- Le module ne crée et ne remplit **que** des dossiers qui portent le préfixe. Il ne supprime, ne renomme et ne vide
  jamais un dossier.
- Seul le dernier e-mail du client dans la conversation est déplacé, jamais les réponses envoyées depuis FreeScout.
- Le déplacement se fait dans la boîte e-mail de la conversation, avec ses propres identifiants : aucun accès à une
  autre boîte.

## À savoir

- Avec « Appliquer aux conversations précédentes » (désactivé par défaut dans les imports), Workflows applique la
  règle aux anciennes conversations, et leurs e-mails sont alors rangés aussi.
- Si un dossier `[WP] …` fait partie des dossiers que FreeScout relève, les e-mails déplacés ne sont pas importés une
  deuxième fois : ils sont déjà lus et FreeScout reconnaît leur Message-ID.
- Testé avec un vrai serveur IMAP (GreenMail, délimiteur `.`) dans les tests d'intégration. Le comportement du module
  Workflows officiel, payant, avec une action ajoutée par filtre suit sa copie publique analysée
  ([analyse.md](analyse.md)) : à vérifier sur ton instance avec une règle de test.
