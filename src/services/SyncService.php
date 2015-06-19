<?php
/**
 * Syncronization service between Edge and FatTail.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 3:50 PM
 */

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Services\Client\EdgeClient;
use CentralDesktop\FatTail\Services\Client\FatTailClient;

use League\Csv\Reader;

class SyncService {

    protected $edgeClient    = null;
    protected $fattailClient = null;

    private $PING_INTERVAL = 5; // In seconds
    private $DONE          = 'done';
    private $tmpDir        = 'tmp/';

    public
    function __construct(
        EdgeClient $edgeClient,
        FatTailClient $fattailClient,
        $tmpDir = ''
    ) {
        $this->edgeClient    = $edgeClient;
        $this->fattailClient = $fattailClient;
        $this->tmpDir        = $tmpDir;
    }

    /**
     * Syncs Edge and FatTail.
     */
    public
    function sync($reportName = '') {

        // Get all saved reports
        $reportListResult = $this
                            ->fattailClient
                            ->call('GetSavedReportList');
        $reportList       = $reportListResult
                            ->GetSavedReportListResult
                            ->SavedReport;

        // Find the specific report
        $report = null;
        foreach ($reportList as $reportItem) {
            if ($reportItem->Name === $reportName) {
                $report = $reportItem;
            }
        }

        if ($report === null) {
            // TODO
            die("Unable to find requested report\n");
        }

        print_r("Starting report processing\n");
        $csvPath = $this->downloadReportCSV(
            $report,
            $this->tmpDir
        );
        print_r("Ending CSV download\n");

        $reader = Reader::createFromPath($csvPath);
        $rows = $reader->fetchAll();

        // Create mapping of column name with column index
        // Not necessary, but makes it easier to work with the data
        // can remove if optimization issues arise
        $colMap = [];
        foreach ($rows[0] as $index => $name) {
            $colMap[$name] = $index;
        }

        // Iterate over CSV and process data
        // Skip the first and last rows since they
        // dont have the data we need
        for ($i = 1, $len = count($rows) - 1; $i < $len; $i++) {
            $row = $rows[$i];

            print_r("Getting Client\n");
            // Get client details
            $clientId = $row[$colMap['Client ID']];
            $client = $this->fattailClient->call(
                'GetClient',
                ['clientId' => $clientId]
            )->GetClientResult;

            print_r("Getting Order\n");
            // Get order details
            $orderId = $rows[$i][$colMap['Campaign ID']];
            $order = $this->fattailClient->call(
                'GetOrder',
                ['orderId' => $orderId]
            )->GetOrderResult;

            print_r("Getting Drop\n");
            // Get drop details
            $dropId = $rows[$i][$colMap['Drop ID']];
            $drop = $this->fattailClient->call(
                'GetDrop',
                ['dropId' => $dropId]
            )->GetDropResult;

            // Check client to account sync
            $accountHash = $client->ExternalID;
            if ($accountHash === "") {

                $customFields = [
                    'c_client_id' => $clientId
                ];
                /*$accountHash = $this->createCDAccount(
                    $client->Name,
                    $customFields
                );*/

                // Update client external id with new account hash
                $client->ExternalID = $accountHash;
            }

            // Check order to workspace sync
            $workspaceHash = $rows[$i][$colMap['(Campaign) CD Workspace ID']];
            if ($workspaceHash === "") {

                $customFields = [
                    'c_order_id'            => $orderId,
                    'c_campaign_status'     => $rows[$i][$colMap['IO Status']],
                    'c_campaign_start_date' => $rows[$i][$colMap['Campaign Start Date']],
                    'c_campaign_end_date'   => $rows[$i][$colMap['Campaign End Date']]
                ];
                /*$workspaceHash = $this->createCDWorkspace(
                    $accountHash,
                    $rows[$i][$colMap['Campaign Name']]
                );*/

                // TODO Gets sales rep information
                // and set role for account
            }

            // Check drop to milestone sync
            $milestoneHash = $rows[$i][$colMap['(Drop) CD Milestone ID']];
            if ($milestoneHash === "") {

                $customFields = [
                    'c_drop_id'              => $dropId,
                    'c_custom_unit_features' => $rows[$i][$colMap['(Drop) Custom Unit Features']],
                    'c_kpi'                  => $rows[$i][$colMap['(Drop) Line Item KPI']],
                    'c_drop_cost_new'        => $rows[$i][$colMap['Sold Amount']]
                ];
                /*$milestoneHash = $this->createCDMilestone(
                    $workspaceHash,
                    $rows[$i][$colMap['Position Path']],
                    $rows[$i][$colMap['Drop Description']],
                    $rows[$i][$colMap['Start Date']],
                    $rows[$i][$colMap['End Date']],
                    $customFields
                );*/

                // TODO Update drop with new milestone hash
            }
            else {
                // TODO Update milestone
            }

            // TODO update on client, order, and drop
            // Update FatTail client
            /*$clientArray = $this->convertToArrays($client);
            $this->fattailClient->call(
                'UpdateClient',
                ['client' => $clientArray]
            );*/

            // Update FatTail order
            /*$orderArray = $this->convertToArrays($order);
            $this->fattailClient->call(
                'UpdateOrder',
                ['order' => $order]
            );*/

            // Update FatTail drop
            /*$dropArray = $this->convertToArrays($drop);
            $this->fattailClient->call(
                'UpdateDrop',
                ['drop' => $drop]
            );*/
        }
        print_r("Ended processing report\n");
        print_r("Cleaning up CSV reports\n");
        $this->cleanUp($this->tmpDir);
        print_r("Finished cleaning up CSV reports\n");
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
    function downloadReportCSV($report, $dir = '') {

        // Get individual report details
        $savedReportQuery = $this->fattailClient->call(
            'GetSavedReportQuery',
            [ 'savedReportId' => $report->SavedReportID ]
        )->GetSavedReportQueryResult;

        // Get the report parameters
        $reportQuery = $savedReportQuery->ReportQuery;
        $reportQuery = ['ReportQuery' => $reportQuery];
        // Convert entirely to use only arrays, no objects
        $reportQueryFormatted = $this->convertToArrays($reportQuery);

        // Run reports jobs
        $runReportJob = $this->fattailClient->call(
            'RunReportJob',
            ['reportJob' => $reportQueryFormatted]
        )->RunReportJobResult;
        $reportJobId = $runReportJob->ReportJobID;

        // Ping report job until status is 'Done'
        $done = false;
        while(!$done) {
            sleep($this->PING_INTERVAL);

            $reportJob = $this->fattailClient->call(
                'GetReportJob',
                ['reportJobId' => $reportJobId]
            )->GetReportJobResult;

            if (strtolower($reportJob->Status) === $this->DONE) {
                $done = true;
            }
        }

        // Get the CSV download URL
        $reportURLResult = $this->fattailClient->call(
            'GetReportDownloadUrl',
            ['reportJobId' => $reportJobId]
        );
        $reportURL = $reportURLResult->GetReportDownloadURLResult;

        $csvPath = $dir . $report->SavedReportID . '.csv';

        // Create download directory if it doesn't exist
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        // Download the file
        file_put_contents(
            $csvPath,
            fopen($reportURL, 'r')
        );

        return $csvPath;
    }

    /**
     * Cleans up (deletes) all CSV files within the directory
     * and then deletes the directory.
     *
     * @param $dir The directory to clean up.
     */
    protected
    function cleanUp($dir = '') {

        if (is_dir($dir)) {

            // Delete all files within the directory
            array_map('unlink', glob($dir . '*.csv'));

            // Delete the directory
            rmdir($dir);
        }
    }

    /**
     * Creates an CD entity based on the path and details.
     *
     * @param $path The path for the resource
     * @param $details An array representing the entity data
     *
     * @return A Psr\Http\Message\ResponseInterface object
     */
    protected
    function createCDEntity($path, $details) {

        $httpResponse = null;
        try {
            $httpResponse = $this->edgeClient->call(
                EdgeClient::METHOD_POST,
                $path,
                [],
                $details
            );
        }
        catch (\GuzzleHttp\Exception\ClientException $e) {
            // Failed to create new account
            // TODO
            die('CLIENT EXCPETION');
        }
        catch (\GuzzleHttp\Exception\ServerException $e) {
            // Failed to create new account
            // TODO
            die('SERVER EXCPETION');
        }
        catch (\Exception $e) {
            // TODO
            die('EXCPETION');
        }

        return $httpResponse;
    }

    /**
     * Creates an account on CD.
     *
     * @param $name The account name.
     * @param $customFields An array of custom fields.
     *
     * @returns The account hash of the new account.
     */
    protected
    function createCDAccount($name, $customFields = []) {

        $details = new \stdClass();
        $details->accountName = $name;

        // Prepare data for request
        $path = 'accounts';
        $details->customFields = $this->createCDCustomFields($customFields);

        $httpResponse = $this->createCDEntity($path, $details);

        // Check if create wasn't successful
        if (
            $httpResponse == null ||
            $httpResponse->getStatusCode() !== 201
        ) {
            // TODO
        }

        // Call accounts endpoint to get the latest hash/id
        $response = $this->edgeClient->call(
            EdgeClient::METHOD_GET,
            $path
        );
        $accounts = json_decode($response->getBody());

        // The last inserted account should be
        // the new one we just created
        return $accounts->lastRecord;
    }

    /**
     * Creates a workspace on CD.
     *
     * @param $accountId The CD account id this workspace will be under.
     * @param $name The name of the workspace.
     * @param $customFields An array of custom fields.
     * @param $orderId The FatTail order id.
     * @param $status The FatTail order status.
     * @param $startDate The FatTail campaign start date.
     * @param $endDate The FatTail campaign end date.
     *
     * @return TODO
     */
    protected
    function createCDWorkspace(
        $accountId,
        $name,
        $customFields = []
    ) {
        $details = new \stdClass();
        $details->workspaceName = $name;

        // Prepare data for request
        $path = 'accounts/' . $accountId . '/workspaces';
        $details->customFields = $this->createCDCustomFields($customFields);

        $httpResponse = $this->createCDEntity($path, $details);

        // Check if create wasn't successful
        if (
            $httpResponse == null ||
            $httpResponse->getStatusCode() !== 201
        ) {
            // TODO
        }

        // TODO on successful creation of workspace
        /*$response = $this->edgeClient->call(
            EdgeClient::METHOD_GET,
            $path
        );
        $workspaces = json_decode($response->getBody());

        return $workspaces->lastRecord;*/
        return $httpResponse; // Temporary
    }

    /**
     * Puts together data in the correct format for Edge API
     * milestone creations.
     *
     * @param $workspaceId The workspace id the milestone will be under.
     * @param $name The name of the milestone.
     * @param $description The description of the milestone.
     * @param $startDate The start date of the milestone.
     * @param $endDate The end date of the milestone.
     * @param $customFields An array of custom fields.
     */
    private
    function createCDMilestone(
        $workspaceId,
        $name,
        $description,
        $startDate,
        $endDate,
        $customFields
    ) {
        $details = new \stdClass();
        $details->title = $name;
        $details->description = $description;
        $details->startDate = $startDate;
        $details->endDate = $endDate;
        $details->customFields = $this->createCDCustomFields($customFields);

        // Create a new milestone
        $path = 'workspaces/' . $workspaceId . '/milestones';

        $httpResponse = $this->createCDEntity($path, $details);

        // Check if create wasn't successful
        if (
            $httpResponse == null ||
            $httpResponse->getStatusCode() !== 201
        ) {
            // TODO
        }

        // Call workspaces endpoint to get the latest hash
        $response = $this->edgeClient->call(
            EdgeClient::METHOD_GET,
            $path
        );
        $workspaces = json_decode($response->getBody());

        return $milestones->lastRecord;
    }

    /**
     * Converts a value into a value using only arrays.
     * Only works on public members.
     *
     * @param $thing The value to be converted.
     * @return The value using only arrays.
     */
    private
    function convertToArrays($thing) {

        return json_decode(json_encode($thing), true);
    }

    /**
     * Builds the custom fields for use with
     * the Edge API call.
     *
     * @param $customFields An array of custom field and value pairs.
     *
     * @return An array of objects representing custom fields.
     */
    private
    function createCDCustomFields($customFields) {

        $fields = [];
        foreach ($customFields as $name => $value) {
            $fields[] = $this->createCDCustomField($name, $value);
        }

        return $fields;
    }

    /**
     * Creates a custom field for use with Edge.
     */
    private
    function createCDCustomField($name, $value) {

        $item = new \stdClass();
        $item->fieldApiId = $name;
        $item->value = $value;

        return $item;
    }
}
