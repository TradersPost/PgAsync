<?php

namespace PgAsync\Message;

class CommandComplete implements ParserInterface
{
    use ParserTrait;

    private $tag;
    private $oid = 0;
    private $rows = 0;

    /**
     * @inheritDoc
     */
    public function parseMessage(string $rawMessage)
    {
        $completeTag = rtrim(substr($rawMessage, 5), "\0");
        $parts       = explode(' ', $completeTag);
        if (isset($parts[0])) {
            switch ($parts[0]) {
                case 'INSERT':
                    // INSERT <oid> <rows>: the oid is 0 on every table without OIDs, which is every table now.
                    $this->oid  = (int) $parts[1];
                    $this->rows = (int) $parts[2];
                    break;
                case 'DELETE':
                case 'UPDATE':
                case 'SELECT':
                case 'MOVE':
                case 'FETCH':
                case 'COPY':
                    $this->rows = (int) $parts[1];
                    break;
            }

            $this->tag = $parts[0];
        }
    }

    /**
     * @inheritDoc
     */
    public static function getMessageIdentifier(): string
    {
        return 'C';
    }

    /**
     * @return mixed
     */
    public function getTag()
    {
        return $this->tag;
    }

    public function getOid(): int
    {
        return $this->oid;
    }

    public function getRows(): int
    {
        return $this->rows;
    }
}
