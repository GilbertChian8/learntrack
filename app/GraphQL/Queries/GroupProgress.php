<?php

namespace App\GraphQL\Queries;

use App\Exceptions\NotFoundException;
use App\Models\User;
use App\Queries\GroupProgress as GroupProgressQuery;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Query.groupProgress. Maps the GroupProgress result to the schema types.
 */
class GroupProgress
{
    /**
     * @param  array{groupId: string}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $educator = $context->user();
        assert($educator instanceof User);

        if (! ctype_digit($args['groupId'])) {
            throw NotFoundException::group();
        }

        $result = GroupProgressQuery::for($educator, (int) $args['groupId']);

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
