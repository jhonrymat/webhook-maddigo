<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{

    use HasFactory;
    protected $fillable = ['nombre', 'descripcion', 'color'];  // Asegúrate de incluir todos los campos que deseas asignar masivamente
    public function contactos()
    {
        return $this->belongsToMany(Contacto::class, 'contacto_tag', 'tag_id', 'contacto_id');
    }
}
