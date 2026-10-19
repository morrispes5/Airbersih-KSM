<?php

namespace App\Models;

class Audit extends Record
{
    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
