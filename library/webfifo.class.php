<?php

/**
 * NiceYou SoftOne ERP - WEB-FIFO purchase price sync (ERP -> eshop).
 *
 * The only reverse-direction flow in the NiceYou model: the ERP publishes a
 * "WEB-FIFO" browser list with each item's purchase (FIFO) price. Fetch()
 * stages the rows locally, Apply() writes them onto the products' cost
 * price via the product map, flagging each staged row once applied.
 */
class ADDON_NICEYOUS1ERP_WEBFIFO extends ADDON_NICEYOUS1ERP
{
  // Column layout of the WEB-FIFO browser list on the NiceYou installation.
  const COL_MTRL = 2;
  const COL_NAME = 4;
  const COL_PRICE = 5;

  /**
   * Stage the ERP purchase prices. Returns the number of staged rows.
   */
  public function Fetch(): int
  {
    $api = $this->ConnectApi();
    $rows = $api->browserRows('ITEM', 'WEB-FIFO');

    $staged = 0;
    $now = time();

    foreach ($rows as $row) {
      $mtrl = trim((string)($row[self::COL_MTRL] ?? ''));
      if ($mtrl === '') {
        continue;
      }

      $name = (string)($row[self::COL_NAME] ?? '');
      $price = (float)($row[self::COL_PRICE] ?? 0);

      $query = "SELECT mtrl FROM [|PREFIX|]addon_niceyous1erp_webfifo WHERE mtrl = ?;";
      $result = $GLOBALS['db']->Query($query);
      $GLOBALS['db']->bindParam($result, 1, $mtrl, PDO::PARAM_STR);

      if ($GLOBALS['db']->FetchOne($result)) {
        $saveData = [
          'name' => $name,
          'purchase_price' => $price,
          'last_update' => $now,
          'applied' => 0,
        ];
        $GLOBALS['db']->UpdateQuery('addon_niceyous1erp_webfifo', $saveData, "mtrl = '" . $GLOBALS['db']->Quote($mtrl) . "'");
      } else {
        $saveData = [
          'mtrl' => $mtrl,
          'name' => $name,
          'purchase_price' => $price,
          'last_update' => $now,
          'applied' => 0,
        ];
        $GLOBALS['db']->InsertQuery('addon_niceyous1erp_webfifo', $saveData);
      }

      $staged++;
    }

    return $staged;
  }

  /**
   * Apply staged purchase prices to the mapped products' cost price.
   * Returns the number of updated products.
   */
  public function Apply(): int
  {
    $query = "SELECT * FROM [|PREFIX|]addon_niceyous1erp_webfifo WHERE applied = 0 AND purchase_price > 0;";
    $result = $GLOBALS['db']->Query($query);
    $rows = $GLOBALS['db']->FetchAll($result);

    $updated = 0;

    foreach ((array)$rows as $row) {
      $mapQuery = "SELECT productid FROM [|PREFIX|]addon_niceyous1erp_product_map WHERE erp_mtrl = ? LIMIT 1;";
      $mapResult = $GLOBALS['db']->Query($mapQuery);
      $GLOBALS['db']->bindParam($mapResult, 1, $row['mtrl'], PDO::PARAM_STR);

      if ($mapRow = $GLOBALS['db']->FetchOne($mapResult)) {
        $saveData = ['prodcostprice' => (float)$row['purchase_price']];
        $GLOBALS['db']->UpdateQuery('products', $saveData, 'productid = ' . (int)$mapRow['productid']);
        $updated++;
      }

      $flagData = ['applied' => 1];
      $GLOBALS['db']->UpdateQuery('addon_niceyous1erp_webfifo', $flagData, "mtrl = '" . $GLOBALS['db']->Quote($row['mtrl']) . "'");
    }

    return $updated;
  }
}
