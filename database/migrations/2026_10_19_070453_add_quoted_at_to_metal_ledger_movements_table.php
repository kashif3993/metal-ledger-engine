<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Day 1 design note's own data-model commitment: "transactions: add
     * ... quoted_at (the moment the rate was locked)." Answers the brief's
     * mandatory Day 1 question — a bill line's price is locked the moment it
     * is quoted, not at print time — by giving every movement a real moment
     * to measure a quote-validity window and rate-change tolerance against
     * (see RateBookService::quote()/assertQuoteStillValid()).
     *
     * Nullable so existing rows (recorded before this column existed) stay
     * valid; every new movement going through LedgerService::recordMovement()
     * always has one set (defaults to "now" when the caller has no separate
     * quote moment — an instant transaction like a buy-back or a karigar
     * settlement, which is quoted and finalized in the same instant).
     */
    public function up(): void
    {
        Schema::table('metal_ledger_movements', function (Blueprint $table) {
            $table->dateTime('quoted_at')->nullable()->after('movement_date');
        });
    }

    public function down(): void
    {
        Schema::table('metal_ledger_movements', function (Blueprint $table) {
            $table->dropColumn('quoted_at');
        });
    }
};
