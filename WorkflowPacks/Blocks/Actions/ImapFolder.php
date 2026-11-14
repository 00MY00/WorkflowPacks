<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;
use Modules\WorkflowPacks\Services\Imap\FolderPath;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;

/** File the e-mail into a folder of the mail server ("[WP] Invoices/Suppliers"), queued as the action of the rules. */
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
        return ['path' => ['type' => 'text', 'required' => true]];
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

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        // option off (Manage › Settings › Workflow Packs): not a temporary problem, the "error" output
        if (!ImapFolders::enabled()) {
            return Result::error(__('workflowpacks::blocks.errors.imap_off'));
        }
        if (!$ctx->actions()->imapFolder((string) $params['path'])) {
            return Result::error(__('workflowpacks::blocks.errors.imap_nothing'));
        }

        return Result::next('next', self::title($params));
    }
}
