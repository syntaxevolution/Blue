<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row in the items_catalog table.
 *
 * Items are static reference data describing what you can buy at each
 * post. Purchases are recorded as Player attribute changes by ShopService;
 * we don't track inventory per-player yet (all current items apply their
 * effects immediately and have no consumable state). Inventory +
 * consumables will land when we add Paper Maps, Fuel Cans, etc.
 */
class Item extends Model
{
    protected $table = 'items_catalog';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'post_type',
        'name',
        'description',
        'price_barrels',
        'price_cash',
        'price_intel',
        'effects',
        'sort_order',
    ];

    protected $casts = [
        'price_barrels' => 'integer',
        'price_cash' => 'decimal:2',
        'price_intel' => 'integer',
        'effects' => 'array',
        'sort_order' => 'integer',
    ];
}
