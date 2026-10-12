<?php

namespace App\GraphQL\Queries;

use App\Exceptions\NotFoundException;
use App\Queries\GroupProgress as GroupProgressQuery;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;

/**
 * Query.groupProgress. Maps the GroupProgress result to the schema types.
 */
class GroupProgress
{
    /**
     * @param  array{groupId: string}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        if (! ctype_digit($args['groupId'])) {
            throw NotFoundException::group();
        }

        $result = GroupProgressQuery::for(request()->user(), (int) $args['groupId']);

        return [
            'group' => ['id' => $result->groupId, 'name' => $result->groupName, 'institution' => $result->institutionName],
            'generatedAt' => $result->generatedAt,
            'assignments' => $result->assignments,
            'learners' => array_map(fn (LearnerProgress $learner) => [
                'learner' => ['id' => $learner->learnerId, 'name' => $learner->learnerName],
                'behind' => $learner->behind,
                'reasons' => array_map(fn (Reason $reason) => $reason->text(), $learner->reasons),
                'averageScore' => $learner->averageScore,
                'counts' => $learner->counts,
                'statuses' => $learner->statuses,
            ], $result->learners),
        ];
    }
}
