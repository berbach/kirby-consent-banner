<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// Kirby would register its error handlers with every instance of a test
Kirby\Cms\App::$enableWhoops = false;

// Registers the plugin; every Kirby instance of a test loads it
require dirname(__DIR__) . '/index.php';
