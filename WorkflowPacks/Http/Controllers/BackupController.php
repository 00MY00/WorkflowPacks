<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Backup;

/**
 * Backups kept by the module (workflowpacks_backups): a flow deleted by a connected agent (its pack of flows, or
 * everything), the data removed when the Workflows rules were separated from the module. Administrators only.
 */
class BackupController extends Controller
{
    public function download(Request $request, $id)
    {
        $this->guard();
        $backup = Backup::find((int) $id);
        abort_if(!$backup, 404);
        $content = json_decode($backup->content, true) ?: [];
        $part = $request->input('part') === 'pack' && isset($content['pack']) ? 'pack' : 'full';
        $data = $part === 'pack' ? $content['pack'] : $content;
        $name = preg_replace('/[^a-z0-9._-]+/i', '-', (string) $backup->pack_key)
            .($part === 'pack' ? '' : '-'.__('workflowpacks::messages.backup_file').'-'.$backup->created_at->format('Ymd-His')).'.json';

        return response(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", 200, [
            'Content-Type'           => 'application/json; charset=utf-8',
            'Content-Disposition'    => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
