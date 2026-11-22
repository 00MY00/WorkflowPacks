# Bouchons de test

`Workflows/` est un **faux module Workflows écrit uniquement pour les tests**. Ce n'est pas le module officiel
(payant) et il ne doit jamais être installé sur un vrai FreeScout. Depuis la version 4.0, Workflow Packs n'utilise
plus le module Workflows : ce bouchon (table `workflows`, modèle `Workflow`) sert seulement aux tests de la
**séparation** (`SeparationTest`), qui suppriment les règles laissées par les anciens packs de règles, et au test de
mise à jour depuis les versions plus anciennes (`tests/update/run.sh`).

`WebPush/` reproduit l'interface publique du module WebPush utilisée par le bloc « Notification push » (envoi,
réglages « Masquer le contenu », icône et boutons de la version 1.3.0, heures calmes des versions plus anciennes).
