<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Thread extends Model
{
    use HasFactory;

    // Nombre de la tabla asociado con el modelo
    protected $table = 'threads';

    // Atributos que se pueden asignar de manera masiva
    protected $fillable = ['wa_id', 'thread_id', 'bot_id']; 
}
