<?php

$agents = json_decode(file_get_contents(__DIR__ . '/agents.json'), true);
file_put_contents(__DIR__ . '/agents.json', json_encode($agents));
