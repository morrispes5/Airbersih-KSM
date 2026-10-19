<?php

namespace App\Models;

class Entry extends Record
{
    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }
}
