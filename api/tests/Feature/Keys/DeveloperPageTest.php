<?php

use App\Support\Errors\ErrorCode;

it('serves the developer page with a CSRF token for its writes', function () {
    $this->withoutVite();

    $this->get('/developer')
        ->assertOk()
        ->assertSee('<div id="developer-page"></div>', escape: false)
        ->assertSee('<meta name="csrf-token" content="'.csrf_token().'">', escape: false)
        ->assertSee('<meta name="referrer" content="no-referrer">', escape: false);
});

it('only serves the page on this machine\'s host names', function () {
    $this->withoutVite();

    assertApiError($this->get('http://evil.example:8000/developer'), ErrorCode::Forbidden);
});

it('sends / to the developer page', function () {
    $this->get('/')->assertRedirect('/developer');
});
