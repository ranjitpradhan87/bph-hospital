<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        // Clinical text is encrypted at rest (APP_KEY); structured observations stay queryable JSON.
        return ['content_encrypted' => 'encrypted', 'data' => 'array', 'file_size' => 'integer'];
    }
}
