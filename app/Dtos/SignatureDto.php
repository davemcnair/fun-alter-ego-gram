<?php

namespace App\Dtos;

use App\Traits\HelpsMatchWords;
use Spatie\LaravelData\Data;

class SignatureDto extends Data
{
    use HelpsMatchWords;

    public function __construct(
        public string $signature,
        public array $defaults
//        public string $signature_indexed,
//        public string $normalized_key,
//        public int    $sig_len,
//        public int    $a_count,
//        public int    $b_count,
//        public int    $c_count,
//        public int    $d_count,
//        public int    $e_count,
//        public int    $f_count,
//        public int    $g_count,
//        public int    $h_count,
//        public int    $i_count,
//        public int    $j_count,
//        public int    $k_count,
//        public int    $l_count,
//        public int    $m_count,
//        public int    $n_count,
//        public int    $o_count,
//        public int    $p_count,
//        public int    $q_count,
//        public int    $r_count,
//        public int    $s_count,
//        public int    $t_count,
//        public int    $u_count,
//        public int    $v_count,
//        public int    $w_count,
//        public int    $x_count,
//        public int    $y_count,
//        public int    $z_count
    )
    {
    }

    public static function fromWord(string $word): self
    {
        $s = strtolower($word);
        // Remove any character outside ASCII a-z. Accented letters are dropped.
        $norm = preg_replace('/[^a-z]/', '', $s) ?? '';

        $chars = str_split($norm);
        sort($chars);
        $signature = implode('', $chars);
        // Compute per-letter letterCounts and signature length for new TokenSignature rows
        $letterCounts = [];
        $len = strlen($signature);
        for ($i=0; $i<$len; $i++) {
            $ch = $signature[$i];
            $letterCounts[$ch] = ($letterCounts[$ch] ?? 0) + 1;
        }
        $defaults = ['length' => $len];
        foreach (range('a', 'z') as $ch) {
            $defaults[$ch . '_count'] = (int)($letterCounts[$ch] ?? 0);
        }
        return new self(
            $signature,
            $defaults,
//            $defaults['length'],
//            $defaults['a_count'],
//            $defaults['b_count'],
//            $defaults['c_count'],
//            $defaults['d_count'],
//            $defaults['e_count'],
//            $defaults['f_count'],
//            $defaults['g_count'],
//            $defaults['h_count'],
//            $defaults['i_count'],
//            $defaults['j_count'],
//            $defaults['k_count'],
//            $defaults['l_count'],
//            $defaults['m_count'],
//            $defaults['n_count'],
//            $defaults['o_count'],
//            $defaults['p_count'],
//            $defaults['q_count'],
//            $defaults['r_count'],
//            $defaults['s_count'],
//            $defaults['t_count'],
//            $defaults['u_count'],
//            $defaults['v_count'],
//            $defaults['w_count'],
//            $defaults['x_count'],
//            $defaults['y_count'],
//            $defaults['z_count'],
        );
    }
}
