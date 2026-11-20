<?php

namespace PgAsync\Tests\Integration;

use PgAsync\Client;
use PgAsync\Connection;
use PgAsync\ErrorException;
use PgAsync\Transaction;
use Rx\Observable;
use Rx\Observer\CallbackObserver;

/**
 * What happens to the connections of the pool when requests are abandoned, sockets go away, and queries are odd.
 */
class ResilienceTest extends TestCase
{
    /** @var Client[] */
    private $clients = [];

    public function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->closeNow();
        }
        $this->clients = [];
        $this->pause(0.05);
        parent::tearDown();
    }

    private function client(array $parameters = [], $connector = null): Client
    {
        return $this->clients[] = self::clientFromEnv($parameters, $this->getLoop(), $connector);
    }

    private function pause(float $seconds)
    {
        $loop  = $this->getLoop();
        $timer = $loop->addTimer($seconds, function () use ($loop) {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }

    /**
     * Subscribes and collects what the observable did.
     */
    private function collect(Observable $observable): \stdClass
    {
        $result            = new \stdClass();
        $result->values    = [];
        $result->error     = null;
        $result->completed = false;
        $result->subscription = $observable->subscribe(new CallbackObserver(
            function ($value) use ($result) {
                $result->values[] = $value;
            },
            function ($error) use ($result) {
                $result->error = $error;
            },
            function () use ($result) {
                $result->completed = true;
            }
        ));

        return $result;
    }

    private function settle(\stdClass $result, float $seconds = 5.0)
    {
        $deadline = microtime(true) + $seconds;
        while (!$result->completed && $result->error === null && microtime(true) < $deadline) {
            $this->pause(0.02);
        }
    }

    private function warm(Client $client)
    {
        $result = $this->collect($client->query('SELECT 1'));
        $this->settle($result);
        $this->assertTrue($result->completed);
    }

    public function testACancelledBeginDoesNotLeakTheReservedConnection()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);

        // The pool has a ready connection, so BEGIN is on its way as soon as this subscribes.
        $client->transaction()->subscribe(new CallbackObserver())->dispose();
        $this->pause(0.3);

        $this->assertSame(0, $client->getReservedConnectionCount(), 'the connection reserved for the cancelled BEGIN was given back');
        $connection = $client->getIdleConnection();
        $this->assertNotNull($connection);
        $this->assertSame('I', $connection->getBackendTransactionStatus(), 'the transaction BEGIN opened was rolled back');

        $next = $this->collect($client->transaction());
        $this->settle($next);
        $this->assertCount(1, $next->values, 'a transaction can still be opened on the only connection');
        $next->values[0]->rollback()->subscribe(new CallbackObserver());
        $this->pause(0.1);
    }

    public function testABeginThatIsOnTheWireWhenTheSubscriberGoesAwayDoesNotLeakTheReservedConnection()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);

        $subscription = $client->transaction()->subscribe(new CallbackObserver());
        // Several turns of the loop: the connection reaches the chain and BEGIN is written, but the answer is not in yet.
        $dispose = function () use ($subscription) {
            $subscription->dispose();
        };
        $this->getLoop()->futureTick(function () use ($dispose) {
            $this->getLoop()->futureTick(function () use ($dispose) {
                $this->getLoop()->futureTick($dispose);
            });
        });
        $this->pause(0.3);

        $this->assertSame(0, $client->getReservedConnectionCount());

        // The cancel sent for BEGIN was not what ended it, so its connection may have been replaced: either way no transaction is left
        // open, which a new transaction on the only connection shows (it starts from idle).
        $next = $this->collect($client->transaction());
        $this->settle($next);
        $this->assertCount(1, $next->values, 'a transaction can still be opened on the only connection');
        $next->values[0]->rollback()->subscribe(new CallbackObserver());
        $this->pause(0.1);
    }

    public function testACancelledCommitDoesNotLeakTheReservedConnection()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $opened = $this->collect($client->transaction());
        $this->settle($opened);
        /** @var Transaction $transaction */
        $transaction = $opened->values[0];

        $transaction->commit()->subscribe(new CallbackObserver())->dispose();
        $this->pause(0.3);

        $this->assertSame(0, $client->getReservedConnectionCount());
        $this->assertSame('I', $client->getIdleConnection()->getBackendTransactionStatus());
    }

    public function testACancelledRollbackDoesNotLeakTheReservedConnection()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $opened = $this->collect($client->transaction());
        $this->settle($opened);
        /** @var Transaction $transaction */
        $transaction = $opened->values[0];

        $transaction->rollback()->subscribe(new CallbackObserver())->dispose();
        $this->pause(0.3);

        $this->assertSame(0, $client->getReservedConnectionCount());
        $this->assertSame('I', $client->getIdleConnection()->getBackendTransactionStatus());
    }

    public function testDisposingARunningQueryCancelsItOnTheServerAtOnce()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $connection = $client->getIdleConnection();

        $running = $connection->query('SELECT pg_sleep(30)')->subscribe(new CallbackObserver());
        $this->pause(0.2);
        $started = microtime(true);
        $running->dispose();

        $next = $this->collect($connection->query('SELECT 1 AS one'));
        $this->settle($next, 5.0);

        $this->assertSame([['one' => '1']], $next->values);
        $this->assertLessThan(3.0, microtime(true) - $started, 'the server stopped the 30 second statement, so the connection served the next query');
    }

    public function testDisposingARunningStatementCancelsItOnTheServerAtOnce()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $connection = $client->getIdleConnection();

        $running = $connection->executeStatement('SELECT pg_sleep(30)')->subscribe(new CallbackObserver());
        $this->pause(0.2);
        $started = microtime(true);
        $running->dispose();

        $next = $this->collect($connection->executeStatement('SELECT 1 AS one'));
        $this->settle($next, 5.0);

        $this->assertSame([['one' => '1']], $next->values);
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    public function testACancelThatArrivesAsItsStatementEndsNeverHitsTheNextStatementOfTheConnection()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $timeouts = 0;

        for ($i = 0; $i < 150; $i++) {
            $connection = $client->getIdleConnection();
            $this->assertNotNull($connection, 'a connection is always available again (dropped ones are replaced)');

            // The statement ends about when it is disposed of, so the cancel races its end.
            $running = $connection->executeStatement('SELECT pg_sleep(0.0505)')->subscribe(new CallbackObserver());
            $this->pause(0.05);
            $running->dispose();
            $timeouts++;

            $next = $this->collect($client->executeStatement('SELECT 1 AS one FROM pg_sleep(0.02)'));
            $this->settle($next, 5.0);

            $this->assertNull($next->error, 'statement ' . $i . ' failed: ' . ($next->error ? $next->error->getMessage() : ''));
            $this->assertSame([['one' => '1']], $next->values);
        }

        $this->assertSame(150, $timeouts);
    }

    public function testAConnectionWhoseCancelDidNotEndItsStatementIsDroppedNotReused()
    {
        $silent    = new \React\Socket\TcpServer('127.0.0.1:0', $this->getLoop());
        $connector = $this->connectorWithSilentCancel($silent);

        try {
            $client = $this->client(['max_connections' => 1], $connector);
            $this->warm($client);
            $connection = $client->getIdleConnection();
            $closed     = false;
            $connection->on('reconnect', function () use (&$closed) {
                $closed = true;
            });

            // The cancel never reaches the server and the statement ends by itself: nothing consumed the cancel, which may still be on its way
            // to whatever runs on this backend next, so the connection is dropped instead of reused.
            $running = $connection->executeStatement('SELECT pg_sleep(0.3)')->subscribe(new CallbackObserver());
            $this->pause(0.1);
            $connector->silentNext = true;
            $running->dispose();
            $this->pause(0.6);

            $this->assertTrue($closed, 'the connection started a new session');

            $next = $this->collect($client->executeStatement('SELECT 1 AS one'));
            $this->settle($next, 5.0);
            $this->assertSame([['one' => '1']], $next->values, 'the pool serves the next statement on a new connection');
        } finally {
            $silent->close();
        }
    }

    public function testACancelNoOneAnswersDropsTheConnectionInsteadOfHoldingItForEver()
    {
        $silent    = new \React\Socket\TcpServer('127.0.0.1:0', $this->getLoop());
        $connector = $this->connectorWithSilentCancel($silent);

        try {
            $client = $this->client(['max_connections' => 1, 'cancel_timeout' => 0.3], $connector);
            $this->warm($client);
            $connection = $client->getIdleConnection();
            $closed     = false;
            $connection->on('reconnect', function () use (&$closed) {
                $closed = true;
            });

            $running = $connection->executeStatement('SELECT pg_sleep(30)')->subscribe(new CallbackObserver());
            $this->pause(0.2);
            $connector->silentNext = true;
            $running->dispose();
            $this->pause(0.8);

            $this->assertTrue($closed, 'a cancel nothing answered did not leave the connection waiting for a 30 second statement (it started a new session)');

            $next = $this->collect($client->executeStatement('SELECT 1 AS one'));
            $this->settle($next, 5.0);
            $this->assertSame([['one' => '1']], $next->values, 'the pool serves the next statement on a new connection');
        } finally {
            $silent->close();
        }
    }

    /**
     * A connector whose next connection (set `silentNext`) goes to a server that accepts and never answers: the cancel request of a
     * statement then never reaches PostgreSQL.
     */
    private function connectorWithSilentCancel(\React\Socket\TcpServer $silent)
    {
        $loop = $this->getLoop();

        return new class($loop, str_replace('tcp://', '', $silent->getAddress())) implements \React\Socket\ConnectorInterface {
            public $silentNext = false;
            private $real;
            private $tcp;
            private $address;

            public function __construct($loop, $address)
            {
                $this->real    = new \WyriHaximus\React\Socket\Connector($loop, []);
                $this->tcp     = new \React\Socket\TcpConnector($loop);
                $this->address = $address;
            }

            public function connect($uri)
            {
                if ($this->silentNext) {
                    $this->silentNext = false;

                    return $this->tcp->connect($this->address);
                }

                return $this->real->connect($uri);
            }
        };
    }

    public function testAQueryInFlightFailsWhenTheSocketGoesAway()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $connection = $client->getIdleConnection();

        $result = $this->collect($connection->query('SELECT pg_sleep(30)'));
        $this->pause(0.2);
        $connection->kill();
        $this->settle($result, 2.0);

        $this->assertNotNull($result->error, 'the waiting query is failed, not left waiting for an answer that cannot come');
    }

    public function testAStatementInFlightFailsWhenTheSocketGoesAway()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $connection = $client->getIdleConnection();

        $result = $this->collect($connection->executeStatement('SELECT pg_sleep(30)'));
        $this->pause(0.2);
        $connection->kill();
        $this->settle($result, 2.0);

        $this->assertNotNull($result->error);
    }

    public function testACallOnAClosedConnectionFailsAtOnce()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $connection = $client->getIdleConnection();
        $connection->kill();

        $query     = $this->collect($connection->query('SELECT 1'));
        $statement = $this->collect($connection->executeStatement('SELECT 1'));
        $ready     = $this->collect($connection->whenReadyForQuery());

        $this->assertNotNull($query->error);
        $this->assertNotNull($statement->error);
        $this->assertNotNull($ready->error);
    }

    public function testATransactionOnAConnectionThatWentAwayEndsWithAnErrorAndFreesItsSlot()
    {
        $client = $this->client(['max_connections' => 1]);
        $this->warm($client);
        $opened = $this->collect($client->transaction());
        $this->settle($opened);
        /** @var Transaction $transaction */
        $transaction = $opened->values[0];

        $statement = $this->collect($transaction->executeStatement('SELECT pg_sleep(30)'));
        $this->pause(0.2);
        $transaction->discard();
        $this->settle($statement, 2.0);

        $this->assertNotNull($statement->error);
        $this->assertSame(0, $client->getReservedConnectionCount());
        $this->assertSame(0, $client->getConnectionCount(), 'the pool forgot the dropped connection');

        $again = $this->collect($client->query('SELECT 1 AS one'));
        $this->settle($again);
        $this->assertSame([['one' => '1']], $again->values, 'a new connection serves the next query');
    }

    public function testAnEmptyQueryCompletesWithNoRows()
    {
        $client = $this->client(['max_connections' => 1]);

        foreach (['', '   ', '-- just a comment', ';'] as $sql) {
            $query = $this->collect($client->query($sql));
            $this->settle($query, 2.0);
            $this->assertTrue($query->completed, 'query ' . json_encode($sql) . ' completed');
            $this->assertSame([], $query->values);

            $statement = $this->collect($client->executeStatement($sql));
            $this->settle($statement, 2.0);
            $this->assertTrue($statement->completed, 'statement ' . json_encode($sql) . ' completed');
            $this->assertSame([], $statement->values);

            $command = $this->collect($client->executeCommand($sql));
            $this->settle($command, 2.0);
            $this->assertSame([0], $command->values);
        }

        $after = $this->collect($client->query('SELECT 1 AS one'));
        $this->settle($after);
        $this->assertSame([['one' => '1']], $after->values, 'the connection is usable after empty queries');
    }

    public function testMergeReportsTheRowsItChanged()
    {
        $client = $this->client(['max_connections' => 1]);
        $version = $this->collect($client->query('SHOW server_version_num'));
        $this->settle($version);
        if ((int) $version->values[0]['server_version_num'] < 150000) {
            $this->markTestSkipped('MERGE needs PostgreSQL 15.');
        }

        $table = 'merge_' . substr(uniqid(), -8);
        foreach ([
            "CREATE TABLE $table (id int PRIMARY KEY, v text)",
            "INSERT INTO $table VALUES (1, 'a'), (2, 'b')",
        ] as $sql) {
            $this->settle($this->collect($client->query($sql)));
        }
        $merge = $this->collect($client->executeCommand(
            "MERGE INTO $table t USING (VALUES (1, 'x'), (3, 'y')) AS s(id, v) ON t.id = s.id "
            . 'WHEN MATCHED THEN UPDATE SET v = s.v WHEN NOT MATCHED THEN INSERT VALUES (s.id, s.v)'
        ));
        $this->settle($merge);
        $this->settle($this->collect($client->query("DROP TABLE $table")));

        $this->assertSame([2], $merge->values);
    }

    public function testATransactionIsNotStarvedByQueriesThatPipelineOntoTheBusyConnections()
    {
        $client = $this->client(['max_connections' => 2]);

        // Both connections are busy; a transaction asks for one, and then plain queries keep coming.
        $busy = [];
        for ($i = 0; $i < 2; $i++) {
            $busy[] = $this->collect($client->query('SELECT pg_sleep(0.4)'));
        }
        $started     = microtime(true);
        $transaction = $this->collect($client->transaction());

        $later = [];
        for ($i = 0; $i < 6; $i++) {
            $later[] = $this->collect($client->query('SELECT pg_sleep(0.4)'));
            // A real server sees them arrive one after the other: each is queued before the next picks a connection.
            $this->pause(0.02);
        }
        while (count($transaction->values) === 0 && $transaction->error === null && microtime(true) - $started < 6) {
            $this->pause(0.01);
        }
        $waited = microtime(true) - $started;

        $this->assertCount(1, $transaction->values);
        $this->assertLessThan(0.9, $waited, 'the transaction got a connection once the first queries ended, not after the queued ones');
        $transaction->values[0]->rollback()->subscribe(new CallbackObserver());
        foreach (array_merge($busy, $later) as $result) {
            $this->settle($result, 8.0);
            $this->assertTrue($result->completed);
        }
    }

    public function testAWaiterForAConnectionGivesUpAfterTheAcquireTimeout()
    {
        $client = $this->client(['max_connections' => 1, 'acquire_timeout' => 0.3]);
        $this->warm($client);
        $held = $this->collect($client->transaction());
        $this->settle($held);
        $this->assertCount(1, $held->values);

        $started = microtime(true);
        $waiter  = $this->collect($client->transaction());
        $this->settle($waiter, 3.0);
        $query = $this->collect($client->query('SELECT 1'));
        $this->settle($query, 3.0);

        $this->assertInstanceOf(\RuntimeException::class, $waiter->error);
        $this->assertStringContainsString('Timed out', $waiter->error->getMessage());
        $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started);
        $this->assertInstanceOf(\RuntimeException::class, $query->error, 'plain queries wait for the connection the transaction holds, and give up too');

        $held->values[0]->rollback()->subscribe(new CallbackObserver());
        $this->pause(0.1);
        $this->assertSame(0, $client->getReservedConnectionCount());
    }

    public function testAnErrorFromTheServerCarriesItsSqlState()
    {
        $client = $this->client(['max_connections' => 1]);
        $table  = 'dup_' . substr(uniqid(), -8);
        $this->settle($this->collect($client->query("CREATE TABLE $table (id int PRIMARY KEY)")));
        $this->settle($this->collect($client->executeCommand("INSERT INTO $table VALUES (1)")));

        $duplicate = $this->collect($client->executeCommand("INSERT INTO $table VALUES (1)"));
        $this->settle($duplicate);
        $this->settle($this->collect($client->query("DROP TABLE $table")));

        $this->assertInstanceOf(ErrorException::class, $duplicate->error);
        $this->assertSame('23505', $duplicate->error->getSqlState());
        $this->assertIsArray($duplicate->error->getExtraInfo());
    }

    public function testDisposingAStatementThatIsRunningOnAFreshConnectionLeavesNoErrorListener()
    {
        $connection = new Connection($this->connectionParameters(), $this->getLoop());

        $result = $this->collect($connection->executeStatement('SELECT pg_sleep(5)'));
        $this->pause(0.5); // connected and the statement is on the wire
        $result->subscription->dispose();

        $this->assertSame(0, count($connection->listeners('error')));
        $connection->kill();
    }

    public function testTheTransactionStatusShowsAFailedStatement()
    {
        $client = $this->client(['max_connections' => 1]);
        $opened = $this->collect($client->transaction());
        $this->settle($opened);
        /** @var Transaction $transaction */
        $transaction = $opened->values[0];
        $this->assertSame('T', $transaction->getBackendTransactionStatus());

        $failed = $this->collect($transaction->executeStatement('SELECT * FROM table_that_does_not_exist'));
        $this->settle($failed);
        $ready = $this->collect($transaction->whenReady());
        $this->settle($ready);
        $this->assertSame(['E'], $ready->values, 'the status that comes with the ReadyForQuery after the error');
        $this->assertSame('E', $transaction->getBackendTransactionStatus());

        $this->settle($this->collect($transaction->rollback()));
        $this->assertSame('I', $transaction->getBackendTransactionStatus());
    }

    private function connectionParameters(): array
    {
        $parts = parse_url(getenv('TEST_POSTGRES_DSN'));

        return [
            'host'     => $parts['host'],
            'port'     => $parts['port'] ?? 5432,
            'user'     => $parts['user'],
            'password' => $parts['pass'] ?? '',
            'database' => trim($parts['path'], '/'),
        ];
    }
}
