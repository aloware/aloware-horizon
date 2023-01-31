<?php

namespace Laravel\Horizon;

use ArrayAccess;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Arr;

class JobPayload implements ArrayAccess
{
    /**
     * The job object.
     *
     * @var object
     */
    public $job;

    /**
     * The raw payload string.
     *
     * @var string
     */
    public $value;

    /**
     * The decoded payload array.
     *
     * @var array
     */
    public $decoded;

    /**
     * Create a new raw job payload instance.
     *
     * @param  string  $value
     * @return void
     */
    public function __construct($value)
    {
        $this->value = $value;

        $this->decoded = json_decode($value, true);

        $this->job = unserialize($this->command());
    }

    /**
     * Get the job ID from the payload.
     *
     * @return string
     */
    public function id()
    {
        $job_id = $this->decoded['uuid'] ?? $this->decoded['id'];

        $fair_signal_prefix = config('fair-queue.signal_key_prefix_for_horizon');

        if($fair_signal_prefix && $this->job && $this->isFairSignal($this->job)) {
            $this->addFairSignalsToList($job_id, $fair_signal_prefix);
        }
        return $job_id;
    }

    /**
     * Get the job tags from the payload.
     *
     * @return array
     */
    public function tags()
    {
        return Arr::get($this->decoded, 'tags', []);
    }

    /**
     * Check the job type is fair-signal.
     *
     * @return boolean
     */
    public function isFairSignal()
    {
        return $this->job instanceof \Aloware\FairQueue\FairSignalJob;
    }

    /**
     * Add fair-signals to a redis list.
     *
     * @param string $job_id
     * @param string $fair_signal_prefix
     * @return void
     */
    public function addFairSignalsToList(string $job_id, string $fair_signal_prefix)
    {
        $redis = $this->getSignalsConnection();

        $queue = $this->job->queue;
        $partition = $this->job->partition;
        $list_key = "{$fair_signal_prefix}{$queue}:{$partition}";

        $redis->lpush($list_key, $job_id);
    }

    /**
     * Determine if the job is a retry of a previous job.
     *
     * @return bool
     */
    public function isRetry()
    {
        return isset($this->decoded['retry_of']);
    }

    /**
     * Get the ID of the job this job is a retry of.
     *
     * @return string|null
     */
    public function retryOf()
    {
        return $this->decoded['retry_of'] ?? null;
    }

    /**
     * Prepare the payload for storage on the queue by adding tags, etc.
     *
     * @param  mixed  $job
     * @return $this
     */
    public function prepare($job)
    {
        return $this->set([
            'type' => $this->determineType($job),
            'tags' => $this->determineTags($job),
            'pushedAt' => str_replace(',', '.', microtime(true)),
        ]);
    }

    /**
     * Get the "type" of job being queued.
     *
     * @param  mixed  $job
     * @return string
     */
    protected function determineType($job)
    {
        switch (true) {
            case $job instanceof BroadcastEvent:
                return 'broadcast';
            case $job instanceof CallQueuedListener:
                return 'event';
            case $job instanceof SendQueuedMailable:
                return 'mail';
            case $job instanceof SendQueuedNotifications:
                return 'notification';
            default:
                return 'job';
        }
    }

    /**
     * Get the appropriate tags for the job.
     *
     * @param  mixed  $job
     * @return array
     */
    protected function determineTags($job)
    {
        return array_merge(
            $this->decoded['tags'] ?? [],
            ! $job || is_string($job) ? [] : Tags::for($job)
        );
    }

    /**
     * Set the given key / value pairs on the payload.
     *
     * @param  array  $values
     * @return $this
     */
    public function set(array $values)
    {
        $this->decoded = array_merge($this->decoded, $values);

        $this->value = json_encode($this->decoded);

        return $this;
    }

    /**
     * Get the "command name" for the job.
     *
     * @return string
     */
    public function commandName()
    {
        return Arr::get($this->decoded, 'data.commandName');
    }

    /**
     * Get the "command" for the job.
     *
     * @return string
     */
    public function command()
    {
        return Arr::get($this->decoded, 'data.command');
    }

    /**
     * Get the "display name" for the job.
     *
     * @return string
     */
    public function displayName()
    {
        return Arr::get($this->decoded, 'displayName');
    }

    /**
     * Determine if the given offset exists.
     *
     * @param  string  $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->decoded);
    }

    /**
     * Get the value at the current offset.
     *
     * @param  string  $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->decoded[$offset];
    }

    /**
     * Set the value at the current offset.
     *
     * @param  string  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        $this->decoded[$offset] = $value;
    }

    /**
     * Unset the value at the current offset.
     *
     * @param  string  $offset
     * @return void
     */
    public function offsetUnset($offset): void
    {
        unset($this->decoded[$offset]);
    }

    /**
     * Returns Redis Fair Signal Connection
     *
     * @return \Illuminate\Redis\Connections\Connection
    */
    public function getSignalsConnection()
    {
        $database = config('fair-queue.signals_database');
        return Redis::connection($database);
    }
}
