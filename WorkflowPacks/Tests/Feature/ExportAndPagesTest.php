<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\Export\Exporter;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** Export (templates kept, explicit replacements, secrets, local references) and the full path through the pages. */
class ExportAndPagesTest extends TestCase
{
    public function testExportOfPackRulesKeepsTheOriginalVariables()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $result = (new Exporter())->exportWorkflows($this->workflows($a), ['key' => 'copie', 'name' => 'Copie'], []);
        $rule = $result['pack']['workflows'][0];
        $this->assertSame('/^{{mailbox.email}}$/i', $rule['conditions']['all'][1]['any'][1]['value']);
        $this->assertSame('Boîte {{mailbox.name}}', $rule['actions'][0]['value']['body']);
        $this->assertNotEmpty($result['report']['from_packs']);
    }

    public function testExportOfExistingRulesReplacesOnlyWhatIsAskedAndRemovesSecrets()
    {
        $a = $this->mailbox('A');
        $agent = $this->user([$a]);
        $wf = new Workflow();
        $wf->mailbox_id = $a->id;
        $wf->name = 'Locale';
        $wf->conditions = [[['type' => 'to', 'operator' => 'regex', 'value' => '/^'.preg_quote($a->email, '/').'$/i']],
            [['type' => 'customer_email', 'operator' => 'equal', 'value' => $a->email]]];
        $wf->actions = [[
            ['type' => 'forward', 'value' => json_encode(['to' => 'x@example.test', 'body' => 'Voir https://hooks.example.test/in?token=s3cr3tValue123'])],
            ['type' => 'assign', 'value' => $agent->id],
        ]];
        $wf->save();

        // nothing asked: the address of the mailbox is NOT replaced by guesswork
        $plain = (new Exporter())->exportWorkflows(collect([$wf]), ['key' => 'k', 'name' => 'N'], []);
        $this->assertSame($a->email, $plain['pack']['workflows'][0]['conditions']['all'][1]['value']);

        $result = (new Exporter())->exportWorkflows(collect([$wf]), ['key' => 'k', 'name' => 'N'], [['text' => $a->email, 'variable' => 'mailbox.email']]);
        $rule = $result['pack']['workflows'][0];
        $this->assertSame('/^{{mailbox.email}}$/i', $rule['conditions']['all'][0]['value'], 'quoted form replaced inside the regex');
        $this->assertSame('{{mailbox.email}}', $rule['conditions']['all'][1]['value']);
        $this->assertSame(2, $result['report']['replaced'][$a->email]);

        $this->assertSame('{{param.secret_1}}', $rule['actions'][0]['value']['body']);
        $this->assertStringNotContainsString('s3cr3tValue123', json_encode($result['pack']));
        $this->assertTrue($result['pack']['params'][0]['sensitive']);
        $this->assertNotEmpty($result['report']['secrets']);
        $this->assertStringContainsString('assign', implode(' ', $result['report']['refs']));

        // the produced file is importable (same validation as the import)
        $this->validated($result['pack']);
    }

    public function testExportIgnoresRulesOfForbiddenMailboxes()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $wf = new Workflow();
        $wf->mailbox_id = $b->id;
        $wf->name = 'Interdite';
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]];
        $wf->actions = [[['type' => 'status', 'value' => '3']]];
        $wf->save();
        $response = $this->actingAs($agent)->post(route('workflowpacks.export.download'), [
            'mode' => 'workflows', 'workflows' => [$wf->id], 'key' => 'k', 'name' => 'N',
        ]);
        $response->assertStatus(200);
        $this->assertStringNotContainsString('Interdite', $response->getContent());
    }

    public function testFullImportPathThroughThePagesWithTheExamplePack()
    {
        $a = $this->mailbox('Support');
        $admin = $this->user([], true);
        $json = file_get_contents(__DIR__.'/../../Resources/packs/tri-emails-multilingue.json');

        // the example pack is offered on the upload page and loaded by name (no free path)
        $this->seeIn($this->actingAs($admin)->get(route('workflowpacks.import')), 'tri-emails-multilingue.json');
        $this->actingAs($admin)->post(route('workflowpacks.import.configure'), ['bundled' => '../../.env'])->assertStatus(200);
        $step2 = $this->actingAs($admin)->post(route('workflowpacks.import.configure'), ['bundled' => 'tri-emails-multilingue.json']);
        $step2->assertStatus(200);
        $this->seeIn($step2, '[Tri] Alertes de sécurité');
        $this->seeIn($step2, 'param.security_email');

        $input = ['pack_b64' => base64_encode($json), 'mailboxes' => [$a->id], 'params' => ['security_email' => 'secu@example.test', 'newsletter_sender' => '']];
        $step3 = $this->actingAs($admin)->post(route('workflowpacks.import.preview'), $input);
        $step3->assertStatus(200);
        $this->seeIn($step3, 'wp-matrix');
        $this->seeIn($step3, e(__('workflowpacks::messages.sensitive_forward')));
        $this->assertSame(1, preg_match('/name="token" value="([a-f0-9]{64})"/', $step3->getContent(), $m));

        // the newsletter rule is blocked (sender not given): explicit acknowledgement needed
        $refused = $this->actingAs($admin)->post(route('workflowpacks.import.confirm'), $input + ['token' => $m[1]]);
        $this->assertSame(0, Workflow::where('mailbox_id', $a->id)->count());
        $this->seeIn($refused, e(\Modules\WorkflowPacks\Services\Pack\Problems::t('imp_blocked', ['count' => 1])));

        $step4 = $this->actingAs($admin)->post(route('workflowpacks.import.confirm'), $input + ['token' => $m[1], 'ack_blocked' => 1]);
        $step4->assertStatus(200);
        $names = Workflow::where('mailbox_id', $a->id)->pluck('name')->all();
        $this->assertCount(7, $names);
        $this->assertNotContains('[Tri] Newsletters (expéditeur choisi)', $names);
        $this->assertSame(0, Workflow::where('mailbox_id', $a->id)->where('active', true)->count(), 'all inactive');

        $index = $this->actingAs($admin)->get(route('workflowpacks.index'));
        $index->assertStatus(200);
        $this->seeIn($index, 'tri-emails-multilingue');
        $this->seeIn($index, 'wp-chip-synced');

        $this->actingAs($admin)->get(route('workflowpacks.reimport', ['key' => 'tri-emails-multilingue']))->assertStatus(200);
        $this->actingAs($admin)->get(route('workflowpacks.export'))->assertStatus(200);
        $diag = $this->actingAs($admin)->get(route('workflowpacks.diagnostic'));
        $diag->assertStatus(200);
        $this->seeIn($diag, 'new_or_reply');
    }

    public function testValuesAreEscapedInThePages()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $pack = json_encode($this->pack([$this->rule('xss', ['name' => '<script>alert(1)</script>'])]));
        $response = $this->actingAs($admin)->post(route('workflowpacks.import.configure'), ['pack_json' => $pack]);
        $response->assertStatus(200);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        $this->seeIn($response, '&lt;script&gt;alert(1)&lt;/script&gt;');
        $this->assertNotNull($a);
    }
}
