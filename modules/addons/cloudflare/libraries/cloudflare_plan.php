<?php

class Cloudflare_Plan
{

    private $addon_type    = array();
    private $addon_pricing = array();
    private $auth_dns      = false;
    private $plan_tag      = "";
    protected $default_description = "<br>Make your website faster and safer with CloudFlare<br><i>By selecting this addon, you agree to CloudFlare's <a href=\"https://www.cloudflare.com/terms\" target=\"_blank\">Terms and Conditions</a></i><br>";

    public function __construct($params = array())
    {
        if (isset($params["auth_dns"])) {
            $this->auth_dns = $params["auth_dns"];
        }

        if ($params["product_price"]) {
            $this->setResellerPlan($params);
        } else {
            $this->setFreePlan();
        }
    }

    private function setResellerPlan($params)
    {
        $this->setPlan($params["sbase_name"], $params["sub_label"], $params["product_price"]);
    }

    private function setFreePlan()
    {
        $this->setPlan("CloudFlare Free");
    }

    private function setPlan($name, $plan_tag = "", $price = null)
    {
        if ($this->auth_dns) {
            $name .= " with CloudFlare DNS";
        }

        $this->plan_tag = $plan_tag;

        $this->addon_type = array(
            "name" => $name,
            "description" => $this->default_description,
            "billingcycle" => ($price ? "Monthly" : "Free Account"),
            "showorder" => "on",
            "welcomeemail" => 0,
            "weight" => 1,
            "autoactivate" => "on");

        $this->addon_pricing = array(
            "type" => "addon",
            "currency" => 1
        );

        if ($price) {
            $this->addon_pricing["monthly"] = $price;
        }
    }

    public function getAddonType()
    {
        return $this->addon_type;
    }

    public function getAddonPricing()
    {
        return $this->addon_pricing;
    }

    public function getPlanTag()
    {
        return $this->plan_tag;
    }

    public function isAuthDNS()
    {
        return $this->auth_dns;
    }
}
