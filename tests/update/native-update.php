<?php
/*
 * Test helper (tests/update/run.sh, scenario "native"): updates Workflow Packs with FreeScout's own module update
 * (Manage > Modules > Update, App\Module::updateModule()), which reads latestVersionUrl / latestVersionZipUrl from
 * module.json — what UpdateGuard relies on too. An old version does not declare them yet: they are added first, as
 * the published module.json has them.
 *
 *   php native-update.php <base URL of the latest release>     prints "success …" or "error …"
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = rtrim((string) ($argv[1] ?? ''), '/');
$file = base_path('Modules/WorkflowPacks/module.json');
$json = json_decode(file_get_contents($file), true);
$json['latestVersionUrl'] = $base.'/version.txt';
$json['latestVersionZipUrl'] = $base.'/WorkflowPacks.zip';
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
\Module::clearCache();

$result = \App\Module::updateModule('workflowpacks');
echo $result['status'].' '.trim(strip_tags((string) ($result['msg_success'] ?? '') ?: (string) ($result['msg'] ?? '')))."\n";
