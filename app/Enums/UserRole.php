<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case PROVIDER = 'provider';
    case CLIENT = 'client';
}