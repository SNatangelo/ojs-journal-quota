<?php

/**
 * @file plugins/generic/journalQuota/classes/QuotaSchemaMigration.php
 *
 * @class QuotaSchemaMigration
 *
 * @brief Tabella journal_quota_files: una riga per ogni file contato.
 *
 *  La chiave è il percorso del file (path_hash), quindi contare due volte lo
 *  stesso file aggiorna la stessa riga invece di sommarlo di nuovo. Un file
 *  cancellato non sparisce subito: resta con deleted_at, così una misura
 *  completa iniziata prima della cancellazione non lo fa ricomparire.
 */

namespace APP\plugins\generic\journalQuota\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class QuotaSchemaMigration extends Migration
{
    public const TABLE = 'journal_quota_files';

    /** Spazio prenotato dai caricamenti in corso (e una riga di blocco per rivista) */
    public const RESERVATIONS = 'journal_quota_reservations';

    /** Si può eseguire più volte: crea le tabelle solo se mancano */
    public function up(): void
    {
        if (!Schema::hasTable(self::RESERVATIONS)) {
            Schema::create(self::RESERVATIONS, function (Blueprint $table) {
                $table->string('token', 40)->primary();
                $table->bigInteger('context_id');
                $table->bigInteger('bytes');
                $table->bigInteger('expires');
                $table->index(['context_id', 'expires'], 'journal_quota_reservations_context');
            });
        }
        if (Schema::hasTable(self::TABLE)) {
            return;
        }
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->bigIncrements('quota_file_id');
            $table->bigInteger('context_id');
            // sha1 della chiave del file ("f:" + percorso in files_dir, "p:" + percorso pubblico)
            $table->char('path_hash', 40);
            $table->string('path', 1024);
            $table->string('part', 16);
            $table->bigInteger('submission_id')->nullable();
            $table->bigInteger('bytes');
            // Momento (microsecondi) dell'ultima conferma, da un hook o da una misura completa
            $table->decimal('seen_at', 16, 4);
            $table->decimal('deleted_at', 16, 4)->nullable();
            $table->unique(['path_hash'], 'journal_quota_files_path');
            // Copre la somma per parte: il totale si legge dal solo indice
            $table->index(['context_id', 'deleted_at', 'part', 'bytes'], 'journal_quota_files_context');
            $table->index(['submission_id'], 'journal_quota_files_submission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::RESERVATIONS);
        Schema::dropIfExists(self::TABLE);
    }
}
