<?php

/*
 * Demo data (test instance only, addresses in .test): an administrator, two mailboxes, three tickets, the example
 * pack of flows imported and published. With --legacy, the data a pack of Workflows rules of version 3.x would have
 * left (a pack, its links, the rules in the Workflows test stub): the page "Separation from Workflows" then shows.
 *
 *   php seed_demo.php <admin-email> <admin-password> [--legacy]      (from FreeScout's root)
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

foreach (['Commande en attente', 'Question sur une facture', 'Newsletter de novembre'] as $i => $subject) {
    $c = new App\Conversation();
    $c->type = App\Conversation::TYPE_EMAIL;
    $c->number = 5000 + $i;
    $c->folder_id = (int) optional($mailboxes[0]->folders()->first())->id;
    $c->mailbox_id = $mailboxes[0]->id;
    $c->subject = $subject;
    $c->customer_email = 'client'.$i.'@example.test';
    $c->source_via = App\Conversation::PERSON_CUSTOMER;
    $c->source_type = App\Conversation::SOURCE_TYPE_EMAIL;
    $c->status = App\Conversation::STATUS_ACTIVE;
    $c->state = App\Conversation::STATE_PUBLISHED;
    $c->save();
}

// the example pack of flows, in the first mailbox, published
App\Option::set(Modules\WorkflowPacks\Services\Engine\Engine::OPTION, true);
$packs = new Modules\WorkflowPacks\Services\Engine\FlowPack();
$json = file_get_contents(getcwd().'/Modules/WorkflowPacks/Resources/flows/tri-emails-multilingue-flux.json');
echo 'flows: '.json_encode($packs->import($packs->read($json, ['newsletter_sender' => 'news@shop.demo.test']), [$mailboxes[0]->id], $admin)).PHP_EOL;
foreach (Modules\WorkflowPacks\Entities\Flow::whereIn('id', $packs->imported())->get() as $flow) {
    (new Modules\WorkflowPacks\Services\Engine\Publisher())->publish($flow, $admin);
}

if (in_array('--legacy', $argv, true) && class_exists('Modules\Workflows\Entities\Workflow')
    && Schema::hasTable('workflowpacks_packs') && Schema::hasTable('workflowpacks_links')) {
    DB::table('workflowpacks_packs')->updateOrInsert(['pack_key' => 'tri-factures'], ['name' => 'Tri des factures', 'pack_version' => '1.0.0',
        'source' => json_encode(['format' => 'freescout-workflow-pack', 'key' => 'tri-factures']), 'created_at' => now(), 'updated_at' => now()]);
    foreach (['Factures fournisseurs', 'Relances clients'] as $i => $name) {
        $wf = new Modules\Workflows\Entities\Workflow();
        $wf->mailbox_id = $mailboxes[1]->id;
        $wf->name = '[Tri] '.$name.' '.substr(md5(uniqid('', true)), 0, 4);
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'facture']]];
        $wf->actions = [[['type' => 'status', 'value' => '3']]];
        $wf->active = $i === 0;
        $wf->save();
        DB::table('workflowpacks_links')->insert(['pack_key' => 'tri-factures', 'workflow_key' => 'r'.$i, 'mailbox_id' => $mailboxes[1]->id,
            'workflow_id' => $wf->id, 'template' => '{}', 'fingerprint' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    }
    echo 'legacy: 1 pack, 2 rules'.PHP_EOL;
}
echo 'ok'.PHP_EOL;
