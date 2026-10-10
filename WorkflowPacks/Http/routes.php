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
    Route::post('/graph/rule/{id}/apply', $users + ['uses' => 'GraphController@apply'])->name('workflowpacks.graph.apply');

    Route::get('/diagnostic', $admins + ['uses' => 'DiagnosticController@index'])->name('workflowpacks.diagnostic');
    Route::post('/auto-update', $admins + ['uses' => 'DiagnosticController@autoUpdate'])->name('workflowpacks.auto_update');
    Route::post('/update-now', $admins + ['uses' => 'DiagnosticController@updateNow'])->name('workflowpacks.update_now');
    Route::post('/check-update', $admins + ['uses' => 'DiagnosticController@checkUpdate'])->name('workflowpacks.check_update');
});
