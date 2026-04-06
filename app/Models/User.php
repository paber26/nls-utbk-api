<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $table = 'users';

    protected $fillable = [
        'name',
        'nama_lengkap',
        'email',
        'google_id',
        'avatar',
        'sekolah_id',
        'sekolah_nama',
        'kelas',
        'provinsi',
        'kota',
        'kecamatan',
        'whatsapp',
        'minat',
        'password',
        'role',
        'is_active',
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    protected $casts = [
        'minat' => 'array',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sekolah()
    {
        return $this->belongsTo(\App\Models\Sekolah::class);
    }

    public function attempts()
    {
        return $this->hasMany(\App\Models\Attempt::class);
    }
}
