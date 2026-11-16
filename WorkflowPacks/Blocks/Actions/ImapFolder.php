<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;
use Modules\WorkflowPacks\Services\Imap\FolderPath;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;

/**
 * File the e-mail into a folder of the mail server ("[WP] Invoices/Suppliers"), queued as the action of the rules. The
 * address: the mailbox where the e-mail arrived, or another one chosen (an address sharing the same mail account, an
 * alias); the e-mail is looked for there by its Message-ID. Only folders carrying the prefix are created or used.
 */
class ImapFolder extends AbstractBlock
{
    public static function type()
    {
        return 'action.imap_folder';
    }

    public static function family()
    {
        return 'action';
    }

    public static function fields()
    {
        return ['mailbox' => ['type' => 'mailbox'], 'path' => ['type' => 'imap_folder', 'required' => true]];
    }

    public static function outputs()
    {
        return ['next', 'error'];
    }

    protected static function titleParams(array $params)
    {
        return ['folder' => FolderPath::label((string) ($params['path'] ?? ''), ImapFolders::prefix())];
    }

    public static function validate(array $params)
    {
        $problems = parent::validate($params);
        if (!$problems && FolderPath::parse($params['path'])['error'] !== null) {
            $problems['path'] = __('workflowpacks::blocks.errors.folder');
        }
        $mailbox = (int) ($params['mailbox'] ?? 0);
        if ($mailbox && !\App\Mailbox::where('id', $mailbox)->exists()) {
            $problems['mailbox'] = __('workflowpacks::blocks.errors.mailbox');
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        // option off (Manage › Settings › Workflow Packs): not a temporary problem, the "error" output
        if (!ImapFolders::enabled()) {
            return Result::error(__('workflowpacks::blocks.errors.imap_off'));
        }
        if (!$ctx->actions()->imapFolder((string) $params['path'], (int) ($params['mailbox'] ?? 0) ?: null)) {
            return Result::error(__('workflowpacks::blocks.errors.imap_nothing'));
        }

        return Result::next('next', self::title($params));
    }
}
