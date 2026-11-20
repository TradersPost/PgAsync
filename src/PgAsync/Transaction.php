<?php

namespace PgAsync;

use Rx\Observable;
use Rx\Observer\CallbackObserver;

final class Transaction
{
    /** @var Connection */
    private $connection;

    /** @var callable */
    private $release;

    /** @var bool */
    private $finishing = false;

    /** @var bool */
    private $finished = false;

    /**
     * @param callable $release
     */
    public function __construct(Connection $connection, callable $release)
    {
        $this->connection = $connection;
        $this->release    = $release;
    }

    public function query(string $queryString): Observable
    {
        if ($this->finished) {
            return $this->inactiveError();
        }

        return $this->connection->query($queryString);
    }

    /**
     * @see Connection::executeCommand()
     *
     * @return Observable<int>
     */
    public function executeCommand(string $queryString, array $parameters = []): Observable
    {
        if ($this->finished) {
            return $this->inactiveError();
        }

        return $this->connection->executeCommand($queryString, $parameters);
    }

    public function executeStatement(string $queryString, array $parameters = []): Observable
    {
        if ($this->finished) {
            return $this->inactiveError();
        }

        return $this->connection->executeStatement($queryString, $parameters);
    }

    /**
     * What the server last said about the transaction: 'T' while it is open, 'E' after a statement in it has failed (it can
     * then only be rolled back, and COMMIT rolls it back silently), 'I' once it has ended.
     */
    public function getBackendTransactionStatus(): string
    {
        return $this->connection->getBackendTransactionStatus();
    }

    /**
     * Emits the backend transaction status once the connection has answered everything sent to it. Right after a statement
     * failed the error arrives before the ReadyForQuery that carries the new status, so
     * {@see Transaction::getBackendTransactionStatus()} may still say 'T' for a moment.
     *
     * @return Observable<string>
     */
    public function whenReady(): Observable
    {
        if ($this->finished) {
            return $this->inactiveError();
        }

        return $this->connection->whenReadyForQuery();
    }

    public function commit(): Observable
    {
        if ($this->finishing) {
            return $this->inactiveError();
        }

        $this->finishing = true;

        return $this->finishWithStatus('COMMIT', [$this, 'validateCommitStatus']);
    }

    public function rollback(): Observable
    {
        if ($this->finishing) {
            return $this->inactiveError();
        }

        $this->finishing = true;

        return $this->finishWithStatus('ROLLBACK', [$this, 'validateRollbackStatus']);
    }

    private function finishWithStatus(string $sql, callable $validate): Observable
    {
        return $this->connection->queryUntilReady($sql)->flatMap(function ($status) use ($validate) {
            $error = $validate($status);
            if ($error !== null) {
                return $this->abandon()->concat(Observable::error($error));
            }

            $this->markFinished();

            return Observable::of($status);
        })->catch(function (\Throwable $e) {
            if ($this->finished) {
                return Observable::error($e);
            }

            // COMMIT/ROLLBACK itself failed (a deferred constraint, a lost connection): do not keep the connection
            // reserved forever.
            return $this->abandon()->concat(Observable::error($e));
        })->finally(function () {
            // The subscriber went away before the server answered (a cancelled request): the connection is still
            // reserved, and the server may still have the transaction open.
            if (!$this->finished) {
                $this->abandon()->subscribe(new CallbackObserver(null, function () {
                }, function () {
                }));
            }
        });
    }

    /**
     * Drops the connection of a transaction whose backend no longer answers (a statement timed out and the server is
     * unreachable): ROLLBACK cannot be sent to it, so the connection is closed and the pool forgets it. Does nothing once
     * the transaction has ended.
     */
    public function discard()
    {
        if ($this->finished) {
            return;
        }

        $this->finishing = true;
        $this->finished  = true;
        $this->connection->kill();
        ($this->release)();
    }

    /**
     * @return \Throwable|null
     */
    private function validateCommitStatus(string $status)
    {
        if ($status === 'E') {
            return new \RuntimeException('Cannot commit: transaction is in failed state');
        }

        if ($status !== 'I') {
            return new \RuntimeException('Expected idle status after COMMIT');
        }

        return null;
    }

    /**
     * @return \Throwable|null
     */
    private function validateRollbackStatus(string $status)
    {
        if ($status !== 'I') {
            return new \RuntimeException('Expected idle status after ROLLBACK');
        }

        return null;
    }

    /**
     * Gives the connection back after a failed COMMIT/ROLLBACK, rolling back first when the server still has the
     * transaction open so the next user of the connection does not inherit it.
     */
    private function abandon(): Observable
    {
        if ($this->finished) {
            return Observable::empty();
        }

        $this->finished = true;

        $release = function () {
            ($this->release)();
        };

        if ($this->connection->getBackendTransactionStatus() === 'I') {
            $release();

            return Observable::empty();
        }

        return $this->connection->queryUntilReady('ROLLBACK')
            ->catch(function () {
                return Observable::empty();
            })
            ->finally($release)
            ->filter(function () {
                return false;
            });
    }

    private function inactiveError(): Observable
    {
        return Observable::error(new \RuntimeException('Transaction has already been committed or rolled back'));
    }

    private function markFinished()
    {
        $this->finished = true;
        ($this->release)();
    }
}
