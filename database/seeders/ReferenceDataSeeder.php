<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Minimal master data this module needs but doesn't own — metals,
 * purities, party types. Not required for the test suite (every test
 * creates its own rows), only for exploring the module manually via
 * `php artisan tinker` or a future UI.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $goldId = DB::table('metal_types')->insertGetId(['name' => 'Gold']);
        $silverId = DB::table('metal_types')->insertGetId(['name' => 'Silver']);

        DB::table('purities')->insert([
            ['metal_type_id' => $goldId, 'name' => '24K', 'fineness_percent' => 99.90],
            // Owner-confirmed: 91.66%, not the textbook 91.67% — see the
            // Day 1 Design Note's "Open question resolved" section.
            ['metal_type_id' => $goldId, 'name' => '22K', 'fineness_percent' => 91.66],
            ['metal_type_id' => $goldId, 'name' => '21K', 'fineness_percent' => 87.50],
            ['metal_type_id' => $goldId, 'name' => '18K', 'fineness_percent' => 75.00],
            ['metal_type_id' => $silverId, 'name' => 'Fine Silver (999)', 'fineness_percent' => 99.90],
            ['metal_type_id' => $silverId, 'name' => 'Sterling Silver (925)', 'fineness_percent' => 92.50],
        ]);

        DB::table('party_types')->insert([
            ['name' => 'Customer'],
            ['name' => 'Karigar'],
            ['name' => 'Wholesaler'],
            ['name' => 'Other Shop'],
            ['name' => 'Company'],
        ]);

        // Owner-confirmed: 11.664g per tola — set once here, per the brief's
        // own instruction ("settle it with the owner, and from then on it
        // is never retyped anywhere"). WeightConversionService falls back to
        // the textbook 11.6638038g only if this row is ever missing.
        DB::table('settings')->insert([
            'key' => 'tola_grams',
            'value' => '11.664',
            'updated_at' => now(),
        ]);
    }
}
