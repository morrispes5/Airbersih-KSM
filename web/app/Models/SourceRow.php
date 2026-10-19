<?php

namespace App\Models;

class SourceRow extends Record
{
    protected function casts(): array
    {
        return ['cells' => 'array', 'issues' => 'array'];
    }
}
