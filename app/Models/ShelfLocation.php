<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShelfLocation extends Model
{
    protected $guarded = [];

    public function materialPieces(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MaterialPiece::class, 'shelf_location_id');
    }
}