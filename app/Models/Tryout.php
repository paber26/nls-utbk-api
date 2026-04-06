<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tryout extends Model
{
    protected $table = 'tryout';

    protected $fillable = [
        'paket',
        'durasi_menit',
        'mulai',
        'selesai',
        'status',
        'access_key',
        'created_by',
        'ketentuan_khusus',
        'pesan_selesai',
        'show_pembahasan',
    ];

    protected $casts = [
        'mulai' => 'datetime',
        'selesai' => 'datetime',
        'show_pembahasan' => 'boolean',
    ];

    public function komponen()
    {
        return $this->belongsToMany(Komponen::class, 'tryout_komponen', 'tryout_id', 'komponen_id')
                    ->withPivot('urutan', 'durasi_menit')
                    ->orderBy('tryout_komponen.urutan');
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ✅ RELASI SOAL TRYOUT
    public function questions()
    {
        return $this->hasMany(
            TryoutSoal::class,
            'tryout_id',
            'id'
        );
    }

    // ✅ RELASI ATTEMPT USER
    public function attempts()
    {
        return $this->hasMany(
            Attempt::class,
            'tryout_id',
            'id'
        );
    }
}
