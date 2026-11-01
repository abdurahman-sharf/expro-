<?php

/**
 * Stands for a second request while a test runs: it takes the row of a treasury in a transaction of
 * its own, says it holds it, waits, commits the statements it was given and ends. It needs nothing of
 * the application. The only argument is a JSON job: the connection, the lock statement, the time to
 * hold it in milliseconds and the statements to commit.
 */
$job = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

$connection = new mysqli($job['hostname'], $job['username'], $job['password'], $job['database'], (int) $job['port']);

$connection->begin_transaction();
$connection->query($job['lock']);

fwrite(STDOUT, "locked\n");
fflush(STDOUT);

usleep((int) $job['hold'] * 1000);

foreach ($job['statements'] as $statement) {
    $connection->query($statement);
}

$connection->commit();

fwrite(STDOUT, "committed\n");
