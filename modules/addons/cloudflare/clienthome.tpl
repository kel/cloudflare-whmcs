<div class="cfcontainer">
    <h1>CloudFlare</h1>
    <br>
    {if $messages}
        <div id="cfmessages" class="notice">
            {foreach from=$messages item=message}
                {$message}
            {/foreach}
        </div>
    {/if}
    <form id="cfstatus" action="index.php?m=cloudflare" method="post">
        <input type="hidden" name="cfCsrfToken" value="{$cfCsrfToken}"/>
        <input type="hidden" name="userid" value="{$user_id}">

        {include file="$current_directory/templates/zones.tpl"}

        {if $reprovision_needed}
            {include file="$current_directory/templates/login.tpl"}
        {/if}
    </form>

    <br>

    {if $cname_instructions}
        {include file="$current_directory/templates/cname_instructions.tpl"}
    {/if}

    {if $nameserver_instructions}
        {include file="$current_directory/templates/nameserver_instructions.tpl"}
    {/if}

    {if $showclientlogs}
        <strong>Provisioning Logs</strong>

        <div class="cflogscontainer">
            <table id="cflogs">
            </table>
        </div>
    {/if}

</div>

