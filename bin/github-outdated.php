<?php

// Application is intended for cli use only, and will most likely not work outside of cli context.
if (PHP_SAPI !== 'cli') {
    die('inspect2csv should only be called via CLI!' . PHP_EOL);
}

$autoloadFiles = array(
    __DIR__ . '/../vendor/autoload.php',
);

$loader = null;
foreach ($autoloadFiles as $autoloadFile) {
    if (file_exists($autoloadFile)) {
        $loader = require $autoloadFile;
        break;
    }
}
if (!$loader) {
    exit('Autoloader not found. Try installing dependencies using composer install.');
}

// Setup project constants
define('PROJECT_ROOT', __DIR__ . '/..');


// Bootstrap CLI component
use Symfony\Component\Console\Application;

$application = new Application();

// Register CLI commands
$application->add(new \app\Commands\OutdatedCommand());
$application->setDefaultCommand('outdated', true);
$application->run();
