<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Signature extends Model
{

    protected $fillable = [
        'signature',
        'length',
        'a_count','b_count','c_count','d_count','e_count','f_count','g_count','h_count','i_count','j_count','k_count','l_count','m_count','n_count','o_count','p_count','q_count','r_count','s_count','t_count','u_count','v_count','w_count','x_count','y_count','z_count',
    ];

    public function tokenSignatures(): BelongsToMany
    {
        return $this->belongsToMany(TokenSignature::class);
    }

    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(Target::class);
    }

    /**
     * Return a compact histogram of non-zero letterCounts from persisted columns.
     * @return array<string,int>
     */
    public function letterCounts(): array
    {
        $out = [];
        foreach (range('a', 'z') as $ch) {
            $n = (int) ($this->getAttribute($ch . '_count') ?? 0);
            if ($n > 0) {
                $out[$ch] = $n;
            }
        }
        return $out;
    }
}

