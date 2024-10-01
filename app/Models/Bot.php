<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bot extends Model
{
    use HasFactory;

    // bot con openai asistente
    protected $fillable = [
        'user_id',
        'nombre',
        'descripcion',
        'openai_key',
        'openai_org',
        'openai_assistant',
        'aplicacion_id', // Relación con la aplicación
    ];

    /**
     * Relación: un bot pertenece a un usuario.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function aplicaciones()
    {
        // Relación muchos a muchos con las aplicaciones, usando la tabla pivote
        return $this->belongsToMany(Aplicaciones::class, 'aplicacion_bot', 'bot_id', 'aplicacion_id')->withTimestamps();
    }
}
