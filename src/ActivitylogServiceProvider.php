<?php

namespace Jurager\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Jurager\Activity\Models\Activity;
use Jurager\Activity\Exceptions\InvalidConfiguration;

class ActivitylogServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/activity.php' => config_path('activity.php'),
        ], 'config');

        $this->mergeConfigFrom(__DIR__.'/../config/activity.php', 'activity');

        if (! class_exists('CreateActivityTable')) {
            $timestamp = date('Y_m_d_His', time());

            $this->publishes([
                __DIR__.'/../migrations/create_activity_table.php.stub' => database_path("/migrations/{$timestamp}_create_activity_table.php"),
            ], 'migrations');
        }
    }

    public function register()
    {
        $this->app->bind('command.activity:clean', CleanActivitylogCommand::class);

        $this->commands([
            'command.activity:clean',
        ]);

        $this->app->bind(ActivityLogger::class);

        $this->app->singleton(ActivityLogStatus::class);
    }

    public static function determineActivityModel(): string
    {
        $activityModel = config('activity.activity_model') ?? Activity::class;

        if (! is_a($activityModel, Activity::class, true)) {
            throw InvalidConfiguration::modelIsNotValid($activityModel);
        }

        return $activityModel;
    }

    public static function getActivityModelInstance(): Model
    {
        $activityModelClassName = self::determineActivityModel();

        return new $activityModelClassName();
    }
}
