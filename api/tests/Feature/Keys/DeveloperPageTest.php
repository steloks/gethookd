<?php

use App\Support\Errors\ErrorCode;

it('serves the developer page with a CSRF token for its writes, and the MCP address it is configured with', function () {
    $this->withoutVite();
    // Set here, not taken from the environment: the stack may run the MCP server on another port.
    config(['app.mcp_url' => 'http://localhost:4321/mcp']);

    $this->get('/developer')
        ->assertOk()
        ->assertSee('<div id="developer-page" data-mcp-url="http://localhost:4321/mcp"></div>', escape: false)
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
