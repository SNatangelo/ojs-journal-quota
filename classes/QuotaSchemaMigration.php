<?php
/*
 * classes/QuotaSchemaMigration.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaSchemaMigration.php
 *
 * @class QuotaSchemaMigration
 *
 * @brief journal_quota_files table: one row for each counted file.
 *
 *  The file path (path_hash) is the key, so counting the same file twice
 *  updates its row instead of adding its size again. Deleted files
 *  remain temporarily with deleted_at set, preventing a full scan
 *  started before deletion from bringing them back.
 */

namespace APP\plugins\generic\journalQuota\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class QuotaSchemaMigration extends Migration
{
    public const TABLE = 'journal_quota_files';

    /** Storage reserved by active uploads (and one lock row per journal) */
    public const RESERVATIONS = 'journal_quota_reservations';

    /** Safe to run repeatedly: creates tables only when absent */
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
            // SHA-1 of the file key ("f:" + files_dir path, "p:" + public path)
            $table->char('path_hash', 40);
            $table->string('path', 1024);
            $table->string('part', 16);
            $table->bigInteger('submission_id')->nullable();
            $table->bigInteger('bytes');
            // Timestamp (microseconds) of the latest confirmation by a hook or full scan
            $table->decimal('seen_at', 16, 4);
            $table->decimal('deleted_at', 16, 4)->nullable();
            $table->unique(['path_hash'], 'journal_quota_files_path');
            // Covers per-part sums: the total can be read from the index alone
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
