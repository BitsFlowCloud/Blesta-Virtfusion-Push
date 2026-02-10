<?php
/**
 * VirtFusion Push Logger
 * Handles logging for push operations
 */
class VirtfusionPushLogger extends VirtfusionPushModel
{
    /**
     * Log an action
     *
     * @param int $service_id Service ID
     * @param int $client_id Client ID
     * @param string $action Action name
     * @param string $message Log message
     * @param array $details Additional details (optional)
     * @param int $transfer_id Transfer ID (optional)
     */
    public function log($service_id, $client_id, $action, $message, $details = [], $transfer_id = null)
    {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

        $data = [
            'transfer_id' => $transfer_id,
            'service_id' => $service_id,
            'client_id' => $client_id,
            'action' => $action,
            'message' => $message,
            'details' => !empty($details) ? json_encode($details) : null,
            'ip_address' => $ip_address,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->Record->insert('virtfusion_push_logs', $data);

        // Also log to Blesta's module log if available
        $this->logToBlesta($client_id, $action, $message, $details);
    }

    /**
     * Log to Blesta's module log
     *
     * @param int $client_id Client ID
     * @param string $action Action name
     * @param string $message Log message
     * @param array $details Additional details
     */
    private function logToBlesta($client_id, $action, $message, $details)
    {
        try {
            Loader::loadModels($this, ['ModuleLogs']);

            $log_data = [
                'module_id' => null,
                'direction' => 'input',
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'data' => json_encode([
                    'action' => $action,
                    'message' => $message,
                    'details' => $details,
                    'client_id' => $client_id
                ]),
                'date_added' => date('Y-m-d H:i:s')
            ];

            $this->Record->insert('log_modules', $log_data);
        } catch (Exception $e) {
            // Silently fail if module log is not available
        }
    }

    /**
     * Get logs for a service
     *
     * @param int $service_id Service ID
     * @param int $limit Limit number of results
     * @return array Array of log entries
     */
    public function getServiceLogs($service_id, $limit = 50)
    {
        return $this->Record->select()
            ->from('virtfusion_push_logs')
            ->where('service_id', '=', $service_id)
            ->order(['created_at' => 'DESC'])
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * Get logs for a transfer
     *
     * @param int $transfer_id Transfer ID
     * @return array Array of log entries
     */
    public function getTransferLogs($transfer_id)
    {
        return $this->Record->select()
            ->from('virtfusion_push_logs')
            ->where('transfer_id', '=', $transfer_id)
            ->order(['created_at' => 'ASC'])
            ->fetchAll();
    }
}
