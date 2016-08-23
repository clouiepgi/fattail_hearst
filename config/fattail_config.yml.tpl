parameters:

  # Configure the FatTail settings here
  fattail.username:
  fattail.password:
  fattail.base_url: http://qa.fattail.com/abn/ws/adbookconnect.svc?singleWsdl
  fattail.report_name:
  fattail.api_namespace: http://www.FatTail.com/api
  fattail.api_version: 10
  fattail.overwrite: false #true will process every item (instead of only on change) and always overwrite data on FatTail

  # FatTail report generation timeout (in seconds)
  fattail.report_timeout: 300
  fattail.report_span: 1 # In years