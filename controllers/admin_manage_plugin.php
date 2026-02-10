<?php
// Load the parent controller
require_once dirname(__FILE__) . '/../virtfusion_push_controller.php';

/**
 * VirtFusion Push Admin Management Controller
 */
class AdminManagePlugin extends VirtfusionPushController
{
    /**
     * Setup
     */
    private function init()
    {
        // Load components
        $this->components(['Record']);

        // Load language
        Language::loadLang('virtfusion_push', null, PLUGINDIR . 'virtfusion_push' . DS . 'language' . DS);

        // Set the view to render for all actions under this controller
        $this->view->setView(null, 'VirtfusionPush.default');
    }

    /**
     * Display settings form (index page)
     */
    public function index()
    {
        $this->init();

        // Get plugin ID from URL
        $plugin_id = isset($this->get[0]) ? $this->get[0] : null;

        // Handle form submission (POST request)
        $success_message = null;
        if (!empty($this->post)) {
            $success_message = $this->handleFormSubmit();
        }

        // Get all VirtFusion module rows
        $module_rows_raw = $this->Record->select(['module_rows.*'])
            ->from('module_rows')
            ->innerJoin('modules', 'modules.id', '=', 'module_rows.module_id', false)
            ->where('modules.class', '=', 'virtfusion_direct_provisioning')
            ->fetchAll();

        // Get all VirtFusion packages for selection
        $packages = $this->Record->select(['packages.id', 'package_names.name', 'packages.module_row'])
            ->from('packages')
            ->innerJoin('modules', 'modules.id', '=', 'packages.module_id', false)
            ->leftJoin('package_names', 'package_names.package_id', '=', 'packages.id', false)
            ->where('modules.class', '=', 'virtfusion_direct_provisioning')
            ->fetchAll();

        // Get all available currencies from Blesta
        $currencies = $this->Record->select(['code', 'format'])
            ->from('currencies')
            ->fetchAll();

        // Unserialize meta data for each module row
        $module_rows = [];
        foreach ($module_rows_raw as $row) {
            if (isset($row->meta) && is_string($row->meta)) {
                $row->meta = unserialize($row->meta);
            } elseif (!isset($row->meta)) {
                $row->meta = new stdClass();
            }
            $module_rows[] = $row;
        }

        // Get all existing settings
        $all_settings = $this->Record->select()
            ->from('virtfusion_push_settings')
            ->fetchAll();

        // Set variables for the view and return the rendered view
        return $this->partial('admin_manage_plugin', compact('module_rows', 'packages', 'all_settings', 'currencies', 'success_message', 'plugin_id'));
    }

    /**
     * Handle form submission
     *
     * @return string Success message or null
     */
    private function handleFormSubmit()
    {
        $module_row_id = $this->post['module_row_id'] ?? null;

        if (!$module_row_id) {
            return null;
        }

        // Get the module row to read API credentials
        $module_row = $this->Record->select()
            ->from('module_rows')
            ->where('id', '=', $module_row_id)
            ->fetch();

        if (!$module_row) {
            return null;
        }

        // Unserialize meta data to get API credentials
        $meta = unserialize($module_row->meta);

        // Extract API URL and Token from VirtFusion module configuration
        $api_url = $meta->hostname ?? '';
        $api_token = $meta->api_token ?? '';

        // Handle allowed_package_ids from checkbox array
        $allowed_package_ids = '';
        if (!empty($this->post['allowed_package_ids']) && is_array($this->post['allowed_package_ids'])) {
            $allowed_package_ids = implode(',', array_map('intval', $this->post['allowed_package_ids']));
        }

        // Get cooldown days and push price
        $push_cooldown_days = max(0, intval($this->post['push_cooldown_days'] ?? 0));
        $push_price = max(0, floatval($this->post['push_price'] ?? 0));
        $push_price_currency = $this->post['push_price_currency'] ?? 'USD';

        $data = [
            'api_url' => $api_url,
            'api_token' => $api_token,
            'enable_all' => isset($this->post['enable_all']) ? 1 : 0,
            'allowed_client_ids' => trim($this->post['allowed_client_ids'] ?? ''),
            'allowed_package_ids' => $allowed_package_ids,
            'push_cooldown_days' => $push_cooldown_days,
            'push_price' => $push_price,
            'push_price_currency' => $push_price_currency,
            'allow_all_packages' => isset($this->post['allow_all_packages']) ? 1 : 0
        ];

        // Check if settings exist for this module row
        $existing = $this->Record->select()
            ->from('virtfusion_push_settings')
            ->where('module_row_id', '=', $module_row_id)
            ->fetch();

        if ($existing) {
            // Update existing settings
            $this->Record->where('module_row_id', '=', $module_row_id)
                ->update('virtfusion_push_settings', $data);
        } else {
            // Insert new settings
            $data['module_row_id'] = $module_row_id;
            $this->Record->insert('virtfusion_push_settings', $data);
        }

        return Language::_('VirtfusionPush.success.settings_saved', true);
    }

    /**
     * Display all logs (logs page)
     */
    public function logs()
    {
        $this->init();

        // Get plugin ID from URL
        $plugin_id = isset($this->get[0]) ? $this->get[0] : null;

        // Pagination settings
        $page = isset($this->get['p']) ? (int)$this->get['p'] : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        // Filter parameters
        $filter_service_id = isset($this->get['service_id']) ? (int)$this->get['service_id'] : null;
        $filter_client_id = isset($this->get['client_id']) ? (int)$this->get['client_id'] : null;
        $filter_action = isset($this->get['action']) ? trim($this->get['action']) : null;
        $filter_transfer_id = isset($this->get['transfer_id']) ? (int)$this->get['transfer_id'] : null;

        // Build query
        $query = $this->Record->select(['virtfusion_push_logs.*'])
            ->from('virtfusion_push_logs');

        // Apply filters
        if ($filter_service_id) {
            $query->where('service_id', '=', $filter_service_id);
        }
        if ($filter_client_id) {
            $query->where('client_id', '=', $filter_client_id);
        }
        if ($filter_action) {
            $query->where('action', '=', $filter_action);
        }
        if ($filter_transfer_id) {
            $query->where('transfer_id', '=', $filter_transfer_id);
        }

        // Get total count for pagination
        $total_logs = $query->numResults();

        // Get logs with pagination
        $logs = $query->order(['created_at' => 'DESC'])
            ->limit($per_page)
            ->offset($offset)
            ->fetchAll();

        // Enrich logs with client and service information
        foreach ($logs as $log) {
            // Get client email
            $client = $this->Record->select(['contacts.email', 'contacts.first_name', 'contacts.last_name'])
                ->from('contacts')
                ->where('client_id', '=', $log->client_id)
                ->fetch();

            $log->client_email = $client ? $client->email : 'N/A';
            $log->client_name = $client ? trim($client->first_name . ' ' . $client->last_name) : 'N/A';

            // Get service package name
            $service = $this->Record->select(['packages.name'])
                ->from('services')
                ->leftJoin('package_pricing', 'package_pricing.pricing_id', '=', 'services.pricing_id', false)
                ->leftJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)
                ->where('services.id', '=', $log->service_id)
                ->fetch();

            $log->service_package = $service ? $service->name : 'N/A';

            // Decode details JSON
            $log->details_decoded = !empty($log->details) ? json_decode($log->details, true) : [];
        }

        // Calculate pagination
        $total_pages = ceil($total_logs / $per_page);

        // Get unique actions for filter dropdown
        $actions = $this->Record->select(['action'])
            ->from('virtfusion_push_logs')
            ->group(['action'])
            ->fetchAll();

        // Set variables for the view
        return $this->partial('admin_logs', compact(
            'logs',
            'plugin_id',
            'page',
            'total_pages',
            'total_logs',
            'per_page',
            'actions',
            'filter_service_id',
            'filter_client_id',
            'filter_action',
            'filter_transfer_id'
        ));
    }

    /**
     * View detailed log entry
     */
    public function viewlog()
    {
        $this->init();

        // Get log ID from URL
        $log_id = isset($this->get[0]) ? (int)$this->get[0] : null;
        $plugin_id = isset($this->get[1]) ? $this->get[1] : null;

        if (!$log_id) {
            $this->redirect($this->base_uri . 'settings/company/plugins/manage/' . $plugin_id . '/logs/');
            return;
        }

        // Get log entry
        $log = $this->Record->select()
            ->from('virtfusion_push_logs')
            ->where('id', '=', $log_id)
            ->fetch();

        if (!$log) {
            $this->redirect($this->base_uri . 'settings/company/plugins/manage/' . $plugin_id . '/logs/');
            return;
        }

        // Get client information
        $client = $this->Record->select(['contacts.email', 'contacts.first_name', 'contacts.last_name', 'clients.id_value'])
            ->from('contacts')
            ->innerJoin('clients', 'clients.id', '=', 'contacts.client_id', false)
            ->where('contacts.client_id', '=', $log->client_id)
            ->fetch();

        $log->client_email = $client ? $client->email : 'N/A';
        $log->client_name = $client ? trim($client->first_name . ' ' . $client->last_name) : 'N/A';
        $log->client_id_value = $client ? $client->id_value : 'N/A';

        // Get service information
        $service = $this->Record->select(['services.*', 'packages.name as package_name'])
            ->from('services')
            ->leftJoin('package_pricing', 'package_pricing.pricing_id', '=', 'services.pricing_id', false)
            ->leftJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)
            ->where('services.id', '=', $log->service_id)
            ->fetch();

        $log->service_package = $service ? $service->package_name : 'N/A';
        $log->service_status = $service ? $service->status : 'N/A';

        // Get transfer information if exists
        $transfer = null;
        if ($log->transfer_id) {
            $transfer = $this->Record->select()
                ->from('virtfusion_push_transfers')
                ->where('id', '=', $log->transfer_id)
                ->fetch();
        }

        // Decode details JSON
        $log->details_decoded = !empty($log->details) ? json_decode($log->details, true) : [];

        // Set variables for the view
        return $this->partial('admin_logs_view', compact('log', 'plugin_id', 'transfer'));
    }
}
