<?php
/**
 * Created by IntelliJ IDEA.
 * User: thyde
 * Date: 6/16/15
 * Time: 11:00 AM
 */

namespace CentralDesktop\FatTail;


use CentralDesktop\FatTail\Services\Client\EdgeClient;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class SyncCommand extends Command {
    use LoggerAwareTrait;

    protected $edgeClient;

    public
    function __construct(EdgeClient $edgeClient) {
        parent::__construct();
        $this->edgeClient = $edgeClient;
    }


    protected
    function configure() {
        $this
            ->setName('sync')
            ->setDescription('Syncs');
    }


    protected
    function execute(InputInterface $input, OutputInterface $output) {
        $this->logger->info("Running sync");

        print_r($this->edgeClient->call(
            EdgeClient::$METHOD_GET,
            'users',
            [
                'contextId' => 67452
            ]
        ));
    }
}
