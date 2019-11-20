<?php

namespace OneFit\Events\Services;

use RdKafka\Message;
use RdKafka\KafkaConsumer;

/**
 * Class ConsumerService.
 */
class ConsumerService
{
    /**
     * @var KafkaConsumer
     */
    private $consumer;

    /**
     * ConsumerService constructor.
     * @param KafkaConsumer $consumer
     */
    public function __construct(KafkaConsumer $consumer)
    {
        $this->consumer = $consumer;
    }

    /**
     * @param array $topics
     * @return ConsumerService
     * @throws \RdKafka\Exception
     */
    public function subscribe(array $topics): self
    {
        $this->consumer->subscribe($topics);

        return $this;
    }

    /**
     * @param  int                $timeout
     * @throws \RdKafka\Exception
     * @return Message
     */
    public function consume(int $timeout): Message
    {
        return $this->consumer->consume($timeout);
    }
}
