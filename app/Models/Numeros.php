<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Numeros extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'numero',
        'id_telefono',
        'aplicacion_id',
        'calidad',
    ];

    /**
     * Obtener la aplicación asociada con el número.
     */
    public function aplicacion()
    {
        return $this->belongsTo(Aplicaciones::class, 'aplicacion_id');
    }

}
