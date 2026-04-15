<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mapel extends Model
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
     * Relasi: 1 mapel punya banyak bank soal
     */
    public function bankSoals()
    {
        return $this->hasMany(BankSoal::class, 'mapel_id');
    }
}
