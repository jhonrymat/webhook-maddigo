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
}
