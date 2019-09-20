<?php

require_once("cloudflare_helper.php");

class Cloudflare_Cpanel
{
    protected $actions = array(
        "fetchzone",
        "zone_set",
        "user_create"
    );

    protected $server = null;
    protected $logger = null;
    protected $response = null;
    protected $session_url = false;
    protected $cookie_jar = 'cookie.txt';

    public function __construct($server, $logger = null)
    {
        $this->server = $server;
        $this->logger = $logger;
    }

    protected function log($message)
    {
        if (!is_null($this->logger) && is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        } else {
            throw new Exception("Can't log!" . $message);
        }
    }

    public function getReponse()
    {
        return $this->response;
    }

    public function curl($action, $data = array())
    {
        if (!in_array($action, $this->actions)) {
            throw new Exception("Invalid action for Cpanel::curl");
        }

        // user_create requires a init call first, so a bit of recursion to make that happen
        if ($this->session_url === false) {
            if (!$this->login()) {
                return false;
            }
        }

        $this->log("WHMCS -> cPanel server: " . $action);

        $url = $this->session_url . "/json-api/cpanel";

        $args = array(
            "cpanel_jsonapi_version" => 2,
            "cpanel_jsonapi_module" => "CloudFlare",
            "cpanel_jsonapi_func" => $action,
            "user" => $this->server["account_username"],
            "homedir" => "/home/" . $this->server["account_username"]);
        $params = array_merge($args, $data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_jar);       // Set the cookie jar.
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_jar);      // Set the cookie file.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        $this->log("cPanel server -> WHMCS: " . $result);

        $this->response = json_decode($result, true);

        cloudflare_logApiCalls('cloudflare:cpanel:'.$action, array($url, $params), $this->response);

        return $this->response;
    }

    protected function login() {
        $ch = curl_init();

        $header = array();
        // use the access hash if we have it, otherwise use the password field
        if ($this->server["accesshash"]) {
            $header[0] = "Authorization: WHM {$this->server["username"]}:" . preg_replace("'(\r|\n)'","",$this->server["accesshash"]);
        } else {
            $header[0] = "Authorization: Basic " . base64_encode($this->server["username"].":".cloudflare_whmcs_decrypt_password($this->server["password"])) . "\n\r";
        }

        $cpanel_user = $this->server['account_username'];
        $url = "https://" . $this->server["ipaddress"] . ":2087/json-api/create_user_session?api.version=1&user=$cpanel_user&service=cpaneld";

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        // Execute POST query returning both the response headers and content into $page
        $result = curl_exec($ch);

        cloudflare_logApiCalls('cloudflare:cpanel:login', array($cpanel_user, $url), $result);

        $decoded_response = json_decode( $result, true );
        $this->session_url = $decoded_response['data']['url'];
        $this->cookie_jar = 'cookie.txt';

        curl_setopt($ch, CURLOPT_HTTPHEADER, array());                // Unset the authentication header.
        curl_setopt($ch, CURLOPT_COOKIESESSION, true);                // Initiate a new cookie session.
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_jar);       // Set the cookie jar.
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_jar);      // Set the cookie file.
        curl_setopt($ch, CURLOPT_URL, $this->session_url);            // Set the query url to the session login url.

        $result = curl_exec($ch);                               // Execute the session login call.
        cloudflare_logApiCalls('cloudflare:cpanel:login2', array($cpanel_user, $this->session_url), $result);

        $this->session_url = preg_replace( '{/login(?:/)??.*}', '', $this->session_url );  // make $session_url = https://10.0.0.1/$session_key
        curl_close($ch);

        return $this->session_url;
    }
}
