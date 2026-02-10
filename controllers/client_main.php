<?php
/**
 * VirtFusion Push Client Controller
 */
class ClientMain extends VirtfusionPushController
{
    /**
     * Setup
     */
    public function preAction()
    {
        parent::preAction();

        $this->structure->set('page_title', Language::_('ClientMain.index.page_title', true));

        $this->uses(['Record', 'Services', 'ModuleManager']);

        // Load VirtFusion API class
        Loader::load(dirname(__FILE__) . DS . '..' . DS . 'virtfusion_api.php');
    }

    /**
     * Index page - List client's VPS services
     */
    public function index()
    {
        // DEBUG: Test if logging works
        error_log("DEBUG index: Method called - logging works!");

        // Get client ID from session
        $session_client_id = $this->Session->read('blesta_client_id');

        if (!$session_client_id) {
            $this->redirect($this->base_uri);
            return;
        }

        // Check if this is the real client ID or id_value
        // First try to use it directly
        $client_check = $this->Record->select(['id'])
            ->from('clients')
            ->where('id', '=', $session_client_id)
            ->fetch();

        if ($client_check) {
            // It's a real client ID
            $client_id = $session_client_id;
        } else {
            // It might be id_value, try to find the real ID
            $real_client = $this->Record->select(['id'])
                ->from('clients')
                ->where('id_value', '=', $session_client_id)
                ->fetch();

            if ($real_client) {
                $client_id = $real_client->id;
            } else {
                // Can't find client, redirect
                $this->redirect($this->base_uri);
                return;
            }
        }

        // Get client's id_value for permission checking
        $client_info = $this->Record->select(['id', 'id_value'])
            ->from('clients')
            ->where('id', '=', $client_id)
            ->fetch();

        $client_id_value = $client_info ? $client_info->id_value : $client_id;

        // Get all active services for this client
        $services_raw = $this->Record->select(['services.*'])
            ->from('services')
            ->where('services.client_id', '=', $client_id)
            ->where('services.status', '=', 'active')
            ->fetchAll();

        // Get all VirtFusion push settings once (optimization)
        $all_settings = $this->Record->select()
            ->from('virtfusion_push_settings')
            ->fetchAll();

        // Create a map of module_row_id => settings for quick lookup
        $settings_map = [];
        foreach ($all_settings as $setting) {
            $settings_map[$setting->module_row_id] = $setting;
        }

        // Add package_name to services using Services model
        $services = [];
        foreach ($services_raw as $service) {
            // Get full service details with correct package name from Services model
            try {
                $service_full = $this->Services->get($service->id);
                $service->package_name = $service_full->package->name ?? 'N/A';
            } catch (Exception $e) {
                // If Services->get() fails, use a default name
                $service->package_name = 'Unknown Package';
            }
            $services[] = $service;
        }

        // Check which services are allowed for push
        $allowed_services = [];
        foreach ($services as $service) {
            // Get package_id from service
            $package_id = $this->Record->select(['package_id'])
                ->from('package_pricing')
                ->where('pricing_id', '=', $service->pricing_id)
                ->fetch();

            // Handle missing package_id (common for manually created services)
            $has_package_id = ($package_id && isset($package_id->package_id));
            if (!$has_package_id) {
                $package_id = (object)['package_id' => null];
            }

            // Get settings from the map (already fetched earlier)
            $settings = $settings_map[$service->module_row_id] ?? null;

            if ($settings) {
                $is_allowed = false;

                if (!empty($settings->enable_all)) {
                    $is_allowed = true;
                } elseif (!empty($settings->allowed_client_ids)) {
                    $allowed_ids = array_map('trim', explode(',', $settings->allowed_client_ids));
                    // Check both id and id_value for compatibility
                    $is_allowed = in_array((string)$client_id, $allowed_ids) ||
                                  in_array((string)$client_id_value, $allowed_ids);
                }

                // Check package filter
                // If package_id is missing AND allow_all_packages is enabled, allow it
                if ($is_allowed && !$has_package_id && $settings->allow_all_packages) {
                    // Service has no package_id, but all packages are allowed - keep allowed
                } elseif ($is_allowed && $has_package_id && !$settings->allow_all_packages && !empty($settings->allowed_package_ids)) {
                    // Service has package_id, and specific packages are restricted
                    $allowed_package_ids = array_map('trim', explode(',', $settings->allowed_package_ids));
                    if (!in_array((string)$package_id->package_id, $allowed_package_ids)) {
                        $is_allowed = false;
                    }
                }

                if ($is_allowed) {
                    // Get service fields for detailed info
                    $service_fields = $this->Record->select(['key', 'value'])
                        ->from('service_fields')
                        ->where('service_id', '=', $service->id)
                        ->fetchAll();

                    $fields = [];
                    foreach ($service_fields as $field) {
                        $fields[$field->key] = $field->value;
                    }

                    // Get VirtFusion server ID from service fields
                    $virtfusion_server_id = $fields['server_id'] ?? null;

                    // Get API credentials using ModuleManager (handles decryption automatically)
                    $module_row = $this->ModuleManager->getRow($service->module_row_id);

                    $api_url = null;
                    $api_token = null;

                    if ($module_row && isset($module_row->meta)) {
                        $api_url = $module_row->meta->hostname ?? null;
                        $api_token = $module_row->meta->api_token ?? null;
                    }

                    // Fetch detailed server info from VirtFusion API
                    if ($virtfusion_server_id && $api_url && $api_token) {
                        try {
                            $api = new VirtfusionApi($api_url, $api_token);
                            $server_info = $api->getServer($virtfusion_server_id);

                            if ($server_info && isset($server_info['data'])) {
                                $server_data = $server_info['data'];

                                // Extract server details from settings.resources
                                if (isset($server_data['settings']['resources'])) {
                                    $resources = $server_data['settings']['resources'];
                                    $fields['virtfusion_cpu'] = $resources['cpuCores'] ?? 'N/A';
                                    $fields['virtfusion_memory'] = $resources['memory'] ?? 'N/A';
                                    $fields['virtfusion_disk'] = $resources['storage'] ?? 'N/A';
                                    $fields['virtfusion_traffic'] = $resources['traffic'] ?? 'N/A';
                                }

                                // Extract bandwidth from network.interfaces[0].outAverage (kB/s)
                                if (isset($server_data['network']['interfaces'][0]['outAverage'])) {
                                    $bandwidth_kbps = $server_data['network']['interfaces'][0]['outAverage'];
                                    // Convert kB/s to Mbps or Gbps
                                    // 1 Mbps = 125 kB/s, 1 Gbps = 125000 kB/s
                                    if ($bandwidth_kbps >= 125000) {
                                        $fields['virtfusion_bandwidth'] = round($bandwidth_kbps / 125000, 2) . ' Gbps';
                                    } else {
                                        $fields['virtfusion_bandwidth'] = round($bandwidth_kbps / 125, 2) . ' Mbps';
                                    }
                                } else {
                                    $fields['virtfusion_bandwidth'] = 'N/A';
                                }
                            }
                        } catch (Exception $e) {
                            // Silently fail - API call failed
                        }
                    }

                    $service->fields = $fields;

                    // Get expiration date
                    $service->date_renews_formatted = $service->date_renews ?
                        date('Y-m-d H:i:s', strtotime($service->date_renews)) : 'N/A';

                    $allowed_services[] = $service;
                }
            }
        }

        $this->set('services', $allowed_services);
        $this->set('client_id', $client_id);
    }

    /**
     * Push service to another client (Simplified single-page workflow)
     */
    public function push()
    {
        // Get client ID from session
        $session_client_id = $this->Session->read('blesta_client_id');
        if (!$session_client_id) {
            $this->redirect($this->base_uri);
            return;
        }

        $client_id = $this->getClientId($session_client_id);
        if (!$client_id) {
            $this->redirect($this->base_uri);
            return;
        }

        // Get service ID from URL
        $service_id = $this->get[0] ?? null;
        if (!$service_id) {
            $this->redirect($this->base_uri . 'plugin/virtfusion_push/client_main/index/');
            return;
        }

        // Verify service exists and belongs to client
        $service = $this->Services->get($service_id);
        if (!$service || $service->client_id != $client_id) {
            $this->redirect($this->base_uri . 'plugin/virtfusion_push/client_main/index/');
            return;
        }

        $this->set('service', $service);

        // Load transfer model
        Loader::load(dirname(dirname(__FILE__)) . DS . 'virtfusion_push_model.php');
        Loader::load(dirname(dirname(__FILE__)) . DS . 'models' . DS . 'virtfusion_push_transfer.php');
        $transfer_model = new VirtfusionPushTransfer();

        // Get push price and currency from model
        $push_price = $transfer_model->getPushPrice($service_id);
        $push_price_currency = $transfer_model->getPushPriceCurrency($service_id);

        $this->set('push_price', $push_price);
        $this->set('push_price_currency', $push_price_currency);

        // Check for existing invoice in session
        $session_invoice_id = $this->Session->read('push_invoice_id_' . $service_id);

        // Handle form submission
        if (!empty($this->post)) {
            $action = $this->post['action'] ?? 'push';
            $recipient_email = trim($this->post['recipient_email'] ?? '');
            $invoice_id = $this->post['invoice_id'] ?? $session_invoice_id;

            // Action: Check Payment Status
            if ($action === 'check_payment') {
                if ($invoice_id) {
                    $invoice = $this->Record->select(['id', 'status', 'paid', 'total'])
                        ->from('invoices')
                        ->where('id', '=', $invoice_id)
                        ->fetch();

                    if ($invoice) {
                        $is_paid = ($invoice->status == 'paid') ||
                                   ($invoice->status == 'active' && $invoice->paid >= $invoice->total);

                        if ($is_paid) {
                            // Payment confirmed! Don't clear session, mark as confirmed
                            $this->set('payment_confirmed', true);
                            $this->set('invoice_id', $invoice_id); // Keep invoice_id for subsequent push
                            // Preserve recipient email if it was entered
                            if (!empty($recipient_email)) {
                                $this->set('recipient_email', $recipient_email);
                            }
                        } else {
                            $this->set('payment_pending', true);
                            $this->set('invoice_id', $invoice_id);
                            $this->set('payment_required', true);
                            $this->set('message', 'Payment is still pending. Please complete the payment.');
                            // Preserve recipient email if it was entered
                            if (!empty($recipient_email)) {
                                $this->set('recipient_email', $recipient_email);
                            }
                        }
                    } else {
                        $this->Session->clear('push_invoice_id_' . $service_id);
                        $this->set('error', 'Invoice not found.');
                    }
                }
            }
            // Action: Execute Push
            elseif ($action === 'push') {
                // Validate email
                if (empty($recipient_email) || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
                    $this->set('error', 'Please enter a valid recipient email address.');
                } else {
                    // Check if payment is required
                    if ($push_price > 0) {
                        // Check if invoice exists and is paid
                        if ($invoice_id) {
                            $invoice = $this->Record->select(['id', 'status', 'paid', 'total'])
                                ->from('invoices')
                                ->where('id', '=', $invoice_id)
                                ->fetch();

                            // Check if invoice is voided or invalid
                            if (!$invoice || in_array($invoice->status, ['void', 'voided', 'canceled', 'cancelled'])) {
                                // Invoice is voided/canceled/missing, clear session and create new one
                                $this->Session->clear('push_invoice_id_' . $service_id);
                                $invoice_id = $transfer_model->createPushInvoice($client_id, $service_id, $push_price, $push_price_currency);
                                if ($invoice_id) {
                                    $this->Session->write('push_invoice_id_' . $service_id, $invoice_id);
                                    $this->set('payment_required', true);
                                    $this->set('invoice_id', $invoice_id);
                                    $this->set('message', 'Previous invoice was voided. A new invoice has been created.');
                                } else {
                                    $this->set('error', 'Failed to create invoice. Please contact administrator.');
                                }
                            } else {
                                $is_paid = (
                                    ($invoice->status == 'paid') ||
                                    ($invoice->status == 'active' && $invoice->paid >= $invoice->total)
                                );

                                if (!$is_paid) {
                                    // Invoice exists but not paid
                                    $this->set('payment_required', true);
                                    $this->set('invoice_id', $invoice_id);
                                    $this->set('error', 'Payment is required. Please pay the invoice before continuing.');
                                } else {
                                    // Invoice is paid, execute push
                                    $result = $transfer_model->pushService($service_id, $recipient_email, $invoice_id);
                                    $this->handlePushResult($result, $service_id, $client_id, $recipient_email);
                                }
                            }
                        } else {
                            // No invoice exists, create one
                            $invoice_id = $transfer_model->createPushInvoice($client_id, $service_id, $push_price, $push_price_currency);
                            if ($invoice_id) {
                                $this->Session->write('push_invoice_id_' . $service_id, $invoice_id);
                                $this->set('payment_required', true);
                                $this->set('invoice_id', $invoice_id);
                                $this->set('message', 'Payment is required. Please pay the invoice to continue.');
                            } else {
                                $this->set('error', 'Failed to create invoice. Please contact administrator.');
                            }
                        }
                    } else {
                        // No payment required, execute push immediately
                        $result = $transfer_model->pushService($service_id, $recipient_email, null);
                        $this->handlePushResult($result, $service_id, $client_id, $recipient_email);
                    }
                }
            }
        }

        // Check if there's an existing invoice in session (for page load)
        if ($session_invoice_id && empty($this->post)) {
            $invoice = $this->Record->select(['id', 'status', 'paid', 'total'])
                ->from('invoices')
                ->where('id', '=', $session_invoice_id)
                ->fetch();

            if ($invoice) {
                // Check if invoice is voided or canceled
                if (in_array($invoice->status, ['void', 'voided', 'canceled', 'cancelled'])) {
                    // Invoice is voided/canceled, clear session and allow new invoice creation
                    $this->Session->clear('push_invoice_id_' . $service_id);
                } else {
                    $is_paid = ($invoice->status == 'paid') ||
                               ($invoice->status == 'active' && $invoice->paid >= $invoice->total);

                    if (!$is_paid) {
                        $this->set('payment_required', true);
                        $this->set('invoice_id', $session_invoice_id);
                    } else {
                        // Invoice is paid - keep it in session for push execution
                        $this->set('invoice_id', $session_invoice_id);
                        $this->set('payment_confirmed', true);
                        $this->set('message', 'Payment confirmed! Enter recipient email and click "Push Service" to complete.');
                    }
                }
            } else {
                // Invoice doesn't exist, clear session
                $this->Session->clear('push_invoice_id_' . $service_id);
            }
        }
    }

    /**
     * Handle push result and set appropriate view variables
     */
    private function handlePushResult($result, $service_id, $client_id, $recipient_email)
    {
        $this->set('transfer_result', $result);
        $this->set('transfer_message', $result['message']);
        $this->set('transfer_success', $result['success']);

        if ($result['success']) {
            // Clear invoice session on success
            $this->Session->clear('push_invoice_id_' . $service_id);

            // Log the push action
            $this->logPushAction($service_id, $client_id, $recipient_email, $result);
        }
    }

    /**
     * Helper: Get real client ID from session
     */
    private function getClientId($session_client_id)
    {
        $client_check = $this->Record->select(['id'])
            ->from('clients')
            ->where('id', '=', $session_client_id)
            ->fetch();

        if ($client_check) {
            return $session_client_id;
        }

        $real_client = $this->Record->select(['id'])
            ->from('clients')
            ->where('id_value', '=', $session_client_id)
            ->fetch();

        return $real_client ? $real_client->id : null;
    }

    /**
     * Log push action to Blesta logs
     */
    private function logPushAction($service_id, $client_id, $recipient_email, $result)
    {
        // Use VirtFusion Push Logger instead of direct database insert
        Loader::load(dirname(dirname(__FILE__)) . DS . 'models' . DS . 'virtfusion_push_logger.php');
        $logger = new VirtfusionPushLogger();

        $log_message = sprintf(
            'Push transfer %s - Service #%d to %s',
            $result['success'] ? 'succeeded' : 'failed',
            $service_id,
            $recipient_email
        );

        $log_details = [
            'service_id' => $service_id,
            'client_id' => $client_id,
            'recipient_email' => $recipient_email,
            'success' => $result['success'],
            'message' => $result['message'],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $logger->log(
            $service_id,
            $client_id,
            'push_transfer',
            $log_message,
            $log_details
        );
    }
}
