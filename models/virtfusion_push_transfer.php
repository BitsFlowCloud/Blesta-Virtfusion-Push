<?php
/**
 * VirtFusion Push Transfer Model
 * Handles all VPS transfer business logic
 */
class VirtfusionPushTransfer extends VirtfusionPushModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Load API library
        Loader::load(dirname(dirname(__FILE__)) . DS . 'virtfusion_api.php');
    }

    /**
     * Check if client has permission to use push feature
     *
     * @param int $client_id Client ID
     * @param int $module_row_id Module row ID (optional)
     * @return bool True if allowed, false otherwise
     */
    public function isClientAllowed($client_id, $module_row_id = null)
    {
        // Get client's id_value for permission checking
        $client_info = $this->Record->select(['id', 'id_value'])
            ->from('clients')
            ->where('id', '=', $client_id)
            ->fetch();

        $client_id_value = $client_info ? $client_info->id_value : $client_id;

        // Build query for settings
        $query = $this->Record->select()
            ->from('virtfusion_push_settings');

        // If module_row_id is provided, filter by it
        if ($module_row_id) {
            $query->where('module_row_id', '=', $module_row_id);
        }

        $settings_list = $query->fetchAll();

        if (empty($settings_list)) {
            return false;
        }

        // Check each setting
        foreach ($settings_list as $settings) {
            // If enable_all is true, allow all users
            if (!empty($settings->enable_all)) {
                return true;
            }

            // Check if client ID or id_value is in allowed list
            if (!empty($settings->allowed_client_ids)) {
                $allowed_ids = array_map('trim', explode(',', $settings->allowed_client_ids));
                if (in_array((string)$client_id, $allowed_ids) ||
                    in_array((string)$client_id_value, $allowed_ids)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Push VPS to another client
     *
     * @param int $service_id Service ID
     * @param string $recipient_email Recipient email
     * @param int $invoice_id Optional invoice ID if payment is required
     * @return array Result with success status and message
     */
    public function pushService($service_id, $recipient_email, $invoice_id = null)
    {
        // Validate service exists
        $service = $this->Services->get($service_id);
        if (!$service) {
            return ['success' => false, 'message' => 'Service not found'];
        }

        // Check if client has permission
        if (!$this->isClientAllowed($service->client_id)) {
            return ['success' => false, 'message' => 'You do not have permission to use this feature'];
        }

        // Get settings for this service's module row
        $settings = $this->getSettingsForService($service);
        if (!$settings) {
            return ['success' => false, 'message' => 'Push settings not found'];
        }

        // Check cooldown period
        $cooldown_check = $this->checkCooldownPeriod($service_id, $settings->push_cooldown_days);
        if (!$cooldown_check['allowed']) {
            return ['success' => false, 'message' => $cooldown_check['message']];
        }

        // Check if payment is required
        if ($settings->push_price > 0) {
            // If no invoice_id provided, return payment required
            if (!$invoice_id) {
                return [
                    'success' => false,
                    'payment_required' => true,
                    'price' => $settings->push_price,
                    'message' => 'Payment required for push operation'
                ];
            }

            // Verify invoice payment status
            $invoice_check = $this->checkInvoicePayment($invoice_id, $service->client_id, $settings->push_price);
            if (!$invoice_check['paid']) {
                return [
                    'success' => false,
                    'payment_required' => true,
                    'invoice_id' => $invoice_id,
                    'message' => $invoice_check['message']
                ];
            }
        }

        // Validate recipient email
        if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }

        // Get current client's email from contacts table
        $current_contact = $this->Record->select(['email'])
            ->from('contacts')
            ->where('client_id', '=', $service->client_id)
            ->fetch();

        // Check if pushing to same owner
        if ($current_contact && $current_contact->email === $recipient_email) {
            return ['success' => false, 'message' => 'Cannot transfer to same owner'];
        }

        // Verify recipient exists in Blesta via contacts table
        $recipient_contact = $this->Record->select(['client_id'])
            ->from('contacts')
            ->where('email', '=', $recipient_email)
            ->fetch();

        if (!$recipient_contact) {
            return ['success' => false, 'message' => 'Recipient not found in system'];
        }

        // Store the real database client ID (not the display id_value)
        $recipient_client_id = $recipient_contact->client_id;

        // Get recipient client object
        $recipient_client = $this->Clients->get($recipient_client_id);
        if (!$recipient_client) {
            return ['success' => false, 'message' => 'Recipient client not found'];
        }

        // Get current client object
        $current_client = $this->Clients->get($service->client_id);

        // Get API instance from service's package configuration
        $api = $this->getApiFromService($service);
        if (!$api) {
            return ['success' => false, 'message' => 'VirtFusion API configuration not found'];
        }

        // Get recipient contact info (email, name)
        $recipient_contact = $this->Record->select(['email', 'first_name', 'last_name'])
            ->from('contacts')
            ->where('client_id', '=', $recipient_client->id)
            ->fetch();

        if (!$recipient_contact) {
            return ['success' => false, 'message' => 'Recipient contact information not found'];
        }

        // Execute transfer
        return $this->executeTransfer($service, $current_client, $recipient_client, $recipient_contact, $api, $recipient_client_id, $invoice_id, $settings->push_price);
    }

    /**
     * Get API instance from service's package configuration
     *
     * @param object $service Service object
     * @return VirtfusionApi|false
     */
    private function getApiFromService($service)
    {
        // Get package information
        $package = $this->Record->select()
            ->from('packages')
            ->where('id', '=', $service->package->id)
            ->fetch();

        if (!$package || !$package->module_row) {
            return false;
        }

        // Use ModuleManager to get decrypted module row data
        $module_row = $this->ModuleManager->getRow($package->module_row);

        if (!$module_row || !isset($module_row->meta)) {
            return false;
        }

        // Extract API URL and Token from decrypted meta
        $api_url = $module_row->meta->hostname ?? '';
        $api_token = $module_row->meta->api_token ?? '';

        if (empty($api_url) || empty($api_token)) {
            return false;
        }

        return new VirtfusionApi($api_url, $api_token);
    }

    /**
     * Get VirtFusion server ID from service fields
     *
     * @param object $service Service object
     * @return int|false Server ID or false if not found
     */
    private function getVirtFusionServerId($service)
    {
        foreach ($service->fields as $field) {
            if (in_array($field->key, ['server_id', 'virtfusion_server_id', 'vps_id'])) {
                return (int)$field->value;
            }
        }
        return false;
    }

    /**
     * Execute the transfer process
     *
     * @param object $service Service object
     * @param object $current_client Current owner
     * @param object $recipient_client Recipient client
     * @param object $recipient_contact Recipient contact info
     * @param VirtfusionApi $api API instance
     * @param int $recipient_client_id Recipient's real database client ID
     * @param int|null $invoice_id Invoice ID if payment was required
     * @param float $push_price Price charged for push
     * @return array Result
     */
    private function executeTransfer($service, $current_client, $recipient_client, $recipient_contact, $api, $recipient_client_id, $invoice_id = null, $push_price = 0)
    {
        try {
            // Get VirtFusion server ID
            $vf_server_id = $this->getVirtFusionServerId($service);
            if (!$vf_server_id) {
                return ['success' => false, 'message' => 'VirtFusion server ID not found'];
            }

            // Get or create VirtFusion user for recipient
            // Follow VirtFusion official module logic
            $vf_recipient_id = null;

            // Step 1: Check if user exists by extRelationId (GET request)
            $vf_user = $api->getUserByExtRelationId($recipient_client_id);

            if ($vf_user && isset($vf_user['id'])) {
                // User already exists with this extRelationId
                $vf_recipient_id = $vf_user['id'];
            } else {
                // User not found by extRelationId (404), try to create
                $http_code = $api->getLastHttpCode();

                if ($http_code == 404) {
                    // Create new user with extRelationId
                    $full_name = trim(($recipient_contact->first_name ?? 'User') . ' ' . ($recipient_contact->last_name ?? 'Account'));

                    $create_result = $api->createUser([
                        'name' => $full_name,
                        'email' => $recipient_contact->email,
                        'extRelationId' => (int)$recipient_client_id,
                        'sendMail' => false
                    ]);

                    if ($create_result && isset($create_result['data']['id'])) {
                        $vf_recipient_id = $create_result['data']['id'];
                    } else {
                        // Creation failed
                        $create_error = $api->getLastError();
                        $create_http_code = $api->getLastHttpCode();

                        if ($create_http_code == 409) {
                            // User with this email already exists, try to find by scanning extRelationIds
                            $found_user = $this->findUserByEmailScan($api, $recipient_contact->email);

                            if ($found_user) {
                                $vf_recipient_id = $found_user['id'];
                            } else {
                                return [
                                    'success' => false,
                                    'message' => "User {$recipient_contact->email} already exists in VirtFusion but could not be found. Please contact administrator."
                                ];
                            }
                        } else {
                            return [
                                'success' => false,
                                'message' => 'Failed to create VirtFusion user. Error: ' . $create_error
                            ];
                        }
                    }
                } else {
                    // Other API error
                    return [
                        'success' => false,
                        'message' => 'Failed to query VirtFusion user. Error: ' . $api->getLastError()
                    ];
                }
            }

            // Transfer server in VirtFusion
            $transfer_result = $api->transferServer($vf_server_id, $vf_recipient_id);
            $already_owned = false;

            if (!$transfer_result) {
                $error_code = $api->getLastHttpCode();
                $error_msg = $api->getLastError();

                // 422 with "same as the existing owner" means already transferred
                if ($error_code == 422 && strpos($error_msg, 'same as the existing owner') !== false) {
                    $already_owned = true;
                    // Continue to update Blesta ownership
                } else {
                    // Check if transfer actually succeeded despite error response
                    // VirtFusion sometimes returns HTML error page even when transfer succeeds
                    // Continue anyway to update Blesta
                }
            }

            // Update service ownership in Blesta
            // Use direct database update for reliability
            $this->Record->where('id', '=', $service->id)
                ->update('services', ['client_id' => $recipient_client_id]);

            // Record transfer in database
            $this->recordTransfer($service, $current_client, $recipient_client_id, $recipient_contact, $vf_server_id, $invoice_id, $push_price);

            return [
                'success' => true,
                'message' => "VPS successfully transferred to {$recipient_contact->email}" . ($already_owned ? " (already owned in VirtFusion)" : "")
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Transfer failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get push price for a service
     *
     * @param int $service_id Service ID
     * @return float Push price
     */
    public function getPushPrice($service_id)
    {
        $service = $this->Services->get($service_id);
        if (!$service) {
            return 0;
        }

        // Get settings using the same logic as getSettingsForService
        $settings = $this->getSettingsForService($service);

        return $settings && isset($settings->push_price) ? (float)$settings->push_price : 0;
    }

    /**
     * Get push price currency for a service
     *
     * @param int $service_id Service ID
     * @return string Currency code
     */
    public function getPushPriceCurrency($service_id)
    {
        $service = $this->Services->get($service_id);
        if (!$service) {
            return 'GBP';
        }

        $settings = $this->getSettingsForService($service);

        return ($settings && isset($settings->push_price_currency)) ? $settings->push_price_currency : 'GBP';
    }

    /**
     * Create invoice for push operation with currency conversion
     *
     * @param int $client_id Client ID
     * @param int $service_id Service ID
     * @param float $amount Amount to charge in base currency
     * @param string $base_currency Base currency code (from settings)
     * @return int|false Invoice ID or false on failure
     */
    public function createPushInvoice($client_id, $service_id, $amount, $base_currency = 'USD')
    {
        if ($amount <= 0) {
            return false;
        }

        Loader::loadModels($this, ['Invoices', 'Currencies']);

        // Get service details for invoice description
        $service = $this->Services->get($service_id);
        if (!$service) {
            return false;
        }

        // Get client's default currency
        $client = $this->Clients->get($client_id);
        $client_currency = $client->settings['default_currency'] ?? 'USD';
        $company_id = Configure::get('Blesta.company_id');

        // Convert amount to client's currency if different from base currency
        $invoice_amount = $amount;
        if ($base_currency !== $client_currency) {
            // Use Blesta's currency conversion (requires 4 parameters: amount, from, to, company_id)
            $converted = $this->Currencies->convert($amount, $base_currency, $client_currency, $company_id);
            if ($converted !== false) {
                $invoice_amount = $converted;
            }
        }

        // Create invoice line item
        $line_items = [
            [
                'service_id' => $service_id,
                'description' => "VPS Push Service - Service #{$service_id}",
                'qty' => 1,
                'amount' => $invoice_amount,
                'tax' => false
            ]
        ];

        // Create invoice
        $invoice_data = [
            'client_id' => $client_id,
            'date_billed' => date('Y-m-d H:i:s'),
            'date_due' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'currency' => $client_currency,
            'status' => 'active',
            'lines' => $line_items
        ];

        $invoice_id = $this->Invoices->add($invoice_data);

        return $invoice_id ? $invoice_id : false;
    }

    /**
     * Get settings for a service
     *
     * @param object $service Service object
     * @return object|false Settings object or false
     */
    private function getSettingsForService($service)
    {
        // Get package information
        $package = $this->Record->select()
            ->from('packages')
            ->where('id', '=', $service->package->id)
            ->fetch();

        if (!$package || !$package->module_row) {
            return false;
        }

        // Get settings for this module row
        return $this->Record->select()
            ->from('virtfusion_push_settings')
            ->where('module_row_id', '=', $package->module_row)
            ->fetch();
    }

    /**
     * Check if service is within cooldown period
     *
     * @param int $service_id Service ID
     * @param int $cooldown_days Number of days for cooldown
     * @return array Result with 'allowed' boolean and 'message'
     */
    private function checkCooldownPeriod($service_id, $cooldown_days)
    {
        if ($cooldown_days <= 0) {
            return ['allowed' => true];
        }

        // Get last successful transfer for this service
        $last_transfer = $this->Record->select(['transferred_at'])
            ->from('virtfusion_push_transfers')
            ->where('service_id', '=', $service_id)
            ->where('status', '=', 'completed')
            ->order(['transferred_at' => 'DESC'])
            ->fetch();

        if (!$last_transfer || !$last_transfer->transferred_at) {
            return ['allowed' => true];
        }

        // Calculate time difference
        $last_transfer_time = strtotime($last_transfer->transferred_at);
        $cooldown_end = $last_transfer_time + ($cooldown_days * 86400);
        $now = time();

        if ($now < $cooldown_end) {
            $days_remaining = ceil(($cooldown_end - $now) / 86400);
            return [
                'allowed' => false,
                'message' => "This service was recently pushed. Please wait {$days_remaining} more day(s) before pushing again."
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Check invoice payment status
     *
     * @param int $invoice_id Invoice ID
     * @param int $client_id Client ID
     * @param float $expected_amount Expected amount
     * @return array Result with 'paid' boolean and 'message'
     */
    private function checkInvoicePayment($invoice_id, $client_id, $expected_amount)
    {
        $invoice = $this->Record->select(['id', 'client_id', 'total', 'paid', 'status'])
            ->from('invoices')
            ->where('id', '=', $invoice_id)
            ->fetch();

        if (!$invoice) {
            return ['paid' => false, 'message' => 'Invoice not found'];
        }

        if ($invoice->client_id != $client_id) {
            return ['paid' => false, 'message' => 'Invoice does not belong to this client'];
        }

        // Check if invoice is paid (status should be 'paid' OR paid amount >= expected amount)
        if ($invoice->status == 'paid' || ($invoice->paid >= $expected_amount)) {
            return ['paid' => true];
        }

        return [
            'paid' => false,
            'message' => 'Invoice payment pending. Please complete payment before continuing.'
        ];
    }

    /**
     * Record transfer in database
     *
     * @param object $service Service object
     * @param object $current_client Current client
     * @param int $recipient_client_id Recipient client ID
     * @param object $recipient_contact Recipient contact
     * @param int $vf_server_id VirtFusion server ID
     * @param int|null $invoice_id Invoice ID if payment was required
     * @param float $push_price Price charged for push
     */
    private function recordTransfer($service, $current_client, $recipient_client_id, $recipient_contact, $vf_server_id, $invoice_id, $push_price)
    {
        $current_contact = $this->Record->select(['email'])
            ->from('contacts')
            ->where('client_id', '=', $service->client_id)
            ->fetch();

        $data = [
            'service_id' => $service->id,
            'from_client_id' => $service->client_id,
            'to_client_id' => $recipient_client_id,
            'from_email' => $current_contact ? $current_contact->email : '',
            'to_email' => $recipient_contact->email,
            'virtfusion_server_id' => $vf_server_id,
            'status' => 'completed',
            'transferred_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'invoice_id' => $invoice_id,
            'push_price' => $push_price
        ];

        $this->Record->insert('virtfusion_push_transfers', $data);
        $transfer_id = $this->Record->lastInsertId();

        // Log the transfer event
        Loader::load(dirname(dirname(__FILE__)) . DS . 'models' . DS . 'virtfusion_push_logger.php');
        $logger = new VirtfusionPushLogger();

        $log_message = sprintf(
            'VPS #%d transferred from %s to %s',
            $service->id,
            $current_contact ? $current_contact->email : 'Unknown',
            $recipient_contact->email
        );

        $log_details = [
            'service_id' => $service->id,
            'from_client_id' => $service->client_id,
            'to_client_id' => $recipient_client_id,
            'virtfusion_server_id' => $vf_server_id,
            'invoice_id' => $invoice_id,
            'push_price' => $push_price
        ];

        $logger->log(
            $service->id,
            $service->client_id,
            'vps_transfer',
            $log_message,
            $log_details,
            $transfer_id
        );
    }

    /**
     * Find VirtFusion user by scanning extRelationIds and matching email
     *
     * @param VirtfusionApi $api API instance
     * @param string $target_email Target email to find
     * @return array|false User data if found, false otherwise
     */
    private function findUserByEmailScan($api, $target_email)
    {
        // Get all Blesta clients to scan their IDs
        $clients = $this->Record->select(['id'])
            ->from('clients')
            ->order(['id' => 'ASC'])
            ->fetchAll();

        if (empty($clients)) {
            return false;
        }

        // Scan through client IDs to find matching user
        foreach ($clients as $client) {
            $vf_user = $api->getUserByExtRelationId($client->id);

            if ($vf_user && isset($vf_user['email'])) {
                // Check if email matches (case-insensitive)
                if (strcasecmp($vf_user['email'], $target_email) === 0) {
                    return $vf_user;
                }
            }
        }

        return false;
    }
}
