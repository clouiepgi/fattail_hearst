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

    protected $edgeClient = null;
    protected $fattailClient = null;

    private $PING_INTERVAL = 5; // In seconds
    private $DONE = 'done';

    public
    function __construct(
        EdgeClient $edgeClient,
        FatTailClient $fattailClient
    ) {
        $this->edgeClient = $edgeClient;
        $this->fattailClient = $fattailClient;
    }

    /**
     * Syncs Edge and FatTail.
     */
    public
    function sync() {

        // Get all saved reports
        $reportListResult = $this->fattailClient->call('GetSavedReportList');
        $reportList = $reportListResult->GetSavedReportListResult->SavedReport;

        // Iterate over report list
        foreach ($reportList as $report) {

            // Get individual report details
            /*$savedReportQuery = $this->fattailClient->call(
                'GetSavedReportQuery',
                [ 'savedReportId' => $report->SavedReportID ]
            )->getSavedReportQueryResult;

            // Get the report parameters
            $reportQuery = $savedReportQuery->ReportQuery;
            $reportQuery = ['ReportQuery' => $reportQuery];
            // Convert entirely to use only arrays, no objects
            $reportQueryFormatted = $this->convertToArrays($reportQuery);

            // Format them so they work with SOAP calls
            //$paramList = $this->formatReportParams($reportParams);

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

            $reportURLResult = $this->fattailClient->call(
                'GetReportDownloadUrl',
                ['reportJobId' => $reportJobId]
            );
            $reportURL = $reportURLResult->GetReportDownloadURLResult;

            // Download the file
            file_put_contents('tmp.csv', fopen($reportURL, 'r'));*/

            $reader = Reader::createFromPath('tmp.csv');
            $rows = $reader->fetchAll();

            // Create mapping of column name with column index
            // Not necessary, but makes it easier to work with the data
            $colMap = [];
            foreach ($rows[0] as $index => $name) {
                $colMap[$name] = $index;
            }

            // Iterate over CSV data extracting relevant parts
            $relevantData = [];
            for ($i = 1, $len = count($rows); $i < $len; $i++) {
                $row = $rows[$i];

                // Get client details to check external id
                $client = $this->fattailClient->call(
                    'GetClient',
                    ['clientId' => $row[$colMap['Client ID']]]
                )->GetClientResult;

                // Check client to account sync
                $accountHash = null;
                if ($client->ExternalID === "") {

                    $customFields = [
                        'c_client_id' => $row[$colMap['Client ID']]
                    ];
                    $accountHash = $this->createCDAccount(
                        $client->Name,
                        $customFields
                    );

                    // Update client external id with new account hash
                    $client->ExternalID = $accountHash;

                    // Update FatTail client
                    $clientArray = $this->convertToArrays($client);
                    // TODO Re-enable for later
                    /*$this->fattailClient->call(
                        'UpdateClient',
                        ['client' => $clientArray]
                    );*/
                }
                else {
                    // TODO Sync-ing
                }

                // Check campaign to workspace sync
                $workspaceHash = null;
                if ($rows[$i][$colMap['(Campaign) CD Workspace ID']] === "") {

                    $customFields = [
                        'c_order_id' => $rows[$i][$colMap['Campaign ID']],
                        'c_campaign_status' => $rows[$i][$colMap['IO Status']],
                        'c_campaign_start_date' => $rows[$i][$colMap['Campaign Start Date']],
                        'c_campaign_end_date' => $rows[$i][$colMap['Campaign End Date']]
                    ];
                    $workspaceHash = $this->createCDWorkspace(
                        $accountHash,
                        $rows[$i][$colMap['Campaign Name']]
                    );

                    // Gets sales rep information
                }
                else {
                    // TODO Sync-ing
                }
                exit;

                // Check drop to milestone sync
                if ($rows[$i][$colMap['(Drop) CD Milestone ID']] === "") {

                    $customFields = [
                        'c_drop_id' => $rows[$i][$colMap['Drop ID']],
                        'c_custom_unit_features' => $rows[$i][$colMap['(Drop) Custom Unit Features']],
                        'c_kpi' => $rows[$i][$colMap['(Drop) Line Item KPI']],
                        'c_drop_cost_new' => $rows[$i][$colMap['Sold Amount']]
                    ];
                    $milestoneHash = $this->createCDMilestone(
                        $workspaceHash,
                        $rows[$i][$colMap['Position Path']],
                        $rows[$i][$colMap['Drop Description']],
                        $rows[$i][$colMap['Start Date']],
                        $rows[$i][$colMap['End Date']],
                        $customFields
                    );
                }
                else {
                    // TODO Sync-ing
                }
            }
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
                EdgeClient::$METHOD_POST,
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
            EdgeClient::$METHOD_GET,
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
            EdgeClient::$METHOD_GET,
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
            EdgeClient::$METHOD_GET,
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
