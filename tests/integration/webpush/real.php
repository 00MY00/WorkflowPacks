<?php
/*
 * Real test of the "Push notification" block with the real WebPush module (test container only):
 * a fake device (its own P-256 keys) subscribed, a fake push service (capture.php) receiving what WebPush sends,
 * the content decrypted as a phone would, quiet hours, module switched off.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Publisher;

$ok = true;
function check($label, $cond, $detail = '')
{
    global $ok;
    $ok = $ok && $cond;
    echo ($cond ? 'OK   ' : 'FAIL ').$label.($detail !== '' ? ' — '.$detail : '')."\n";
}
function b64u($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function pem($raw) { return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw), 64, "\n")."-----END PUBLIC KEY-----\n"; }
function requests() { $f = glob('/tmp/push/req-*.json'); natsort($f); return array_values(array_map(function ($p) { return json_decode(file_get_contents($p), true); }, $f)); }

/** RFC 8291 / 8188 (aes128gcm): what the browser of the device does. */
function decrypt($body, $priv, $uaPub, $auth)
{
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $ct = substr($body, 21 + $idlen);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(pem($asPub)), $priv, 32);
    $prk = hash_hmac('sha256', $ecdh, $auth, true);
    $ikm = substr(hash_hmac('sha256', "WebPush: info\0".$uaPub.$asPub."\x01", $prk, true), 0, 32);
    $prk2 = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk2, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk2, true), 0, 12);
    $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($ct, -16));
    if ($plain === false) {
        return null;
    }
    $plain = rtrim($plain, "\0");

    return json_decode(substr($plain, 0, -1), true);   // last byte: the delimiter \x02
}

echo 'WebPush module ', json_decode(file_get_contents('/var/www/html/Modules/WebPush/module.json'), true)['version'], "\n";
\App\Module::setActive('webpush', true);
\App\Module::clearModulesCache();
\Option::set('workflowpacks.engine', true);
check('adapter sees the module', \Modules\WorkflowPacks\Services\Notify\WebPush::available());

$admin = \App\User::where('email', 'admin@demo.test')->first();
$mailbox = \App\Mailbox::orderBy('id')->first();
\Option::set('webpush.quiet.'.$admin->id, '');

@unlink(storage_path('app/webpush/push_subscriptions.json'));   // one device only
// fake device: its keys, subscribed through the module, then pointed at the local fake push service
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d = openssl_pkey_get_details($key)['ec'];
$uaPub = "\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
$auth = random_bytes(16);
check('device subscribed', \Modules\WebPush\Services\WebPush::subscribe($admin->id, ['endpoint' => 'https://push.invalid/device-1', 'keys' => ['p256dh' => b64u($uaPub), 'auth' => b64u($auth)]], 'Test'));
$file = storage_path('app/webpush/push_subscriptions.json');
file_put_contents($file, str_replace('https:\/\/push.invalid', 'http:\/\/127.0.0.1:9999', str_replace('https://push.invalid', 'http://127.0.0.1:9999', file_get_contents($file))));

// a flow: new e-mail -> push notification to the assignee
Flow::where('name', 'Test push réel')->delete();
$flow = Flow::create(['name' => 'Test push réel', 'mailbox_ids' => [$mailbox->id], 'active' => true, 'once' => false, 'created_by' => $admin->id, 'draft' => ['blocks' => [
    ['id' => 'b1', 'type' => 'trigger.new_mail'],
    ['id' => 'b2', 'type' => 'action.push', 'params' => ['to' => 'assignee', 'title' => '', 'text' => 'Nouveau : {{ticket.subject}} — {{mailbox.name}}']],
], 'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]);
$pub = (new Publisher())->publish($flow, $admin);
check('flow published', $pub['ok'], json_encode($pub['problems'] ?? []));
Listener::forget();

$ticket = function ($subject) use ($mailbox, $admin) {
    $c = new \App\Conversation();
    $c->type = \App\Conversation::TYPE_EMAIL;
    $c->folder_id = (int) optional($mailbox->folders()->first())->id;
    $c->mailbox_id = $mailbox->id;
    $c->subject = $subject;
    $c->user_id = $admin->id;
    $c->customer_email = 'client@example.test';
    $c->source_via = \App\Conversation::PERSON_CUSTOMER;
    $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
    $c->status = \App\Conversation::STATUS_ACTIVE;
    $c->state = \App\Conversation::STATE_PUBLISHED;
    $c->save();
    // what FreeScout fires when a customer writes: the engine starts the flows
    \Eventy::action('conversation.created_by_customer', $c, null, null);

    return $c;
};
$lastEvent = function ($c) {
    return \DB::table('workflowpacks_flow_events')->where('conversation_id', $c->id)->orderBy('id', 'desc')->first();
};

// 1. delivered: the request WebPush sends, decrypted as the device does
$c = $ticket('Serveur <arrêté> & urgent');
$reqs = requests();
check('one push request received', count($reqs) === 1, count($reqs).' request(s)');
if ($reqs) {
    $r = $reqs[0];
    $h = array_change_key_case($r['headers']);
    check('VAPID signature sent', strpos($h['authorization'] ?? '', 'vapid t=') === 0);
    check('encrypted (aes128gcm)', ($h['content-encoding'] ?? '') === 'aes128gcm');
    $payload = decrypt(hex2bin($r['body']), $key, $uaPub, $auth);
    check('decrypted by the device', is_array($payload));
    check('title = subject, not escaped', ($payload['title'] ?? '') === 'Serveur <arrêté> & urgent', $payload['title'] ?? '');
    check('text with variables', ($payload['body'] ?? '') === 'Nouveau : Serveur <arrêté> & urgent — '.$mailbox->name, $payload['body'] ?? '');
    check('opens the ticket', ($payload['url'] ?? '') === $c->url(), $payload['url'] ?? '');
    check('badge count added by WebPush', isset($payload['badge_count']));
}
$e = $lastEvent($c);
check('flow event recorded', $e && $e->output === 'next', $e ? $e->output.' '.$e->summary : 'none');

// 2. quiet hours of the agent (set in WebPush; versions that have them): nothing sent
if (class_exists('\Modules\WebPush\Services\QuietHours')) {
    \Option::set('webpush.quiet.'.$admin->id, json_encode(['enabled' => true, 'from' => '00:00', 'to' => '23:59', 'weekend' => true, 'urgent' => false]));
    \App\Option::$cache = [];   // set by the agent in another request in real life: FreeScout's per-process cache is not refreshed by set()
    $before = count(requests());
    $ticket('Pendant les heures calmes');
    check('quiet hours respected', count(requests()) === $before, (count(requests()) - $before).' new request(s)');
    \Option::set('webpush.quiet.'.$admin->id, '');
} else {
    echo "--   no quiet hours in this version of WebPush\n";
}

// 3. module switched off: "error" output, nothing sent
\App\Module::setActive('webpush', false);
\App\Module::clearModulesCache();
$before = count(requests());
$c3 = $ticket('Module désactivé');
$e = $lastEvent($c3);
check('module off: nothing sent', count(requests()) === $before);
check('module off: error output', $e && $e->output === 'error', $e ? $e->output.' '.$e->summary : 'none');
\App\Module::setActive('webpush', true);
\App\Module::clearModulesCache();

$flow->delete();
echo $ok ? "ALL OK\n" : "FAILED\n";
