<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-generated expenses were deduplicated in PHP only, so two concurrent
 * requests could both pass the "does one already exist?" check and charge the
 * writer, the link or the recurring template twice. These indexes make the
 * database refuse the second row.
 *
 * Recurring expenses reuse one template id across months, so they cannot share
 * the work-sourced key. They get their own (template, date) constraint and
 * leave source_key null, which a unique index permits many times over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('source_key')->nullable()->after('source_id');
        });

        // Backfill work-sourced rows, keeping the earliest of any existing duplicates.
        $seen = [];

        DB::table('expenses')
            ->whereNotNull('source_type')
            ->whereNull('recurring_expense_id')
            ->orderBy('id')
            ->select('id', 'source_type', 'source_id')
            ->get()
            ->each(function ($row) use (&$seen) {
                $key = $row->source_type.':'.$row->source_id;

                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;

                DB::table('expenses')->where('id', $row->id)->update(['source_key' => $key]);
            });

        Schema::table('expenses', function (Blueprint $table) {
            $table->unique('source_key');
            $table->unique(['recurring_expense_id', 'expense_date'], 'expenses_recurring_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_recurring_period_unique');
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
    }
};
