<?
/*
 * Any changes made to this file also need to be made in zones.tpl.
 * WHMCS hooks must return HTML (not smarty templates) and since this
 * template contains logic it fails in the ShoppingCartCheckoutCompletePage
 * hook.
 */
?>
<table id="cfzones">
    <tr>
        <th>Zone</th>
        <th>Configuration</th>
    </tr>
    <?php
        if(!empty($cf_zones_html)) {
            echo $cf_zones_html;
        } else {
            echo('<tr>
                    <td colspan="2" class="text-center">
                         No Zones Found
                     </td>
                  </tr>');
        }
    ?>
</table>