<?php

namespace App\Providers;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use App\GraphQL\BackedEnumType;
use App\GraphQL\Scalars\DateTime as DateTimeScalar;
use App\Models\Assignment;
use App\Models\Group;
use App\Support\Scope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureUrls();
        $this->configurePassport();
        $this->configureRouteBindings();
        $this->configureRateLimiting();
        $this->configureGraphQL();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Model::shouldBeStrict(! app()->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }

    /**
     * Generated URLs start from APP_URL, so a forged Host header cannot change them.
     * The scheme still comes from the request, through TrustProxies behind the ALB.
     */
    protected function configureUrls(): void
    {
        URL::forceRootUrl(Config::string('app.url'));
    }

    /**
     * Token lifetimes, and the consent page. Authorization codes keep Passport's fixed 10 minutes.
     */
    protected function configurePassport(): void
    {
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::hours(8));

        Passport::authorizationView(fn (array $parameters): Response => Inertia::render('mcp/consent', [
            'clientName' => $parameters['client']->name,
            'userName' => $parameters['user']->name,
            'capabilities' => ['Read the progress of your groups', 'Assign content', 'Change due dates'],
            'authToken' => $parameters['authToken'],
            'state' => $parameters['request']->query('state'),
            'csrfToken' => csrf_token(),
        ])->toResponse($parameters['request']));
    }

    /**
     * A {group} or an assignment loads only through the scoped lookup, so one outside scope is 404.
     * {removableAssignment} also finds removed assignments, so a repeated removal answers 204.
     * {learnerAssignment} finds the active assignments that target the learner.
     */
    protected function configureRouteBindings(): void
    {
        Route::pattern('group', '[0-9]+');
        Route::bind('group', fn (string $id): Group => Scope::group(request()->user(), (int) $id));

        Route::pattern('assignment', '[0-9]+');
        Route::bind('assignment', fn (string $id): Assignment => Scope::assignment(request()->user(), (int) $id));

        Route::pattern('removableAssignment', '[0-9]+');
        Route::bind('removableAssignment', fn (string $id): Assignment => Scope::removableAssignment(request()->user(), (int) $id));

        Route::pattern('learnerAssignment', '[0-9]+');
        Route::bind('learnerAssignment', fn (string $id): Assignment => Scope::learnerAssignment(request()->user(), (int) $id));
    }

    /**
     * The limits of the TDD's rate limit table. The failed login limits live in LoginRequest.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('oauth-token', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('oauth-register', fn (Request $request): Limit => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->getAuthIdentifier()));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(300)->by($request->user()?->getAuthIdentifier()));
    }

    /**
     * The schema's DateTime scalar and enums are bound to PHP types here,
     * so graphql/schema.graphql stays the TDD schema as written.
     */
    protected function configureGraphQL(): void
    {
        $this->callAfterResolving(TypeRegistry::class, function (TypeRegistry $typeRegistry): void {
            $typeRegistry->register(new DateTimeScalar);
            $typeRegistry->overwrite(new BackedEnumType('Status', DerivedStatus::class));
            $typeRegistry->overwrite(new BackedEnumType('ContentType', ContentType::class));
            $typeRegistry->overwrite(new BackedEnumType('Scope', AssignmentScope::class));
        });
    }
}
