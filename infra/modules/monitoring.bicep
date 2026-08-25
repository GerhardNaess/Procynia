// Log Analytics workspace + (optional) workspace-based Application Insights.
//
// The workspace is the log sink for the Container Apps Environment: every
// container's stdout/stderr, plus revision/replica system logs, land here.
// Laravel is configured with LOG_CHANNEL=stderr so application logs follow the
// same path.
//
// No shared key is exported. The Container Apps Environment module resolves the
// key itself via an "existing" reference so it never travels through a
// deployment output.

@description('Log Analytics workspace name.')
param logAnalyticsWorkspaceName string

@description('Application Insights component name.')
param applicationInsightsName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('Log retention in days.')
@minValue(30)
@maxValue(730)
param retentionInDays int = 30

@description('Daily ingestion cap in GB. -1 disables the cap.')
param dailyQuotaGb int = -1

@description('Create a workspace-based Application Insights component. No application side instrumentation is wired up yet; the component exists for availability tests and future tracing.')
param deployApplicationInsights bool = true

resource workspace 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: logAnalyticsWorkspaceName
  location: location
  tags: tags
  properties: {
    sku: {
      name: 'PerGB2018'
    }
    retentionInDays: retentionInDays
    workspaceCapping: {
      dailyQuotaGb: dailyQuotaGb
    }
    publicNetworkAccessForIngestion: 'Enabled'
    publicNetworkAccessForQuery: 'Enabled'
    features: {
      enableLogAccessUsingOnlyResourcePermissions: true
    }
  }
}

resource applicationInsights 'Microsoft.Insights/components@2020-02-02' = if (deployApplicationInsights) {
  name: applicationInsightsName
  location: location
  tags: tags
  kind: 'web'
  properties: {
    Application_Type: 'web'
    WorkspaceResourceId: workspace.id
    IngestionMode: 'LogAnalytics'
    publicNetworkAccessForIngestion: 'Enabled'
    publicNetworkAccessForQuery: 'Enabled'
  }
}

@description('Log Analytics workspace resource id.')
output logAnalyticsWorkspaceId string = workspace.id

@description('Log Analytics workspace name.')
output logAnalyticsWorkspaceName string = workspace.name

@description('Application Insights resource id, or an empty string when not deployed.')
output applicationInsightsId string = deployApplicationInsights ? applicationInsights.id : ''

@description('Application Insights name, or an empty string when not deployed.')
output applicationInsightsName string = deployApplicationInsights ? applicationInsights.name : ''
