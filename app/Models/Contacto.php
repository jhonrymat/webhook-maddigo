<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contacto extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'apellido',
        'correo',
        'telefono',
        'notas'
    ];

    public function tags(){
        return $this->belongsToMany(Tag::class, 'contacto_tag', 'contacto_id', 'tag_id');
    }

    public function createWithTags(array $data)
    {
        $contacto = $this->create($data);

        // Obtén los tags a partir de los datos
        $tagNames = explode(',', $data['tags']);
        $tags = Tag::whereIn('nombre', $tagNames)->pluck('id');

        // Relaciona los tags al contacto
        $contacto->tags()->sync($tags);

        return $contacto;
    }
}
