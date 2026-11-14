# Guide de style « Workflow Packs » (maquette G)

Implémentation : [`WorkflowPacks/Public/css/workflowpacks-g.css`](../WorkflowPacks/Public/css/workflowpacks-g.css),
chargée par toutes les pages du module (après `workflowpacks.css`). Icônes et aides de présentation :
`Services/Ui.php` (`icon()`, `initials()`, `family()`, `category()`, `infoBlocks()`).

## Objectif

Réduire la sensation de « trop de texte » sans supprimer d'information. Toute information a une forme : pastille,
tuile, icône ou interrupteur. Aucun texte d'interface ne reste « nu » s'il représente un état, un compteur ou une
action.

## 1. Jetons

**Police** : Inter (400, 500, 600, 700), repli « Segoe UI », sans-serif. Taille de base 14 px. Aucune police n'est
téléchargée, pour la confidentialité et la Content-Security-Policy de FreeScout : Inter est utilisée si elle est
installée.

| Rôle | Valeur |
|---|---|
| Texte principal (titres, noms, libellés de boutons) | `#312e81` |
| Texte secondaire (descriptions) | `#5b6472` |
| Texte tertiaire (onglets inactifs, texte des blocs d'info) | `#4b5563` |
| Icônes neutres | `#6b7280` |
| Primaire (bouton principal, compteur, bordure de sélection) | `#4f46e5` |
| Lien | `#4338ca`, survol `#312e81` |
| Fond de page | `#ffffff` |
| Surface douce (blocs d'info, bloc SI) | `#f6f7f9` |
| Surface neutre (barre d'onglets, pastilles neutres) | `#eef0f4` |
| Surface indigo clair (icône du pack, bloc ALORS) | `#eef2ff` |
| Fond d'élément sélectionné | `#f5f5ff` |
| Bordure de section / de tuile / séparateur | `#dfe3ea` / `#e2e6ec` / `#eef0f4` |
| Bordure de bouton et de champ | `#cfd5de` |
| Succès | point `#059669`, texte `#047857` sur `#e7f6ef` |
| Danger | texte `#b42318`, bordure `#f3c6c1` |
| Interrupteur éteint | `#8b94a3` |

**Pastilles (fond / texte)** :
- info (« Automatique ») : `#e0f2fe` / `#075985` ;
- indigo (« Toutes les boîtes », « 70 règles ») : `#e0e7ff` / `#3730a3` ;
- identifiant technique : `#fff1f2` / `#be123c`, graisse 500 ;
- neutre : `#eef0f4` / `#374151`.

**Familles de boîtes (fond / texte des initiales)** : `#ede9fe` / `#5b21b6`, `#dbeafe` / `#1e40af`,
`#ccfbf1` / `#115e59`, `#fef3c7` / `#92400e`. La famille est **calculée à partir du nom** de la boîte : la même
boîte a toujours la même couleur, et aucun nom n'est écrit dans le code. Les mêmes quatre couleurs servent aux
catégories de règles.

**Rayons** :
- 8 px : onglet, jeton ;
- 10 px : bouton, bouton-icône, carré d'icône 36 px, initiales ;
- 12 px : barre d'onglets, bloc d'info, SI / ALORS, tuile, carré 40 px ;
- 14 px : barre de sélection ;
- 16 px : section du pack ;
- 999 px : pastilles.

**Ombres** : légère `0 1px 2px rgba(16,24,40,.10)` ; onglet actif `0 1px 2px rgba(16,24,40,.12)` ; barre flottante
`0 10px 24px rgba(16,24,40,.25)`. Aucune autre ombre, aucun dégradé.

**Typographie** :

| Élément | Taille / graisse |
|---|---|
| Titre de page | 26 px / 700 |
| Nom du pack | 17 px / 700 |
| Nom de règle | 16 px / 700 |
| Titre de bloc d'info | 15 px / 700 |
| Nom de boîte, bouton | 14 px / 600 |
| Onglet | 14 px / 500 (600 si actif) |
| Description | 13 px / 400, `#5b6472` |
| Texte de bloc d'info | 12 px, interligne 1,45, `#4b5563` |
| Pastille | 12 px / 600 |
| Étiquettes SI / ALORS | 11 px / 700, espacement 0,08 em, majuscules |

La hiérarchie se fait par la graisse et la couleur : indigo foncé gras pour l'important, gris normal pour le
secondaire.

**Espacements** :
- marge de page 32 px, largeur maximale 1400 px, centrée ;
- zone cliquable de 44 px de haut au minimum ;
- écart entre tuiles 8 px, entre blocs d'info 10 px ;
- icônes 16 px, trait 2 px, extrémités arrondies, sans remplissage ;
- pas d'emoji.

## 2. Composants

- **En-tête de page** : titre et description à gauche ; à droite, le sélecteur de boîtes, « Exporter » (secondaire,
  icône) et « Importer un pack » (le seul bouton primaire de l'écran).
- **Onglets** : contrôle segmenté (conteneur `#eef0f4`, rayon 12, marge 4). L'onglet actif a un fond blanc et une
  ombre légère.
- **Section du pack** :
  - cadre 16 px ;
  - en-tête : case à cocher, carré d'icône 40 px, nom, puis pastilles (identifiant, version, nombre de règles) ;
  - à droite : « Mettre à jour / appliquer à d'autres boîtes » (secondaire) et « Retirer le pack » (contour rouge
    clair).
- **Blocs d'info** : remplacent le paragraphe de description. Grille de colonnes de 240 px minimum ; chaque bloc a un
  carré d'icône blanc, un titre gras s'il y en a un (« Titre : texte »), puis la phrase d'origine. Un bloc = une
  idée, et le texte est seulement découpé.
- **Règle** : carré d'icône coloré selon la catégorie (ce que fait la règle), nom, description, pastilles de type,
  « Détails ».
- **SI → ALORS** : deux encadrés côte à côte reliés par une flèche. SI est sur `#f6f7f9`, ALORS sur `#eef2ff` ;
  chaque condition ou action est un jeton blanc.
- **Synthèse des boîtes** :
  - « N boîtes », suivi des pastilles « N conformes au pack » (point vert), « N inactives · N actives » (mini
    interrupteur) et « Tickets existants : oui / non / N oui · N non » (horloge) ;
  - à droite, « Tout sélectionner » ;
  - un état commun à toutes les boîtes n'est écrit qu'ici.
- **Tuile de boîte** :
  - colonnes de 280 px minimum ;
  - de gauche à droite : case à cocher, initiales avec point d'état (vert = conforme), nom tronqué, bouton horloge
    44 px (tickets existants), interrupteur 34 × 20 (active) ;
  - sélectionnée : bordure et contour `#4f46e5`, fond `#f5f5ff` ;
  - un état différent de la synthèse (modifiée, supprimée) est écrit sous le nom.
- **Barre de sélection flottante** :
  - fond `#312e81`, rayon 14, centrée en bas ;
  - contenu : compteur, « règle(s) sélectionnée(s) », croix pour vider, séparateur, puis les actions avec icône.

## 3. Règles de comportement

1. La barre de sélection n'existe que si la sélection est supérieure à 0 : à 0, elle est absente (ni grisée, ni
   affichée avec « 0 »).
2. Ne jamais supprimer un texte existant : le déplacer dans une pastille, un bloc d'info, une infobulle ou un
   libellé accessible.
3. Un état identique pour toutes les boîtes s'écrit une seule fois, dans la synthèse.
4. Tout bouton sans texte visible a une infobulle et un `aria-label` (« Tickets existants : non », « Inactive —
   activer »).
5. La couleur ne porte jamais seule une information : toujours une forme ou un texte en plus (point + pastille,
   texte accessible).
