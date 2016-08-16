<?php
/**
 * Syncronization service between Edge and FatTail.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 3:50 PM
 */

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Entities\Account;
use CentralDesktop\FatTail\Entities\Milestone;
use CentralDesktop\FatTail\Entities\Workspace;
use JmesPath;
use League\Csv\Reader;
use PhpOption\Option;
use Psr\Log\LoggerAwareTrait;
use stdClass;

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

    private $tmp_dir                  = __DIR__ . '/../../tmp/';
    private $workspace_template_hash  = 'pm';
    private $roles                    = [];
    private $tasklist_templates       = [];
    private $report_timeout           = 300;
    private $report_span              = 1;
    private $fattail_overwrite        = false;

    public
    function __construct(
        EdgeService $edge_service,
        FatTailService $fattail_service,
        SyncCache $cache,
        $tmp_dir = '',
        $workspace_template_hash = 'pm',
        $roles = [],
        $report_timeout = 300,
        $report_span = 1,
        $tasklist_templates = [],
        $fattail_overwrite = false
    ) {
        $this->edge_service             = $edge_service;
        $this->fattail_service          = $fattail_service;
        $this->cache                    = $cache;
        $this->tmp_dir                  = $tmp_dir;
        $this->workspace_template_hash  = $workspace_template_hash;
        $this->roles                    = $roles;
        $this->report_timeout           = $report_timeout;
        $this->report_span              = $report_span;
        $this->tasklist_templates       = $tasklist_templates;
        $this->fattail_overwrite        = $fattail_overwrite;
    }

    /**
     * Syncs Edge and FatTail.
     * @param $report_name string The name of the report
     */
    public
    function sync($report_name = '') {

        $this->logger->info("Starting report sync.\n");

        $report = $this->fattail_service
                  ->get_saved_report_info_by_name($report_name);

        if ($report === null) {
            $this->logger->error("Unable to find requested report. Exiting.\n");
            exit;
        }

        $start_date = date('m/d/Y');
        $end_date   = date('m/d/Y', strtotime('+' . $this->report_span . ' years'));

        $csv_path = $this->download_report_csv(
            $report,
            $this->tmp_dir,
            $start_date,
            $end_date
        );
        $reader = Reader::createFromPath($csv_path);
        $rows = $reader->fetchAll();

        $order_workspace_property_id = $this
                                       ->fattail_service
                                       ->get_order_dynamic_property_id(
                                           $this->WORKSPACE_DYNAMIC_PROP_NAME
                                       );

        $drop_milestone_property_id = $this
                                      ->fattail_service
                                      ->get_drop_dynamic_property_id(
                                          $this->MILESTONE_DYNAMIC_PROP_NAME
                                      );

        // Create mapping of column name with column index
        // Not necessary, but makes it easier to work with the data.
        // Can remove if optimization issues arise because of this
        $col_map = [];
        foreach ($rows[0] as $index => $name) {
            $col_map[$name] = $index;
        }

        $this->logger->info("Preparing to sync data. This may take some time. Feel like taking a coffee break?\n");

        $this->prepare_cache();

        // Iterate over CSV and process data
        // Skip the first and last rows since they
        // don't have the data we need
        for ($i = 1, $len = count($rows); $i < $len; $i++) {

            $row = $rows[$i];

            if (count($rows) === 1) {
                // Skip empty rows
                continue;
            }

            if ($this->filter_row($row)) {
                $this->logger->info('Skipping item', [
                    'Drop ID'   => $row[$col_map['Drop ID']],
                    'Drop Name' => $row[$col_map['Position Path']],
                ]);
                continue;
            }

            $this->logger->info("Processing next item. Please wait.");

            // Process the client
            // Get client details
            $client_id = $row[$col_map['Client ID']];
            try {
                $cd_account = $this->cache->get_client($client_id)
                    ->orElse(Option::fromValue($this->fattail_service->get_client_by_id($client_id)))
                    ->flatMap(function($client) {
                        return $this->sync_client($client);
                    })
                    ->getOrThrow(new \Exception());

            }
            catch (\Exception $e) {

                $this->logger->error(
                    'Failed to sync client. Skipping the workspace and milestone.',
                    [
                        'Client ID'   => $client_id,
                        'Campaign ID' => $row[$col_map['Campaign ID']],
                        'Drop ID'     => $row[$col_map['Drop ID']],
                    ]
                );

                continue;
            }

            // Process the order
            $order_data = [
                'campaign_name'         => $row[$col_map['Campaign Name']],
                'c_campaign_status'     => $row[$col_map['IO Status']],
                'c_campaign_start_date' => $row[$col_map['Campaign Start Date']],
                'c_campaign_end_date'   => $row[$col_map['Campaign End Date']],
                'workspace_id'          => $row[$col_map['(Campaign) CD Workspace ID']],
                'sales_rep'             => $row[$col_map['Sales Rep']],
                'hdm_pm'                => $row[$col_map['HDM | Project Manager']]
            ];

            $order_id = $row[$col_map['Campaign ID']];
            try {
                // Get order details
                $cd_workspace = $this->cache->get_order($order_id)
                    ->orElse(Option::fromValue($this->fattail_service->get_order_by_id($order_id)))
                    ->flatMap(function($order) use ($cd_account, $order_data, $order_workspace_property_id) {
                        $this->cache->add_order($order);
                        return $this->sync_order(
                            $order,
                            $cd_account,
                            $order_data,
                            $order_workspace_property_id
                        );
                    })
                    ->getOrThrow(new \Exception());
            }
            catch (\Exception $e) {

                $this->logger->error(
                    'Failed to sync order. Skipping the milestone.',
                    ['Order ID' => $order_id]
                );

                continue;
            }

            // Process the drop
            $drop_id = $row[$col_map['Drop ID']];
            try {
                // Get drop details
                $drop = $this->fattail_service->get_drop_by_id($drop_id);

                $package_drop_id = $row[$col_map['Package Drop ID']];
                if (!empty($package_drop_id)) {
                    $drop->ParentDropID = $package_drop_id;
                }
                $milestone_data = [
                    'name'                   => $row[$col_map['Position Path']],
                    'description'            => $row[$col_map['Drop Description']],
                    'start_date'             => $row[$col_map['Start Date']],
                    'end_date'               => $row[$col_map['End Date']],
                    'c_custom_unit_features' => $row[$col_map['(Drop) Custom Unit Features']],
                    'c_kpi'                  => $row[$col_map['(Drop) Line Item KPI']],
                    'c_drop_cost_new'        => $row[$col_map['Sold Amount']],
                    'milestone_id'           => $row[$col_map['(Drop) CD Milestone ID']]
                ];

                $this->sync_drop(
                    $drop,
                    $cd_workspace,
                    $milestone_data,
                    $drop_milestone_property_id
                );
            }
            catch (\Exception $e) {

                $this->logger->error(
                    'Failed to sync drop. Continuing.',
                    ['Drop ID' => $drop_id]
                );

                continue;
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
     * @param $report object The report that will
     *        have it's CSV file generated.
     * @param $dir string The system directory to save the CSV to.
     * @param $start_date string The start date in m/d/Y format
     * @param $end_date string The end date in m/d/Y format
     *
     * @return string System path to the CSV file.
     */
    protected
    function download_report_csv(
        $report,
        $dir = '',
        $start_date = null,
        $end_date = null
    ) {
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

        // Set the report dates
        $start_date = !is_null($start_date) ? $start_date : date('m/d/Y');
        $end_date   = !is_null($end_date) ? $end_date : date('m/d/Y');

        $query_parameters = $report_query_formatted['ReportQuery']['QueryParameterList']['QueryParameter'];
        foreach ($query_parameters as &$parameter) {

            if (is_null($start_date) && is_null($end_date)) {
                break;
            }

            if (!is_null($start_date) && $parameter['ParameterType'] === 'StartDate') {

                $parameter['ParameterValue'] = $start_date;
                $start_date = null;
            }
            if (!is_null($end_date) && $parameter['ParameterType'] === 'EndDate') {

                $parameter['ParameterValue'] = $end_date;
                $end_date = null;
            }
        }
        $report_query_formatted['ReportQuery']['QueryParameterList']['QueryParameter'] = $query_parameters;

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
     * @param $dir string The directory to clean up.
     */
    protected
    function clean_up($dir = '') {

        if (is_dir($dir)) {

            // Delete all csv files within the directory
            array_map('unlink', glob($dir . '*.csv'));
        }
    }

    /**
     * Queries Edge for all accounts, workspaces, and milestones
     * to cache them for processing.
     */
    private
    function prepare_cache() {
        $this->logger->info('Starting to prepare cache');

        // Fetch all FatTail clients into an associative array by client id for easy lookup
        $this->logger->info('Caching FatTail clients');
        $this->cache->set_clients($this->fattail_service->get_clients());

        // Only need to cache accounts and workspaces since they're repeated
        $this->logger->info('Caching iMeetCentral accounts and workspaces');
        $workspaces = [];
        $accounts = $this->edge_service->get_cd_accounts();
        foreach ($accounts as $account) {
            $workspaces = array_merge($workspaces, $this->edge_service->get_cd_workspaces($account->hash));
        }
        $this->cache->set_workspaces($workspaces);
        $this->cache->set_accounts($accounts);

        $this->logger->info('Caching iMeetCentral task list templates');
        $this->cache->set_tasklist_templates(
            $this->edge_service->get_cd_tasklist_templates()
        );

        $this->logger->info('Finished preparing cache');
    }

    /**
     * Syncs a FatTail client with CD.
     *
     * @param $client object The FatTail Client.
     * @return Option An Account representing the CD account.
     */
    private
    function sync_client($client) {

        // Process the client
        $custom_fields = [
            'c_client_id' => $client->ClientID
        ];
        $cd_account = $this->cache->find_account_by_c_client_id(
            $client->ClientID
        );
        if ($cd_account->isEmpty()) {
            // Try fetching by hash from iMC API
            $cd_account = $this->edge_service->get_cd_account($client->ExternalID);
        }
        if ($cd_account->isEmpty()) {
            // Create a new CD Account if we couldn't find an account
            $cd_account = $this->edge_service->create_cd_account(
                $client->Name,
                $custom_fields
            );
        }

        $cd_account->forAll(function(Account $account) {
            $this->cache->add_account($account);
        });

        if ($this->fattail_overwrite || $client->ExternalID === '') {
            $cd_account->forAll(function(Account $account) use ($client) {
                // Update the FatTail client external id
                // if it doesn't have a value
                $client->ExternalID = $account->hash;
                $this->fattail_service->update_client($client);
            });
        }

        return $cd_account;
    }

    /**
     * Syncs a FatTail order with CD.
     *
     * @param $order stdClass The FatTail order.
     * @param $cd_account Account The Account the workspace will be under.
     * @param $order_data array The FatTail order data.
     * @param $order_workspace_property_id integer The dynamic property id of
     *                                     the FatTail order.
     * @return Option A Workspace representing the CD workspace
     */
    private
    function sync_order(
        $order,
        $cd_account,
        $order_data,
        $order_workspace_property_id
    ) {

        // Get the name from the campaign name
        $name_parts = explode('|', $order_data['campaign_name']);
        $name = trim($name_parts[count($name_parts) - 1]);

        $custom_fields = [
            'c_order_id'            => $order->OrderID,
            'c_campaign_status'     => $order_data['c_campaign_status'],
            'c_campaign_start_date' => $order_data['c_campaign_start_date'],
            'c_campaign_end_date'   => $order_data['c_campaign_end_date']
        ];
        $cd_workspace = $this->cache->get_workspace_by_order_id($order->OrderID);
            // Couldn't find it in cache so try to fetch it from iMC by hash
        if ($cd_workspace->isEmpty()) {
            $cd_workspace = $this->edge_service->get_cd_workspace($order_data['workspace_id']);
        }
        if ($cd_workspace->isEmpty()) {
            // Couldn't find it in cache or by iMC API hash so try searching under parent account
            foreach ($this->edge_service->get_cd_workspaces($cd_account->hash) as $workspace) {
                /* @var $workspace Workspace */
                $this->cache->add_workspace($workspace);
            }

            $cd_workspace = $this->cache->get_workspace_by_order_id($order->OrderID);
        }
        if ($cd_workspace->isEmpty()) {
            // Couldn't find a workspace so create it
            $cd_workspace = $this->edge_service->create_cd_workspace(
                $cd_account->hash,
                $name,
                $this->workspace_template_hash,
                $custom_fields
            );
        }

        $cd_workspace->forAll(function(Workspace $workspace) use (&$cd_account) {
            $this->cache->add_workspace($workspace);
        });

        if ($this->fattail_overwrite || $order_data['workspace_id'] === '') {

            $cd_workspace->forAll(function(Workspace $workspace) use ($order, $order_workspace_property_id) {

                // Only need to update Order DynamicPropertyValue
                // if it doesn't have one
                $dynamic_properties = $order
                    ->OrderDynamicProperties
                    ->DynamicPropertyValue;
                $order->OrderDynamicProperties->DynamicPropertyValue =
                    $this->fattail_service->update_dynamic_properties(
                        $dynamic_properties,
                        $order_workspace_property_id,
                        $workspace->hash
                    );

                $this->fattail_service->update_order($order);
            });
        }

        $cd_workspace->forAll(function(Workspace $workspace) use ($order_data) {

            // Assign Salesrole
            $sales_rep_name = $this->format_name($order_data['sales_rep']);
            $this->edge_service->assign_user_to_role(
                $sales_rep_name,
                $this->roles['sales_role_hash'],
                $workspace->hash,
                'Sales Rep'
            );

            // Assign HDM PM
            if (!empty($order_data['hdm_pm'])) {
                $hdm_pm_name = $this->format_name($order_data['hdm_pm']);
                $this->edge_service->assign_user_to_role(
                    $hdm_pm_name,
                    $this->roles['hdm_pm_role_hash'],
                    $workspace->hash,
                    'HDM Project Manager'
                );
            }
        });

        return $cd_workspace;
    }

    /**
     * Syncs a FatTail drop with CD.
     *
     * @param $drop object The FatTail drop.
     * @param $cd_workspace Workspace The Workspace the milestone will be under.
     * @param $drop_data array The FatTail drop data.
     * @param $drop_milestone_property_id integer The dynamic property id
     *                                    of the FatTail drop.
     * @return Milestone A Milestone that represents a CD milestone.
     */
    private
    function sync_drop(
        $drop,
        $cd_workspace,
        $drop_data,
        $drop_milestone_property_id
    ) {

        $cd_milestone = $cd_workspace->find_milestone_by_c_drop_id(
            $drop->DropID
        );
        $custom_fields = [
            'c_drop_id'              => $drop->DropID,
            'c_custom_unit_features' => $drop_data['c_custom_unit_features'],
            'c_kpi'                  => $drop_data['c_kpi'],
            'c_drop_cost_new'        => $drop_data['c_drop_cost_new']
        ];
        $milestone_name = $drop_data['name'] . '-' . $drop->DropID;
        if ($cd_milestone === null) {

            $cd_milestone = $this->edge_service->create_cd_milestone(
                $cd_workspace->hash,
                $milestone_name,
                $drop_data['description'],
                $drop_data['start_date'],
                $drop_data['end_date'],
                $custom_fields
            );

            if ($cd_milestone) {
                $cd_workspace->add_milestone($cd_milestone);
            }
        }
        else {

            $status = $this->edge_service->update_cd_milestone(
                $cd_milestone->hash,
                $milestone_name,
                $drop_data['description'],
                $drop_data['start_date'],
                $drop_data['end_date'],
                $custom_fields
            );

            if (!$status) {
                $this->logger->warning(
                    "Failed to updated a milestone. Continuing."
                );
            }
        }

        // Add tasklist to milestone
        $name_parts = explode('|', $drop_data['name']);
        $drop_type  = $name_parts[count($name_parts) - 1];

        // Get hash from drop type
        $tasklist_templates = isset($this->tasklist_templates[$drop_type]) ?
            $this->tasklist_templates[$drop_type] : [];

        if (($this->fattail_overwrite || $drop_data['milestone_id'] === '') && $cd_milestone) {

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

        return $cd_milestone;
    }

    /**
     * Converts a value into a value using only arrays.
     * Only works on public members.
     *
     * @param $thing array|object The value to be converted.
     * @return array The value using only arrays.
     */
    private
    function convert_to_arrays($thing) {

        return json_decode(json_encode($thing), true);
    }

    /**
     * Takes a name formatted "Last, First" and turns it into
     * "First Last".
     * @param $name
     * @return string
     */
    private
    function format_name($name) {

        if (empty($name)) return '';

        $name_parts = explode(', ', $name);

        return strtolower($name_parts[1] . ' ' . $name_parts[0]);
    }


    /**
     * Determines whether to filter out a row. Returns true
     * if row should be filtered out (not processed), false otherwise.
     * @param array $row
     * @return bool
     */
    private
    function filter_row(array $row) {

        // Implement your own logic on what rows to filter here
        return false;
    }
}
