<?php

namespace OneFit\Events;

use RdKafka\Conf;
use RdKafka\Producer;
use RdKafka\KafkaConsumer;
use OneFit\Events\Models\Source;
use OneFit\Events\Models\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;
use OneFit\Events\Services\ConsumerService;
use OneFit\Events\Services\ProducerService;
use OneFit\Events\Observers\CreatedObserver;
use OneFit\Events\Observers\DeletedObserver;
use OneFit\Events\Observers\GenericObserver;
use OneFit\Events\Observers\UpdatedObserver;
use Illuminate\Contracts\Foundation\Application;

/**
 * Class EventsServiceProvider.
 */
class EventsServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->registerProducer();
        $this->registerConsumer();
    }

    /**
     * Bootstrap application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->setupConfig();
        $this->registerObservers();
        $this->registerListeners();
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            ProducerService::class,
            ConsumerService::class,
        ];
    }

    /**
     * @param  Conf $configuration
     * @return void
     */
    private function setConfiguration(Conf $configuration): void
    {
        // Initial list of Kafka brokers
        $configuration->set('metadata.broker.list', env('METADATA_BROKER_LIST', 'localhost:9092'));

        // Default timeout for network requests
        $configuration->set('socket.timeout.ms', env('SOCKET_TIMEOUT_MS', 60000));

        // Fetch only the topics in use, reduce the bandwidth
        $configuration->set('topic.metadata.refresh.sparse', env('TOPIC_METADATA_REFRESH_SPARSE', 'true'));
        $configuration->set('topic.metadata.refresh.interval.ms', env('TOPIC_METADATA_REFRESH_INTERVAL_MS', 300000));

        // Signal that librdkafka will use to quickly terminate on rd_kafka_destroy()
        pcntl_sigprocmask(SIG_BLOCK, [env('INTERNAL_TERMINATION_SIGNAL', 29)]);
        $configuration->set('internal.termination.signal', env('INTERNAL_TERMINATION_SIGNAL', 29));
    }

    /**
     * @return void
     */
    private function registerProducer(): void
    {
        $this->app->singleton(ProducerService::class, function (Application $app) {
            $configuration = $app->make(Conf::class);
            $this->setConfiguration($configuration);

            $producer = $app->make(Producer::class, ['conf' => $configuration]);

            return new ProducerService($producer);
        });
    }

    /**
     * @return void
     */
    private function registerConsumer(): void
    {
        $this->app->bind(ConsumerService::class, function (Application $app, array $params = []) {
            $configuration = $app->make(Conf::class);
            $this->setConfiguration($configuration);

            isset($params['group_id']) && $configuration->set('group.id', $params['group_id']);

            // Set where to start consuming messages when there is no initial offset in
            // offset store or the desired offset is out of range.
            // 'smallest': start from the beginning
            $configuration->set('auto.offset.reset', env('AUTO_OFFSET_RESET', 'smallest'));

            // Automatically and periodically commit offsets in the background.
            $configuration->set('enable.auto.commit', env('ENABLE_AUTO_COMMIT', 'true'));

            $consumer = $app->make(KafkaConsumer::class, ['conf' => $configuration]);

            return new ConsumerService($consumer);
        });
    }

    /**
     * @return void
     */
    private function registerObservers(): void
    {
        $producers = Config::get('events.producers', []);

        foreach ($producers as $topic => $topicProducers) {
            if (is_array($topicProducers)) {
                array_walk($topicProducers, function (string $producer, string $type, string $topic) {
                    $this->registerGenericObservers($producer, $type, $topic);
                }, $topic);
            }
        }
    }

    /**
     * Setup exact configuration.
     */
    protected function setupConfig()
    {
        $source = realpath(__DIR__.'/../config/events.php');
        $this->publishes([$source => $this->configPath('events.php')]);
        $this->mergeConfigFrom($source, 'events');
    }

    /**
     * Get the configuration path.
     *
     * @param  string $path
     * @return string
     */
    private function configPath($path = ''): string
    {
        return $this->app->make('path.config').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * @param string $producer
     * @param string $type
     * @param string $topic
     */
    private function registerGenericObservers(string $producer, string $type, string $topic): void
    {
        if (class_exists($producer)) {
            $this->registerCreatedObserver($producer, $type, $topic);
            $this->registerUpdatedObserver($producer, $type, $topic);
            $this->registerDeletedObserver($producer, $type, $topic);
        }
    }

    /**
     * @param string $producer
     * @param string $type
     * @param string $topic
     */
    private function registerCreatedObserver(string $producer, string $type, string $topic): void
    {
        if (method_exists($producer, 'created')) {
            $producer::created($this->app->make(CreatedObserver::class, [
                'producer' => function () {
                    return $this->app->make(ProducerService::class);
                },
                'message' => $this->makeMessage($type),
                'topic' => $topic,
            ]));
        }
    }

    /**
     * @param string $producer
     * @param string $type
     * @param string $topic
     */
    private function registerUpdatedObserver(string $producer, string $type, string $topic): void
    {
        if (method_exists($producer, 'updated')) {
            $producer::updated($this->app->make(UpdatedObserver::class, [
                'producer' => function () {
                    return $this->app->make(ProducerService::class);
                },
                'message' => $this->makeMessage($type),
                'topic' => $topic,
            ]));
        }
    }

    /**
     * @param string $producer
     * @param string $type
     * @param string $topic
     */
    private function registerDeletedObserver(string $producer, string $type, string $topic): void
    {
        if (method_exists($producer, 'deleted')) {
            $producer::deleted($this->app->make(DeletedObserver::class, [
                'producer' => function () {
                    return $this->app->make(ProducerService::class);
                },
                'message' => $this->makeMessage($type),
                'topic' => $topic,
            ]));
        }
    }

    /**
     * @return void
     */
    private function registerListeners(): void
    {
        $listeners = Config::get('events.listeners', []);

        foreach ($listeners as $type => $topic) {
            $this->getDispatcher()->listen("{$type}.*", $this->app->make(GenericObserver::class, [
                'producer' => function () {
                    return $this->app->make(ProducerService::class);
                },
                'message' => $this->makeMessage($type),
                'topic' => $topic,
            ]));
        }
    }

    /**
     * @param  string  $type
     * @return Message
     */
    private function makeMessage(string $type): Message
    {
        $source = Config::get('events.source', Source::UNDEFINED);
        $salt = env('MESSAGE_SIGNATURE_SALT', '');

        return $this->app
            ->make(Message::class)
            ->setType($type)
            ->setSource($source)
            ->setSalt($salt);
    }

    /**
     * @return Dispatcher
     */
    private function getDispatcher(): Dispatcher
    {
        return $this->app->make(Dispatcher::class);
    }
}
