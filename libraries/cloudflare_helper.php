<?php

require_once("cloudflare_cpanel.php");
require_once("cloudflare_zones.php");

class Cloudflare_Helper
{
    protected static $filename;

    public static function getFilename()
    {
        if (self::$filename === null) {
            self::$filename = basename($_SERVER['REQUEST_URI'], '?' . $_SERVER['QUERY_STRING']);
        }

        return self::$filename;
    }

    public static function getInstalledAddons()
    {
        return cloudflare_getInstalledAddons();
    }

    public static function setDefaultOn($addonid, $defaulton_state)
    {
        // force accurate values
        $addonid = (int)$addonid;
        $defaulton_state = $defaulton_state ? 1 : 0;

        full_query(sprintf("UPDATE cloudflare_plans SET defaulton = '%s' WHERE addonid = '%s'", mysql_real_escape_string($defaulton_state), mysql_real_escape_string($addonid)));
    }
}

function validateCsrfToken($params) {
    if (!isset($_SESSION['cf_token']))
      return FALSE;
    if (!isset($params['cfCsrfToken']))
      return FALSE;
    $validToken = $_SESSION['cf_token'] === $params['cfCsrfToken'];
    if (!$validToken) {
        cloudflare_logMessage("CSRF failed");
    }
    return $validToken;
}
function genCsrfToken() {
  // Only set this if they don't have one yet.
  if (!isset($_SESSION['cf_token'])) {
    // Generate secure random bytes. Fall back to a non-CSPRNG
    $randomBytes = "";
    if (function_exists("random_bytes")) {
      $randomBytes = random_bytes(32);
    } else if (function_exists("openssl_random_pseudo_bytes")) {
      $randomBytes = openssl_random_pseudo_bytes(32);
    } else {
      $salt = isset($_SESSION['uid']) ? $_SESSION['uid'] : "";
      $randomBytes = rand(0, getrandmax()).$_SESSION['uid'];
    }
    $_SESSION['cf_token'] = hash("sha256", $randomBytes);
  }
  return $_SESSION['cf_token'];
}
function cloudflare_addPlans()
{
    $cf_zone_query = full_query("SELECT * from cloudflare_plans JOIN tbladdons ON cloudflare_plans.addonid = tbladdons.id");

    if (mysql_num_rows($cf_zone_query) == 0) {
        cloudflare_insertPlan(new Cloudflare_Plan());
        cloudflare_insertPlan(new Cloudflare_Plan(array("auth_dns" => true)));
    } else {
        full_query("UPDATE tbladdons JOIN cloudflare_plans ON tbladdons.id = cloudflare_plans.addonid SET showorder = 'on'");
    }
}

function cloudFlare_importResellerPlans($reseller_plans)
{
    foreach ($reseller_plans as $plan) {
        cloudflare_insertPlan(new Cloudflare_Plan($plan));

        $plan["auth_dns"] = true;
        cloudflare_insertPlan(new Cloudflare_Plan($plan));
    }
}

function cloudflare_insertPlan($plan)
{
    $addonid               = insert_query("tbladdons", $plan->getAddonType());
    $plan_pricing          = $plan->getAddonPricing();
    $plan_pricing['relid'] = $addonid;
    $pricingid             = insert_query("tblpricing", $plan_pricing);

    insert_query("cloudflare_plans", array("addonid" => $addonid, "pricingid" => $pricingid, "cf_auth_dns" => $plan->isAuthDNS(), "plan_tag" => $plan->getPlanTag()));
}

function cloudflare_hideInstalledAddons()
{
    $result = full_query("UPDATE tbladdons JOIN cloudflare_plans ON tbladdons.id = cloudflare_plans.addonid SET showorder = ''");
}

function cloudflare_getInstalledAddons()
{
    $result   = full_query("SELECT cloudflare_plans.addonid, cloudflare_plans.pricingid, cloudflare_plans.plan_tag, cloudflare_plans.cf_auth_dns, cloudflare_plans.defaulton, tbladdons.name, tbladdons.billingcycle, tblpricing.monthly FROM cloudflare_plans JOIN tbladdons ON cloudflare_plans.addonid = tbladdons.id JOIN tblpricing ON tbladdons.id = tblpricing.relid WHERE tblpricing.type = 'addon'");
    $plan_arr = array();

    while ($plan = mysql_fetch_assoc($result)) {
        $plan_arr[] = $plan;
    }

    return $plan_arr;
}

function cloudflare_getPlans() {
    $cloudflare_addons = cloudflare_getInstalledAddons();
    $cloudflare_plans = array();
    foreach ($cloudflare_addons as $plan) {
        $cloudflare_plans[$plan["addonid"]] = $plan["plan_tag"];

    }

    return $cloudflare_plans;
}

function cloudflare_getUnprovisionedAddon($hosting_id) {
    return mysql_fetch_assoc(full_query(sprintf("SELECT id, addonid
                                          FROM   tblhostingaddons
                                          WHERE  hostingid = '%d'
                                            AND addonid IN (SELECT cloudflare_plans.addonid
                                                            FROM   cloudflare_plans
                                                                JOIN tbladdons
                                                                ON cloudflare_plans.addonid = tbladdons.id
                                                                JOIN tblpricing
                                                                ON tbladdons.id = tblpricing.relid
                                                          WHERE  tblpricing.type = 'addon')
                                            LIMIT  1  ",mysql_real_escape_string($hosting_id))));
}

function cloudflare_isCFDNS($id)
{
    $result = mysql_fetch_assoc(full_query(sprintf("SELECT cf_auth_dns from cloudflare_plans where addonid = '%s'" , mysql_real_escape_string($id))));

    return $result["cf_auth_dns"];
}

function cloudflare_getSettingValue($setting, $default = null)
{
    $result = mysql_fetch_assoc(full_query("select value from tbladdonmodules where module = 'cloudflare' AND setting = '".mysql_real_escape_string($setting)."'"));

    if (isset($result["value"]))
        return $result["value"];
    else {
        cloudflare_logMessage($setting . " not defined!");
        return $default;
    }
}

function cloudflare_logMessage($message, $user = 0)
{
    if ($user > 0)
        full_query(sprintf("INSERT INTO cloudflare_ulog ( whmcs_id , message ) VALUES ( '%s' , '%s')", mysql_real_escape_string($user), mysql_real_escape_string($message)));
    else
        full_query(sprintf("INSERT INTO cloudflare_log ( message ) VALUES ( '%s' )", mysql_real_escape_string($message)));
}

function cloudflare_logApiCalls($action, $requeststring, $responsedata, $processeddata = null, $replacevars = null)
{
    logModuleCall('cloudflare',$action,$requeststring,$responsedata,$processeddata,$replacevars);
}

function cloudflare_localAPI($action, $values, $adminid = true)
{
    if ($adminid) {
        return localAPI($action, $values, cloudflare_getSettingValue("adminid", 1));
    } else {
        return localAPI($action, $values);
    }
}

function cloudflare_generateAdminUser()
{
    // only run if the adminid is still 1
    if (cloudflare_getSettingValue("adminid", 1) != 1) {
        return true;
    }

    $table = "tbladmins";
    $values = array(
        "roleid" => "1",
        "username" => "cloudflare_addon",
        "firstname" => "CloudFlare",
        "lastname" => "AddOn",
        "notes" => "Account created for CloudFlare localAPI calls.",
        "language"=> "english"
    );
    $newid = insert_query($table,$values);

    if (!$newid) {
        return false;
    }

    $result = mysql_fetch_assoc(full_query("select value from tbladdonmodules where module = 'cloudflare' AND setting = 'adminid'"));

    // check in case no row exists
    $table = "tbladdonmodules";
    $values = array("value"=>$newid);
    $where = array("module"=>"cloudflare","setting"=>"adminid");
    if (isset($result["value"])) {
        update_query($table,$values,$where);
    } else {
        $values = array_merge($values, $where);
        insert_query($table,$values);
    }

    return true;
}

function cloudflare_removeOldLogs()
{
    full_query("DELETE FROM cloudflare_log WHERE date < ( NOW() - INTERVAL 3 MONTH )");
    full_query("DELETE FROM cloudflare_ulog WHERE date < ( NOW() - INTERVAL 3 MONTH)");
}

function cloudflare_getLogs($user = 0)
{
    $result = null;

    if ($user > 0)
        $result = full_query(sprintf("SELECT date, message FROM cloudflare_ulog WHERE whmcs_id = '%s' ORDER BY log_id DESC", mysql_real_escape_string($user)));
    else
        $result = full_query("SELECT * FROM cloudflare_log ORDER BY log_id DESC;");

    cloudflare_removeOldLogs();

    $response = array();

    while ($row = mysql_fetch_assoc($result)) {
        $response[] = $row;
    }

    return json_encode($response);

}

function cloudflare_whmcs_decrypt_password($pass)
{
    $values["password2"] = $pass;
    $result              = cloudflare_localAPI("decryptpassword", $values);

    return $result["password"];
}

function cloudflare_getServerTypeFromHosting($id)
{
    $query = mysql_fetch_assoc(full_query("select tblservers.type from tblhosting join tblservers on tblhosting.server = tblservers.id where tblhosting.id = '%s'", mysql_real_escape_string($id)));

    return $query["type"];
}

function cloudflare_getServerCredentials($hostingid)
{
    return mysql_fetch_assoc(full_query(sprintf("SELECT tblhosting.userid,
                tblhosting.username as account_username,
                tblservers.username,
                tblservers.password,
                tblservers.accesshash,
                tblservers.ipaddress,
                tblservers.nameserver1,
                tblservers.nameserver2 
                FROM tblhosting 
                JOIN tblservers ON tblhosting.server = tblservers.id WHERE tblhosting.id ='%s'", mysql_real_escape_string($hostingid))));
}

function cloudflare_getDomainByServiceID($id)
{
    return mysql_fetch_assoc(full_query(sprintf("SELECT userid, domain FROM tblhosting WHERE id = '%s'", mysql_real_escape_string($id))));
}

function cloudflare_getDomainByOrderID($order_id)
{
    // can return multiple records if they purchased multiple cf addons
    $result =  full_query(sprintf("SELECT tblhostingaddons.orderid, tblhosting.userid, tblhosting.domain, tbladdons.name, p.plan_tag, p.pricingid FROM tblhostingaddons JOIN tblhosting ON tblhostingaddons.hostingid = tblhosting.id JOIN cloudflare_plans as p ON p.addonid = tblhostingaddons.addonid JOIN tbladdons ON tbladdons.id = tblhostingaddons.addonid WHERE tblhostingaddons.orderid = '%s'", mysql_real_escape_string($order_id)));

    $domains_arr = array();
    while ($domain = mysql_fetch_assoc($result)) {
        $domains_arr[] = $domain;
    }

    return $domains_arr;
}

function cloudflare_getCloudFlareZone($id, $zone)
{
    return mysql_fetch_assoc(full_query(sprintf("SELECT * from cloudflare_zone WHERE whmcs_id = '%s' AND active_zone = '%s' LIMIT 1", mysql_real_escape_string($id), mysql_real_escape_string($zone))));
}

function cloudflare_userIsUsingCloudFlare($userid)
{
    $query = full_query(sprintf("SELECT tblhostingaddons.addonid FROM tblhostingaddons JOIN tblhosting ON tblhosting.id = tblhostingaddons.hostingid JOIN cloudflare_plans ON tblhostingaddons.addonid = cloudflare_plans.addonid where userid = '%s' AND tblhostingaddons.status = 'Active'", mysql_real_escape_string($userid)));

    return (mysql_num_rows($query) > 0);
}

function cloudflare_zoneIsUsingCloudFlare($userid, $zone)
{
    $host_api = new Cloudflare_Hostapi();

    $query = full_query(sprintf("SELECT * FROM cloudflare_zone WHERE whmcs_id = '%s' AND active_zone = '%s' LIMIT 1", mysql_real_escape_string($userid), mysql_real_escape_string($zone)));
    if(mysql_num_rows($query) == 1) {
        $query = mysql_fetch_assoc($query);
        $zone_lookup = $host_api->zone_lookup($query['user_key'], $query['active_zone']);
        return ($zone_lookup['zone_exists'] == 1);
    }
    return false;
}

function cloudflare_zoneIsUsingCloudFlareResellerPlan($userid, $zone)
{
    $query = full_query(sprintf("SELECT * FROM cloudflare_zone WHERE whmcs_id = '%s' AND active_zone = '%s' AND plan_tag IS NOT NULL LIMIT 1", mysql_real_escape_string($userid), mysql_real_escape_string($zone)));

    return (mysql_num_rows($query) == 1);
}

function cloudflare_setResellerSubscription($userid, $zone_name, $plan_tag)
{
    $cf_host_api = new Cloudflare_Hostapi();
    $cf_zone     = mysql_fetch_assoc(full_query(sprintf("SELECT * FROM cloudflare_zone WHERE whmcs_id = '%s' AND active_zone = '%s' LIMIT 1", mysql_real_escape_string($userid), mysql_real_escape_string($zone_name))));

    if ($user_key = $cf_zone["user_key"]) {
        $result = $cf_host_api->reseller_sub_new($user_key, $zone_name, $plan_tag);

        if (is_array($result)) {
            full_query(sprintf("UPDATE cloudflare_zone SET plan_tag = '%s' , sub_id = '%s' WHERE whmcs_id = '%s' AND active_zone = '%s'",
                mysql_real_escape_string($plan_tag),
                mysql_real_escape_string($result["sub_id"]),
                mysql_real_escape_string($userid),
                mysql_real_escape_string($zone_name)));
        } else {
            cloudflare_logMessage("Plan set failed: $result", $userid);
            cloudflare_logMessage("Plan set failed for $userid : $result");
        }
    } else {
        cloudflare_logMessage("Plan set failed, user key nonexistent", $userid);
        cloudflare_logMessage("Plan set failed for $userid and $zone_name, user key nonexistent");
    }
}

function cloudflare_provision($user_id, $service_id, $cf_plan_addon_id){
    /*
     * This method is called by:
     *
     * hooks.php -> hook_cloudflare_create($vars) - Executes when a CloudFlare addon is purchased
     * separately from a hosting package.
     *
     * hooks.php -> hook_cloudflare_provision_after_cpanel_account_creation($vars) - Executes when
     * a hosting package AND a cloudflare addon are purchased together.
     *
     * hook_cloudflare_create() will always trigger after hook_cloudflare_provision_after_cpanel_account_creation()
     * but should fail at "!cloudflare_zoneIsUsingCloudFlare($user_id, $zone["domain"])" which checks the cloudflare_zone
     * table for a user key corresponding to this domain.
     */
    $cloudflare_plans = cloudflare_getPlans();

    if ($zone = cloudflare_getDomainByServiceID($service_id)) {
        if (array_key_exists($cf_plan_addon_id, $cloudflare_plans)) {
            if (!cloudflare_zoneIsUsingCloudFlare($user_id, $zone["domain"])) {
                cloudflare_logMessage("Zone isn't using CloudFlare yet.");
                if (cloudflare_isCFDNS($cf_plan_addon_id)) {
                    cloudflare_provisionCloudFlareNS($service_id);
                }
                else {
                    cloudflare_provisionCloudFlareCNAME($service_id);
                }
            }

            if ($cloudflare_plans[$cf_plan_addon_id] && !cloudflare_zoneIsUsingCloudFlareResellerPlan($user_id, $zone["domain"])) {
                $plan_tag = $cloudflare_plans[$cf_plan_addon_id];
                cloudflare_setResellerSubscription($user_id, $zone["domain"], $plan_tag);
            }
        }
    } else {
        cloudflare_logMessage("No zone/domain found in associated hosting plan.", $user_id);
        cloudflare_logMessage("No zone/domain found in associated hosting plan for service ID: $service_id");
    }
}

function cloudflare_provisionCloudFlareNS($hostingid, $cf_creds = null)
{
    $cf_host_api = new Cloudflare_Hostapi();
    $zone        = cloudflare_getDomainByServiceID($hostingid);

    if (!isset($cf_creds)) {
        $cf_creds = cloudflare_auto_createCFUser($zone["userid"]);
    }

    if ($cf_creds) {
        $user_key = $cf_creds["user_key"];
        $domain   = $zone["domain"];
        $user_id  = $zone["userid"];

        cloudflare_logMessage("Using user key: " . $user_key, $user_id);

        $nameservers = $cf_host_api->zone_set_full($user_key, $domain);

        if (is_array($nameservers)) {
            $ns_store = $nameservers[0] . " " . $nameservers[1];

            full_query(sprintf("INSERT IGNORE INTO cloudflare_zone (whmcs_id, user_key, active_zone, nameservers) VALUES ( '%s', '%s', '%s', '%s' )", mysql_real_escape_string($user_id), mysql_real_escape_string($user_key), mysql_real_escape_string($domain), mysql_real_escape_string($ns_store) ));

            $values["domain"] = $domain;
            $values["ns1"]    = $nameservers[0];
            $values["ns2"]    = $nameservers[1];

            $change_ns = cloudflare_localAPI("domainupdatenameservers", $values);

            if ($change_ns["result"] == "error") {
                cloudflare_logMessage("Unable to make name server change through WHMCS, please change your name servers at the registrar.", $user_id);
            }
        } else {
            cloudflare_logMessage("CloudFlare name server provisioning failed: {$nameservers}", $user_id);
            cloudflare_logMessage("CloudFlare full zone set failed for {$domain}");
        }

    }
}

function cloudflare_provisionCloudFlareCNAME($hostingid, $cf_creds = null)
{
    $server_type = cloudflare_getServerTypeFromHosting($hostingid);
    $zone        = cloudflare_getDomainByServiceID($hostingid);
    $done        = false;

    if (!isset($cf_creds)) {
        $cf_creds = cloudflare_auto_createCFUser($zone["userid"]);
    }

    cloudflare_logMessage("CNAME provisioning, server type: " . $server_type);

    switch ($server_type) {
        case "cpanel":
            $done = cloudflare_provisionCloudFlareCNAME_cPanel($hostingid, $cf_creds);
            break;
        default:
            $done = cloudflare_provisionCloudFlareCNAME_default($hostingid, $cf_creds);
    }

    if (!$done) {
        cloudflare_logMessage("CloudFlare CNAME provisioning failed for hosting id: {$hostingid}");
    }
}

//$cf_zone = cloudflare_getCloudFlareZone($userid, $zone);
function cloudflare_cancelResellerSubscriptionByCFZone($cf_zone) {
    // plan_tag == NULL == Free Plan
    if ($cf_zone["plan_tag"]) {
        $host_api            = new Cloudflare_Hostapi();
        $reseller_sub_cancel = $host_api->reseller_sub_cancel($cf_zone["user_key"], $cf_zone["active_zone"], $cf_zone["plan_tag"], $cf_zone["sub_id"]);
        if (!is_array($reseller_sub_cancel)) {
            cloudflare_logMessage("Subscription cancellation failed: " . $reseller_sub_cancel);
        }
        else {
            full_query(sprintf("UPDATE cloudflare_zone SET plan_tag = '' , sub_id = '' WHERE whmcs_id = '%s' AND active_zone = '%s'",
                mysql_real_escape_string($cf_zone["whmcs_id"]),
                mysql_real_escape_string($cf_zone["active_zone"])));
            cloudflare_logMessage("Subscription cancellation successful: " . print_r($reseller_sub_cancel, true));
            return true;
        }
    } else {
        cloudflare_logMessage("CloudFlare zone '".$cf_zone["active_zone"]."' doesn't contain a paid subscription.");
    }

    return false;
}

function cloudflare_deprovisionCloudFlare($userid, $zone, $service_id, $addon_id)
{
    $cf_installed_addons = cloudflare_getInstalledAddons();

    foreach ($cf_installed_addons as $cf_addon) {
        $cloudflare_plans[$cf_addon["addonid"]] = $cf_addon["plan_tag"];
    }

    //The WHMCS API uses getclientsaddons for three different cases
    $get_service_addons = cloudflare_localAPI("getclientsaddons", array("serviceid" => $service_id));

    $service_addons = $get_service_addons["addons"];

    foreach ($service_addons["addon"] as $addon) {
        if (in_array($addon["addonid"], array_keys($cloudflare_plans))) {
            if ($addon["status"] != "Active")
                $cf_service_addons[$addon["addonid"]] = $addon["id"];
        }
    }

    if (in_array($addon_id, array_keys($cf_service_addons))) {

        $cf_zone = cloudflare_getCloudFlareZone($userid, $zone);

        cloudflare_cancelResellerSubscriptionByCFZone($cf_zone);

        if (count($cf_service_addons) == 1) //This simply means it's the last CloudFlare Addon to be canceled
        {
            $host_api            = new Cloudflare_Hostapi();
            $zone_delete = $host_api->zone_delete($cf_zone["user_key"], $cf_zone["active_zone"]);

            if (is_array($zone_delete)) {
                cloudflare_logMessage($cf_zone["active_zone"] . " has been removed from CloudFlare.", $userid);
                cloudflare_logMessage("zone_delete: " . print_r($zone_delete, true));
                full_query(sprintf("DELETE FROM cloudflare_zone WHERE whmcs_id = '%s' AND active_zone = '%s' AND
                        user_key = '%s'", mysql_real_escape_string($userid),
                    mysql_real_escape_string($cf_zone["active_zone"]),
                    mysql_real_escape_string($cf_zone["user_key"])));

                if (cloudflare_isCFDNS($addon_id)) {
                    $server = cloudflare_getServerCredentials($service_id);

                    $change_ns = cloudflare_localAPI("domainupdatenameservers", array("domain" => $cf_zone["active_zone"], "ns1" => $server["nameserver1"], "ns2" => $server["nameserver2"]));
                    if ($change_ns["result"] == "error") {
                        cloudflare_logMessage("Unable to make name server change through WHMCS, please change your name servers at the registrar.", $userid);
                        cloudflare_logMessage("Unable to make name server change for " . $cf_zone["active_zone"] . "through WHMCS, please change your name servers at the registrar.");
                    }
                }

            } else {
                cloudflare_logMessage("CloudFlare returned an error when deleting: " . $zone_delete, $userid);
                cloudflare_logMessage("zone_delete failed: " . $zone_delete);

                // Check the error code to see if the domain has already been removed from CloudFlare
                // Remove the domain from WHMCS based on the error received.
                $response = $host_api->getResponse();
                $error_code = $response["err_code"];

                if (in_array($error_code, array(703,211,213))) {
                    cloudflare_logMessage("Domain no longer appears to be on CloudFlare", $userid);
                    cloudflare_logMessage("Domain (".$cf_zone["active_zone"].") no longer appears to be on CloudFlare");
                    full_query(sprintf("DELETE FROM cloudflare_zone WHERE whmcs_id = '%s' AND active_zone = '%s' AND
                            user_key = '%s'", mysql_real_escape_string($userid),
                        mysql_real_escape_string($cf_zone["active_zone"]),
                        mysql_real_escape_string($cf_zone["user_key"])));
                }
            }
        }

    }
}

function cloudflare_findWWW($zone_name, $zone_records)
{
    $result = array();
    $www    = "www." . $zone_name . ".";

    foreach ($zone_records["cpanelresult"]["data"] as $record) {
        if ($record["name"] == $www && $record["type"] == "CNAME")
            $result[$www] = intval($record["Line"]);
    }

    return json_encode($result);
}


function cloudflare_manual_create_CFUser($userid, $email, $pass)
{
    $host_api = new Cloudflare_Hostapi();

    $ucreate = $host_api->user_create($email, $pass);

    if (is_array($ucreate)) {
        cloudflare_logMessage("CloudFlare user authed/created: {$email} ");
        cloudflare_logMessage("CloudFlare user authed/created: {$email} ", $userid);

        return array("email" => $email, "pass" => $pass, "user_key" => $ucreate["user_key"]);
    } else {
        cloudflare_logMessage("Manual creation/authentication failed: " . $ucreate, $userid);
        cloudflare_logMessage("Manual creation/authentication failed for user {$userid}");
        return false;
    }

}

function cloudflare_auto_createCFUser($userid)
{
    $query       = mysql_fetch_assoc(full_query(sprintf("SELECT email FROM tblclients WHERE id = '%s'", mysql_real_escape_string($userid))));
    $email       = $query["email"];
    $random_pass = md5($email . time() . mt_rand());

    $host_api = new Cloudflare_Hostapi();

    cloudflare_logMessage("Account auto-create: {$email}");
    cloudflare_logMessage("Attempting to automatically create CloudFlare account: {$email}", $userid);

    $cf_zone_query = full_query(sprintf("SELECT user_key FROM cloudflare_zone WHERE whmcs_id = '%s' LIMIT 1", mysql_real_escape_string($userid)));

    if ($cf_user_key = mysql_fetch_assoc($cf_zone_query)) {
        cloudflare_logMessage("Using user key: " . $cf_user_key["user_key"]);
        return array("email" => $email, "pass" => $random_pass, "user_key" => $cf_user_key["user_key"]);
    } else
        $ucreate = $host_api->user_create($email, $random_pass);


    if (is_array($ucreate)) {
        cloudflare_logMessage("CloudFlare account creation successful.", $userid);
        cloudflare_logMessage("CloudFlare account created! User key: {$ucreate["user_key"]}");
        return array("email" => $email, "pass" => $random_pass, "user_key" => $ucreate["user_key"]);
    } else {
        cloudflare_logMessage("Automatic creation failed: " . $ucreate . ".  Manual account setup required: <a href=\"https://support.cloudflare.com/hc/en-us/articles/205078128\" target=\"_blank\">https://support.cloudflare.com/hc/en-us/articles/205078128</a>", $userid);
        cloudflare_logMessage("Automatic creation failed for user {$userid}.  Manual account setup required: <a href=\"https://support.cloudflare.com/hc/en-us/articles/205078128\" target=\"_blank\">https://support.cloudflare.com/hc/en-us/articles/205078128</a>");
        return false;
    }
}

function cloudflare_reprovisionCloudFlare($hostingid, $cf_creds)
{
    if ($addon_order = mysql_fetch_assoc(full_query(sprintf("select tblhostingaddons.addonid, cloudflare_plans.plan_tag, tblhosting.userid, tblhosting.domain from tblhostingaddons JOIN cloudflare_plans ON tblhostingaddons.addonid = cloudflare_plans.addonid JOIN tblhosting ON tblhostingaddons.hostingid = tblhosting.id where tblhostingaddons.hostingid = '%s' LIMIT 1", mysql_real_escape_string($hostingid))))) {
        if (cloudflare_isCFDNS($addon_order["addonid"])) {
            cloudflare_provisionCloudFlareNS($hostingid, $cf_creds);
        } else {
            cloudflare_provisionCloudFlareCNAME($hostingid, $cf_creds);
        }

        if ($addon_order["plan_tag"] && !cloudflare_zoneIsUsingCloudFlareResellerPlan($addon_order["userid"], $addon_order["domain"])) {
            $plan_tag = $addon_order["plan_tag"];
            cloudflare_setResellerSubscription($addon_order["userid"], $addon_order["domain"], $plan_tag);
        }
    } else
        cloudflare_logMessage("Manual provisioning for $hostingid failed.");

}

function cloudflare_provisionCloudFlareCNAME_default($hostingid, $cf_creds)
{
    $zone       = cloudflare_getDomainByServiceID($hostingid);
    $zone_name  = $zone["domain"];
    $resolve_to = "cloudflare-resolve-to." . $zone_name;
    $host_api   = new Cloudflare_Hostapi();

    cloudflare_logMessage("Server integration not (yet) supported, provisioning CloudFlare manually.", $zone["userid"]);

    $zset = $host_api->zone_set($cf_creds["user_key"], $zone_name, $resolve_to, "www");

    if ($zset) {
        cloudflare_logMessage("Provisioning failed: {$zset}", $zone["userid"]);
        cloudflare_logMessage("CNAME Provisioning failed for {$zone["userid"]}: {$zset}");
        return false;
    } else {
        full_query(sprintf("INSERT IGNORE INTO cloudflare_zone (whmcs_id, user_key, active_zone) VALUES ( '%s', '%s', '%s' )", mysql_real_escape_string($zone['userid']), mysql_real_escape_string($cf_creds['user_key']), mysql_real_escape_string($zone_name)));
        cloudflare_logMessage("{$zone_name} successfully signed up.", $zone["userid"]);
        return true;
    }

}

function cloudflare_provisionCloudFlareCNAME_cPanel($hostingid, $cf_creds)
{
    $server_creds = cloudflare_getServerCredentials($hostingid);
    $cpanel_obj = cloudflare_getCpanelObject($server_creds);
    $zone = cloudflare_getDomainByServiceID($hostingid);
    $zone_name = $zone["domain"];
    $user_key_result = full_query(sprintf("SELECT user_key FROM cloudflare_zone WHERE active_zone = '%s'", mysql_real_escape_string($zone_name)));

    if(mysql_num_rows($user_key_result) == 0) {  //Create user only if we don't already have a key in the database.
        if ($server_creds && $cf_creds) {
            $is_created = $cpanel_obj->curl("user_create", array("email" => $cf_creds["email"], "password" => $cf_creds["pass"]));
            if ($is_created["cpanelresult"]["data"][0]["result"] == "success" && !$is_created["cpanelresult"]["error"]) {
                $user_key = $is_created['cpanelresult']['data'][0]['response']['user_key'];
                full_query(sprintf("INSERT IGNORE INTO cloudflare_zone (whmcs_id, user_key, active_zone) VALUES ( '%s', '%s', '%s' )", mysql_real_escape_string($server_creds['userid']), mysql_real_escape_string($user_key), mysql_real_escape_string($zone_name)));
            } else {
                cloudflare_logMessage("CloudFlare user_create through cPanel server failed.", $zone["userid"]);
                return false;
            }

        } else {
            cloudflare_logMessage("cPanel provisioning failed due to nonexistent credentials for {$zone_name}", $zone["userid"]);
            return false;
        }
    } else {
        $user_key_result = mysql_fetch_assoc($user_key_result);
        $user_key = $user_key_result["user_key"];
    }

    $zone_set_data = array();
    $zone_set_data["user_key"] = $user_key;
    $zone_set_data["zone_name"] = $zone_name;
    $zone_set_data["subdomains"] = "www." . $zone_name . ".";
    $zone_set_data["cf_recs"] = cloudflare_findWWW($zone_name, $cpanel_obj->curl("fetchzone", array("domain" => $zone_name)));

    return $cpanel_obj->curl("zone_set", $zone_set_data);
}


function cloudflare_getCFZoneFromDomainID($domainid)
{
    $whmcs_domain = mysql_fetch_assoc(full_query(sprintf("SELECT userid, domain FROM tbldomains WHERE id = '%s'",mysql_real_escape_string($domainid))));

    return cloudflare_getCloudFlareZone($whmcs_domain["userid"], $whmcs_domain["domain"]);
}

function cloudflare_getConfigButtonHTML()
{
    return '<a href="index.php?m=cloudflare"><div id="cfslideout">Setup CloudFlare</div></a>';
}

function cloudflare_getUserLogsHTML($userid)
{
    $logs = cloudflare_getLogs($userid);

    $log_html = '<script> var cloudflarelogs = ' . $logs . ';';
    $log_html .= 'for (var i=0; i < cloudflarelogs.length; i++){document.getElementById("cflogs").innerHTML +=  "<tr><td style=\"width:15%\">" + cloudflarelogs[i].date + "</td><td>" + cloudflarelogs[i].message + "</td></tr>";}';
    $log_html .= '</script>';

    return $log_html;
}

function cloudflare_getCpanelObject($server)
{
    return new Cloudflare_Cpanel($server, "cloudflare_logMessage");
}
