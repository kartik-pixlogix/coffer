<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('the component gallery is hidden outside the local environment', function () {
    $this->get(route('dev.ui'))->assertNotFound();
});

test('the component gallery renders in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get(route('dev.ui'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dev/ui'));
});
