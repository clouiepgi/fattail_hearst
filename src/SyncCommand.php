<?php
/**
 * Created by IntelliJ IDEA.
 * User: thyde
 * Date: 6/16/15
 * Time: 11:00 AM
 */

namespace CentralDesktop\FatTail;


use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;


class SyncCommand extends Command {
    use LoggerAwareTrait;


    protected
    function configure() {
        $this
            ->setName('sync')
            ->setDescription('Syncs');
    }


    protected
    function execute(InputInterface $input, OutputInterface $output) {
        $this->logger->info("Running sync");
    }
}