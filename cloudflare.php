<?php

require_once("libraries/cloudflare_plan.php");
require_once("libraries/cloudflare_helper.php");
require_once("libraries/cloudflare_host_api.php");
require_once("libraries/cloudflare_cpanel.php");
require_once("libraries/cloudflare_db.php");

function cloudflare_config()
{
    // Configuration is stored in package.json file in this directory
    $config = json_decode(file_get_contents(dirname(__FILE__) . '/package.json'), true);

    return $config;
}

function cloudflare_activate()
{
    $db = new Cloudflare_Db();
    $db->install();
    cloudflare_addPlans();

    return array('status' => 'success', 'description' => 'Thanks for installing the WHMCS module from CloudFlare. Don\'t forget to add your host API key in the configuration section.');
}

function cloudflare_deactivate()
{
    cloudflare_hideInstalledAddons();
    return array('status' => 'success', 'description' => 'We\'re sorry to see you go. Please send any feedback on the module to partnersupport@cloudflare.com.');
}


function cloudflare_output($vars)
{
    $host_api        = new Cloudflare_Hostapi();
    $reseller_plans  = $host_api->reseller_plan_list();
    $installed_plans = cloudflare_getInstalledAddons();

    print '<div class="cfcontainer">';

    if (is_array($reseller_plans)) {
        print '<div class="cfinfobox" id="cfapisuccess"><img src="../modules/addons/cloudflare/images/api_success.png" /><br>API Status: Successful!</div>';

        if (isset($_POST["act"])) {
            switch ($_POST["act"]) {
              case "import_reseller":
                  // They need to submit a CsrfToken
                  if (!validateCsrfToken($_POST)) {
                    break;
                  }
                  cloudflare_importResellerPlans($reseller_plans);
                  $installed_plans = cloudflare_getInstalledAddons();
                  break;
            }
        }
        $token = genCsrfToken();
        if ($import_available = (count($installed_plans) < 2 + (2 * count($reseller_plans)))) {
            print '<form action="addonmodules.php?module=cloudflare">';
            print '<input type="hidden" value='.htmlspecialchars($token).' name="cfCsrfToken"/>';
            print '<button type="submit" formmethod="post" formaction="addonmodules.php?module=cloudflare" name="act" value="import_reseller" >';
            print '<div class="cfinfobox" id="cfresellerimport"><img src="../modules/addons/cloudflare/images/import_plans.png" /><br>Import Reseller Plans</div>';
            print '</button>';
            print '</form>';
        }
    } else {
        print '<div class="cfinfobox" id="cfapifail"><img src="../modules/addons/cloudflare/images/api_failed.png" /><br>API Status: Failed!</div>';
    }

    print '<a href="https://partners.cloudflare.com" target="_blank"><div class="cfinfobox" id="cfpartnerportal"><img src="../modules/addons/cloudflare/images/portal.png" /><br>CloudFlare Partner Portal</div></a>';

    // prompt to generate a module specific admin user if they are still using the default "1" user
    if (cloudflare_getSettingValue("adminid", 1) == 1) {
        $print_notice = true;
        if (isset($_GET["act"])) {
            switch ($_GET["act"]) {
              case "generateAdminId":
                  if (!validateCsrfToken($_GET)) {
                    break;
                  }
                  if (cloudflare_generateAdminUser()) {
                      $print_notice = false;
                  }
                  break;
            }
        }
        $token = genCsrfToken();
        if ($print_notice) {
            print '<div class="notice">';
            print '<p>Plugin is currently running on the default admin id of 1. We recommend creating an admin user specifically for the module. To automatically generate this user and assign the module to use this, <a href="addonmodules.php?module=cloudflare&act=generateAdminId&cfCsrfToken='.htmlspecialchars($token).'"">click here</a>.</p><p><em>Admin user will be generated without a password, so they can not log in to WHMCS. It is purely an account for tracking actions by this module.</em></p>';
            print '</div>';
        }
    }

    print '<table id="cfplans">';
    print '<th>Addon Id</th>';
    print '<th>Addon Plan</th>';
    print '<th>Uses CloudFlare DNS</th>';
    print '<th>Enabled by Default</th>';
    print '<th>CloudFlare Plan Tag</th>';
    print '<th>Billing Cycle</th>';
    print '<th>Your Cost</th>';
    print '<th>Manage Addon</th>';

    foreach ($installed_plans as $plan) {
        $dns = $plan["cf_auth_dns"] ? 'Yes' : 'No';

        if (!isset($plan["plan_tag"]) || trim($plan['plan_tag']) == '') {
            $plan['plan_tag'] = 'FREE';
        }

        print '<tr>';
        print '<td>' . $plan["addonid"] . '</td>';
        print '<td>' . $plan["name"] . '</td>';
        print '<td>' . $dns . '</td>';
        print '<td>' . ($plan["defaulton"] ? 'Yes' : 'No') . '</td>';
        print '<td>' . $plan["plan_tag"] . '</td>';
        print '<td>' . $plan["billingcycle"] . '</td>';
        print '<td>' . $plan["monthly"] . '</td>';
        print '<td><a href="configaddons.php?action=manage&id=' . $plan["addonid"] . '"><img src="../modules/addons/cloudflare/images/gear.png" /></a></td>';
        print '</tr>';
    }

    print '</table>';

    $logs = json_decode(cloudflare_getLogs(), true);
    print '<br><strong>Provisioning Logs</strong><br>';


    print '<div id="cflogs" style="max-height:300px;overflow:scroll">';

    foreach ($logs as $line) {
        print $line["date"] . " " . $line["message"] . '<br>';
    }
    print '</div></div>';

}

function cloudflare_upgrade($vars)
{
    $db = new Cloudflare_Db();
    $db->processMigrations($vars["version"]);
}

function cloudflare_clientarea($vars)
{
    $WHMCS_client_area = new WHMCS_ClientArea();

    if (isset($_POST["act"])) {
        switch ($_POST["act"]) {
            case "reprovision":
              if (is_array($_POST["zone"]) && isset($_POST["userid"])) {
                  if ($_POST["userid"] != $_SESSION["uid"]) {
                    break;
                  }
                  if (!validateCsrfToken($_POST)) {
                    break;
                  }
                  if ($cf_creds = cloudflare_manual_create_CFUser($_POST["userid"], $_POST["cfuser"], $_POST["cfpass"])) {
                      foreach ($_POST["zone"] as $hostingid) {
                          cloudflare_reprovisionCloudFlare($hostingid, $cf_creds);
                      }
                  }
              }
              break;
        }
    }

    $cf_zones = new Cloudflare_Zones($WHMCS_client_area->getUserID());
    $token = genCsrfToken();
    return array(
        'pagetitle' => 'CloudFlare Client Area',
        'breadcrumb' => array(
            'index.php?m=cloudflare' => 'CloudFlare Settings',
        ),
        'templatefile' => 'clienthome',
        'requirelogin' => true,
        'vars' => array(
            'showclientlogs' => (cloudflare_getSettingValue("showclientlogs", "on") === "on"),
            'cname_instructions' => $cf_zones->isCnameSetup(),
            'nameserver_instructions' => $cf_zones->isFullSetup(),
            'reprovision_needed' => $cf_zones->isReprovisionNeeded(),
            'cf_zones_html' => $cf_zones->renderTableRows(),
            'messages' => $cf_zones->getMessages(),
            'current_directory' => dirname(__FILE__),
            'cfCsrfToken'=>$token,
            'user_id' => $WHMCS_client_area->getUserID()
        )
    );
}
