
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

@TODO Fill in custom fields.
@TODO Move custom fields mappings to yml.

Configure
---------
1) Copy cd_config.yml.tpl as cd_config.yml and fill in the settings.
1) Copy fattail_config.yml.tpl as fattail_config.yml and fill in the settings.


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
