<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aplicaciones extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'id_app',
        'id_c_business',
        'token_api',
    ];

    public function numeros()
    {
        // Asegúrate de que el espacio de nombres del modelo Numeros sea correcto
        return $this->hasMany(Numeros::class, 'aplicacion_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_aplicaciones', 'aplicacion_id', 'user_id');
    }

    public function bot()
    {
        // Relación uno a uno con el bot, usando la tabla pivote
        return $this->belongsToMany(Bot::class, 'aplicacion_bot', 'aplicacion_id', 'bot_id')->withTimestamps();
    }
}
