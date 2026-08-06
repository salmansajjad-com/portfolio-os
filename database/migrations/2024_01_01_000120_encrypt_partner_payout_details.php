<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Payout details hold bank and wallet identifiers but were stored in clear text,
 * so a leaked database backup exposed them while credential secrets stayed
 * encrypted. The column now uses the same encryption as the vault; existing rows
 * are encrypted in place here so they remain readable through the cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('partner_profiles')
            ->whereNotNull('payout_details')
            ->where('payout_details', '!=', '')
            ->orderBy('id')
            ->select('id', 'payout_details')
            ->get()
            ->each(function ($row) {
                // Skip anything already encrypted, so re-running is harmless.
                try {
                    Crypt::decryptString($row->payout_details);

                    return;
                } catch (Throwable) {
                    // Plain text, as expected before this migration.
                }

                DB::table('partner_profiles')
                    ->where('id', $row->id)
                    ->update(['payout_details' => Crypt::encryptString($row->payout_details)]);
            });
    }

    public function down(): void
    {
        DB::table('partner_profiles')
            ->whereNotNull('payout_details')
            ->where('payout_details', '!=', '')
            ->orderBy('id')
            ->select('id', 'payout_details')
            ->get()
            ->each(function ($row) {
                try {
                    $plain = Crypt::decryptString($row->payout_details);
                } catch (Throwable) {
                    return;
                }

                DB::table('partner_profiles')
                    ->where('id', $row->id)
                    ->update(['payout_details' => $plain]);
            });
    }
};
