<?php

/*
 * Same pattern as the other FreeScout modules: "web" group (CSRF), FreeScout's subdirectory prefix, "auth" + "roles".
 * Administrators only: the flows act on every mailbox. The controllers check it again for every request.
 */
Route::group([
    'middleware' => 'web',
    'prefix'     => \Helper::getSubdirectory().'/workflow-packs',
    'namespace'  => 'Modules\WorkflowPacks\Http\Controllers',
], function () {
    $admins = ['middleware' => ['auth', 'roles'], 'roles' => ['admin']];

    // home: the editor of the flows (the page of the separation from Workflows while it waits for a decision)
    Route::get('/', $admins + ['uses' => 'FlowsController@home'])->name('workflowpacks.index');
    // former address of the diagram of the rules (3.x), kept for the other modules that link to it (RefreshGlobal) and
    // for bookmarks: leads to the editor of the flows
    Route::get('/graph', $admins + ['uses' => 'FlowsController@formerDiagram'])->name('workflowpacks.graph');
    // separation from Workflows (4.0): what will be deleted, its backup, confirm or stay on the previous version
    Route::get('/separation', $admins + ['uses' => 'SeparationController@show'])->name('workflowpacks.separation');
    Route::get('/separation/backup', $admins + ['uses' => 'SeparationController@backup'])->name('workflowpacks.separation.backup');
    Route::post('/separation', $admins + ['uses' => 'SeparationController@confirm'])->name('workflowpacks.separation.confirm');
    // backups kept by the module (a flow deleted by an agent, the data of the separation)
    Route::get('/backups/{id}', $admins + ['uses' => 'BackupController@download'])->name('workflowpacks.backup');

    // history of what the module did (+ diagnostic of the e-mail filing)
    Route::get('/history', $admins + ['uses' => 'HistoryController@index'])->name('workflowpacks.history');
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
    // the tickets no flow has acted on, and a temporary flow for them
    Route::get('/flows/untreated', $admins + ['uses' => 'UntreatedController@show'])->name('workflowpacks.untreated');
    Route::post('/flows/untreated', $admins + ['uses' => 'UntreatedController@create'])->name('workflowpacks.untreated.create');
    Route::post('/flows', $admins + ['uses' => 'FlowsController@store'])->name('workflowpacks.flows.store');
    Route::post('/flows/delete-many', $admins + ['uses' => 'FlowsController@destroyMany'])->name('workflowpacks.flows.destroy_many');
    Route::post('/flows/bulk', $admins + ['uses' => 'FlowsController@bulk'])->name('workflowpacks.flows.bulk');
    Route::get('/flows/imap-folders', $admins + ['uses' => 'FlowsController@imapFolders'])->name('workflowpacks.flows.imap_folders');
    Route::get('/flows/tickets', $admins + ['uses' => 'FlowsController@tickets'])->name('workflowpacks.flows.tickets');
    Route::post('/flows/similar-test', $admins + ['uses' => 'FlowsController@similarTest'])->name('workflowpacks.flows.similar_test');
    Route::post('/flows/{id}', $admins + ['uses' => 'FlowsController@save'])->name('workflowpacks.flows.save');
    Route::post('/flows/{id}/publish', $admins + ['uses' => 'FlowsController@publish'])->name('workflowpacks.flows.publish');
    Route::post('/flows/{id}/toggle', $admins + ['uses' => 'FlowsController@toggle'])->name('workflowpacks.flows.toggle');
    Route::post('/flows/{id}/delete', $admins + ['uses' => 'FlowsController@destroy'])->name('workflowpacks.flows.destroy');
    Route::post('/flows/{id}/simulate', $admins + ['uses' => 'FlowsController@simulate'])->name('workflowpacks.flows.simulate');
    Route::post('/flows/{id}/sample-mail', $admins + ['uses' => 'FlowsController@sampleMail'])->name('workflowpacks.flows.sample');
    Route::get('/flows/{id}/path', $admins + ['uses' => 'FlowsController@path'])->name('workflowpacks.flows.path');
    // a flow applied to the tickets already there (in progress / existing), in batches
    Route::get('/flows/{id}/reapply', $admins + ['uses' => 'FlowReapplyController@show'])->name('workflowpacks.reapply');
    Route::post('/flows/{id}/reapply/publish', $admins + ['uses' => 'FlowReapplyController@publish'])->name('workflowpacks.reapply.publish');
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
    Route::post('/ai/agents/{id}/expiry', $admins + ['uses' => 'AiController@agentExpiry'])->name('workflowpacks.ai.agents.expiry');
    Route::post('/ai/agents/{id}/update', $admins + ['uses' => 'AiController@updateAgent'])->name('workflowpacks.ai.agents.update');
    Route::post('/ai/agents/{id}/delete', $admins + ['uses' => 'AiController@deleteAgent'])->name('workflowpacks.ai.agents.delete');
    // automatic clean-up of a managed e-mail folder (settings: list)
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
    Route::post('/flows/{id}/activate', 'AgentApiController@activate')->name('workflowpacks.api.activate');
    Route::post('/flows/{id}/deactivate', 'AgentApiController@deactivate')->name('workflowpacks.api.deactivate');
    Route::post('/flows/{id}/reapply', 'AgentApiController@reapply')->name('workflowpacks.api.reapply');
    Route::post('/flows/{id}/reapply/control', 'AgentApiController@reapplyControl')->name('workflowpacks.api.reapply.control');
    Route::post('/flows/{id}/simulate', 'AgentApiController@simulate')->name('workflowpacks.api.simulate');
    Route::post('/flows/{id}/rename', 'AgentApiController@rename')->name('workflowpacks.api.rename');
    Route::post('/flows/{id}/delete', 'AgentApiController@deleteFlow')->name('workflowpacks.api.delete');
});

/*
 * Connector for Claude (Model Context Protocol, McpController): the token of a connected agent in the address (Claude's
 * custom connectors send no header) or in "Authorization: Bearer"; no session, no cookie, outside the "web" group.
 */
Route::group([
    'prefix'    => \Helper::getSubdirectory().'/workflow-packs',
    'namespace' => 'Modules\WorkflowPacks\Http\Controllers',
], function () {
    Route::match(['get', 'post', 'delete'], '/mcp/{token?}', 'McpController@handle')->where('token', 'wpa_[0-9a-f]{1,96}')->name('workflowpacks.mcp');
});
