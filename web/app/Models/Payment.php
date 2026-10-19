<?php

namespace App\Models;

class Payment extends Record
{
    protected function casts(): array
    {
        return ['paid_on' => 'date'];
    }
}
