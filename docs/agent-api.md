# API des agents IA connectés

Un agent (Claude, un script, un autre outil) peut lire des tickets et proposer des flux au moteur interne, **sans**
accès à FreeScout lui-même : un jeton, des droits limités, et une déconnexion d'un clic.

## Connecter un agent

**Workflow Packs › Assistant IA › Agents IA connectés › Connecter un agent** (administrateurs, moteur interne activé) :

| Réglage | Effet |
|---|---|
| Nom | affiché dans la liste et dans l'historique |
| Usage | **unique** : le jeton ferme après la **première proposition** ou au bout de **24 h** ; **continu** : jusqu'à l'échéance choisie |
| Durée d'accès | usage continu : **7, 30 (par défaut), 90 ou 365 jours**, ou sans limite ; à l'échéance le jeton ne marche plus. **Modifiable ensuite** sur la fiche de l'agent (à partir du jour même) ; un agent déconnecté ne peut pas être réactivé |
| Tickets lisibles | **facultatif** : les seules boîtes dont l'agent peut lire les tickets ; ses flux s'appliquent à ces boîtes. **Aucune cochée** : l'agent voit seulement les flux, aucun ticket |
| Ne pas anonymiser | décoché par défaut : adresses, téléphones, IBAN, liens et longs numéros masqués |
| Droits | un tableau **action × flux** (détail ci-dessous) ; chaque action a sa bulle **?** |
| Adresses IP autorisées | facultatif, **séparées par des virgules** : une adresse (`203.0.113.7`), une plage CIDR (`203.0.113.0/24`, `2001:db8::/32`) ou une plage **début - fin** (`192.168.1.10 - 192.168.1.50`, même famille, début ≤ fin). Seules celles-ci peuvent utiliser le jeton ; toute autre est refusée et notée dans l'Historique. Une entrée invalide : rien n'est enregistré |

Chaque réglage a une bulle **?** (survol ou clic sur PC, appui sur téléphone ; Échap ou un clic ailleurs la ferme).

### Droits (3.17)

Deux colonnes : **ses flux** (ceux que l'agent a proposés) et **les autres flux de ses boîtes**. Sur les autres flux,
l'agent n'agit que sur ceux qui **ne visent que ses boîtes** (un flux sur « toutes les boîtes » : seulement s'il les a
toutes).

| Action | Ses flux | Autres flux |
|---|---|---|
| Voir | toujours | `others.see` : listés et lus |
| Tester, simuler | toujours | `others.test` |
| Proposer de nouveaux flux | `own.propose` (brouillon désactivé) | — |
| Modifier (le brouillon) | `own.edit` | `others.edit` |
| Renommer | `own.rename` | `others.rename` |
| Supprimer (confirmation, sauvegarde d'abord) | `own.delete` | `others.delete` |
| Activer après un test réussi (cette version exacte, 24 h) | `own.activate` | `others.activate` |
| Désactiver (version publiée et brouillon gardés) | `own.deactivate` | `others.deactivate` |
| Publier et activer sans condition | `own.publish` | `others.publish` |

- Par défaut à la création : proposer, modifier, renommer ses flux. **Aucune case cochée** : lecture seule.
- Un outil sans le droit n'est **pas proposé** au connecteur (`unknown_tool` s'il est appelé). Par l'API : `{action}_denied`
  (403) pour ses flux, ou `read_only` si l'agent n'a aucun droit d'écriture ; pour un autre flux sans le droit :
  **404 `not_found`**, son existence n'est pas révélée. Hors de ses boîtes : `mailbox_denied`.
- `GET /me` renvoie `permissions` (la liste ci-dessus) ; `read_only`, `can_publish`, `can_activate`, `can_see_all`,
  `can_delete`, `can_deactivate` restent fournis, déduits de cette liste.
- Les agents créés avant 3.17 gardent exactement leurs droits (anciennes cases traduites dans le tableau ; « agir sur
  les autres flux » n'a jamais permis d'en **modifier** le contenu, `others.edit` reste donc décoché). « Désactiver »
  (3.18) n'est donné à aucun agent existant : à cocher sur sa fiche. Activer ne donne pas le droit de désactiver.

Le **jeton** (`wpa_…`) est affiché **une seule fois**, à la création. Seule son empreinte SHA-256 est gardée.
**Déconnecter** le rend inutilisable immédiatement. **Modifier les droits** (fiche de l'agent : nom, boîtes, anonymisation,
tableau des droits, adresses IP) garde la même clé et la même adresse ; le changement est noté dans
l'Historique (avant → après). Un agent **déconnecté ou expiré** peut être **supprimé** de la liste : sa clé disparaît
définitivement, les flux qu'il a proposés restent comme des flux ordinaires. Un agent déconnecté ne se modifie ni ne se
réactive : on en crée un nouveau. Chaque action de l'agent est notée dans **Historique** (type
« Agent IA connecté »).

## Connecteur Claude (chat)

Pour que **Claude dans le chat** (claude.ai, application de bureau ou mobile) voie les règles et propose des flux, sans
programme à écrire : connectez un agent (usage **continu** conseillé), puis copiez l'**adresse du connecteur** affichée
avec le jeton, et dans **Claude › Paramètres › Connecteurs › Ajouter un connecteur personnalisé**, collez-la.

Claude dispose alors de ces outils (protocole MCP) :

| Outil | Rôle |
|---|---|
| `get_catalog` | les blocs, les règles d'un flux valide et un exemple |
| `list_flows` | ses flux, et les autres avec « Voir » sur les autres flux (nom, actif, version publiée, boîtes, blocs et liens) ; `mine` : ceux qu'il a proposés |
| `simulate_flow` | simule un flux étape par étape (sans e-mail donné : un e-mail qui suit le chemin « oui » est généré) ; renvoie les étapes, un **récit** en clair et `replay_url`, une adresse où un administrateur **revoit la simulation sur le schéma** ; avec un jeton de progression, les étapes arrivent **en direct** |
| `list_tickets` | seulement si des boîtes sont cochées : les tickets récents de ces boîtes, anonymisés par défaut |
| `propose_flow` | « Proposer » : un nouveau flux, enregistré **en brouillon, désactivé** ; ses problèmes s'il n'est pas valide |
| `update_flow` | « Modifier » : remplace le brouillon de son flux, ou d'un autre de ses boîtes avec `others.edit` |
| `test_flow` | tester à blanc (rien n'est écrit) ses flux, ou les autres avec « Tester » sur les autres flux |
| `activate_flow` | « Activer après un test réussi » (ou « Publier sans condition ») : publie et active le flux après un test réussi de cette version |
| `deactivate_flow` | « Désactiver » : le flux ne démarre plus sur les nouveaux e-mails, comme depuis l'éditeur ; sa version publiée et son brouillon restent ; déjà désactivé : rien ne change (`changed: false`) |
| `publish_flow` | « Publier et activer sans condition » |
| `rename_flow` | « Renommer » ; seul le nom change |
| `delete_flow` | « Supprimer » : **seulement avec `confirm: true`**, après une sauvegarde (`backup_url`) |

Chaque outil n'est proposé que si l'agent a le droit correspondant, pour ses flux ou pour les autres (voir « Droits »).

L'adresse contient le jeton (les connecteurs personnalisés de Claude n'envoient pas d'en-tête) : ne la partagez pas ;
**Déconnecter** l'agent la rend inutilisable. FreeScout doit être joignable depuis Internet en **https**. Mêmes limites
que l'API : 60 requêtes par minute, rien d'envoyé aux clients, chaque action dans l'Historique.

Ouverte dans un navigateur, l'adresse affiche seulement « Le connecteur est joignable » (rien sur l'agent) : c'est le
moyen de vérifier qu'elle est accessible depuis Internet. C'est Claude qui vient chercher les données : FreeScout doit
être joignable en https depuis Internet (déjà le cas s'il l'est pour vous de l'extérieur ; sinon un tunnel, sans port
à ouvrir, ou le port 443 redirigé).

**« Le serveur a demandé une connexion (401) »** dans Claude, ou adresse refusée : l'adresse appartient à un agent
déconnecté, expiré ou inexistant (le connecteur répond alors 404 « adresse inconnue » ; l'Historique note la tentative).
Créez un nouvel agent (ou prolongez sa durée s'il a seulement expiré) et collez sa **nouvelle** adresse dans Claude ;
dans Claude, le connecteur se configure **sans authentification** (la clé est dans l'adresse).

**Derrière Cloudflare** : si Claude n'arrive pas à se connecter, voir dans Cloudflare › Security › Events si ses
requêtes sont bloquées (règles du pare-feu, « Bot Fight Mode ») et autoriser le chemin `/workflow-packs/mcp/`. Le champ
« Adresses IP autorisées » voit l'adresse de Cloudflare, pas celle de Claude, tant que Cloudflare n'est pas déclaré
comme proxy de confiance de FreeScout : le laisser vide dans ce cas.

## Utiliser l'API

Adresse : `https://votre-freescout/workflow-packs/api/v1`. Le jeton va dans l'en-tête
`Authorization: Bearer wpa_…`. Les réponses sont en JSON, et l'API accepte 60 requêtes par minute et par agent.
Elle répond `404` tant que le moteur interne est désactivé. Utilisez HTTPS.

| Méthode | Chemin | Rôle |
|---|---|---|
| GET | `/me` | l'agent : usage, boîtes, anonymisation, droits (`permissions`), expiration |
| GET | `/flows?all=1` | tous les flux, en lecture (`mine` : les siens) |
| POST | `/flows/{id}/rename` | renommer (`{"name": "…"}`) |
| POST | `/flows/{id}/delete` | supprimer (`{"confirm": true}`, sauvegarde d'abord ; `409 confirm_required` sinon) |
| POST | `/flows/{id}/simulate` | simuler (corps facultatif `{"mail": {…}}`) ; avec `Accept: text/event-stream`, les étapes arrivent une à une (événements `step`, puis `result`) |
| POST | `/flows/{id}/activate` | publier et activer son flux après un test réussi (`409 test_required` sinon) |
| POST | `/flows/{id}/deactivate` | désactiver le flux (droit « Désactiver ») |
| GET | `/catalog` | les règles d'un flux, les blocs (champs, sorties, variables) et un exemple, comme dans le dossier de l'assistant |
| GET | `/tickets?mailbox_id=&days=&limit=&search=` | tickets de ses boîtes : numéro, sujet, extrait, domaine, statut, indices (anonymisés selon le réglage) |
| GET | `/flows` | ses flux : brouillon, version publiée, actif |
| POST | `/flows` | propose un flux (même JSON que la réponse à l'assistant) : enregistré en **brouillon désactivé** |
| PUT | `/flows/{id}` | modifie le brouillon de son flux, ou d'un autre de ses boîtes avec `others.edit` (la version publiée continue de tourner) |
| POST | `/flows/{id}/test` | joue le brouillon à blanc sur des tickets (`ticket_ids`, ou les 100 derniers de ses boîtes) : effet par ticket |
| POST | `/flows/{id}/publish` | publie (et active avec `activate=1`) — seulement avec « Publier et activer sans condition » |

Erreurs : `401 invalid_token` (jeton inconnu, révoqué ou expiré), `403 mailbox_denied` / `read_only` /
`{action}_denied` (`propose_denied`, `edit_denied`, `publish_denied`…), `404 not_found` (inexistant, ou autre flux sans le droit), `422 invalid_flow` (avec `problems` : bloc, champ, message), `429 too_many_requests`.

```bash
curl -H "Authorization: Bearer wpa_…" https://votre-freescout/workflow-packs/api/v1/tickets?limit=50
curl -X POST -H "Authorization: Bearer wpa_…" -H "Content-Type: application/json" \
     --data @flux.json https://votre-freescout/workflow-packs/api/v1/flows
```

## Sécurité

- **HTTPS obligatoire** : l'API et le connecteur refusent les requêtes en http (`https_required`), pour que le jeton ne
  circule jamais en clair ; seule une adresse locale (`localhost`) est acceptée, pour les tests. Derrière un proxy
  inverse, FreeScout doit savoir que la connexion d'origine est en https (proxy de confiance).
- **Jeton** : 192 bits aléatoires, seule son empreinte SHA-256 est gardée, affiché une seule fois ; **déconnexion**
  immédiate, **échéance** automatique (usage continu : 30 jours par défaut).
- **Anti-force brute** : au-delà de 20 jetons invalides par minute depuis une adresse, elle est refusée pour la minute ;
  chaque agent est limité à 60 requêtes par minute.
- **Adresses IP autorisées** par agent (facultatif ; adresses, plages CIDR ou début - fin, séparées par des virgules) ;
  la dernière adresse utilisée est affichée.
- **Droits par action**, séparés pour ses flux et pour les autres flux de ses boîtes (voir « Droits »).
- **Droits** : l'agent ne lit que les tickets des boîtes cochées (aucune : aucun ticket), anonymisés par défaut ; ne
  modifie que ses flux ; ne propose rien en lecture seule ; ne publie que si on le lui a permis.
- **Flux bornés** : un flux d'agent ne peut nommer dans ses blocs que les boîtes de l'agent (« classer dans un dossier »)
  ni prendre comme exemples (« Ressemble à… ») que des tickets de ces boîtes ; il ne peut être publié par l'agent que
  s'il vise uniquement ces boîtes. Un flux sur **toutes les boîtes** (aucune choisie) ne peut être publié ou activé que par un agent qui a **toutes les boîtes existantes** cochées ; une boîte ajoutée ensuite et non cochée le bloque de nouveau. Un agent sans boîte ne publie jamais.
- **Lecture des flux** : `list_flows` montre tous les flux (leur configuration, pas les tickets) ; c'est voulu pour que
  Claude voie les règles. N'utilisez pas de données personnelles dans les textes des blocs si cela pose problème.
- **Traçabilité** : chaque lecture de tickets, proposition, modification, publication et adresse refusée est notée dans
  l'Historique.
- Réponses jamais mises en cache (`Cache-Control: no-store`), requêtes de plus de 1 Mo et lots MCP de plus de 20 messages refusés, aucune session ni
  cookie (hors du groupe « web » de FreeScout).
- Les vecteurs de ressemblance sont calculés sur le serveur. Le module ne fait lui-même **aucun appel réseau** : c'est
  l'agent (ou Claude) qui l'appelle.
