<?php

require_once('libraries/cloudflare_helper.php');
require_once('libraries/cloudflare_host_api.php');

function hook_cloudflare_create($vars)
{
    $addon_id = $vars["addonid"];
    $service_id = $vars["serviceid"];

    cloudflare_provision($vars["userid"],$service_id,$addon_id);
}

add_hook('AddonActivation', 1, 'hook_cloudflare_create');
add_hook('AddonActivated', 1, 'hook_cloudflare_create');

function hook_cloudflare_provision_after_cpanel_account_creation($vars)
{
    $service_id = $vars['params']['serviceid'];
    $module_id = $vars['params']['moduletype'];

    if ($module_id == "cpanel") {
        $cloudFlareAddonToProvision = cloudflare_getUnprovisionedAddon($service_id);
        
        if (isset($cloudFlareAddonToProvision['id'])) {
            cloudflare_provision($vars['params']['userid'],$service_id,$cloudFlareAddonToProvision["addonid"]);
        }
    }
}

add_hook('AfterModuleCreate', 1, 'hook_cloudflare_provision_after_cpanel_account_creation');

function hook_cloudflare_delete($vars)
{
    $addon_id = $vars["addonid"];
    $service_id = $vars["serviceid"];

    if ($zone = cloudflare_getDomainByServiceID($service_id)) {
        cloudflare_logMessage("Deprovisioning " . $zone["domain"]);

        if ($cfzone = cloudflare_getCloudFlareZone($zone["userid"], $zone["domain"])) {
            cloudflare_deprovisionCloudFlare($zone["userid"], $zone["domain"], $service_id, $addon_id);
        } else {
            cloudflare_logMessage("Deprovisioning " . $zone["domain"] . " from CloudFlare failed, zone not found in WHMCS database.");
        }
    }
}

add_hook('AddonTerminated', 1, 'hook_cloudflare_delete');
add_hook('AddonCancelled', 1, 'hook_cloudflare_delete');
add_hook('AddonFraud', 1, 'hook_cloudflare_delete');

function hook_cloudflare_suspend($vars) {
    $service_id = $vars["serviceid"];
    $user_id = $vars["userid"];

    /*
     * If its a paid plan downgrade to free, if its free do nothing.
     */
    if($zone = cloudflare_getDomainByServiceID($service_id)) {

        if($cf_zone = cloudflare_getCloudFlareZone($user_id, $zone["domain"])) {
            cloudflare_cancelResellerSubscriptionByCFZone($cf_zone);
        }
    }

}
add_hook('AddonSuspended', 1, 'hook_cloudflare_suspend');

function hook_cloudflare_client_head($vars)
{
    $inject = "";

    if ($vars["loggedin"]) {
        $inject .= "<link href=\"modules/addons/cloudflare/css/cloudflare.css\" rel=\"stylesheet\">";
    }

    switch (Cloudflare_Helper::getFilename()) {
        case 'cart.php':
            // retrieve addon information
            $data = Cloudflare_Helper::getInstalledAddons();

            foreach ($data as $addon) {
                if ($addon['defaulton'] == 1) {
                    // this may be injected on more pages than just the addon page, but it shouldn't affect any other pages
                    $inject .= "<script>jQuery(document).ready(function($) { $('input[type=checkbox][name=\"addons[" . $addon['addonid'] . "]\"]').prop('checked', 'checked'); });</script>";
                }
            }
            break;
    }

    return $inject;
}

add_hook('ClientAreaHeadOutput', 1, 'hook_cloudflare_client_head');

function hook_cloudflare_client_foot($vars)
{
    $userid = $vars["clientsdetails"]["userid"];

    $inject = "";

    if ($vars["loggedin"] && cloudflare_userIsUsingCloudFlare($userid)) {

        if (cloudflare_getSettingValue("displayfrontendlink", "on") === "on") {
            $inject .= cloudflare_getConfigButtonHTML();
        }

        if ((substr($vars["SCRIPT_NAME"], -10) == "/index.php") && ($_GET["m"] == "cloudflare")) {
            $inject .= cloudflare_getUserLogsHTML($userid);
        }
    }

    return $inject;
}

add_hook('ClientAreaFooterOutput', 1, 'hook_cloudflare_client_foot');

function hook_cloudflare_admin_area($vars)
{
    $inject = "";

    $inject .= '<link href="../modules/addons/cloudflare/css/cloudflare.css" rel="stylesheet">';

    return $inject;
}

add_hook('AdminAreaHeadOutput', 1, 'hook_cloudflare_admin_area');

function hook_cloudflare_after_domain_registration($vars)
{
    $data = $vars["params"];
    $domainid = $data["domainid"];

    if ($cf_zone = cloudflare_getCFZoneFromDomainID($domainid)) {
        $nameservers = explode(" ", $cf_zone["nameservers"]);
        $values["domain"] = $cf_zone["active_zone"];
        $values["ns1"] = $nameservers[0];
        $values["ns2"] = $nameservers[1];

        $change_ns = cloudflare_localAPI("domainupdatenameservers", $values);

        if ($change_ns["result"] == "error") {
            cloudflare_logMessage("Unable to make name server change through WHMCS, please change your name servers at the registrar.", $user_id);
        }
    }
}

add_hook('AfterRegistrarRegistration', 1, 'hook_cloudflare_after_domain_registration');

// hook for enabling and saving default on for any addon
function hook_cloudflare_addon_config($vars)
{
    $fields = array();

    // retrieve addon information
    $data = Cloudflare_Helper::getInstalledAddons();

    foreach ($data as $addon) {
        if ($addon['addonid'] == $vars['id']) {
            $fields['Enabled by Default'] = '<input type="checkbox" name="cf_defaulton" id="cf_defaulton" ' . ($addon["defaulton"] == 1 ? 'checked="checked" ' : '') . ' /> <label for="cf_defaulton">Enabling this will force this addon to be checked by default on the client side of WHMCS when a customer selects addons (if the addon is visible).</label>';
            return $fields;
        }
    }
}

add_hook('AddonConfig', 1, 'hook_cloudflare_addon_config');

function hook_cloudflare_addon_config_save($vars)
{
    Cloudflare_Helper::setDefaultOn($vars['id'], $_REQUEST['cf_defaulton']);
}

add_hook('AddonConfigSave', 1, 'hook_cloudflare_addon_config_save');

function hook_cloudflare_cart_checkout_complete($vars)
{
    $order = $vars['orderid'];
    $user_id = $vars['clientdetails']['userid'];

    $display_cloudflare = cloudflare_getSettingValue("displayorderconfirmpage", "on");

    if (!$order || !$user_id || $display_cloudflare !== "on") {
        return;
    }

    // load order information
    $domain = cloudflare_getDomainByOrderID($order);

    // not a CloudFlare domain.
    if (!$domain) {
        return;
    }

    // determine status of provisioning
    $cf_zones = new Cloudflare_Zones($user_id);

    // TODO determine if CloudFlare has been provisioned correctly and display appropriate page:
    // 1. All provisioned correctly? Just show success message.
    // 2. Setup on CF correctly but need to configure DNS/nameservers? Show config settings.
    // 3. Error provisioning? Display login screen.

    // For now just jump out the usual module output

    $output = '
<div class="cfcontainer">
    <hr/>
    <h3>CloudFlare Settings</h3>
    <form id="cfstatus" action="index.php?m=cloudflare" method="post">
        <input type="hidden" name="userid" value="' . $user_id . '">';

    ob_start();
    $cf_zones_html = $cf_zones->renderTableRows();
    require('modules/addons/cloudflare/templates/zones.php');
    $output .= ob_get_clean();

    $output .= '<br>
        <br>';

    // completely failed, so show the login screen
    if ($cf_zones->isReprovisionNeeded()) {
        ob_start();
        require('modules/addons/cloudflare/templates/login.tpl');
        $output .= ob_get_clean();
    }

    // TODO: since we only support auto-provisioning on cPanel, let's check if that has happened

    $output .= '</form>';

    // display config instructions that are relevant
    if ($cf_zones->isCnameSetup()) {
        ob_start();
        require('modules/addons/cloudflare/templates/cname_instructions.tpl');
        $output .= ob_get_clean();
    }
    if ($cf_zones->isFullSetup()) {
        ob_start();
        require('modules/addons/cloudflare/templates/nameserver_instructions.tpl');
        $output .= ob_get_clean();
    }

    $output .= '</div>';

    return $output;
}

add_hook('ShoppingCartCheckoutCompletePage', 1, 'hook_cloudflare_cart_checkout_complete');
