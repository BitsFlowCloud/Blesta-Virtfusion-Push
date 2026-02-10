<?php
// Load the parent controller
require_once dirname(__FILE__) . '/../virtfusion_push_controller.php';

/**
 * VirtFusion Push Admin Logs Controller
 */
class AdminLogs extends VirtfusionPushController
{
    /**
     * Setup
     */
    private function init()
    {
        // Load components
        $this->components(['Record']);

        // Load Pagination helper
        $this->helpers(['Pagination']);

        // Load logger model
        Loader::load(dirname(dirname(__FILE__)) . DS . 'models' . DS . 'virtfusion_push_logger.php');
        $this->logger = new VirtfusionPushLogger();

        // Load language
        Language::loadLang('virtfusion_push', null, PLUGINDIR . 'virtfusion_push' . DS . 'language' . DS);

        // Set the view to render for all actions under this controller
        $this->view->setView(null, 'VirtfusionPush.default');
    }

    /**
     * Display all logs (index page)
     */
    public function index()
    {
        $this->init();

        // Get plugin ID from URL
        $plugin_id = isset($this->get[0]) ? $this->get[0] : null;

        // Pagination
        $page = isset($this->get['p']) ? max(1, (int)$this->get['p']) : 1;
        $per_page = 10;

        // Get total count
        $total_transfers = $this->Record->select()
            ->from('virtfusion_push_transfers')
            ->numResults();

        // Get transfers with pagination
        $transfers = $this->Record->select()
            ->from('virtfusion_push_transfers')
            ->order(['created_at' => 'DESC'])
            ->limit($per_page, ($page - 1) * $per_page)
            ->fetchAll();

        // Build pagination
        $params = ['p' => $page];
        $pagination_uri = $this->base_uri . 'plugin/virtfusion_push/admin_logs/index/' . $plugin_id . '/';

        $this->Pagination->setSettings(Configure::get('Blesta.pagination_ajax'));
        $this->Pagination->setResults($total_transfers);
        $this->Pagination->setUri($pagination_uri, $params);

        // Set variables
        $this->set('transfers', $transfers);
        $this->set('plugin_id', $plugin_id);
        $this->set('total_transfers', $total_transfers);

        // Return the rendered view
        return $this->partial('admin_logs');
    }

    /**
     * View detailed log entry
     */
    public function view()
    {
        $this->init();

        // Get log ID from URL
        $log_id = isset($this->get[0]) ? (int)$this->get[0] : null;
        $plugin_id = isset($this->get[1]) ? $this->get[1] : null;

        if (!$log_id) {
            $this->redirect($this->base_uri . 'plugin/virtfusion_push/admin_logs/index/' . $plugin_id . '/');
            return;
        }

        // Get log entry
        $log = $this->Record->select()
            ->from('virtfusion_push_logs')
            ->where('id', '=', $log_id)
            ->fetch();

        if (!$log) {
            $this->redirect($this->base_uri . 'plugin/virtfusion_push/admin_logs/index/' . $plugin_id . '/');
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
