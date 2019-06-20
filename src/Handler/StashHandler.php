<?php
namespace Unifreak\QLog\Handler;

use Monolog\Handler\AbstractProcessingHandler;

class StashHandler extends AbstractProcessingHandler
{
    private $records = [];

    public function write(array $record)
    {
        $record = json_decode($record['formatted'], true);
        array_push($this->records, $record);
    }

    public function stashed($predicate = null)
    {
        if (is_null($predicate)) {
            return $this->records;
        }

        if (!is_callable($predicate)) {
            throw new \InvalidArgumentException("Expected a callable for match");
        }

        $result = [];
        foreach ($this->records as $i => $record) {
            if (call_user_func($predicate, $record)) {
                array_push($result, $record);
            }
        }
        return $result;
    }

    public function clean()
    {
        $this->records = [];
    }

    public function stashLen()
    {
        return count($this->records);
    }

    public function shift()
    {
        return array_shift($this->records);
    }

    public function pop()
    {
        return array_pop($this->records);
    }
}
