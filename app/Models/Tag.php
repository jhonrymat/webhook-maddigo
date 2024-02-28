<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;
    public function contactos(){
        return $this->belongsToMany(Contacto::class, 'contacto_tag', 'tag_id', 'contacto_id');
    }
}
