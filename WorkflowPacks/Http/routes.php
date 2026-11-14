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
    Route::post('/export/group', $users + ['uses' => 'ExportController@group'])->name('workflowpacks.export.group');
    Route::post('/export/update-pack', $users + ['uses' => 'ExportController@updatePack'])->name('workflowpacks.export.update_pack');

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
    Route::post('/graph/rules/refile', $users + ['uses' => 'GraphController@refileMany'])->name('workflowpacks.graph.refile_many');
    Route::post('/graph/simulate', $users + ['uses' => 'GraphController@simulate'])->name('workflowpacks.graph.simulate');
    Route::get('/graph/sample/{id}', $users + ['uses' => 'GraphController@sample'])->name('workflowpacks.graph.sample');
    Route::post('/graph/delete/plan', $users + ['uses' => 'GraphController@deletePlan'])->name('workflowpacks.graph.delete_plan');
    Route::post('/graph/delete', $users + ['uses' => 'GraphController@delete'])->name('workflowpacks.graph.delete');
    Route::post('/graph/delete/undo', $users + ['uses' => 'GraphController@undoDelete'])->name('workflowpacks.graph.delete_undo');
    Route::post('/graph/rule/{id}/apply', $users + ['uses' => 'GraphController@apply'])->name('workflowpacks.graph.apply');

    // history of what the module did (+ diagnostic of the e-mail filing for administrators)
    Route::get('/history', $users + ['uses' => 'HistoryController@index'])->name('workflowpacks.history');
    Route::get('/history/inspect', $admins + ['uses' => 'HistoryController@inspect'])->name('workflowpacks.history.inspect');
    Route::get('/history/trace', $admins + ['uses' => 'HistoryController@trace'])->name('workflowpacks.history.trace');
    Route::get('/diagnostic', $admins + ['uses' => 'DiagnosticController@index'])->name('workflowpacks.diagnostic');
    // flows of the internal engine (3.0, experimental; administrators, when the engine is on)
    Route::get('/flows', $admins + ['uses' => 'FlowsController@show'])->name('workflowpacks.flows');
    Route::get('/flows/data', $admins + ['uses' => 'FlowsController@data'])->name('workflowpacks.flows.data');
    Route::post('/flows', $admins + ['uses' => 'FlowsController@store'])->name('workflowpacks.flows.store');
    Route::post('/flows/{id}', $admins + ['uses' => 'FlowsController@save'])->name('workflowpacks.flows.save');
    Route::post('/flows/{id}/publish', $admins + ['uses' => 'FlowsController@publish'])->name('workflowpacks.flows.publish');
    Route::post('/flows/{id}/toggle', $admins + ['uses' => 'FlowsController@toggle'])->name('workflowpacks.flows.toggle');
    Route::post('/flows/{id}/delete', $admins + ['uses' => 'FlowsController@destroy'])->name('workflowpacks.flows.destroy');
    Route::post('/flows/{id}/simulate', $admins + ['uses' => 'FlowsController@simulate'])->name('workflowpacks.flows.simulate');
    Route::get('/flows/{id}/path', $admins + ['uses' => 'FlowsController@path'])->name('workflowpacks.flows.path');
    // AI assistant without connection: brief for the AI, its answer checked and played, flow saved as a draft
    Route::get('/ai', $admins + ['uses' => 'AiController@show'])->name('workflowpacks.ai');
    Route::post('/ai/brief', $admins + ['uses' => 'AiController@brief'])->name('workflowpacks.ai.brief');
    Route::post('/ai/check', $admins + ['uses' => 'AiController@check'])->name('workflowpacks.ai.check');
    Route::post('/ai/create', $admins + ['uses' => 'AiController@create'])->name('workflowpacks.ai.create');
    // automatic clean-up of a managed e-mail folder (diagram: side panel of the folder; settings: list)
    Route::post('/retention', $admins + ['uses' => 'RetentionController@save'])->name('workflowpacks.retention.save');
    Route::post('/retention/remove', $admins + ['uses' => 'RetentionController@remove'])->name('workflowpacks.retention.remove');
});
