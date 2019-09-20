<?php

class Cloudflare_Zones
{
    protected $reprovision_needed = false;
    protected $cname_setup = false;
    protected $full_setup  = false;

    protected $messages   = array();
    protected $default_message = 'Provisioning failures detected, enter CloudFlare Credentials to try again.';

    protected $cf_zones = array();
    protected $active_cf_zones = array();

    public function __construct($user_id)
    {
        $cf_zone_query = full_query(sprintf("select tblhosting.domain, tblhosting.id, cloudflare_plans.cf_auth_dns from tblhosting JOIN tblhostingaddons ON tblhostingaddons.hostingid = tblhosting.id JOIN cloudflare_plans ON tblhostingaddons.addonid = cloudflare_plans.addonid where tblhosting.userid = '%s' AND tblhosting.domainstatus != 'Terminated'", mysql_real_escape_string($user_id)));
        $active_cf_zone_query = full_query(sprintf("select * from cloudflare_zone where whmcs_id = '%s'", mysql_real_escape_string($user_id)));
        $host_api = new Cloudflare_Hostapi();

        while ($row = mysql_fetch_assoc($cf_zone_query)) {
            $this->cf_zones[] = $row;
        }

        while ($row = mysql_fetch_assoc($active_cf_zone_query)) {
            if (isset($row["nameservers"])) {
                $this->active_cf_zones[$row["active_zone"]] = $row["nameservers"];
            } else {
                if ($z_lookup = $host_api->zone_lookup($row["user_key"], $row["active_zone"])) {
                    $cname_config = array("cname" => $z_lookup["forward_tos"], "proxy_target" => $z_lookup["hosted_cnames"]);
                    $this->active_cf_zones[$row["active_zone"]] = $cname_config;
                }
            }
        }

        $this->preprocess();
    }

    // Determine what actions need to happen and what instructions are relevant
    protected function preprocess()
    {
        foreach ($this->cf_zones as $zone) {
            if (isset($this->active_cf_zones[$zone["domain"]])) {
                $config = $this->active_cf_zones[$zone["domain"]];
                if (is_array($config)) {
                    $this->cname_setup = true;
                } else {
                    $this->full_setup = true;
                }
            } else {
                $this->reprovision_needed = true;
            }
        }

        if ($this->reprovision_needed) {
            array_push($this->messages, $this->default_message);
        }
    }

    public function renderTableRows()
    {
        $html = '';

        foreach ($this->cf_zones as $zone) {
            $html .= '<tr><td>' . $zone["domain"] . ' <small><em>('. ($zone["cf_auth_dns"] ? 'Full' : 'Partial') .' Setup)</em></small></td>';

            if (isset($this->active_cf_zones[$zone["domain"]])) {

                $config = $this->active_cf_zones[$zone["domain"]];

                if (is_array($config)) {
                    $html .= '<td><strong>CNAME(s)</strong><br>';

                    foreach ($config["cname"] as $record => $cname) {
                        if ($record != $zone["domain"]) {
                            $html .= '<code>' . $record . '</code> => <code>' . $cname . '</code><br>';
                        }
                    }

                    $html .= '<strong>PROXY TARGETS</strong><br>';

                    foreach ($config["proxy_target"] as $subdomain => $target) {
                        if ($subdomain != $zone["domain"]) {
                            $html .= '<code>' . $subdomain . '</code> => <code>' . $target . '</code><br>';
                        }
                    }

                    $html .= '</td></tr>';
                } else {
                    $nservers = explode(" ", $config);
                    $html .= '<td><strong>NAMESERVERS</strong><br><code>' . $nservers[0] . '<br>' . $nservers[1] . '</code></td></tr>';
                }
            } else {
                $html .= '<td> Reprovision: <input type="checkbox" name="zone[]" value="' . $zone["id"] . '" checked="checked"></td></tr>';
            }
        }

        return $html;
    }

    public function isCnameSetup()
    {
        return $this->cname_setup;
    }

    public function isFullSetup()
    {
        return $this->full_setup;
    }

    public function isReprovisionNeeded()
    {
        return $this->reprovision_needed;
    }

    public function getMessages()
    {
        return $this->messages;
    }
}
