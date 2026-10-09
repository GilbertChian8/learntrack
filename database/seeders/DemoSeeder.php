<?php

namespace Database\Seeders;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use App\Enums\ProgressStatus;
use App\Enums\Role;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Progress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The demo data: two institutions, their educators, learners and groups,
 * assignments due between 30 days ago and 30 days ahead, and progress that
 * leaves every group with learners behind by overdue work, learners behind
 * by a low average, and learners on track. Runs after ContentSeeder.
 *
 * Every password is "password". Fixed accounts:
 *
 * - anna.keller@example.edu: educator at Northside Medical School (Europe/Berlin),
 *   teaches Cardiology Group A, Nephrology Group A and Neurology Group A.
 * - sara.lindqvist@example.edu: learner at Northside Medical School, in Cardiology Group A.
 * - emily.carter@example.edu: learner at Riverside College of Medicine (Europe/London),
 *   in Endocrinology Group A.
 *
 * The other educators are firstname.lastname@example.edu, as listed below.
 * Faker is seeded with 42, so every machine gets the same names, groups and
 * progress. Due dates are whole days relative to the day the seed runs (each
 * one 23:59:59 in the institution's time zone), so the demo always has
 * overdue and upcoming work.
 */
class DemoSeeder extends Seeder
{
    private const LEARNERS_PER_INSTITUTION = 150;

    /**
     * Groups name their educators by index into the institution's educators.
     *
     * @var list<array{name: string, timezone: string, educators: list<string>, learner: string, groups: list<array{name: string, topic: string, educators: list<int>}>}>
     */
    private const INSTITUTIONS = [
        [
            'name' => 'Northside Medical School',
            'timezone' => 'Europe/Berlin',
            'educators' => ['Anna Keller', 'Jonas Weber', 'Miriam Hoffmann'],
            'learner' => 'Sara Lindqvist',
            'groups' => [
                ['name' => 'Cardiology Group A', 'topic' => 'Cardiology', 'educators' => [0, 1]],
                ['name' => 'Cardiology Group B', 'topic' => 'Cardiology', 'educators' => [1]],
                ['name' => 'Pulmonology Group A', 'topic' => 'Pulmonology', 'educators' => [2]],
                ['name' => 'Nephrology Group A', 'topic' => 'Nephrology', 'educators' => [0]],
                ['name' => 'Neurology Group A', 'topic' => 'Neurology', 'educators' => [2, 0]],
                ['name' => 'Pediatrics Group A', 'topic' => 'Pediatrics', 'educators' => [1]],
            ],
        ],
        [
            'name' => 'Riverside College of Medicine',
            'timezone' => 'Europe/London',
            'educators' => ['Oliver Bennett', 'Priya Shah', 'Hannah Clarke'],
            'learner' => 'Emily Carter',
            'groups' => [
                ['name' => 'Endocrinology Group A', 'topic' => 'Endocrinology', 'educators' => [0]],
                ['name' => 'Cardiology Group C', 'topic' => 'Cardiology', 'educators' => [1, 2]],
                ['name' => 'Pulmonology Group B', 'topic' => 'Pulmonology', 'educators' => [2]],
                ['name' => 'Nephrology Group B', 'topic' => 'Nephrology', 'educators' => [0, 1]],
                ['name' => 'Neurology Group B', 'topic' => 'Neurology', 'educators' => [1]],
                ['name' => 'Pediatrics Group B', 'topic' => 'Pediatrics', 'educators' => [2]],
            ],
        ],
    ];

    private CarbonImmutable $now;

    private string $password;

    /** @var array<string, true> */
    private array $emails = [];

    /** @var list<array<string, mixed>> */
    private array $progress = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        fake()->seed(42);

        $this->now = now()->startOfSecond();
        $this->password = Hash::make('password');
        $this->emails = [];
        $this->progress = [];

        $content = ContentItem::query()->orderBy('id')->get();

        foreach (self::INSTITUTIONS as $spec) {
            $institution = Institution::query()->forceCreate([
                'name' => $spec['name'],
                'timezone' => $spec['timezone'],
                'created_at' => $this->termStart(),
                'updated_at' => $this->termStart(),
            ]);

            $educators = array_map(fn (string $name) => $this->createUser($institution, $name, Role::Educator), $spec['educators']);
            $demoLearner = $this->createUser($institution, $spec['learner'], Role::Learner);
            $learners = [$demoLearner->id];

            for ($i = 1; $i < self::LEARNERS_PER_INSTITUTION; $i++) {
                $learners[] = $this->createUser($institution, fake()->firstName().' '.fake()->lastName(), Role::Learner)->id;
            }

            foreach ($spec['groups'] as $index => $groupSpec) {
                $this->seedGroup(
                    $institution,
                    $groupSpec,
                    array_map(fn (int $educator) => $educators[$educator]->id, $groupSpec['educators']),
                    $learners,
                    $index === 0 ? $demoLearner->id : null,
                    $content,
                );
            }
        }

        foreach (array_chunk($this->progress, 500) as $rows) {
            Progress::query()->insert($rows);
        }
    }

    private function termStart(): CarbonImmutable
    {
        return $this->now->subDays(60);
    }

    private function createUser(Institution $institution, string $name, Role $role): User
    {
        $local = Str::slug(Str::ascii($name), '.');
        $email = "{$local}@example.edu";

        for ($suffix = 2; isset($this->emails[$email]); $suffix++) {
            $email = "{$local}{$suffix}@example.edu";
        }

        $this->emails[$email] = true;

        return User::query()->forceCreate([
            'institution_id' => $institution->id,
            'name' => $name,
            'email' => $email,
            'password' => $this->password,
            'role' => $role,
            'created_at' => $this->termStart(),
            'updated_at' => $this->termStart(),
        ]);
    }

    /**
     * One group: its educators, 20 to 60 members, 5 to 12 assignments (the
     * last two of them subset assignments) and the members' progress.
     *
     * The first three members (in a shuffled order) are one learner behind by
     * overdue work, one behind by a low average and one on track, so every
     * group has all three; the rest are drawn at random.
     *
     * @param  array{name: string, topic: string, educators: list<int>}  $spec
     * @param  list<int>  $educatorIds
     * @param  list<int>  $learnerIds
     * @param  Collection<int, ContentItem>  $content
     */
    private function seedGroup(Institution $institution, array $spec, array $educatorIds, array $learnerIds, ?int $demoLearnerId, Collection $content): void
    {
        $group = Group::query()->forceCreate([
            'institution_id' => $institution->id,
            'name' => $spec['name'],
            'created_at' => $this->termStart(),
            'updated_at' => $this->termStart(),
        ]);

        $group->educators()->attach($educatorIds, ['created_at' => $this->termStart()]);

        /** @var list<int> $members */
        $members = fake()->randomElements($learnerIds, fake()->numberBetween(20, 60));

        if ($demoLearnerId !== null && ! in_array($demoLearnerId, $members, true)) {
            $members[0] = $demoLearnerId;
        }

        $group->learners()->attach($members, ['created_at' => $this->termStart()]);

        $standing = [];

        foreach (fake()->shuffle($members) as $position => $member) {
            $standing[$member] = match ($position) {
                0 => 'overdue',
                1 => 'low_score',
                2 => 'on_track',
                default => $this->randomStanding(),
            };
        }

        $assignments = $this->seedAssignments($group, $institution->timezone, $spec['topic'], $educatorIds, $members, $content);

        foreach ($assignments as $slot => $assignment) {
            foreach ($assignment['targets'] as $learnerId) {
                $this->addProgress($assignment, $learnerId, $standing[$learnerId], $slot === 0);
            }
        }
    }

    private function randomStanding(): string
    {
        $draw = fake()->numberBetween(1, 100);

        return match (true) {
            $draw <= 55 => 'on_track',
            $draw <= 75 => 'overdue',
            $draw <= 90 => 'low_score',
            default => 'overdue_and_low_score',
        };
    }

    /**
     * Slot 0 is a group-wide question set of the group's topic, due in the
     * past, so every member has an overdue candidate and a scored item. Slot 1
     * is due in the future. The last two slots target a few members only.
     *
     * @param  list<int>  $educatorIds
     * @param  list<int>  $members
     * @param  Collection<int, ContentItem>  $content
     * @return list<array{id: int, type: ContentType, due_at: CarbonImmutable, created_at: CarbonImmutable, targets: list<int>}>
     */
    private function seedAssignments(Group $group, string $timezone, string $topic, array $educatorIds, array $members, Collection $content): array
    {
        $count = fake()->numberBetween(5, 12);
        $ofTopic = $content->where('topic', $topic);
        $first = fake()->randomElement($ofTopic->where('type', ContentType::QuestionSet)->all());

        $picked = array_merge(
            [$first],
            fake()->shuffle(array_values($ofTopic->reject(fn (ContentItem $item) => $item->is($first))->all())),
            fake()->shuffle(array_values($content->where('topic', '!=', $topic)->all())),
        );

        $today = $this->now->setTimezone($timezone)->startOfDay();
        $assignments = [];

        foreach (array_slice($picked, 0, $count) as $slot => $item) {
            $days = match ($slot) {
                0 => fake()->numberBetween(-30, -3),
                1 => fake()->numberBetween(3, 30),
                default => fake()->numberBetween(-30, 30),
            };
            $dueAt = $today->addDays($days)->setTime(23, 59, 59)->utc();
            $createdAt = $dueAt->subDays(21)->min($this->now->subDay());
            $isSubset = $slot >= $count - 2;
            $targets = $isSubset ? fake()->randomElements($members, fake()->numberBetween(3, 8)) : $members;

            $assignment = Assignment::query()->forceCreate([
                'group_id' => $group->id,
                'content_item_id' => $item->id,
                'due_at' => $dueAt,
                'scope' => $isSubset ? AssignmentScope::Learners : AssignmentScope::Group,
                'created_by' => fake()->randomElement($educatorIds),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($isSubset) {
                $assignment->learners()->attach($targets, ['created_at' => $createdAt]);
            }

            $assignments[] = [
                'id' => $assignment->id,
                'type' => $item->type,
                'due_at' => $dueAt,
                'created_at' => $createdAt,
                'targets' => $targets,
            ];
        }

        return $assignments;
    }

    /**
     * Learners on track, or behind by score only, complete everything that is
     * past due. Learners behind by overdue work leave slot 0 open and half of
     * the rest. Future work is done, started or untouched at random.
     *
     * @param  array{id: int, type: ContentType, due_at: CarbonImmutable, created_at: CarbonImmutable, targets: list<int>}  $assignment
     */
    private function addProgress(array $assignment, int $learnerId, string $standing, bool $isOverdueCandidate): void
    {
        $leavesOverdueWork = in_array($standing, ['overdue', 'overdue_and_low_score'], true);
        $scoresLow = in_array($standing, ['low_score', 'overdue_and_low_score'], true);

        if ($assignment['due_at']->lessThan($this->now)) {
            $status = match (true) {
                ! $leavesOverdueWork => ProgressStatus::Completed,
                $isOverdueCandidate => fake()->boolean() ? ProgressStatus::InProgress : null,
                default => fake()->randomElement([ProgressStatus::Completed, ProgressStatus::InProgress, null]),
            };
        } else {
            $draw = fake()->numberBetween(1, 100);
            $status = $draw <= 40 ? ProgressStatus::Completed : ($draw <= 65 ? ProgressStatus::InProgress : null);
        }

        if ($status === null) {
            return;
        }

        $windowEnd = $assignment['due_at']->min($this->now);
        $span = (int) $assignment['created_at']->diffInSeconds($windowEnd);
        $startedAt = $assignment['created_at']->addSeconds(fake()->numberBetween(0, intdiv($span, 2)));
        $completedAt = $status === ProgressStatus::Completed
            ? $startedAt->addSeconds(fake()->numberBetween(3600, (int) $startedAt->diffInSeconds($windowEnd)))
            : null;
        $score = $status === ProgressStatus::Completed && $assignment['type'] === ContentType::QuestionSet
            ? ($scoresLow ? fake()->numberBetween(20, 45) : fake()->numberBetween(55, 98))
            : null;

        $this->progress[] = [
            'assignment_id' => $assignment['id'],
            'user_id' => $learnerId,
            'status' => $status->value,
            'score' => $score,
            'started_at' => $startedAt->toDateTimeString(),
            'completed_at' => $completedAt?->toDateTimeString(),
            'created_at' => $startedAt->toDateTimeString(),
            'updated_at' => ($completedAt ?? $startedAt)->toDateTimeString(),
        ];
    }
}
