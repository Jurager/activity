<?php

namespace Jurager\Activity;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Eloquent\Model;
use Jurager\Activity\Models\Activity;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Contracts\Config\Repository;
use Jurager\Activity\Exceptions\CouldNotLogActivity;

class ActivityLogger
{
    use Macroable;

    /** @var \Illuminate\Auth\AuthManager */
    protected $auth;

    protected $logName = '';

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $performedOn;

	/** @var \Illuminate\Database\Eloquent\Model */
	protected $causedBy;

	/** @var \Illuminate\Database\Eloquent\Model */
	protected $entityFor;

    /** @var \Illuminate\Support\Collection */
    protected $properties;

    /** @var string */
    protected $authDriver;

    /** @var \Jurager\Activity\ActivityLogStatus */
    protected $logStatus;

    public function __construct(AuthManager $auth, Repository $config, ActivityLogStatus $logStatus)
    {
        $this->auth = $auth;

        $this->properties = collect();

        $this->authDriver = $config['activitylog']['default_auth_driver'] ?? $auth->getDefaultDriver();

        if (starts_with(app()->version(), '5.1')) {
            $this->causedBy = $auth->driver($this->authDriver)->user();
        } else {
            $this->causedBy = $auth->guard($this->authDriver)->user();
        }

        $this->logName = $config['activitylog']['default_log_name'];

        $this->logEnabled = $config['activitylog']['enabled'] ?? true;

        $this->logStatus = $logStatus;
    }

    public function setLogStatus(ActivityLogStatus $logStatus)
    {
        $this->logStatus = $logStatus;

        return $this;
    }

	public function performedOn(Model $model)
	{
		$this->performedOn = $model;

		return $this;
	}

	public function entityFor(Model $model)
	{
		$this->entityFor = $model;

		return $this;
	}

    public function for(Model $model)
    {
        return $this->entityFor($model);
    }

    public function on(Model $model)
    {
        return $this->performedOn($model);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model|int|string $modelOrId
     *
     * @return $this
     */
    public function causedBy($modelOrId)
    {
        if ($modelOrId === null) {
            return $this;
        }

        $model = $this->normalizeCauser($modelOrId);

        $this->causedBy = $model;

        return $this;
    }

    public function by($modelOrId)
    {
        return $this->causedBy($modelOrId);
    }

    /**
     * @param array|\Illuminate\Support\Collection $properties
     *
     * @return $this
     */
    public function withProperties($properties)
    {
        $this->properties = collect($properties);

        return $this;
    }

    public function with($properties)
    {
        return $this->withProperties($properties);
    }

    /**
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function withProperty(string $key, $value)
    {
        $this->properties->put($key, $value);

        return $this;
    }

    public function useLog(string $logName)
    {
        $this->logName = $logName;

        return $this;
    }

    public function inLog(string $logName)
    {
        return $this->useLog($logName);
    }

    public function enableLogging()
    {
        $this->logStatus->enable();

        return $this;
    }

    public function disableLogging()
    {
        $this->logStatus->disable();

        return $this;
    }

    /**
     * @param string $description
     *
     * @return null|mixed
     */
    public function log(string $description)
    {
        if ($this->logStatus->disabled()) {
            return;
        }

        $activity = ActivitylogServiceProvider::getActivityModelInstance();

        if ($this->performedOn) {
            $activity->subject()->associate($this->performedOn);
        }

		if ($this->causedBy) {
			$activity->causer()->associate($this->causedBy);
		}

		if ($this->entityFor) {
			$activity->entity()->associate($this->entityFor);
		}

        $activity->properties = $this->properties;

        $activity->description = $this->replacePlaceholders($description, $activity);

        $activity->log_name = $this->logName;

        $activity->save();

        return $activity;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model|int|string $modelOrId
     *
     * @throws \Jurager\Activity\Exceptions\CouldNotLogActivity
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function normalizeCauser($modelOrId): Model
    {
        if ($modelOrId instanceof Model) {
            return $modelOrId;
        }

        if (starts_with(app()->version(), '5.1')) {
            $model = $this->auth->driver($this->authDriver)->getProvider()->retrieveById($modelOrId);
        } else {
            $model = $this->auth->guard($this->authDriver)->getProvider()->retrieveById($modelOrId);
        }

        if ($model) {
            return $model;
        }

        throw CouldNotLogActivity::couldNotDetermineUser($modelOrId);
    }

    protected function replacePlaceholders(string $description, Activity $activity): string
    {
        return preg_replace_callback('/:[a-z0-9._-]+/i', function ($match) use ($activity) {
            $match = $match[0];

            $attribute = (string) string($match)->between(':', '.');

            if (! in_array($attribute, ['subject', 'causer', 'properties'])) {
                return $match;
            }

            $propertyName = substr($match, strpos($match, '.') + 1);

            $attributeValue = $activity->$attribute;

            if (is_null($attributeValue)) {
                return $match;
            }

            $attributeValue = $attributeValue->toArray();

            return array_get($attributeValue, $propertyName, $match);
        }, $description);
    }
}
