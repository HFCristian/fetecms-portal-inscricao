<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PalavraChave extends Model
{
    protected $table = 'palavras_chave';

    protected $fillable = ['texto'];
}
