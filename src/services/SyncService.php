<?php
/**
 * Syncronization service between Edge and FatTail.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 3:50 PM
 */

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Services\EdgeService;
use CentralDesktop\FatTail\Services\FatTailService;
use CentralDesktop\FatTail\Entities\Account;
use CentralDesktop\FatTail\Entities\Milestone;
use CentralDesktop\FatTail\Entities\Workspace;

use JmesPath;
use League\Csv\Reader;
use Psr\Log\LoggerAwareTrait;

class SyncService {
    use LoggerAwareTrait;

    protected $edge_service    = null;
    protected $fattail_service = null;
    protected $cache           = null;
    protected $data_extractor  = null;

    private $PING_INTERVAL               = 5; // In seconds
    private $DONE                        = 'done';
    private $WORKSPACE_DYNAMIC_PROP_NAME = 'H_CD_Workspace_ID';
    private $MILESTONE_DYNAMIC_PROP_NAME = 'H_CD_Milestone_ID';

    private $tmp_dir                 = 'tmp/';
    private $workspace_template_hash = 'pm';
    private $sales_role_hash         = '';
    private $report_timeout          = 300;

    public
    function __construct(
        EdgeService $edge_service,
        FatTailService $fattail_service,
        SyncCache $cache,
        $tmp_dir = '',
        $workspace_template_hash = 'pm',
        $sales_role_hash = '',
        $report_timeout
    ) {
        $this->edge_service            = $edge_service;
        $this->fattail_service         = $fattail_service;
        $this->cache                   = $cache;
        $this->tmp_dir                 = $tmp_dir;
        $this->workspace_template_hash = $workspace_template_hash;
        $this->sales_role_hash         = $sales_role_hash;
        $this->report_timeout          = $report_timeout;
    }

    /**
     * Syncs Edge and FatTail.
     */
    public
    function sync($report_name = '') {


        $this->logger->info("Starting report sync.\n");

        // Get all saved reports
        $report_list_result = $this
                              ->fattail_service
                              ->get_saved_report_list();
        $report_list        = $report_list_result
                              ->GetSavedReportListResult
                              ->SavedReport;

        // Find the specific report
        $report = null;
        foreach ($report_list as $report_item) {
            if ($report_item->Name === $report_name) {
                $report = $report_item;
            }
        }

        if ($report === null) {
            $this->logger->error("Unable to find requested report. Exiting.\n");
            exit;
        }

        $csv_path = $this->download_report_csv(
            $report,
            $this->tmp_dir
        );
        $reader = Reader::createFromPath($csv_path);
        $rows = $reader->fetchAll();

        $order_workspace_property_id =
            $this->fattail_service->get_order_dynamic_property_id(
                $this->WORKSPACE_DYNAMIC_PROP_NAME
            );

        $drop_milestone_property_id =
            $this->fattail_service->get_drop_dynamic_property_id(
                $this->MILESTONE_DYNAMIC_PROP_NAME
            );

        // Create mapping of column name with column index
        // Not necessary, but makes it easier to work with the data.
        // Can remove if optimization issues arise because of this
        $col_map = [];
        foreach ($rows[0] as $index => $name) {
            $col_map[$name] = $index;
        }

        $this->logger->info("Preparing to sync data. Please wait.\n");

        // Populate a local copy of CD accounts, workspace, and milestones
        $this->cache->set_accounts(array_map(function ($account) {

            $account->set_workspaces(array_map(function ($workspace) {

                $workspace->set_milestones(
                    $this->edge_service->get_cd_milestones($workspace->hash)
                );

                return $workspace;
            }, $this->edge_service->get_cd_workspaces($account->hash)));

            return $account;
        }, $this->edge_service->get_cd_accounts()));

        // Iterate over CSV and process data
        // Skip the first and last rows since they
        // don't have the data we need
        for ($i = 1, $len = count($rows) - 1; $i < $len; $i++) {
            $this->logger->info("Processing next item. Please wait.");

            $row = $rows[$i];

            // Get client details
            $client_id = $row[$col_map['Client ID']];
            $client = $this->fattail_service->get_client_by_id($client_id);

            // Get order details
            $order_id = $rows[$i][$col_map['Campaign ID']];
            $order = $this->fattail_service->get_order_by_id($order_id);

            // Get drop details
            $drop_id = $rows[$i][$col_map['Drop ID']];
            $drop = $this->fattail_service->get_drop_by_id($drop_id);

            // Process the client
            $cd_account = $this->cache->find_account_by_c_client_id(
                $client->ClientID
            );
            if ($cd_account === null) {
                // Create a new CD Account
                $custom_fields = [
                    'c_client_id' => $client->ClientID
                ];
                $cd_account = $this->edge_service->create_cd_account(
                    $client->Name,
                    $custom_fields
                );

                $this->cache->add_account($cd_account);
            }

            if ($client->ExternalID === '') {
                // Update the FatTail client external id
                // if it doesn't have a value
                $client->ExternalID = $cd_account->hash;

                // Update the FatTail Client with the
                // CD Account hash
                $this->fattail_service->update_client($client);
            }

            // Process the order
            $cd_workspace = $cd_account->find_workspace_by_c_order_id(
                $order->OrderID
            );
            if ($cd_workspace === null) {

                $custom_fields = [
                    'c_order_id'            => $order->OrderID,
                    'c_campaign_status'     => $row[$col_map['IO Status']],
                    'c_campaign_start_date' => $row[$col_map['Campaign Start Date']],
                    'c_campaign_end_date'   => $row[$col_map['Campaign End Date']]
                ];
                $cd_workspace = $this->edge_service->create_cd_workspace(
                    $cd_account->hash,
                    $row[$col_map['Campaign Name']],
                    $this->workspace_template_hash,
                    $custom_fields
                );

                $cd_account->add_workspace($cd_workspace);
            }

            if ($row[$col_map['(Campaign) CD Workspace ID']] === '') {

                // Only need to update Order DynamicPropertyValue
                // if it doesn't have one
                $dynamic_properties = $order
                    ->OrderDynamicProperties
                    ->DynamicPropertyValue;
                $order->OrderDynamicProperties->DynamicPropertyValue =
                    $this->fattail_service->update_dynamic_properties(
                        $dynamic_properties,
                        $order_workspace_property_id,
                        $cd_workspace->hash
                    );

                $this->fattail_service->update_order($order);
            }


            // Assign Salesrole
            // Splitting name, assuming only first and last name in
            // 'Last,' First format
            $sales_rep_name = $row[$col_map['Sales Rep']];
            $name_parts = explode(', ', $sales_rep_name);
            $full_name = strtolower($name_parts[1] . ' ' . $name_parts[0]);

            $this->edge_service->assign_user_to_role(
                $full_name,
                $this->sales_role_hash,
                $cd_workspace->hash
            );

            // Process the drop
            $cd_milestone = $cd_workspace->find_milestone_by_c_drop_id(
                $drop->DropID
            );
            $custom_fields = [
                'c_drop_id'              => $drop->DropID,
                'c_custom_unit_features' => $row[$col_map['(Drop) Custom Unit Features']],
                'c_kpi'                  => $row[$col_map['(Drop) Line Item KPI']],
                'c_drop_cost_new'        => $row[$col_map['Sold Amount']]
            ];
            if ($cd_milestone === null) {

                $cd_milestone = $this->edge_service->create_cd_milestone(
                    $cd_workspace->hash,
                    $row[$col_map['Position Path']],
                    $row[$col_map['Drop Description']],
                    $row[$col_map['Start Date']],
                    $row[$col_map['End Date']],
                    $custom_fields
                );

                $cd_workspace->add_milestone($cd_milestone);
            }
            else {

                $status = $this->edge_service->update_cd_milestone(
                    $cd_milestone->hash,
                    $row[$col_map['Position Path']],
                    $row[$col_map['Drop Description']],
                    $row[$col_map['Start Date']],
                    $row[$col_map['End Date']],
                    $custom_fields
                );

                if (!$status) {
                    $this->logger->warning(
                        "Failed to updated a milestone. Continuing."
                    );
                }
            }

            if ($row[$col_map['(Drop) CD Milestone ID']] === '') {

                // Only need to update Drop DynamicPropertyValue
                // if it doesn't have one
                $dynamic_properties = $drop
                    ->DropDynamicProperties
                    ->DynamicPropertyValue;
                $drop->DropDynamicProperties->DynamicPropertyValue =
                    $this->fattail_service->update_dynamic_properties(
                        $dynamic_properties,
                        $drop_milestone_property_id,
                        $cd_milestone->hash
                    );

                $this->fattail_service->update_drop($drop);
            }
        }

        $this->logger->info("Finished report sync.\n");
        $this->logger->info("Cleaning up temporary CSV report.\n");
        $this->clean_up($this->tmp_dir);
        $this->logger->info("Finished cleaning up CSV report.\n");
        $this->logger->info("Done.\n");
    }

    /**
     * Downloads a CSV report from FatTail.
     *
     * @param $report The report that will
     *        have it's CSV file generated.
     * @param $dir The system directory to save the CSV to.
     *
     * @return System path to the CSV file.
     */
    protected
    function download_report_csv($report, $dir = '') {
        $this->logger->info('Downloading CSV report.');

        // Get individual report details
        $saved_report_query = $this
                              ->fattail_service
                              ->get_saved_report_by_id(
                                  $report->SavedReportID
                              );

        // Get the report parameters
        $report_query = $saved_report_query->ReportQuery;
        $report_query = ['ReportQuery' => $report_query];
        // Convert entirely to use only arrays, no objects
        $report_query_formatted = $this->convert_to_arrays($report_query);

        // Run reports jobs
        $report_job_id = $this->fattail_service->run_report_job(
            $report_query_formatted
        );

        // Ping report job until status is 'Done'
        $done = false;
        $start = time();
        while(!$done) {
            sleep($this->PING_INTERVAL);

            $report_job = $this->fattail_service->get_report_job_by_id(
                $report_job_id
            );

            if (strtolower($report_job->Status) === $this->DONE) {
                $done = true;
            }

            // Check if we hit our timeout limit
            $elapsed = time() - $start;
            if ($elapsed > $this->report_timeout) {
                $this->logger->error(
                    'Timed out waiting for FatTail report. Exiting.'
                );
                exit;
            }
        }

        // Get the CSV download URL
        $report_url = $this->fattail_service->get_report_url_by_id(
            $report_job_id
        );

        $csv_path = $dir . $report->SavedReportID . '.csv';

        // Create download directory if it doesn't exist
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        // Download the file
        file_put_contents(
            $csv_path,
            fopen($report_url, 'r')
        );

        $this->logger->info('Finished downloading CSV report.');

        return $csv_path;
    }

    /**
     * Cleans up (deletes) all CSV files within the directory
     * and then deletes the directory.
     *
     * @param $dir The directory to clean up.
     */
    protected
    function clean_up($dir = '') {

        if (is_dir($dir)) {

            // Delete all csv files within the directory
            array_map('unlink', glob($dir . '*.csv'));
        }
    }

    /**
     * Converts a value into a value using only arrays.
     * Only works on public members.
     *
     * @param $thing The value to be converted.
     * @return The value using only arrays.
     */
    private
    function convert_to_arrays($thing) {

        return json_decode(json_encode($thing), true);
    }
}
