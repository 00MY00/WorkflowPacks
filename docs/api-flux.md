# Ce que font les flux : API pour les autres modules

Depuis la version **3.20.0**, Workflow Packs dit aux autres modules ce que ses flux (moteur interne) changent sur les
tickets, et leur permet de demander un **flux temporaire** pour une liste de tickets. Exemple : RefreshGlobal compte
les e-mails traités par chaque flux et propose de traiter ceux qu'aucun flux n'a touchés.

- **Tout passe par Eventy, à l'intérieur de FreeScout** : aucune adresse web, aucun accès réseau, rien n'est envoyé
  ailleurs.
- **Aucune dépendance** : sans Workflow Packs (ou avant 3.20), Eventy renvoie la valeur par défaut et aucune action
  n'est appelée.
- **Aucun texte** : un changement ne contient jamais le texte d'un e-mail ni celui d'une note. Seulement des numéros,
  le nom du flux et des valeurs connues (statut, numéro de l'utilisateur assigné, dossier).
- **Jamais pour un essai** : les simulations, essais à blanc et estimations ne déclenchent rien. Seules les vraies
  modifications sont annoncées.
- **Sans risque pour le flux** : si un module plante en recevant l'action, l'erreur est notée dans le journal de
  Laravel et le flux continue.

## Savoir si l'API est là

```php
$wp = \Eventy::filter('workflowpacks.info', []);
// []  : Workflow Packs absent ou trop ancien
// ['version' => '3.20.0', 'api' => 1, 'engine' => true, 'max_tickets' => 5000]
$ready = !empty($wp['api']) && !empty($wp['engine']);
```

`engine` vaut `false` quand le moteur interne est éteint (Gérer › Réglages › Workflow Packs) : aucun flux ne tourne.

## Action : un flux a changé un ticket

```php
\Eventy::addAction('workflowpacks.ticket_changed', function ($conversationId, array $change) {
    // enregistrer $change dans sa propre table
}, 20, 2);
```

Une action par changement : un flux qui ferme le ticket puis ajoute une note donne deux appels.

| Clé | Exemple | Sens |
|---|---|---|
| `v` | `1` | version du format |
| `conversation_id` | `1234` | id du ticket |
| `mailbox_id` | `3` | boîte du ticket |
| `flow_id` | `12` | id du flux |
| `flow_name` | `Fermer les notifications` | nom du flux à ce moment-là |
| `version` | `3` | numéro de la version publiée qui a tourné |
| `run_id` | `845` | passage du ticket dans le flux (plusieurs changements d'un même passage ont le même) |
| `event_id` | `9921` | étape du flux |
| `block` | `b4` | bloc du flux |
| `field` | `status` | ce qui a changé (tableau ci-dessous) |
| `before` | `active` | valeur avant |
| `after` | `closed` | valeur après |
| `origin` | `new_mail` | comment le ticket est entré dans le flux (tableau ci-dessous) |
| `temporary` | `false` | `true` pour un flux temporaire |
| `at` | `2026-11-19 10:42:00` | date et heure dans le fuseau de FreeScout (`APP_TIMEZONE`), comme ses autres dates enregistrées |

| `field` | `before` / `after` |
|---|---|
| `status` | `active`, `pending`, `closed`, `spam` |
| `assignee` | id de l'utilisateur, `null` pour personne |
| `folder` | dossier e-mail tel qu'écrit dans les règles (`Factures/Fournisseurs`) |
| `note` | `null` / `true` (note ajoutée, sans son texte) |
| `deleted` | `false` / `true` |
| `push` | `null` / nombre d'utilisateurs notifiés |
| autre | bloc d'un autre module : son nom seulement, `before` et `after` à `null` |

| `origin` | Sens |
|---|---|
| `new_mail` | le flux s'est déclenché à l'arrivée de l'e-mail |
| `reapply` | un administrateur l'a appliqué aux tickets existants |
| `agent` | un agent IA connecté l'a appliqué aux tickets existants |
| `temporary` | flux temporaire, lancé sur sa liste de tickets |

Les tickets entrés dans un flux avant 3.20 sont annoncés avec `new_mail`.

## Lectures

### Les changements de quelques tickets

```php
$changes = \Eventy::filter('workflowpacks.ticket_changes', [], $conversationIds, ['field' => 'status']);
// [1234 => [change, change, …]]   les mêmes clés que l'action ; un ticket sans changement n'a pas de clé
```

- au plus **1 000 tickets** par appel et **5 000 changements** renvoyés, du plus ancien au plus récent ;
- `field` est facultatif : un seul type de changement.

Cette lecture sert au **rattrapage** : compter ce qui a été fait avant l'installation de l'écoute, ou afficher sur un
ticket « fermé par le flux X ».

### Statistiques par flux

```php
$stats = \Eventy::filter('workflowpacks.flow_stats', [], [
    'from'      => '2026-10-01',          // "Y-m-d" ou "Y-m-d H:i:s" ; par défaut les 30 derniers jours
    'to'        => '2026-10-31',
    'mailboxes' => [3, 5],                // facultatif
    'field'     => 'status',              // facultatif
    'after'     => 'closed',              // facultatif : valeur après ("closed", "true", "12")
    'origins'   => ['new_mail'],          // facultatif
]);
// ['ok' => true, 'from' => …, 'to' => …, 'scanned' => 812, 'truncated' => false, 'flows' => [
//     ['flow_id' => 12, 'flow_name' => 'Fermer les notifications', 'exists' => true, 'temporary' => false,
//      'tickets' => 87, 'changes' => 87], …]]   du flux qui a touché le plus de tickets au moins
```

- `tickets` : nombre de tickets différents ; `changes` : nombre de changements.
- `exists` : `false` quand le flux a été supprimé depuis. Son nom reste connu, car il est gardé avec chaque ticket.
- Période de 10 ans au plus. Au plus **200 000 changements** examinés ; au-delà, `truncated` vaut `true` et il faut
  réduire la période. Pour des statistiques sur une longue durée, enregistrez plutôt les actions au fil de l'eau.

### Tickets sans action d'un flux

```php
$untreated = \Eventy::filter('workflowpacks.untreated', [], [
    'mailboxes' => [3],                       // facultatif : toutes les boîtes
    'statuses'  => ['active', 'pending'],     // par défaut
    'period'    => 30,                        // jours en arrière (1 à 3650), 0 : tous
    'limit'     => 1000,                      // 1 à 5000
]);
// ['ok' => true, 'total' => 42, 'ids' => [1201, 1207, …], 'truncated' => false, 'filters' => […]]
```

Un ticket est « sans action » quand aucun flux n'y a jamais rien changé. Si vous avez votre propre définition des
tickets non traités, calculez la liste vous-même et passez-la au flux temporaire.

## Flux temporaire pour une liste de tickets

Un flux temporaire reçoit une liste de tickets. Il est lancé **une fois** sur cette liste seulement, quels que soient
le statut et la date des tickets, puis **se supprime tout seul**. Il ne se déclenche jamais sur les nouveaux e-mails,
n'est jamais montré aux agents IA et n'est jamais exporté.

```php
$out = \Eventy::filter('workflowpacks.temp_flow', [], [
    'ticket_ids'    => [1201, 1207, 1210],   // obligatoire, au plus 5 000
    'name'          => 'E-mails non traités d’octobre',   // facultatif
    'origin'        => 'refreshglobal',      // facultatif : [a-z0-9_-], 40 caractères au plus
    // le contenu, au choix :
    'from_flow'     => 12,                   // une copie de ce flux (sa version publiée, sinon son brouillon)
    // 'draft'      => ['blocks' => […], 'links' => […]],   // ou un flux donné (format de docs/flux-internes.md)
    //              aucun des deux : un flux vide, à construire dans l'éditeur
    'launch'        => false,                // true : publié et lancé tout de suite
    'confirm_risky' => false,                // obligatoire en plus quand le flux supprime des tickets ou notifie
]);
```

Réponse :

```php
['ok' => true, 'flow_id' => 57, 'flow_name' => '…', 'state' => 'draft', 'tickets' => 3, 'total' => 0, 'done' => 0,
 'started' => 0, 'waiting' => 0, 'expires_at' => '2026-11-26 10:00:00',
 'edit_url' => 'https://…/workflow-packs/flows?flow=57',          // ouvrir l'éditeur
 'launch_url' => 'https://…/workflow-packs/flows/57/reapply',     // « Tickets existants » : estimation puis lancement
 'job' => null]                                                   // lancé : ['total' => 3]
```

**Usage conseillé** : sans `launch`, puis rediriger l'administrateur vers `edit_url`. Il construit le flux dans
l'éditeur, puis le lance avec « Lancer sur les N tickets ». Il voit l'estimation et un essai à blanc avant.

| Erreur (`'ok' => false`) | Cause |
|---|---|
| `engine_off` | moteur interne éteint |
| `forbidden` | l'utilisateur n'est pas un administrateur actif |
| `no_tickets` | aucun ticket valide (ids inconnus, supprimés, pas des nombres) |
| `too_many_tickets` | plus de 5 000 tickets (`max`) |
| `flow_not_found` | `from_flow` inconnu (ou temporaire) |
| `invalid_flow` | flux impossible à publier (`problems`) ; avec `launch`, le flux est gardé en brouillon (`edit_url`) |
| `confirm_risky_required` | le flux supprime ou notifie : renvoyer avec `confirm_risky` après accord ; le flux est gardé en brouillon |
| `nothing_to_do` | plus aucun ticket à traiter |
| `internal` | erreur inattendue (journal de Laravel) |

**Droits** : seulement pour un **administrateur actif**. C'est l'utilisateur connecté qui compte, quel que soit
`user_id`. Quand personne n'est connecté (planificateur, ligne de commande), `user_id` doit désigner un administrateur
actif. Le module qui appelle doit aussi vérifier ses propres droits avant de proposer le bouton.

### Suivre un flux temporaire

```php
$state = \Eventy::filter('workflowpacks.temp_flow_status', [], $flowId);
// state : draft | running | paused | stopped | waiting (des tickets attendent dans un délai) | done | deleted
```

```php
\Eventy::addAction('workflowpacks.temp_flow_finished', function ($flowId, array $summary) {
    // ['flow_id' => 57, 'flow_name' => '…', 'origin' => 'refreshglobal', 'tickets' => 3, 'handled' => 3,
    //  'reason' => 'done' | 'expired']
}, 20, 2);
```

**Quand il se supprime** :

- dès que son lancement est fini et qu'aucun ticket n'attend plus dans le flux ;
- sinon au plus tard 30 jours après le lancement, ou 7 jours s'il n'a jamais été lancé. Les tickets qui attendaient
  encore quittent alors le flux, comme quand on supprime n'importe quel flux.

Ce qu'il a fait reste dans l'Historique et dans les statistiques.

## Exemple : écouteur prêt à copier

```php
// dans le boot() du module, seulement si Workflow Packs est là
if (\Module::isActive('workflowpacks')) {
    \Eventy::addAction('workflowpacks.ticket_changed', function ($conversationId, $change) {
        try {
            if (!is_array($change) || ($change['v'] ?? 0) !== 1) {
                return;
            }
            $key = ['event_id' => (int) $change['event_id'], 'field' => mb_substr((string) $change['field'], 0, 40)];
            if (\DB::table('monmodule_flow_changes')->where($key)->exists()) {
                return;   // déjà enregistré
            }
            \DB::table('monmodule_flow_changes')->insert($key + [
                'conversation_id' => (int) $conversationId,
                'mailbox_id'      => (int) $change['mailbox_id'],
                'flow_id'         => (int) $change['flow_id'],
                'flow_name'       => mb_substr((string) $change['flow_name'], 0, 191),
                'after'           => is_scalar($change['after']) ? mb_substr(var_export($change['after'], true), 0, 191) : null,
                'origin'          => mb_substr((string) $change['origin'], 0, 20),
                'created_at'      => $change['at'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('[MonModule] workflowpacks.ticket_changed: '.$e->getMessage());
        }
    }, 20, 2);
}
```

Un index unique sur `(event_id, field)` dans la table du module garantit qu'aucun changement n'est compté deux fois.
