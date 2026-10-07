<div class="crm-block crm-form-block crm-wallet-manage-event-wallet-form-block">
  <div class="help">
    {ts}Override the default wallet visuals for this event only. Leave these fields empty to keep the global branding configured in CiviWallet settings.{/ts}
  </div>

  <table class="form-layout-compressed">
    {if !empty($walletFields.color)}
      <tr class="crm-wallet-event-color-form-block-custom_{$walletFields.color.id}">
        <td class="label">{$form["custom_`$walletFields.color.id`"].label}</td>
        <td>
          {$form["custom_`$walletFields.color.id`"].html}
          <div class="description">{ts}Hex color override for the event pass, for example #ea148c.{/ts}</div>
        </td>
      </tr>
    {/if}
    {if !empty($walletFields.image)}
      <tr class="crm-wallet-event-image-form-block-custom_{$walletFields.image.id}">
        <td class="label">{$form["custom_`$walletFields.image.id`"].label}</td>
        <td>
          {$form["custom_`$walletFields.image.id`"].html}
          <div class="description">{ts}Optional event image override used on participant wallet passes. Recommended strip/hero artwork with a wide format.{/ts}</div>
          {if $walletCurrentImage}
            <div class="description">{ts 1=$walletCurrentImage.fileName}Current file: %1{/ts}</div>
          {/if}
        </td>
      </tr>
    {/if}
  </table>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
