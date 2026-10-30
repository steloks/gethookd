<?php

use Symfony\Component\Yaml\Yaml;

// The dev Postgres role is a superuser with a committed password and Redis has no password, so
// their ports must only be reachable from this machine.
it('publishes every dev service port on loopback only', function () {
    $services = Yaml::parseFile(__DIR__.'/../../../docker-compose.yml')['services'];
    $ports = array_merge(...array_values(array_map(fn (array $service) => $service['ports'] ?? [], $services)));

    expect($ports)->not->toBeEmpty()
        ->each->toStartWith('127.0.0.1:');
});
