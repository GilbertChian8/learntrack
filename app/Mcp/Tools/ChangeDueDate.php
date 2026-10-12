<?php

namespace App\Mcp\Tools;

use App\Actions\ChangeDueDate as ChangeDueDateAction;
use App\Enums\AuditChannel;
use App\Http\Resources\AssignmentResource;
use App\Mcp\Schemas\AssignmentSchema;
use App\Mcp\Tools\Concerns\ParsesDueDates;
use App\Support\InstitutionTime;
use App\Support\Scope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('change_due_date')]
#[Title('Change due date')]
#[Description('Moves the due date of an assignment in one of your groups. Get the assignment_id from get_group_progress, find_hardest_assignments or assign_content. The same date answers changed: false. Nothing else about the assignment changes and no progress is touched.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(true)]
class ChangeDueDate extends BaseTool
{
    use ParsesDueDates;

    public function __construct(private readonly ChangeDueDateAction $changeDueDate) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'assignment_id' => $schema->integer()->description('The assignment id from an earlier answer.')->required(),
            'due_date' => $schema->string()->description("YYYY-MM-DD (end of that day in the institution's time zone) or an ISO 8601 date-time with offset. Must be in the future.")->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->required(),
            'assignment' => AssignmentSchema::make($schema)->required(),
            'previous_due_at' => $schema->string()->format('date-time')->required(),
            'due_at' => $schema->string()->format('date-time')->required(),
            'message' => $schema->string()->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        $request->validate([
            'assignment_id' => ['required', 'integer:strict'],
            'due_date' => ['required', 'string'],
        ]);

        $educator = $this->educator($request);
        $assignment = Scope::assignment($educator, $request->integer('assignment_id'));
        $dueAt = $this->dueAt($request->string('due_date')->value(), $assignment->group->institution);

        $result = $this->changeDueDate->handle($educator, $assignment, $dueAt, AuditChannel::Mcp);

        $assignment = $result->assignment;
        $institutionTime = new InstitutionTime($assignment->group->institution->timezone);
        $title = $assignment->contentItem->title;
        $group = $assignment->group->name;
        $previous = $institutionTime->format($result->previousDueAt);
        $due = $institutionTime->format($assignment->due_at);
        $targeted = $assignment->getAttribute('targeted_count');
        $affected = $targeted.' '.Str::plural('learner', $targeted).' '.($targeted === 1 ? 'is' : 'are');

        return [
            'changed' => $result->changed,
            'assignment' => new AssignmentResource($assignment),
            'previous_due_at' => $previous,
            'due_at' => $due,
            'message' => $result->changed
                ? "Moved {$title} in {$group} from {$previous} to {$due}. {$affected} affected."
                : "{$title} in {$group} is already due {$due}. Nothing changed.",
        ];
    }

    protected function summary(array $answer): string
    {
        return $answer['message'];
    }
}
