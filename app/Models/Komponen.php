<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Komponen extends Model
{
    use HasFactory;

    protected $table = 'komponen';

    protected $fillable = [
        'kode',
        'nama_komponen',
        'mata_uji',
    ];

    public $timestamps = true; // karena ada created_at

    /**
     * Relasi: 1 komponen punya banyak bank soal
     */
    public function bankSoals()
    {
        return $this->hasMany(BankSoal::class, 'komponen_id');
    }
}