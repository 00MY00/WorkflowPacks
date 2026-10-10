# Bouchons de test

`Workflows/` est un **faux module Workflows écrit uniquement pour les tests** de Workflow Packs. Ce n'est pas le
module officiel (payant) et il ne doit jamais être installé sur un vrai FreeScout. Il reproduit seulement l'interface
publique utilisée par Workflow Packs (table `workflows`, constantes `TYPE_*`, `conditionsConfig()` / `actionsConfig()`
filtrables, `canEditWorkflows()`, `validate()`, `checkComplete()`, `setSortOrderLast()`), avec un petit sous-ensemble
du catalogue. Voir `docs/analyse.md` pour l'origine de ces noms.

Le module Tags n'est pas simulé : son absence est le cas testé (règles bloquées). Le test « Tags présent » ajoute
ses types au catalogue par les filtres `workflows.conditions_config` / `workflows.actions_config`, comme le ferait
un module réel, et active l'alias `tags` dans la table `modules`.
