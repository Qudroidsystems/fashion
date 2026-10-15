<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPricedLines;
use Illuminate\Database\Eloquent\Model;

class QuotationLine extends Model
{
    use BelongsToTenant, HasPricedLines;

    protected $fillable = ['quotation_id'];
}
