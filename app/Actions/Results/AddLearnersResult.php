<?php

namespace App\Actions\Results;

use App\Models\User;
use Illuminate\Support\Collection;

readonly class AddLearnersResult
{
    /**
     * @param  Collection<int, User>  $added
     * @param  Collection<int, User>  $alreadyMembers
     */
    public function __construct(
        public Collection $added,
        public Collection $alreadyMembers,
    ) {}
}
