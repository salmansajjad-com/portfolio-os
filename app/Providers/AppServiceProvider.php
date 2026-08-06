<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Credential;
use App\Models\Link;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Policies\ArticlePolicy;
use App\Policies\CredentialPolicy;
use App\Policies\LinkPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaskTemplatePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Hostinger / Cloudflare terminate TLS; force HTTPS URLs in production.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Lazy loading is how list screens quietly grow a query per row. Fail
        // loudly in development instead of shipping it, but never break a live
        // page over it.
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Credential::class, CredentialPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Link::class, LinkPolicy::class);
        Gate::policy(TaskTemplate::class, TaskTemplatePolicy::class);
    }
}
