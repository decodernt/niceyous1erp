<?php

require_once(__DIR__ . '/webfifostore.class.php');

/**
 * NiceYou SoftOne ERP - WEB-FIFO purchase price sync (ERP -> eshop).
 *
 * The only reverse-direction flow in the NiceYou model: the ERP publishes a
 * "WEB-FIFO" browser list with each item's purchase (FIFO) price. Fetch()
 * pulls the list and stages the rows locally, Apply() writes them onto the
 * products' cost price via the product map, flagging each staged row once
 * applied. Row handling lives in ADDON_NICEYOUS1ERP_WEBFIFOSTORE (unit
 * tested against FakeDb); this class owns the ERP round trip.
 */
class ADDON_NICEYOUS1ERP_WEBFIFO extends ADDON_NICEYOUS1ERP
{
  // Column layout of the WEB-FIFO browser list on the NiceYou installation.
  const COL_MTRL = ADDON_NICEYOUS1ERP_WEBFIFOSTORE::COL_MTRL;
  const COL_NAME = ADDON_NICEYOUS1ERP_WEBFIFOSTORE::COL_NAME;
  const COL_PRICE = ADDON_NICEYOUS1ERP_WEBFIFOSTORE::COL_PRICE;

  /**
   * Stage the ERP purchase prices. Returns the number of staged rows.
   * @throws Exception on ERP/auth failure or when rows could not be staged
   */
  public function Fetch(): int
  {
    $api = $this->ConnectApi();
    $rows = $api->browserRows('ITEM', 'WEB-FIFO');

    // The browser round trip can take long enough for the cron's DB handle
    // to be dropped ("MySQL server has gone away" on the first query after
    // it). Re-establish it before touching the staging table.
    ADDON_NICEYOUS1ERP_WEBFIFOSTORE::ensureDbConnectionAlive();

    return (new ADDON_NICEYOUS1ERP_WEBFIFOSTORE())->stage($rows, time());
  }

  /**
   * Apply staged purchase prices to the mapped products' cost price.
   * Returns the number of updated products.
   */
  public function Apply(): int
  {
    return (new ADDON_NICEYOUS1ERP_WEBFIFOSTORE())->applyPending();
  }
}
