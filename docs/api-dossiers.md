# Dossier e-mail actuel des tickets : API pour les autres modules

Depuis la version **1.14.0**, Workflow Packs garde, pour chaque ticket dont il a rangé l'e-mail, le **dossier e-mail
actuel** sur le serveur. D'autres modules peuvent lire cette information, par exemple RefreshGlobal pour afficher le
dossier ou filtrer la liste.

- **Durée de conservation :**
  - sans limite de durée, contrairement à l'historique (90 jours) ;
  - effacée **seulement avec le ticket**.
- **Mise à jour :**
  - à chaque rangement, quand l'e-mail est déplacé ou trouvé déjà en place ;
  - lors du nettoyage automatique du dossier.
- **Lecture seule** pour les autres modules : seul Workflows Packs écrit dans la table.
- **Disponibilité :** l'API n'existe que si la table `workflowpacks_ticket_folders` existe, c'est-à-dire si
  Workflow Packs 1.14.0 ou plus récent est installé et migré.

## Filtres Eventy (recommandé)

Les filtres Eventy ne créent aucune dépendance. Si Workflow Packs n'est pas installé, Eventy renvoie la valeur par
défaut.

```php
// un ticket : tableau ou null
$folder = \Eventy::filter('workflowpacks.ticket_folder', null, $conversationId);

// plusieurs tickets : [id du ticket => tableau], seulement ceux qui ont un dossier
$folders = \Eventy::filter('workflowpacks.ticket_folders', [], $conversationIds);
```

Chaque tableau contient :

| Clé | Exemple | Sens |
|---|---|---|
| `conversation_id` | `198` | id du ticket |
| `mailbox_id` | `3` | boîte du ticket |
| `folder` | `Factures/Fournisseurs` | dossier tel qu'écrit dans les règles : niveaux séparés par `/`, **sans** préfixe |
| `label` | `[WP] Factures › Fournisseurs` | dossier à afficher |
| `path` | `INBOX.[WP] Factures.Fournisseurs` | dossier sur le serveur e-mail (UTF-8), avec son délimiteur |
| `state` | `filed` | `filed` : l'e-mail est dans ce dossier ; `cleaned` : le nettoyage automatique l'en a retiré (corbeille ou supprimé) |
| `filed_at` | `2026-10-12 07:40:00` | dernier rangement (ou dernière vérification « déjà rangé ») |
| `cleaned_at` | `null` | date du nettoyage, si `state` vaut `cleaned` |
| `updated_at` | `2026-10-12 07:40:00` | dernière modification |

## Être prévenu d'un changement

L'action `workflowpacks.ticket_folder_changed` est déclenchée quand le dossier, l'état ou le chemin d'un ticket
change :

```php
\Eventy::addAction('workflowpacks.ticket_folder_changed', function ($conversationId, $current, $previous) {
    // $current, $previous : tableau ci-dessus, ou null
}, 20, 3);
```

## SQL, en lecture seule

Pour filtrer une liste de tickets ou compter par dossier, la table peut être jointe. Ses colonnes et index sont
**stables** :

```
workflowpacks_ticket_folders
  conversation_id  unsigned int, unique     → conversations.id
  mailbox_id       unsigned int
  folder           varchar(191)             "Factures/Fournisseurs" (comme dans les règles)
  label            varchar(191)             "[WP] Factures › Fournisseurs"
  path             varchar(191)             dossier sur le serveur
  state            varchar(10)              filed | cleaned
  filed_at, cleaned_at, created_at, updated_at   timestamps
  index (mailbox_id, state), index (folder)
```

```php
// tickets encore dans un dossier, par dossier, pour des boîtes données
\DB::table('workflowpacks_ticket_folders')
    ->whereIn('mailbox_id', $mailboxIds)->where('state', 'filed')
    ->groupBy('folder')->select('folder', \DB::raw('count(*) as tickets'))->get();
```

Avant d'utiliser la table, vérifier qu'elle existe : `\Schema::hasTable('workflowpacks_ticket_folders')`.

## Suppression d'un ticket

La ligne d'un ticket est effacée dans l'action FreeScout `conversations.before_delete_forever`, avec la **priorité
1000**. Un module qui écoute cette action avec la priorité habituelle (20) **lit encore** le dossier du ticket.
S'il traite la suppression plus tard, par exemple en file d'attente, il doit **copier le dossier** au moment de
l'action : la ligne n'existera plus ensuite.

Une vérification quotidienne efface aussi les lignes des tickets supprimés autrement (directement dans la base,
par exemple).

## Ce que lit RefreshGlobal (à garder stable)

RefreshGlobal 1.8.0 et plus récent (`Services/AutoFolders.php`) lit, **en lecture seule**, les éléments suivants de
Workflow Packs. Ne pas les renommer ni changer leur sens sans adapter RefreshGlobal en même temps :

| Élément | Utilisé pour |
|---|---|
| table `workflowpacks_ticket_folders` : `conversation_id`, `mailbox_id`, `folder`, `label` (séparateur `" › "`), `path`, `state` (`filed`), `filed_at`, `updated_at` | étiquette des tickets, filtre et comptage par dossier |
| ordre de suppression : RefreshGlobal à la priorité 20, Workflow Packs à 1000 sur `conversations.before_delete_forever` | RefreshGlobal copie `path` dans sa file « corbeille du serveur » avant que la ligne disparaisse |
| table `workflowpacks_retention` : `folder`, `number`, `metric` (`i`, `h`, `d`), `mode` (`trash`, `delete`) | colonne « nettoyage » de sa page de synthèse |
| routes `workflowpacks.graph` et `workflowpacks.history` | liens vers le schéma et l'historique |
| `WorkflowPacksServiceProvider::userCanUse($user)` | ces liens ne sont montrés qu'aux utilisateurs qui ont accès au module |

RefreshGlobal détecte la fonction avec `App\Module::isActive('workflowpacks')`, `Schema::hasTable` et
`Schema::hasColumns`.

## À savoir

- Le dossier est celui du **dernier e-mail du client rangé** par le module dans ce ticket. Les e-mails plus anciens
  du même ticket peuvent être ailleurs.
- **Tickets jamais rangés :** un ticket dont l'e-mail n'a jamais été rangé par le module n'a pas de ligne. Le filtre
  renvoie `null`.
- **Reprise de l'historique :** à l'installation de la 1.14.0, les rangements déjà présents dans l'historique sont
  repris. Seuls ceux des 90 derniers jours y figurent.
