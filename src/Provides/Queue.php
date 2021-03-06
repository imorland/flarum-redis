<?php

namespace Bokt\Redis\Provides;

use Bokt\Redis\Configuration;
use Bokt\Redis\Manager;
use Flarum\Extend\Frontend;
use Flarum\Extension\ExtensionManager;
use Flarum\Frontend\Document;
use Flarum\Settings\Event\Deserializing;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\RedisQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;

class Queue extends Provider
{
    public function __invoke(Configuration $configuration, Container $container)
    {
        $container->resolving(Factory::class, function (Manager $manager) use ($configuration) {
            $manager->addConnection('default', $config = $configuration->toArray());
        });

        $container->bind('flarum.queue.connection', function ($app) {
            /** @var Manager $manager */
            $manager = $app->make(Factory::class);
            $queue = new RedisQueue($manager);
            $queue->setContainer($app);

            return $queue;
        });

        /** @var ExtensionManager $extensions */
        $extensions = $container->make(ExtensionManager::class);

        (new Frontend('admin'))
            ->js(__DIR__ . '/../../js/dist/admin.js')
            ->content([$this, 'adminWidgetAttributes'])
            ->extend($container, $extensions->getExtension('bokt-redis'));

        /** @var Dispatcher $dispatcher */
        $dispatcher = $container->make(Dispatcher::class);
        $dispatcher->listen(Looping::class, [$this, 'trackQueues']);
    }

    public function adminWidgetAttributes(Document $document)
    {
        /** @var Store $cache */
        $cache = app('cache.store');
        /** @var QueueContract $queue */
        $queue = app(QueueContract::class);

        $queues = $cache->get('bokt.queue.queues-seen') ?? [];

        if ($queue instanceof RedisQueue) {
            $load = [];

            foreach ($queues as $name) {
                $load[$name] = $queue->getRedis()->connection()->llen('queues:' . $name);
            }
        }

        $document->payload['boktQueuesSeen'] = $queues;
        $document->payload['boktQueuesLoad'] = $load ?? null;
    }

    public function trackQueues(Looping $event)
    {
        /** @var Store $cache */
        $cache = app('cache.store');

        $queues = $cache->get('bokt.queue.queues-seen') ?? [];
        $queues = array_merge($queues, (array) explode(',', $event->queue));
        $cache->put('bokt.queue.queues-seen', array_unique($queues), 60);
    }
}
