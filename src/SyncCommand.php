<?php
/**
 * Created by IntelliJ IDEA.
 * User: thyde
 * Date: 6/16/15
 * Time: 11:00 AM
 */

namespace CentralDesktop\FatTail;


use CentralDesktop\FatTail\Services\SyncService;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class SyncCommand extends Command {
    use LoggerAwareTrait;

    protected $syncService;

    public
    function __construct(SyncService $syncService) {
        parent::__construct();
        $this->syncService = $syncService;
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

        $this->syncService->sync();
    }
}
