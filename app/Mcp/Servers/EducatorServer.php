<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AssignContent;
use App\Mcp\Tools\ChangeDueDate;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('LearnTrack Educator')]
#[Version('1.0.0')]
#[Instructions('LearnTrack shows how the learners in the groups you teach are doing. Start with list_my_groups to get group ids. Tools answer only about your own groups; anything else is "not found". Every answer carries the reasons behind it, so quote them instead of guessing. assign_content and change_due_date change data: tell the educator exactly what will change and ask before calling them. Dates are given in the institution\'s time zone.')]
class EducatorServer extends Server
{
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
    ];

    protected array $tools = [
        AssignContent::class,
        ChangeDueDate::class,
    ];
}
