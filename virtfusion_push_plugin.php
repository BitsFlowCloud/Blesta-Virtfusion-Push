<?php
/**
 * VirtFusion Push Plugin
 * Allows clients to transfer VPS ownership between accounts
 */
class VirtfusionPushPlugin extends Plugin
{
    /**
     * Initialize the plugin
     */
    public function __construct()
    {
        // Load components
        Loader::loadComponents($this, ['Input', 'Record']);

        // Load language file - use plugin class name prefix
        Language::loadLang('virtfusion_push_plugin', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load configuration from config.json
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    /**
     * Returns all actions to be configured for this widget
     * (invoked after install() or upgrade())
     *
     * @return array A numerically indexed array containing action arrays
     */
    public function getActions()
    {
        return [
            [
                'action' => 'nav_primary_client',
                'uri' => 'plugin/virtfusion_push/client_main/index/',
                'name' => 'VirtfusionPush.nav_primary_client.index'
            ]
        ];
    }

    /**
     * Returns the manage page for this plugin
     *
     * @param array $vars An array of post data
     * @return string HTML content
     */
    public function getManageWidget(array &$vars = [])
    {
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Handle form submission
        if (!empty($vars)) {
            $module_row_id = $vars['module_row_id'] ?? null;

            if ($module_row_id) {
                // Get the module row to read API credentials
                $module_row = $this->Record->select()
                    ->from('module_rows')
                    ->where('id', '=', $module_row_id)
                    ->fetch();

                if ($module_row) {
                    // Unserialize meta data to get API credentials
                    $meta = unserialize($module_row->meta);

                    // Extract API URL and Token from VirtFusion module configuration
                    $api_url = $meta->hostname ?? '';
                    $api_token = $meta->api_token ?? '';

                    // Check if settings exist for this module row
                    $existing = $this->Record->select()
                        ->from('virtfusion_push_settings')
                        ->where('module_row_id', '=', $module_row_id)
                        ->fetch();

                    // Handle allowed_package_ids from checkbox array
                    $allowed_package_ids = '';
                    if (!empty($vars['allowed_package_ids']) && is_array($vars['allowed_package_ids'])) {
                        $allowed_package_ids = implode(',', array_map('intval', $vars['allowed_package_ids']));
                    }

                    // Get cooldown days and push price
                    $push_cooldown_days = max(0, intval($vars['push_cooldown_days'] ?? 0));
                    $push_price = max(0, floatval($vars['push_price'] ?? 0));

                    $data = [
                        'api_url' => $api_url,
                        'api_token' => $api_token,
                        'enable_all' => isset($vars['enable_all']) ? 1 : 0,
                        'allowed_client_ids' => $vars['allowed_client_ids'] ?? '',
                        'allowed_package_ids' => $allowed_package_ids,
                        'push_cooldown_days' => $push_cooldown_days,
                        'push_price' => $push_price
                    ];

                    if ($existing) {
                        // Update existing settings
                        $this->Record->where('module_row_id', '=', $module_row_id)
                            ->update('virtfusion_push_settings', $data);
                    } else {
                        // Insert new settings
                        $data['module_row_id'] = $module_row_id;
                        $this->Record->insert('virtfusion_push_settings', $data);
                    }

                    $vars['success_message'] = 'Settings saved successfully!';
                }
            }
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

        // Get plugin ID from database
        $plugin = $this->Record->select()
            ->from('plugins')
            ->where('dir', '=', 'virtfusion_push')
            ->fetch();
        $plugin_id = $plugin ? $plugin->id : null;

        // Load the view into this object
        $this->view = $this->makeView('admin_manage_plugin', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Set variables for the view
        $this->view->set('module_rows', $module_rows);
        $this->view->set('packages', $packages);
        $this->view->set('all_settings', $all_settings);
        $this->view->set('plugin_id', $plugin_id);
        $this->view->set('vars', $vars);

        return $this->view->fetch();
    }

    /**
     * Install the plugin
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        try {
            $sql = file_get_contents(dirname(__FILE__) . DS . 'install' . DS . 'install.sql');

            // Split SQL statements and execute one by one
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt);
                }
            );

            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $this->Record->query($statement);
                }
            }
        } catch (Exception $e) {
            $this->Input->setErrors(['install' => ['error' => $e->getMessage()]]);
        }
    }

    /**
     * Uninstall the plugin
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if this is the last instance of the plugin
     */
    public function uninstall($plugin_id, $last_instance)
    {
        if ($last_instance) {
            try {
                $sql = file_get_contents(dirname(__FILE__) . DS . 'install' . DS . 'uninstall.sql');

                // Split SQL statements and execute one by one
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    function($stmt) {
                        return !empty($stmt);
                    }
                );

                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        $this->Record->query($statement);
                    }
                }
            } catch (Exception $e) {
                $this->Input->setErrors(['uninstall' => ['error' => $e->getMessage()]]);
            }
        }
    }

    /**
     * Returns all events to be registered for this plugin
     *
     * @return array A numerically indexed array containing event arrays
     */
    public function getEvents()
    {
        return [
            [
                'event' => 'Services.add',
                'callback' => ['this', 'logServiceEvent']
            ],
            [
                'event' => 'Services.edit',
                'callback' => ['this', 'logServiceEvent']
            ]
        ];
    }

    /**
     * Log service events
     *
     * @param EventObject $event The event object
     */
    public function logServiceEvent($event)
    {
        // This is a placeholder for event logging
        // Actual push logging is handled in the transfer model
    }

    /**
     * Upgrade the plugin
     *
     * @param string $current_version The current installed version
     * @param int $plugin_id The ID of the plugin being upgraded
     */
    public function upgrade($current_version, $plugin_id)
    {
        // No upgrade logic needed yet
    }
}
