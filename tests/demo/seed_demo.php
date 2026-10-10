<?php

/*
 * Demo data for the screenshots (test instance only): an administrator, two mailboxes, the example pack imported
 * in one of them and one rule changed by hand. Run from FreeScout's root: php Modules/WorkflowPacks/../seed_demo.php
 *
 *   php seed_demo.php <admin-email> <admin-password>
 */
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = App\User::where('email', $argv[1])->first() ?: new App\User();
$admin->first_name = 'Admin';
$admin->last_name = 'Démo';
$admin->email = $argv[1];
$admin->password = bcrypt($argv[2]);
$admin->role = App\User::ROLE_ADMIN;
$admin->save();

$mailboxes = [];
foreach ([['Support', 'support@demo.test'], ['Comptabilité', 'compta@demo.test']] as $m) {
    $mailbox = App\Mailbox::where('email', $m[1])->first() ?: new App\Mailbox();
    $mailbox->name = $m[0];
    $mailbox->email = $m[1];
    $mailbox->save();
    $mailboxes[] = $mailbox;
}

$json = file_get_contents(getcwd().'/Modules/WorkflowPacks/Resources/packs/tri-emails-multilingue.json');
$pack = (new Modules\WorkflowPacks\Services\Pack\PackValidator())->validateJson($json)['pack'];
$plan = (new Modules\WorkflowPacks\Services\Plan\Planner())->plan($pack, [$mailboxes[0]->id], ['security_email' => 'secu@demo.test', 'newsletter_sender' => 'news@shop.demo.test'], $admin);
$result = (new Modules\WorkflowPacks\Services\Plan\Importer())->apply($plan, [], [], $admin);
echo 'import: '.json_encode($result['counts'] ?: $result['error']).PHP_EOL;

$wf = Modules\Workflows\Entities\Workflow::where('mailbox_id', $mailboxes[0]->id)->where('name', '[Tri] Codes de connexion')->first();
if ($wf) {
    $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'code']]];
    $wf->save();
}
echo 'ok'.PHP_EOL;
