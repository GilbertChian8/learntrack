<?php

namespace App\Enums;

enum AuditChannel: string
{
    case Rest = 'rest';
    case Mcp = 'mcp';
}
