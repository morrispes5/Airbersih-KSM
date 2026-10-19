<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class Record extends Model
{
    protected $guarded = ['id'];
}
