# Mise à jour vers la dernière version publiée — 2026-10-10 20:37

Dernière release GitHub : **1.2.1**. FreeScout (branche dist) + MariaDB 10.11 + bouchon Workflows.

| Scénario | Vérification | Résultat | Détail |
|---|---|---|---|
| cli depuis 1.0.0 (e875b74) | version de départ installée | PASS | 1.0.0, données 1/8/8 |
| cli depuis 1.0.0 (e875b74) | version installée = 1.2.1 | PASS | 1.2.1 |
| cli depuis 1.0.0 (e875b74) | aucun retour arrière | PASS |  |
| cli depuis 1.0.0 (e875b74) | workflowpacks:check | PASS | ok |
| cli depuis 1.0.0 (e875b74) | migration 1.2 (workflowpacks_resources) | PASS | yes |
| cli depuis 1.0.0 (e875b74) | données conservées (packs/liens/règles) | PASS | 1/8/8 → 1/8/8 |
| cli depuis 1.0.0 (e875b74) | FreeScout répond (HTTP 200) | PASS | 200 |
| cli depuis 1.1.0 (8cae637) | version de départ installée | PASS | 1.1.0, données 1/8/8 |
| cli depuis 1.1.0 (8cae637) | version installée = 1.2.1 | PASS | 1.2.1 |
| cli depuis 1.1.0 (8cae637) | aucun retour arrière | PASS |  |
| cli depuis 1.1.0 (8cae637) | workflowpacks:check | PASS | ok |
| cli depuis 1.1.0 (8cae637) | migration 1.2 (workflowpacks_resources) | PASS | yes |
| cli depuis 1.1.0 (8cae637) | données conservées (packs/liens/règles) | PASS | 1/8/8 → 1/8/8 |
| cli depuis 1.1.0 (8cae637) | FreeScout répond (HTTP 200) | PASS | 200 |
| button depuis 1.2.0 (v1.2.0) | version de départ installée | PASS | 1.2.0, données 1/8/8 |
| button depuis 1.2.0 (v1.2.0) | demande traitée par le planificateur | PASS | cleared |
| button depuis 1.2.0 (v1.2.0) | version installée = 1.2.1 | PASS | 1.2.1 |
| button depuis 1.2.0 (v1.2.0) | aucun retour arrière | PASS |  |
| button depuis 1.2.0 (v1.2.0) | workflowpacks:check | PASS | ok |
| button depuis 1.2.0 (v1.2.0) | migration 1.2 (workflowpacks_resources) | PASS | yes |
| button depuis 1.2.0 (v1.2.0) | données conservées (packs/liens/règles) | PASS | 1/8/8 → 1/8/8 |
| button depuis 1.2.0 (v1.2.0) | FreeScout répond (HTTP 200) | PASS | 200 |
| install depuis 1.0.0 (e875b74) | version de départ installée | PASS | 1.0.0, données 1/8/8 |
| install depuis 1.0.0 (e875b74) | version installée = 1.2.1 | PASS | 1.2.1 |
| install depuis 1.0.0 (e875b74) | aucun retour arrière | PASS |  |
| install depuis 1.0.0 (e875b74) | workflowpacks:check | PASS | ok |
| install depuis 1.0.0 (e875b74) | migration 1.2 (workflowpacks_resources) | PASS | yes |
| install depuis 1.0.0 (e875b74) | données conservées (packs/liens/règles) | PASS | 1/8/8 → 1/8/8 |
| install depuis 1.0.0 (e875b74) | FreeScout répond (HTTP 200) | PASS | 200 |
| install-local depuis 1.1.0 (8cae637) | version de départ installée | PASS | 1.1.0, données 1/8/8 |
| install-local:8cae637 | archive de la release vérifiée (SHA-256) | PASS |  |
| install-local depuis 1.1.0 (8cae637) | version installée = 1.2.1 | PASS | 1.2.1 |
| install-local depuis 1.1.0 (8cae637) | aucun retour arrière | PASS |  |
| install-local depuis 1.1.0 (8cae637) | workflowpacks:check | PASS | ok |
| install-local depuis 1.1.0 (8cae637) | migration 1.2 (workflowpacks_resources) | PASS | yes |
| install-local depuis 1.1.0 (8cae637) | données conservées (packs/liens/règles) | PASS | 1/8/8 → 1/8/8 |
| install-local depuis 1.1.0 (8cae637) | FreeScout répond (HTTP 200) | PASS | 200 |

**37 vérifications réussies, 0 en échec.**
