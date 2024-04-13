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

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'contacto_tag', 'contacto_id', 'tag_id');
    }

    // public function mensajes()
    // {
    //     return $this->hasMany(Message::class, 'wa_id', 'telefono');
    // }
    public function messages()
    {
        return $this->hasMany(Message::class, 'telefono', 'wa_id');
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

    public function createWithDefaultTag(array $data, $defaultTagName = 'Pendiente')
    {
        $contacto = $this->create($data);

        // Encuentra el tag 'Pendiente' o crea uno si no existe
        $tag = Tag::firstOrCreate(['nombre' => $defaultTagName], ['descripcion' => 'Descripción pendiente', 'color' => 'gray']);

        // Asigna el tag 'Pendiente' al nuevo contacto
        $contacto->tags()->attach($tag->id);

        return $contacto;
    }
}
