<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pattern extends Model
{
    use HasFactory;

    protected $fillable = [
        'template',
        'popularity_rank',
        'min_total_length',
        'forename_count',
        'surname_count',
        'has_title',
        'has_initials',
        'has_prefix',
        'has_suffix',
        'has_honorific',
    ];
}
