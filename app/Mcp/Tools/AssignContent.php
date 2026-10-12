<?php

namespace App\Mcp\Tools;

use App\Actions\AssignContent as AssignContentAction;
use App\Actions\Results\AssignResult;
use App\Enums\AssignmentScope;
use App\Enums\AssignOutcome;
use App\Enums\AuditChannel;
use App\Enums\ContentType;
use App\Exceptions\AlreadyAssignedException;
use App\Exceptions\NotFoundException;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\LearnerResource;
use App\Mcp\Schemas\AssignmentSchema;
use App\Mcp\Schemas\LearnerSchema;
use App\Mcp\Tools\Concerns\ParsesDueDates;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\User;
use App\Queries\FindContent;
use App\Support\InstitutionTime;
use App\Support\Scope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('assign_content')]
#[Title('Assign content')]
#[Description('Assigns a content item (an article or a question set) to one of your groups with a due date, or only to some learners in it (for example the learners who are behind). Give content_item_id when you know it; otherwise give content_title and the tool finds the item, or returns the candidates when several match. Nothing is ever deleted. Repeating the same call changes nothing and says so. If the content is already assigned with another due date, nothing changes; use change_due_date instead.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(true)]
class AssignContent extends BaseTool
{
    use ParsesDueDates;

    private const int NAMES_LISTED_UP_TO = 10;

    public function __construct(private readonly AssignContentAction $assignContent) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->description('The group id from list_my_groups.')->required(),
            'content_item_id' => $schema->integer()->description('The content item id. Preferred when known.'),
            'content_title' => $schema->string()->min(2)->description('Part of the content title, when the id is unknown. Case-insensitive.'),
            'due_date' => $schema->string()->description("YYYY-MM-DD (end of that day in the institution's time zone) or an ISO 8601 date-time with offset. Must be in the future.")->required(),
            'learner_ids' => $schema->array()->items($schema->integer())->min(1)->max(60)->unique()->description('Only these learners get the assignment. Leave out to assign to the whole group.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->required(),
            'result' => $schema->string()->enum([...array_column(AssignOutcome::cases(), 'value'), 'already_assigned', 'candidates'])->required(),
            'assignment' => AssignmentSchema::make($schema)->nullable()->required(),
            'added' => $schema->array()->items(LearnerSchema::make($schema))->required(),
            'already_had_it' => $schema->array()->items(LearnerSchema::make($schema))->required(),
            'candidates' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
                'topic' => $schema->string()->required(),
                'type' => $schema->string()->enum(ContentType::class)->required(),
            ])->withoutAdditionalProperties())->required(),
            'message' => $schema->string()->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        $request->validate([
            'group_id' => ['required', 'integer:strict'],
            'content_item_id' => ['required_without:content_title', 'integer:strict'],
            'content_title' => ['sometimes', 'string', 'min:2'],
            'due_date' => ['required', 'string'],
            'learner_ids' => ['sometimes', 'list', 'min:1', 'max:60'],
            'learner_ids.*' => ['integer:strict', 'distinct'],
        ], [
            'content_item_id.required_without' => 'Give a content_item_id or a content_title.',
        ]);

        $educator = $this->educator($request);
        $group = Scope::group($educator, $request->integer('group_id'));
        $content = $this->content($request);

        if ($content instanceof Collection) {
            return $this->candidates($content, $request->string('content_title')->value());
        }

        $dueDate = $request->string('due_date')->value();
        $dueAt = $this->dueAt($dueDate, $group->institution);
        /** @var list<int> $learnerIds */
        $learnerIds = $request->array('learner_ids');

        try {
            $result = $this->assignContent->handle($educator, $group, $content, $dueAt, $learnerIds, AuditChannel::Mcp);
        } catch (AlreadyAssignedException $exception) {
            return $this->alreadyAssigned($exception->assignment, $dueDate);
        } catch (ValidationException $exception) {
            throw $this->notAMember($exception, $learnerIds, $group);
        }

        return $this->assigned($result, $learnerIds !== []);
    }

    protected function summary(array $answer): string
    {
        return $answer['message'];
    }

    /**
     * The content item, or the candidates when several titles match. content_item_id wins.
     *
     * @return ContentItem|Collection<int, ContentItem>
     */
    private function content(Request $request): ContentItem|Collection
    {
        if ($request->has('content_item_id')) {
            return FindContent::byId($request->integer('content_item_id')) ?? throw NotFoundException::contentItem();
        }

        $title = $request->string('content_title')->value();
        $matches = FindContent::byTitle($title);

        return match ($matches->count()) {
            0 => throw ValidationException::withMessages(['content_title' => "No content item matches \"{$title}\"."]),
            1 => $matches->sole(),
            default => $matches,
        };
    }

    /**
     * The action names every learner who is not a member, keyed learner_ids.{index}; the answer names the first.
     * Any other validation error goes out unchanged.
     *
     * @param  list<int>  $learnerIds
     */
    private function notAMember(ValidationException $exception, array $learnerIds, Group $group): ValidationException
    {
        $key = (string) array_key_first($exception->errors());

        if (! str_starts_with($key, 'learner_ids.')) {
            return $exception;
        }

        $index = (int) Str::after($key, 'learner_ids.');

        return ValidationException::withMessages(['learner_ids' => "Learner {$learnerIds[$index]} is not a member of {$group->name}."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assigned(AssignResult $result, bool $namesLearners): array
    {
        $assignment = $result->assignment;
        $title = $assignment->contentItem->title;
        $group = $assignment->group->name;
        $due = self::dueAtOf($assignment);

        $message = match (true) {
            $result->result === AssignOutcome::Created && $assignment->scope === AssignmentScope::Group => "Assigned {$title} to {$group}, due {$due}, for all ".self::learners($result->added->count()).'.',
            $result->result === AssignOutcome::Created => "Assigned {$title} to {$group}, due {$due}, for ".self::named($result->added).'.',
            $result->result === AssignOutcome::Unchanged => "{$title} is already assigned to {$group}, due {$due}, and ".($namesLearners ? 'every learner you named already has it' : 'the whole group already has it').'. Nothing changed.',
            $namesLearners => "{$title} was already assigned in {$group}, due {$due}. Added ".self::named($result->added).'.'.self::alreadyHadIt($result->alreadyHadIt),
            default => "{$title} was already assigned in {$group} to ".self::learners($result->alreadyHadIt->count()).", due {$due}. Now assigned to the whole group, including learners who join later. ".self::addedToTheGroup($result->added),
        };

        return self::reply($result->result->value, $message, $assignment, $result->added, $result->alreadyHadIt);
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyAssigned(Assignment $existing, string $dueDate): array
    {
        $message = "{$existing->contentItem->title} is already assigned to {$existing->group->name}, due ".self::dueAtOf($existing).", not {$dueDate}. Nothing changed. To move it, call change_due_date with assignment_id {$existing->id}.";

        return self::reply('already_assigned', $message, $existing);
    }

    /**
     * @param  Collection<int, ContentItem>  $matches
     * @return array<string, mixed>
     */
    private function candidates(Collection $matches, string $title): array
    {
        $list = $matches->map(fn (ContentItem $item) => "{$item->title} ({$item->type->value}, {$item->topic})")->implode(', ');
        $message = "{$matches->count()} content items match \"{$title}\": {$list}. Ask which one is meant, then call again with content_item_id.";

        return self::reply('candidates', $message, candidates: $matches->map(fn (ContentItem $item) => [
            'id' => $item->id,
            'title' => $item->title,
            'topic' => $item->topic,
            'type' => $item->type->value,
        ])->all());
    }

    /**
     * @param  BaseCollection<int, User>  $added
     * @param  BaseCollection<int, User>  $alreadyHadIt
     * @param  list<array{id: int, title: string, topic: string, type: string}>  $candidates
     * @return array<string, mixed>
     */
    private static function reply(string $result, string $message, ?Assignment $assignment = null, BaseCollection $added = new BaseCollection, BaseCollection $alreadyHadIt = new BaseCollection, array $candidates = []): array
    {
        return [
            'changed' => in_array($result, [AssignOutcome::Created->value, AssignOutcome::LearnersAdded->value], true),
            'result' => $result,
            'assignment' => $assignment === null ? null : new AssignmentResource($assignment),
            'added' => LearnerResource::collection($added),
            'already_had_it' => LearnerResource::collection($alreadyHadIt),
            'candidates' => $candidates,
            'message' => $message,
        ];
    }

    private static function dueAtOf(Assignment $assignment): string
    {
        return (new InstitutionTime($assignment->group->institution->timezone))->format($assignment->due_at);
    }

    private static function learners(int $count): string
    {
        return $count.' '.Str::plural('learner', $count);
    }

    /**
     * @param  BaseCollection<int, User>  $learners
     */
    private static function named(BaseCollection $learners): string
    {
        return self::learners($learners->count()).': '.$learners->pluck('name')->implode(', ');
    }

    /**
     * @param  BaseCollection<int, User>  $alreadyHadIt
     */
    private static function alreadyHadIt(BaseCollection $alreadyHadIt): string
    {
        if ($alreadyHadIt->isEmpty()) {
            return '';
        }

        return ' '.self::learners($alreadyHadIt->count()).' already had it: '.$alreadyHadIt->pluck('name')->implode(', ').'.';
    }

    /**
     * @param  BaseCollection<int, User>  $added
     */
    private static function addedToTheGroup(BaseCollection $added): string
    {
        return match (true) {
            $added->isEmpty() => 'Every current learner already had it.',
            $added->count() <= self::NAMES_LISTED_UP_TO => 'Added '.self::named($added).'.',
            default => 'Added '.self::learners($added->count()).'.',
        };
    }
}
