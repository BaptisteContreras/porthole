<?php

namespace Porthole\Tests\Background;

use PHPUnit\Framework\TestCase;
use Porthole\Background\BackgroundProcess;
use Porthole\Background\Event\BackgroundTaskCompletedEvent;
use Porthole\Background\Event\BackgroundTaskFailedEvent;
use Porthole\Background\Event\BackgroundTaskProgressEvent;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class BackgroundProcessTest extends TestCase
{
    public function testDispatchesProgressEventsAndCompletedEventOnSuccess(): void
    {
        $dispatcher = new EventDispatcher();
        $progressEvents = [];
        $completed = false;

        $dispatcher->addListener(
            BackgroundTaskProgressEvent::class,
            function (BackgroundTaskProgressEvent $e) use (&$progressEvents): void {
                $progressEvents[] = $e->data;
            }
        );
        $dispatcher->addListener(
            BackgroundTaskCompletedEvent::class,
            function () use (&$completed): void { $completed = true; }
        );

        // Inline child script: one progress line then done
        $script = 'fwrite(STDOUT,json_encode(["type"=>"page_fetched","page"=>1,"total"=>0])."\n");'
            .'fflush(STDOUT);'
            .'fwrite(STDOUT,json_encode(["type"=>"done"])."\n");'
            .'fflush(STDOUT);';

        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', $script],
            dispatcher: $dispatcher,
        );
        $bg->start([]);

        EventLoop::run();

        self::assertTrue($completed);
        self::assertCount(1, $progressEvents);
        self::assertSame('page_fetched', $progressEvents[0]['type']);
        self::assertSame(1, $progressEvents[0]['page']);
        self::assertSame(0, $progressEvents[0]['total']);
    }

    public function testDispatchesFailedEventOnErrorLine(): void
    {
        $dispatcher = new EventDispatcher();
        $failedMessage = null;

        $dispatcher->addListener(
            BackgroundTaskFailedEvent::class,
            function (BackgroundTaskFailedEvent $e) use (&$failedMessage): void {
                $failedMessage = $e->message;
            }
        );

        $script = 'fwrite(STDOUT,json_encode(["type"=>"error","message"=>"401 Unauthorized"])."\n");'
            .'fflush(STDOUT);';

        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', $script],
            dispatcher: $dispatcher,
        );
        $bg->start([]);

        EventLoop::run();

        self::assertSame('401 Unauthorized', $failedMessage);
    }

    public function testDispatchesFailedEventOnUnexpectedExit(): void
    {
        $dispatcher = new EventDispatcher();
        $failedMessage = null;

        $dispatcher->addListener(
            BackgroundTaskFailedEvent::class,
            function (BackgroundTaskFailedEvent $e) use (&$failedMessage): void {
                $failedMessage = $e->message;
            }
        );

        // Child exits immediately without writing any output
        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', 'exit(1);'],
            dispatcher: $dispatcher,
        );
        $bg->start([]);

        EventLoop::run();

        self::assertSame('Worker process exited unexpectedly', $failedMessage);
    }

    public function testDispatchesFailedEventOnTimeout(): void
    {
        $dispatcher = new EventDispatcher();
        $failedMessage = null;

        $dispatcher->addListener(
            BackgroundTaskFailedEvent::class,
            function (BackgroundTaskFailedEvent $e) use (&$failedMessage): void {
                $failedMessage = $e->message;
            }
        );

        // Child sleeps forever; timeout is 1s
        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', 'sleep(60);'],
            dispatcher: $dispatcher,
            timeoutSeconds: 1,
        );
        $bg->start([]);

        EventLoop::run();

        self::assertSame('Worker timed out after 1s', $failedMessage);
    }

    public function testStartThrowsWhenCalledTwice(): void
    {
        $dispatcher = new EventDispatcher();
        $completed = false;

        $dispatcher->addListener(
            BackgroundTaskCompletedEvent::class,
            function () use (&$completed): void { $completed = true; }
        );

        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', 'fwrite(STDOUT,json_encode(["type"=>"done"])."\n");fflush(STDOUT);'],
            dispatcher: $dispatcher,
        );
        $bg->start([]);

        $this->expectException(\LogicException::class);
        $bg->start([]);

        EventLoop::run();
    }

    public function testDispatchesCompletedEventWhenDoneWrittenWithoutNewline(): void
    {
        $dispatcher = new EventDispatcher();
        $completed = false;

        $dispatcher->addListener(
            BackgroundTaskCompletedEvent::class,
            function () use (&$completed): void { $completed = true; }
        );

        // Child writes done JSON with no trailing newline and exits — exercises partial-line drain
        $script = 'fwrite(STDOUT,json_encode(["type"=>"done"]));exit(0);';

        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', $script],
            dispatcher: $dispatcher,
        );
        $bg->start([]);

        EventLoop::run();

        self::assertTrue($completed);
    }

    public function testKillDispatchesNoEventsAndTerminatesChild(): void
    {
        $dispatcher = new EventDispatcher();
        $eventCount = 0;

        $dispatcher->addListener(BackgroundTaskProgressEvent::class, function () use (&$eventCount): void { ++$eventCount; });
        $dispatcher->addListener(BackgroundTaskCompletedEvent::class, function () use (&$eventCount): void { ++$eventCount; });
        $dispatcher->addListener(BackgroundTaskFailedEvent::class, function () use (&$eventCount): void { ++$eventCount; });

        $bg = new BackgroundProcess(
            command: [PHP_BINARY, '-r', 'sleep(60);'],
            dispatcher: $dispatcher,
        );
        $bg->start([]);
        $bg->kill();

        // Nothing pending in the event loop — run() returns immediately
        EventLoop::run();

        self::assertSame(0, $eventCount);
    }
}
