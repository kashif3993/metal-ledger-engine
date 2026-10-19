<?php

declare(strict_types=1);

namespace App\Models\MetalLedger;

use App\Models\MetalType;
use App\Models\Party;
use App\Models\Purity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in the Metal Ledger movement ledger (Task 3) — one gram-moving
 * event, whoever it moves to or from. Rows are never updated or deleted by
 * the service that writes them; a correction is always a new row pointing
 * back at the one it corrects via {@see correctedMovement()}.
 */
class MetalLedgerMovement extends Model
{
    public $timestamps = false;

    protected $table = 'metal_ledger_movements';

    protected $fillable = [
        'party_id',
        'direction',
        'metal_type_id',
        'purity_id',
        'weight_grams',
        'pure_weight_grams',
        'rate_per_gram',
        'amount',
        'movement_date',
        'quoted_at',
        'entered_by_user_id',
        'item_reference',
        'document_reference',
        'notes',
        'corrects_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'weight_grams' => 'decimal:3',
            'pure_weight_grams' => 'decimal:3',
            'rate_per_gram' => 'decimal:2',
            'amount' => 'decimal:2',
            'movement_date' => 'date',
            'quoted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function metalType(): BelongsTo
    {
        return $this->belongsTo(MetalType::class);
    }

    public function purity(): BelongsTo
    {
        return $this->belongsTo(Purity::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    /** The movement this one corrects, if it's a correction. */
    public function correctedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_movement_id');
    }
}
