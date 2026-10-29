<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Inactive = 'inactive';
}
