{* changes to this file must also be made in zones.php *}
<table id="cfzones">
    <tr>
        <th>Zone</th>
        <th>Configuration</th>
    </tr>
    {if $cf_zones_html}
        {$cf_zones_html}
    {else}
        <tr>
            <td colspan="2" class="text-center">
                No Zones Found
            </td>
        </tr>
    {/if}
</table>
