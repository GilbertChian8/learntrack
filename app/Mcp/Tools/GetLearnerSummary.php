<?php

namespace App\Mcp\Tools;

use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use App\Exceptions\NotFoundException;
use App\Mcp\Schemas\CountsSchema;
use App\Mcp\Schemas\LearnerSchema;
use App\Mcp\Schemas\ReasonSchema;
use App\Queries\LearnerSummary;
use App\Queries\Results\AssignmentStatus;
use App\Queries\Results\LearnerCandidate;
use App\Queries\Results\LearnerGroupProgress;
use App\Queries\Results\LearnerSummaryResult;
use App\Queries\Results\Reason;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_learner_summary')]
#[Title('Learner summary')]
#[Description('Shows one learner\'s progress in every group you teach that they belong to: whether they are behind in each group and why, their status counts, their average score and each assignment\'s status. Give the learner_id when you have it; otherwise give the name and the tool returns the matching learners so you can ask which one is meant.')]
#[IsReadOnly]
#[IsIdempotent]
class GetLearnerSummary extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'learner_id' => $schema->integer()->description('The learner\'s id from an earlier answer. Preferred.'),
            'name' => $schema->string()->min(2)->description('Part of the learner\'s name, when the id is unknown. Case-insensitive.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'learner' => LearnerSchema::make($schema)->nullable()->required(),
            'groups' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
                'behind' => $schema->boolean()->required(),
                'reasons' => $schema->array()->items(ReasonSchema::make($schema))->required(),
                'average_score' => $schema->number()->nullable()->required(),
                'counts' => CountsSchema::make($schema)->required(),
                'assignments' => $schema->array()->items($schema->object([
                    'assignment_id' => $schema->integer()->required(),
                    'title' => $schema->string()->required(),
                    'type' => $schema->string()->enum(ContentType::class)->required(),
                    'due_at' => $schema->string()->format('date-time')->required(),
                    'status' => $schema->string()->enum(DerivedStatus::class)->required(),
                    'score' => $schema->integer()->nullable()->required(),
                    'completed_at' => $schema->string()->format('date-time')->nullable()->required(),
                ])->withoutAdditionalProperties())->required(),
            ])->withoutAdditionalProperties())->required(),
            'candidates' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
                'groups' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    /**
     * learner_id wins over name. A name that matches one learner gives that learner's summary.
     */
    protected function answer(Request $request): array
    {
        $request->validate([
            'learner_id' => ['required_without:name', 'integer:strict'],
            'name' => ['string', 'min:2'],
        ], [
            'learner_id.required_without' => 'Give a learner_id or a name.',
        ]);
        $educator = $this->educator($request);

        if ($request->has('learner_id')) {
            return $this->summaryOf(LearnerSummary::for($educator, $request->integer('learner_id')));
        }

        $candidates = LearnerSummary::candidates($educator, $request->string('name')->value());

        return match (count($candidates)) {
            0 => throw NotFoundException::learner(),
            1 => $this->summaryOf(LearnerSummary::for($educator, $candidates[0]->id)),
            default => [
                'learner' => null,
                'groups' => [],
                'candidates' => array_map(fn (LearnerCandidate $candidate) => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'groups' => $candidate->groupNames,
                ], $candidates),
            ],
        };
    }

    protected function summary(array $answer, Request $request): string
    {
        if ($answer['learner'] === null) {
            $candidates = array_map(fn (array $candidate) => $candidate['name'].' ('.implode(', ', $candidate['groups']).')', $answer['candidates']);

            return count($candidates)." learners match \"{$request->string('name')->value()}\": ".implode(', ', $candidates).'. '
                .'Ask which one is meant, then call again with the learner_id.';
        }

        $groups = array_map(function (array $group): string {
            $standing = $group['behind'] ? 'behind ('.implode('; ', array_column($group['reasons'], 'text')).')' : 'on track';
            $counts = $group['counts'];

            return "{$group['name']}: {$standing}; {$counts['completed']} completed, {$counts['in_progress']} in progress, "
                ."{$counts['not_started']} not started, {$counts['overdue']} overdue.";
        }, $answer['groups']);

        return "{$answer['learner']['name']} is in ".count($groups).' of your groups. '.implode(' ', $groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryOf(LearnerSummaryResult $summary): array
    {
        return [
            'learner' => ['id' => $summary->learnerId, 'name' => $summary->learnerName],
            'groups' => array_map(fn (LearnerGroupProgress $group) => [
                'id' => $group->groupId,
                'name' => $group->groupName,
                'behind' => $group->progress->behind,
                'reasons' => array_map(fn (Reason $reason) => $reason->toArray(), $group->progress->reasons),
                'average_score' => $group->progress->averageScore,
                'counts' => CountsSchema::from($group->progress->counts),
                'assignments' => array_map(fn (AssignmentStatus $status) => [
                    'assignment_id' => $status->assignmentId,
                    'title' => $status->title,
                    'type' => $status->type->value,
                    'due_at' => $status->dueAt->toIso8601String(),
                    'status' => $status->status->value,
                    'score' => $status->score,
                    'completed_at' => $status->completedAt?->toIso8601String(),
                ], $group->progress->statuses),
            ], $summary->groups),
            'candidates' => [],
        ];
    }
}
