<?php

namespace App\Livewire\Project\Database\Redis;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\StandaloneRedis;
use Exception;
use Livewire\Component;

class General extends Component
{
    protected $listeners = ['refresh'];

    public Server $server;

    public StandaloneRedis $database;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    protected $rules = [
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.redis_conf' => 'nullable',
        'database.redis_username' => 'required',
        'database.redis_password' => 'required',
        'database.image' => 'required',
        'database.ports_mappings' => 'nullable',
        'database.is_public' => 'nullable|boolean',
        'database.public_port' => 'nullable|integer',
        'database.is_log_drain_enabled' => 'nullable|boolean',
        'database.custom_docker_run_options' => 'nullable',
    ];

    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.redis_conf' => 'Redis Configuration',
        'database.redis_username' => 'Redis Username',
        'database.redis_password' => 'Redis Password',
        'database.image' => 'Image',
        'database.ports_mappings' => 'Port Mapping',
        'database.is_public' => 'Is Public',
        'database.public_port' => 'Public Port',
        'database.custom_docker_run_options' => 'Custom Docker Options',
    ];

    public function mount()
    {
        $this->db_url = $this->database->internal_db_url;
        $this->db_url_public = $this->database->external_db_url;
        $this->server = data_get($this->database, 'destination.server');

    }

    public function instantSaveAdvanced()
    {
        try {
            if (! $this->server->isLogDrainEnabled()) {
                $this->database->is_log_drain_enabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->database->save();
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->validate();

            $redis_version = $this->get_redis_version();

            if (version_compare($redis_version, '6.0', '>=') && $this->database->isDirty('redis_username')) {
                $this->updateEnvironmentVariable('REDIS_USERNAME', $this->database->redis_username);
            }

            if ($this->database->isDirty('redis_password')) {
                $this->updateEnvironmentVariable('REDIS_PASSWORD', $this->database->redis_password);
            }

            $this->database->save();

            $this->dispatch('success', 'Database updated.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    private function get_redis_version()
    {
        $image_parts = explode(':', $this->database->image);

        return $image_parts[1] ?? '0.0';
    }

    public function instantSave()
    {
        try {
            if ($this->database->is_public && ! $this->database->public_port) {
                $this->dispatch('error', 'Public port is required.');
                $this->database->is_public = false;

                return;
            }
            if ($this->database->is_public) {
                if (! str($this->database->status)->startsWith('running')) {
                    $this->dispatch('error', 'Database must be started to be publicly accessible.');
                    $this->database->is_public = false;

                    return;
                }
                StartDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
            $this->db_url_public = $this->database->external_db_url;
            $this->database->save();
        } catch (\Throwable $e) {
            $this->database->is_public = ! $this->database->is_public;

            return handleError($e, $this);
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
    }

    public function render()
    {
        return view('livewire.project.database.redis.general');
    }

    public function isSharedVariable($name)
    {
        return EnvironmentVariable::where('key', $name)
            ->where('standalone_redis_id', $this->database->id)
            ->where('is_shared', true)
            ->exists();
    }

    private function updateEnvironmentVariable($key, $value)
    {
        $envVar = $this->database->runtime_environment_variables()
            ->where('key', $key)
            ->first();

        if ($envVar) {
            if (! $envVar->is_shared) {
                $envVar->update(['value' => $value]);
            }
        } else {
            $this->database->runtime_environment_variables()->create([
                'key' => $key,
                'value' => $value,
                'is_shared' => false,
            ]);
        }
    }
}
