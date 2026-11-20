<?php

namespace PgAsync\Command;

use Rx\ObserverInterface;

trait CommandTrait
{
    /** @var ObserverInterface */
    protected $observer;

    protected $active = true;

    public function complete()
    {
        $wasActive    = $this->active;
        $this->active = false;
        if (!$this->observer instanceof ObserverInterface) {
            throw new \Exception('Observer not set on command.');
        }
        // A command that was cancelled has nobody to tell: its subscriber disposed of it.
        if ($wasActive) {
            $this->observer->onCompleted();
        }
    }

    public function error(\Throwable $exception)
    {
        $wasActive    = $this->active;
        $this->active = false;
        if (!$wasActive) {
            return;
        }
        // The commands of an extended query that only prepare it (Parse, Bind, ...) have no observer: failing them
        // must not throw, or the Sync command that carries the observer would never be failed.
        if (!$this->observer instanceof ObserverInterface) {
            return;
        }
        $this->observer->onError($exception);
    }

    public function next($value) {
        if (!$this->active) {
            return;
        }
        if (!$this->observer instanceof ObserverInterface) {
            throw new \Exception('Observer not set on command.');
        }
        $this->observer->onNext($value);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function cancel()
    {
        $this->active = false;
    }
}
