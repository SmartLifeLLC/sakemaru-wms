<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先-仕入先マッピング
 */
class WmsContractorSupplier extends WmsModel
{
    protected $table = 'wms_contractor_suppliers';

    protected $fillable = [
        'contractor_id',
        'supplier_id',
        'memo',
    ];

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
