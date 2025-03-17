<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'nombre',
        'email',
        'telefono',
        'detalles',
        'calificacion',
        'estado',
    ];

    protected $casts = [
        'detalles' => 'array',
    ];

    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }
}
