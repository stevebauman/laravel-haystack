<?php

use Illuminate\Support\Facades\Queue;
use Sammyjo20\LaravelHaystack\Models\Haystack;
use Sammyjo20\LaravelHaystack\Models\HaystackBale;
use Sammyjo20\LaravelHaystack\Middleware\CheckAttempts;
use Sammyjo20\LaravelHaystack\Middleware\CheckFinished;
use Sammyjo20\LaravelHaystack\Tests\Fixtures\Jobs\NameJob;
use Sammyjo20\LaravelHaystack\Middleware\IncrementAttempts;
use Sammyjo20\LaravelHaystack\Tests\Fixtures\Callables\Middleware;
use Sammyjo20\LaravelHaystack\Tests\Fixtures\Jobs\OrderCheckCacheJob;

test('a haystack can be created with jobs', function () {
    $haystack = Haystack::build()
        ->addJob(new NameJob('Sam'))
        ->addJob(new NameJob('Gareth'))
        ->create();

    expect($haystack)->toBeInstanceOf(Haystack::class);

    $haystackBales = $haystack->bales()->get();

    expect($haystackBales)->toHaveCount(2);
    expect($haystackBales[0])->toBeInstanceOf(HaystackBale::class);
    expect($haystackBales[1])->toBeInstanceOf(HaystackBale::class);

    expect($haystackBales[0]->job)->toEqual(new NameJob('Sam'));
    expect($haystackBales[1]->job)->toEqual(new NameJob('Gareth'));
});

test('a haystack can be created with default delay, queue and connection', function () {
    $haystack = Haystack::build()
        ->addJob(new NameJob('Sam'))
        ->withDelay(60)
        ->onQueue('testing')
        ->onConnection('database')
        ->create();

    expect($haystack)->toBeInstanceOf(Haystack::class);

    $haystackBales = $haystack->bales()->get();

    expect($haystackBales)->toHaveCount(1);

    expect($haystackBales[0]->delay)->toEqual(60);
    expect($haystackBales[0]->on_queue)->toEqual('testing');
    expect($haystackBales[0]->on_connection)->toEqual('database');
});

test('a haystack can be created with middleware', function () {
    $haystack = Haystack::build()
        ->addJob(new NameJob('Sam'))
        ->addJob(new NameJob('Gareth'))
        ->withMiddleware([
            new Middleware(),
        ])
        ->create();

    $haystackBales = $haystack->bales()->get();

    expect($haystackBales)->toHaveCount(2);

    $samJob = $haystack->getNextJob()->job;

    $haystack->getNextJob()->haystackRow->delete();

    $garethJob = $haystack->getNextJob()->job;

    // Check the middleware is applied to all jobs.

    $defaultMiddleware = [new CheckFinished, new CheckAttempts, new IncrementAttempts];

    expect($samJob->middleware)->toEqual(array_merge($defaultMiddleware, [new Middleware]));
    expect($garethJob->middleware)->toEqual(array_merge($defaultMiddleware, [new Middleware]));
});

test('a haystack job can have their own delay, queue and connection', function () {
    $haystack = Haystack::build()
        ->addJob(new NameJob('Sam'))
        ->addJob(new NameJob('Gareth'), 120, 'cowboy', 'redis')
        ->withDelay(60)
        ->onQueue('testing')
        ->onConnection('database')
        ->create();

    expect($haystack)->toBeInstanceOf(Haystack::class);

    $haystackBales = $haystack->bales()->get();

    expect($haystackBales)->toHaveCount(2);

    expect($haystackBales[0]->delay)->toEqual(60);
    expect($haystackBales[0]->on_queue)->toEqual('testing');
    expect($haystackBales[0]->on_connection)->toEqual('database');

    expect($haystackBales[1]->delay)->toEqual(120);
    expect($haystackBales[1]->on_queue)->toEqual('cowboy');
    expect($haystackBales[1]->on_connection)->toEqual('redis');
});

test('a haystack can have closures', function () {
    $closureA = fn () => 'A';
    $closureB = fn () => 'B';
    $closureC = fn () => 'C';
    $closureD = fn () => 'D';

    $haystack = Haystack::build()
        ->then($closureA)
        ->catch($closureB)
        ->finally($closureC)
        ->paused($closureD)
        ->create();

    $haystack->refresh();

    expect($haystack->on_then)->toEqual($closureA);
    expect($haystack->on_catch)->toEqual($closureB);
    expect($haystack->on_finally)->toEqual($closureC);
    expect($haystack->on_paused)->toEqual($closureC);
});

test('a haystack can be dispatched straight away', function () {
    Queue::fake();

    $haystack = Haystack::build()
        ->addJob(new NameJob('Sam'))
        ->addJob(new NameJob('Steve'))
        ->addJob(new NameJob('Taylor'))
        ->dispatch();

    expect($haystack->started)->toBeTrue();

    $nextJob = null;

    Queue::assertPushed(NameJob::class, function (NameJob $job) use (&$nextJob) {
        $nextJob = $job;

        return $job->name === 'Sam';
    });

    $haystack->dispatchNextJob($nextJob);

    Queue::assertPushed(NameJob::class, function (NameJob $job) use (&$nextJob) {
        $nextJob = $job;

        return $job->name === 'Steve';
    });

    $haystack->dispatchNextJob($nextJob);

    Queue::assertPushed(NameJob::class, function (NameJob $job) use (&$nextJob) {
        $nextJob = $job;

        return $job->name === 'Taylor';
    });
});

test('you can specify a name for the haystack', function () {
    $haystack = Haystack::build()
        ->addJob(new NameJob('Sam'))
        ->withName('My Custom Name')
        ->create();

    $haystack->refresh();

    expect($haystack->name)->toEqual('My Custom Name');
});

test('can add multiple jobs to the haystack at once', function () {
    $haystack = Haystack::build()
        ->addJobs([
            new OrderCheckCacheJob('Taylor'),
            new OrderCheckCacheJob('Steve'),
            new OrderCheckCacheJob('Gareth'),
        ])
        ->addJobs(collect([
            new OrderCheckCacheJob('Patrick'),
            new OrderCheckCacheJob('Mantas'),
        ]))
        ->addJob(new OrderCheckCacheJob('Teo'))
        ->create();

    expect($haystack->bales()->count())->toEqual(6);

    $haystack->start();

    expect(cache()->get('order'))->toEqual([
        'Taylor',
        'Steve',
        'Gareth',
        'Patrick',
        'Mantas',
        'Teo',
    ]);
});
