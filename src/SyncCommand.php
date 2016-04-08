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

    protected $sync_service = null;
    protected $report_name  = '';

    public
    function __construct(SyncService $sync_service, $report_name) {
        parent::__construct();
        $this->sync_service = $sync_service;
        $this->report_name  = $report_name;
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

        $this->sync_service->sync2($this->report_name);
    }
}
