
Central Desktop / FatTail AdBook Integration
============================================

About
-----

This integration starts by executing a FatTail saved report.
For each record:
1) A Central Desktop Account will be created for each FatTail Client
1) A Central Desktop Workspace will be created for each FatTail Order
1) A Central Desktop Milestone will be created for each FatTail Drop

References to the FatTail object will be retailed in Central Desktop and references to Central Desktop objects
will be stored in FatTail to minimize duplication.


Prerequisites
-------------

This integration requires that the "Account View" feature is enabled on your Central Desktop account.
 Please contact support@centraldesktop.com for more information on this topic.

### Custom Fields

You will need to create the following customer fields in the Central Desktop interface before continuing.

Account:
1. c_client_id

Workspace:
1. c_order_id
2. c_campaign_status
3. c_campaign_start_date
4. c_campaign_end_date

Milestone:
1. c_drop_id
2. c_custom_unit_features
3. c_kpi
4. c_drop_cost_new

All custom fields can have any name, but must be of type text and when entering the API ID
must not contain "c_" (this will get prepended automatically).

You can create these custom fields under "Company Setup" then the "Custom Fields" tab as the Company Admin.
Here you will add the above custom fields by adding property fields under the "Account Properties",
"Workspace Properties", "Milestone Properties" tabs.

Your generated FatTail report must also contain the following columns:
1. Client ID
2. Client Name
3. (Campaign) CD Workspace ID
4. Campaign ID
5. Campaign Name
6. IO Status
7. Campaign Start Date
8. Campaign End Date
9. Sales Rep
10. (Drop) CD Milestone ID
11. Position Path
12. Drop ID
13. Drop Description
14. (Drop) Custom Unit Features
15. (Drop) Line Item KPI
16. Start Date
17. End Date
18. Sold Amount

Please ensure that your FatTail account is also set up with the following:
1. Orders have a dynamic property named "H_CD_Workspace_ID" with type Text
2. Drops have a dynamic property name "H_CD_Milestone_ID" with type Text

Configure
---------
1) Copy cd_config.yml.tpl as cd_config.yml and fill in the settings.
2) Copy fattail_config.yml.tpl as fattail_config.yml and fill in the settings.


### cd.config.yml

To get your API client_id and private key, please navigate to Company Setup > Advanced > API.
Select a user that has the appropriate permissions to create accounts and workspaces.  You may want to create a new
user specifically for this purpose.

After clicking on "Create new Client ID," your browser will download a file with the necessary information to create a
trust relationship between this application and Central Desktop.

The client_id and the private_key that are required in cd.config.yml are found in this json file.


You'll need to use our API in order to fetch the workspace_template_hash as well as the sales_role_hash.


Run
---

This application manages its dependencies with Composer. For more information, including install instructions,
please go to https://getcomposer.org/


	composer install -o
	php bin/run.php sync
