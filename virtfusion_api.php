<?php
/**
 * VirtFusion API Library
 */
class VirtfusionApi
{
    private $api_url;
    private $api_token;
    private $last_error = null;
    private $last_http_code = null;
    private $last_response = null;

    public function __construct($api_url, $api_token)
    {
        // Ensure URL has protocol
        if (!preg_match('/^https?:\/\//', $api_url)) {
            $api_url = 'https://' . $api_url;
        }

        $this->api_url = rtrim($api_url, '/');
        $this->api_token = $api_token;
    }

    public function getLastError()
    {
        return $this->last_error;
    }

    public function getLastHttpCode()
    {
        return $this->last_http_code;
    }

    public function getLastResponse()
    {
        return $this->last_response;
    }

    private function request($endpoint, $method = 'GET', $data = null)
    {
        $url = $this->api_url . '/api/v1/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->api_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            return json_decode($response, true);
        }

        // Store both HTTP code and response for detailed error handling
        $this->last_error = "HTTP {$http_code}: " . $response;
        $this->last_http_code = $http_code;
        $this->last_response = json_decode($response, true);
        return false;
    }

    public function getServer($server_id)
    {
        return $this->request("servers/{$server_id}");
    }

    // getUserByEmail() method removed - VirtFusion API does not support GET /users
    // Users should be created directly, API will return error if user already exists

    public function createUser($user_data)
    {
        $result = $this->request("users", 'POST', $user_data);
        return $result;
    }

    public function userHasServers($user_id)
    {
        $result = $this->request("users/{$user_id}/servers");
        return $result && isset($result['data']) && count($result['data']) > 0;
    }

    public function transferServer($server_id, $new_user_id)
    {
        $result = $this->request("servers/{$server_id}/owner/{$new_user_id}", 'PUT');
        return $result;
    }

    public function getUserByExtRelationId($ext_relation_id)
    {
        $result = $this->request("users/{$ext_relation_id}/byExtRelation", 'GET');
        return $result && isset($result['data']) ? $result['data'] : false;
    }
}
