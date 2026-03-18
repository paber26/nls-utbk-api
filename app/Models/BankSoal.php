<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankSoal extends Model
{
    use HasFactory;

    protected $table = 'banksoal'; // 👈 INI KUNCINYA

    protected $fillable = [
        'komponen_id',
        'tipe',
        'pertanyaan',
        'pembahasan',
        'jawaban',
        'idopsijawaban',
        'created_by'
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function komponen()
    {
        return $this->belongsTo(Komponen::class, 'komponen_id');
    }

    public function opsiJawaban()
    {
        return $this->hasMany(OpsiJawaban::class, 'soal_id');
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pernyataanKompleks()
    {
        return $this->hasMany(
            BankSoalPernyataan::class,
            'banksoal_id'
        )->orderBy('urutan');
    }

}