<?php

use PgAsync\Message\CommandComplete;
use PHPUnit\Framework\TestCase;

class CommandCompleteTest extends TestCase
{
    /**
     * @dataProvider tags
     */
    public function testRowsAndOidAreReadFromTheCommandTag(string $tag, int $rows, int $oid)
    {
        $message = new CommandComplete();
        // 'C', the int32 length, then the NUL terminated tag.
        $message->parseMessage('C' . pack('N', strlen($tag) + 5) . $tag . "\0");

        $this->assertSame($rows, $message->getRows());
        $this->assertSame($oid, $message->getOid());
    }

    public function tags(): array
    {
        return [
            'insert' => ['INSERT 0 3', 3, 0],
            'insert with an oid' => ['INSERT 16385 1', 1, 16385],
            'update' => ['UPDATE 2', 2, 0],
            'delete' => ['DELETE 0', 0, 0],
            'select' => ['SELECT 5', 5, 0],
            'merge' => ['MERGE 4', 4, 0],
            'a command without a count' => ['CREATE TABLE', 0, 0],
        ];
    }
}
