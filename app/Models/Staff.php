<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use SoftDeletes;

    protected $table = 'staff';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        // Salary is encrypted at rest (APP_KEY); only holders of staff.salary ever see it.
        return ['salary_encrypted' => 'encrypted', 'joining_date' => 'date:Y-m-d', 'date_of_birth' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }
}
