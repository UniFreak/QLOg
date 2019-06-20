<?php
namespace Unifreak\QLog\Processor;

class IdProcessor
{
    private $identities = [];

    public function setId($id, $value)
    {
        if (substr($id, -3) !== '_id') {
            throw new \InvalidArgumentException('id must end with `_id`');
        }
        $this->identities[$id] = $value;
    }

    public function __invoke(array $record)
    {
        foreach ($this->identities as $id => $value) {
            $record[$id] = $value;
        }
        return $record;
    }
}