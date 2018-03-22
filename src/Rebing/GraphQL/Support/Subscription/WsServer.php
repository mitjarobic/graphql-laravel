<?php


namespace Rebing\GraphQL\Support\Subscription;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\WebSocket\WsServerInterface;
use Rebing\GraphQL;

class WsServer implements MessageComponentInterface, WsServerInterface
{
    protected $manager;

    public function __construct(WsManager $manager)
    {
        $this->manager = $manager;
    }

    public function onOpen(ConnectionInterface $conn)
    {
    }

    public function onMessage(ConnectionInterface $conn, $message)
    {
        $data = json_decode($message, true);

        switch ($data['type']) {
            case Graphql\GQL_CONNECTION_INIT:
                return $this->manager->handleConnectionInit($conn);

            case Graphql\GQL_START:
                return $this->manager->handleStart($conn, $data);

            case Graphql\GQL_DATA:
                return $this->manager->handleData($data);

            case Graphql\GQL_STOP:
                return $this->manager->handleStop($conn, $data);
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
    }

    public function onError(ConnectionInterface $conn, \Exception $exception)
    {
        var_dump($exception->getMessage());
        var_dump($exception->getFile());
        var_dump($exception->getLine());
    }

    public function getSubProtocols()
    {
        return ['graphql-ws'];
    }
}