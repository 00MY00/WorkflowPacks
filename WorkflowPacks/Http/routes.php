<?php

/*
 * Same pattern as the other FreeScout modules: "web" group (CSRF), FreeScout's subdirectory prefix, "auth" + "roles".
 * The permission on each mailbox is checked again by the controllers for every request (Workflow::canEditWorkflows).
 */
Route::group([
    'middleware' => 'web',
    'prefix'     => \Helper::getSubdirectory().'/workflow-packs',
    'namespace'  => 'Modules\WorkflowPacks\Http\Controllers',
], function () {
    $users = ['middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']];
    $admins = ['middleware' => ['auth', 'roles'], 'roles' => ['admin']];

    Route::get('/', $users + ['uses' => 'PacksController@index'])->name('workflowpacks.index');
    Route::get('/import', $users + ['uses' => 'PacksController@upload'])->name('workflowpacks.import');
    Route::post('/import/configure', $users + ['uses' => 'PacksController@configure'])->name('workflowpacks.import.configure');
    Route::post('/import/preview', $users + ['uses' => 'PacksController@preview'])->name('workflowpacks.import.preview');
    Route::post('/import/confirm', $users + ['uses' => 'PacksController@confirm'])->name('workflowpacks.import.confirm');
    Route::get('/packs/{key}/reimport', $users + ['uses' => 'PacksController@reimport'])->name('workflowpacks.reimport');
    // enable / disable rules from the list of the packs (one rule or the ticked ones)
    Route::post('/rules/toggle', $users + ['uses' => 'PacksController@toggleRules'])->name('workflowpacks.rules.toggle');
    // remove a pack: preview -> confirmation (with a backup first) -> backup download
    Route::get('/packs/{key}/remove', $users + ['uses' => 'RemoveController@preview'])->name('workflowpacks.remove');
    Route::post('/packs/{key}/remove', $users + ['uses' => 'RemoveController@confirm'])->name('workflowpacks.remove.confirm');
    Route::get('/backups/{id}', $users + ['uses' => 'RemoveController@backup'])->name('workflowpacks.backup');

    Route::get('/export', $users + ['uses' => 'ExportController@form'])->name('workflowpacks.export');
    Route::post('/export', $users + ['uses' => 'ExportController@preview'])->name('workflowpacks.export.preview');
    Route::post('/export/download', $users + ['uses' => 'ExportController@download'])->name('workflowpacks.export.download');

    // diagram (page + JSON endpoints of Public/js/graph)
    Route::get('/graph', $users + ['uses' => 'GraphController@show'])->name('workflowpacks.graph');
    Route::get('/graph/data', $users + ['uses' => 'GraphController@data'])->name('workflowpacks.graph.data');
    Route::post('/graph/layout', $users + ['uses' => 'GraphController@saveLayout'])->name('workflowpacks.graph.layout');
    Route::get('/graph/rule/{id}', $users + ['uses' => 'GraphController@rule'])->name('workflowpacks.graph.rule');
    Route::get('/graph/catalog/{mailbox_id}', $users + ['uses' => 'GraphController@catalog'])->name('workflowpacks.graph.catalog');
    Route::post('/graph/rule', $users + ['uses' => 'GraphController@saveRule'])->name('workflowpacks.graph.save');
    Route::post('/graph/rule/{id}/toggle', $users + ['uses' => 'GraphController@toggle'])->name('workflowpacks.graph.toggle');
    Route::post('/graph/rules/toggle', $users + ['uses' => 'GraphController@toggleMany'])->name('workflowpacks.graph.toggle_many');
    Route::post('/graph/rules/previous', $users + ['uses' => 'GraphController@previousMany'])->name('workflowpacks.graph.previous_many');
    Route::post('/graph/rule/{id}/apply', $users + ['uses' => 'GraphController@apply'])->name('workflowpacks.graph.apply');

    Route::get('/diagnostic', $admins + ['uses' => 'DiagnosticController@index'])->name('workflowpacks.diagnostic');
    // automatic clean-up of a managed e-mail folder (diagram: side panel of the folder; settings: list)
    Route::post('/retention', $admins + ['uses' => 'RetentionController@save'])->name('workflowpacks.retention.save');
    Route::post('/retention/remove', $admins + ['uses' => 'RetentionController@remove'])->name('workflowpacks.retention.remove');
    Route::post('/auto-update', $admins + ['uses' => 'DiagnosticController@autoUpdate'])->name('workflowpacks.auto_update');
    Route::post('/update-now', $admins + ['uses' => 'DiagnosticController@updateNow'])->name('workflowpacks.update_now');
    Route::post('/check-update', $admins + ['uses' => 'DiagnosticController@checkUpdate'])->name('workflowpacks.check_update');
});
