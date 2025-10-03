<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Token extends Model
{

    public const TOKEN_NAME_TITLE = 'title';
    public const TOKEN_NAME_FORENAME = 'forename';
    public const TOKEN_NAME_INITIALS = 'initials';
    public const TOKEN_NAME_PREFIX = 'prefix';
    public const TOKEN_NAME_SURNAME = 'surname';
    public const TOKEN_NAME_SUFFIX = 'suffix';
    public const TOKEN_NAME_HONORIFIC = 'honorific';

    public const NAMES = [
        self::TOKEN_NAME_TITLE,
        self::TOKEN_NAME_FORENAME,
        self::TOKEN_NAME_INITIALS,
        self::TOKEN_NAME_PREFIX,
        self::TOKEN_NAME_SURNAME,
        self::TOKEN_NAME_SUFFIX,
        self::TOKEN_NAME_HONORIFIC,
    ];

    public const DROPDOWN = [
        self::TOKEN_NAME_SURNAME,
        self::TOKEN_NAME_FORENAME,
        self::TOKEN_NAME_TITLE,
        self::TOKEN_NAME_INITIALS,
        self::TOKEN_NAME_PREFIX,
        self::TOKEN_NAME_SUFFIX,
        self::TOKEN_NAME_HONORIFIC,
    ];

    protected $fillable = [
        'name',
        'prio',
        'min_length',
        'allow_nearly',
        'has_fun',
        'has_boring',
        'max_multiples',
    ];

    public static function lookup(string $name): self
    {
        return self::where('name', $name)->first();
    }
}
