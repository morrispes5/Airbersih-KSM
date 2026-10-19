<?php

namespace App\Models;

class Invoice extends Record
{
    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_at' => 'datetime', 'due_date' => 'date'];
    }
}
