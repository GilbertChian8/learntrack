<?php

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Group;
use App\Models\User;
use App\Support\AuditWriter;
use Illuminate\Support\Facades\DB;

class CreateGroup
{
    public function handle(User $educator, string $name, AuditChannel $channel): Group
    {
        return DB::transaction(function () use ($educator, $name, $channel): Group {
            $group = Group::query()->create([
                'institution_id' => $educator->institution_id,
                'name' => $name,
            ]);

            $group->educators()->attach($educator);

            AuditWriter::record($educator, $channel, AuditAction::GroupCreated, $group, ['name' => $name]);

            return $group;
        });
    }
}
