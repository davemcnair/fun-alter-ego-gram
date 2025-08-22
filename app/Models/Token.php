<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'prio',
        'min_length',
        'allow_nearly',
        'has_fun',
        'has_boring',
        'max_multiples',
    ];
}
