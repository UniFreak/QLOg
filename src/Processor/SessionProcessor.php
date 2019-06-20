<?php
namespace Unifreak\QLog\Processor;

class SessionProcessor
{
    private $session;

    public function __construct($session)
    {
        $this->session = $session;
    }

    public function __invoke(array $record)
    {
        $record['session'] = $this->session;
        return $record;
    }
}