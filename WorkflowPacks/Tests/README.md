# Tests du module (PHPUnit, dans FreeScout)

Ces tests s'exécutent **dans une installation FreeScout de test**, jamais en production. Ils créent des boîtes, des
utilisateurs et des règles dans la base, à l'intérieur d'une transaction annulée à la fin de chaque test.

Le plus simple : `bash tests/integration/run.sh` à la racine du dépôt. Le script utilise des conteneurs Docker
jetables : FreeScout officiel, MariaDB et le bouchon `tests/stubs/Workflows`.

À la main, sur une instance de test qui a un module Workflows :

```bash
cd /var/www/html
curl -sSL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
sudo -u www-data php artisan config:clear
sudo -u www-data php /tmp/phpunit.phar -c Modules/WorkflowPacks/Tests/phpunit.xml
sudo -u www-data php artisan freescout:clear-cache
```

| Fichier | Ce qui est vérifié |
|---|---|
| `Feature/ImportTest.php` | Variables par boîte, avec l'entier conservé et l'adresse littérale dans la regex. Règles créées inactives et « conversations précédentes » désactivé. Options explicites. Second import sans doublon. Mise à jour avec différences et état actif conservé. Désactivation explicite. Modification manuelle avec chaque choix : conserver, ignorer, remplacer. Règle étrangère de même nom jamais modifiée. Choix falsifié refusé. Pas de suppression. Règle supprimée recréée. Paramètres obligatoires et invalides. Référence locale inconnue. |
| `Feature/SafetyTest.php` | Droits contrôlés côté serveur pour chaque boîte, y compris avec un formulaire falsifié. Accès refusé sans la permission Workflows. Routes dans le groupe `web` (CSRF). Tags absent : règle bloquée, import refusé sans accord explicite. Types ajoutés par un module actif. Workflows absent : message explicite. **Erreur au 3e enregistrement : rien n'est écrit** (règles, liens, historique). Jeton de prévisualisation. |
| `Feature/ExportAndPagesTest.php` | Export du modèle d'origine avec ses variables. Remplacements uniquement à la demande, y compris dans les regex. Secrets retirés. Références locales signalées. Fichier réimportable. Règles de boîtes interdites ignorées. Parcours complet des pages avec le pack d'exemple. Valeurs échappées (XSS). |
