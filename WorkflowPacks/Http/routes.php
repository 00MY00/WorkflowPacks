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
    // without the Workflows module: the pack forgotten by Workflow Packs (its rules cannot be reached)
    Route::post('/packs/{key}/forget', $admins + ['uses' => 'RemoveController@forget'])->name('workflowpacks.forget');
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
    // flows of the internal engine (administrators, when the engine is on)
    Route::get('/flows', $admins + ['uses' => 'FlowsController@show'])->name('workflowpacks.flows');
    Route::get('/flows/data', $admins + ['uses' => 'FlowsController@data'])->name('workflowpacks.flows.data');
    // packs of flows: import (preview, then drafts switched off) and export
    Route::get('/flows/import', $admins + ['uses' => 'FlowPackController@show'])->name('workflowpacks.flowpack');
    Route::post('/flows/import/preview', $admins + ['uses' => 'FlowPackController@preview'])->name('workflowpacks.flowpack.preview');
    Route::post('/flows/import/confirm', $admins + ['uses' => 'FlowPackController@confirm'])->name('workflowpacks.flowpack.confirm');
    Route::get('/flows/export', $admins + ['uses' => 'FlowPackController@export'])->name('workflowpacks.flowpack.export');
    Route::post('/flows', $admins + ['uses' => 'FlowsController@store'])->name('workflowpacks.flows.store');
    Route::post('/flows/delete-many', $admins + ['uses' => 'FlowsController@destroyMany'])->name('workflowpacks.flows.destroy_many');
    Route::post('/flows/bulk', $admins + ['uses' => 'FlowsController@bulk'])->name('workflowpacks.flows.bulk');
    Route::post('/flows/{id}', $admins + ['uses' => 'FlowsController@save'])->name('workflowpacks.flows.save');
    Route::post('/flows/{id}/publish', $admins + ['uses' => 'FlowsController@publish'])->name('workflowpacks.flows.publish');
    Route::post('/flows/{id}/toggle', $admins + ['uses' => 'FlowsController@toggle'])->name('workflowpacks.flows.toggle');
    Route::post('/flows/{id}/delete', $admins + ['uses' => 'FlowsController@destroy'])->name('workflowpacks.flows.destroy');
    Route::post('/flows/{id}/simulate', $admins + ['uses' => 'FlowsController@simulate'])->name('workflowpacks.flows.simulate');
    Route::get('/flows/{id}/path', $admins + ['uses' => 'FlowsController@path'])->name('workflowpacks.flows.path');
    // a flow applied to the tickets already there (in progress / existing), in batches
    Route::get('/flows/{id}/reapply', $admins + ['uses' => 'FlowReapplyController@show'])->name('workflowpacks.reapply');
    Route::post('/flows/{id}/reapply/estimate', $admins + ['uses' => 'FlowReapplyController@estimate'])->name('workflowpacks.reapply.estimate');
    Route::post('/flows/{id}/reapply/start', $admins + ['uses' => 'FlowReapplyController@start'])->name('workflowpacks.reapply.start');
    Route::post('/flows/{id}/reapply/control', $admins + ['uses' => 'FlowReapplyController@control'])->name('workflowpacks.reapply.control');
    // AI assistant without connection: brief for the AI, its answer checked and played, flow saved as a draft
    Route::get('/ai', $admins + ['uses' => 'AiController@show'])->name('workflowpacks.ai');
    Route::post('/ai/brief', $admins + ['uses' => 'AiController@brief'])->name('workflowpacks.ai.brief');
    Route::post('/ai/check', $admins + ['uses' => 'AiController@check'])->name('workflowpacks.ai.check');
    Route::post('/ai/create', $admins + ['uses' => 'AiController@create'])->name('workflowpacks.ai.create');
    // connected AI agents: created and disconnected by an administrator
    Route::post('/ai/agents', $admins + ['uses' => 'AiController@createAgent'])->name('workflowpacks.ai.agents.create');
    Route::post('/ai/agents/{id}/revoke', $admins + ['uses' => 'AiController@revokeAgent'])->name('workflowpacks.ai.agents.revoke');
    // automatic clean-up of a managed e-mail folder (diagram: side panel of the folder; settings: list)
    Route::post('/retention', $admins + ['uses' => 'RetentionController@save'])->name('workflowpacks.retention.save');
    Route::post('/retention/remove', $admins + ['uses' => 'RetentionController@remove'])->name('workflowpacks.retention.remove');
});

/*
 * API of the connected AI agents (AgentApiController, docs/agent-api.md): a token in "Authorization: Bearer", no
 * session, no cookie, so outside the "web" group (and its CSRF check, which is for forms of the browser).
 */
Route::group([
    'prefix'    => \Helper::getSubdirectory().'/workflow-packs/api/v1',
    'namespace' => 'Modules\WorkflowPacks\Http\Controllers',
], function () {
    Route::get('/me', 'AgentApiController@me')->name('workflowpacks.api.me');
    Route::get('/catalog', 'AgentApiController@catalog')->name('workflowpacks.api.catalog');
    Route::get('/tickets', 'AgentApiController@tickets')->name('workflowpacks.api.tickets');
    Route::get('/flows', 'AgentApiController@flows')->name('workflowpacks.api.flows');
    Route::post('/flows', 'AgentApiController@propose')->name('workflowpacks.api.propose');
    Route::put('/flows/{id}', 'AgentApiController@update')->name('workflowpacks.api.update');
    Route::post('/flows/{id}/test', 'AgentApiController@test')->name('workflowpacks.api.test');
    Route::post('/flows/{id}/publish', 'AgentApiController@publish')->name('workflowpacks.api.publish');
});
