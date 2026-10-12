<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current e-mail folder of each ticket (1.14.0): one row per ticket whose e-mail was filed into a managed folder
 * ("[WP] Factures › Fournisseurs"). Unlike the history (kept 90 days), it is kept as long as the ticket exists, so
 * other modules (RefreshGlobal) can show and filter the tickets by folder. Filled from the history already there.
 */
class CreateWorkflowpacksTicketFoldersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_ticket_folders')) {
            Schema::create('workflowpacks_ticket_folders', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('conversation_id')->unique();
                $table->unsignedInteger('mailbox_id');
                $table->string('folder', 191);                 // as written in the rules: "Factures/Fournisseurs"
                $table->string('label', 191);                  // as shown: "[WP] Factures › Fournisseurs"
                $table->string('path', 191)->default('');      // on the mail server: "INBOX.[WP] Factures.Fournisseurs"
                $table->string('state', 10)->default('filed'); // filed | cleaned (removed by the automatic clean-up of the folder)
                $table->timestamp('filed_at')->nullable();
                $table->timestamp('cleaned_at')->nullable();
                $table->timestamps();
                $table->index(['mailbox_id', 'state']);
                // folder alone: with the state, the key would exceed 767 bytes on older MySQL (utf8mb4)
                $table->index('folder');
            });
        }
        try {
            \Modules\WorkflowPacks\Services\Imap\TicketFolders::backfill();
        } catch (\Throwable $e) {
            // the table is filled again by the next filings
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_ticket_folders');
    }
}
