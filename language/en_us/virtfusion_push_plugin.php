<?php
// Plugin name
$lang['VirtfusionPush.name'] = 'VirtFusion Push';
$lang['VirtfusionPush.description'] = 'Allows clients to transfer VPS ownership between accounts';

// Navigation
$lang['VirtfusionPush.nav_primary_client.index'] = 'Transfer VPS';

// Tab
$lang['VirtfusionPush.tab_push'] = 'Transfer VPS';

// Admin settings
$lang['VirtfusionPush.admin.heading'] = 'VirtFusion Push Settings';
$lang['VirtfusionPush.admin.add_server'] = 'Add VirtFusion Server Configuration';
$lang['VirtfusionPush.admin.module_row'] = 'VirtFusion Server';
$lang['VirtfusionPush.admin.api_url'] = 'VirtFusion API URL';
$lang['VirtfusionPush.admin.api_token'] = 'VirtFusion API Token';
$lang['VirtfusionPush.admin.submit'] = 'Save Settings';
$lang['VirtfusionPush.admin.configured_servers'] = 'Configured Servers';
$lang['VirtfusionPush.admin.server_name'] = 'Server Name';
$lang['VirtfusionPush.admin.status'] = 'Status';
$lang['VirtfusionPush.admin.configured'] = 'Configured';
$lang['VirtfusionPush.admin.no_servers_configured'] = 'No servers configured yet. Please add a server configuration above.';

// Client index page
$lang['VirtfusionPush.client.index.heading'] = 'Transfer VPS';
$lang['VirtfusionPush.client.index.no_services'] = 'You have no VPS services available for transfer.';
$lang['VirtfusionPush.client.index.service_id'] = 'Service ID';
$lang['VirtfusionPush.client.index.package_name'] = 'Package';
$lang['VirtfusionPush.client.index.status'] = 'Status';
$lang['VirtfusionPush.client.index.actions'] = 'Actions';
$lang['VirtfusionPush.client.index.push_button'] = 'Transfer';

// Client push form
$lang['VirtfusionPush.client.push.heading'] = 'Transfer VPS';
$lang['VirtfusionPush.client.push.description'] = 'Transfer ownership of this VPS to another account. This action cannot be undone.';
$lang['VirtfusionPush.client.push.service_id'] = 'Service ID';
$lang['VirtfusionPush.client.push.recipient_email'] = 'Recipient Email Address';
$lang['VirtfusionPush.client.push.recipient_email_help'] = 'Enter the email address of the account that will receive this VPS.';
$lang['VirtfusionPush.client.push.submit'] = 'Transfer VPS';
$lang['VirtfusionPush.client.push.cancel'] = 'Cancel';

// Success messages
$lang['VirtfusionPush.success.settings_saved'] = 'Settings saved successfully';
$lang['VirtfusionPush.success.transfer_completed'] = 'VPS transferred successfully';

// Error messages
$lang['VirtfusionPush.error.invalid_email'] = 'Invalid email address';
$lang['VirtfusionPush.error.same_owner'] = 'Cannot transfer to the same owner';
$lang['VirtfusionPush.error.recipient_not_found'] = 'Recipient not found in system';
$lang['VirtfusionPush.error.service_not_found'] = 'Service not found';
$lang['VirtfusionPush.error.api_not_configured'] = 'API not configured';
$lang['VirtfusionPush.error.transfer_failed'] = 'Transfer failed';

// Admin logs
$lang['VirtfusionPush.admin.logs.heading'] = 'Activity Logs';
$lang['VirtfusionPush.admin.logs.view'] = 'View Log Details';
$lang['VirtfusionPush.admin.logs.no_logs'] = 'No logs found';
$lang['VirtfusionPush.admin.logs.filter'] = 'Filter Logs';
$lang['VirtfusionPush.admin.logs.clear_filters'] = 'Clear Filters';
