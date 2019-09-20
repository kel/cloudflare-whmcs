<?php
require_once("cloudflare_helper.php");

class Cloudflare_Hostapi
{
    private $response = null; //where we store the most current response

    public function getResponse() {
        return $this->response;
    }

    function user_create($email, $passwd)
    { //create a new user
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $params = array('cloudflare_email' => $email, 'cloudflare_pass' => $passwd);

        if ($this->_call_api('user_create', $params)) {
            return $this->response['response']; //return the response of the response
        } else {
            return $this->response['msg'];
        }
    }

    function zone_set($user_key, $zone_name, $resolve_to, $subdomains)
    { //set or edit a zone
        $params = array('user_key' => $user_key, 'zone_name' => $zone_name, 'resolve_to' => $resolve_to, 'subdomains' => $subdomains);

        $this->_call_api('zone_set', $params);

        return $this->response['msg'];
    }

    function zone_set_full($user_key, $zone_name)
    {
        $params = array('user_key' => $user_key, 'zone_name' => $zone_name, 'jumpstart' => 1);
        if ($this->_call_api('full_zone_set', $params)) {
            return $this->getNameservers($this->response["response"]["msg"]);
        } else {
            return $this->response['msg'];
        }
    }

    private function getNameservers($message)
    {
        $tokens      = preg_split("/[\s,\s]/", $message);
        $nameservers = array();

        foreach ($tokens as $token) {
            if (preg_match("/ns.cloudflare.com/", $token))
                $nameservers[] = $token;
        }

        return $nameservers;
    }


    function user_lookup($email = null, $unique = null)
    { //lookup for a user to get host_key
        if (isset($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) { //make sure the email is vaild
            $params['cloudflare_email'] = $email;
        } elseif (isset($unique)) { //if the email is not vaild lets hope they have a unique
            $params['unique'] = $unique;
        } else { //oh well game over
            return null;
        }

        if ($this->_call_api('user_lookup', $params)) {
            return $this->response['response']; //return the response of the response
        } else {
            return false;
        }
    }

    function zone_lookup($user_key, $zone_name)
    {
        $params = array('user_key' => $user_key, 'zone_name' => $zone_name);
        if ($this->_call_api('zone_lookup', $params)) {
            return $this->response['response']; //return the response of the response
        } else {
            return false;
        }
    }

    function zone_delete($user_key, $zone_name)
    {
        $params = array('user_key' => $user_key, 'zone_name' => $zone_name);
        if ($this->_call_api('zone_delete', $params)) {
            return $this->response['response'];
        } else {
            return $this->response['msg'];
        }
    }

    function zone_list($zone_name)
    {
        $params = array('zone_name' => $zone_name, 'zone_status' => 'V');
        if ($this->_call_api('zone_list', $params)) {
            return $this->response['response'];
        } else {
            return false;
        }
    }

    function reseller_plan_list()
    {
        if ($this->_call_api('reseller_plan_list', array())) {
            $resp = $this->response['response'];
            return $resp['objs'];
        } else {
            return $this->response['msg'];
        }
    }

    function reseller_sub_new($user_key, $zone_name, $plan_tag)
    {
        $params = array("user_key" => $user_key, "zone_name" => $zone_name, "plan_tag" => $plan_tag);

        if ($this->_call_api('reseller_sub_new', $params)) {
            return $this->response['response'];
        } else
            return $this->response['msg'];

    }

    function reseller_sub_cancel($user_key, $zone_name, $plan_tag, $sub_id)
    {
        $params = array("user_key" => $user_key, "zone_name" => $zone_name, "plan_tag" => $plan_tag, "sub_id" => $sub_id);

        if ($this->_call_api("reseller_sub_cancel", $params)) {
            return $this->response["response"];
        } else
            return $this->response["msg"];
    }

    function _call_api($act, $params)
    {
        $params['act']      = $act; //add an action
        $params["host_key"] = cloudflare_getSettingValue("hostkey"); //add the host key
        $this->response     = json_decode(curlCall("https://api.cloudflare.com/host-gw.html", $params), true);
        cloudflare_logApiCalls('host_api:'.$act, $params, $this->response, null, array($params["host_key"], $params["user_key"], $params["cloudflare_pass"]));
        if ($this->response['result'] == 'success') { //check to see if the call worked
            return true;
        } else {
            return false;
        }
    }
}
