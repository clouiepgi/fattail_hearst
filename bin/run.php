<?php

require_once(__DIR__ . "/../vendor/autoload.php");

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Console\Application;

ini_set('default_socket_timeout', 600);

$container = new ContainerBuilder();
$loader    = new YamlFileLoader($container, new FileLocator(__DIR__ . "/../config"));
$loader->load('fattail_config.yml');
$loader->load('cd_config.yml');
$loader->load('tasklist_mappings.yml');
$loader->load('config.yml');

/** @var Application $app */
$app = $container->get('console.application');
$app->run();