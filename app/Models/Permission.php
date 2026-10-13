<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Permission extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
