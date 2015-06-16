<?php

require_once("vendor/autoload.php");


use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Console\Application;

$container = new ContainerBuilder();
$loader    = new YamlFileLoader($container, new FileLocator(__DIR__ . "/../config"));
$loader->load('config.yml');


/**
 * @var Monolog\Logger $logger
 */
$logger = $container->get('logger.default');

$logger->info("hello world");

/** @var Application $app */
$app = $container->get('console.application');
$app->run();