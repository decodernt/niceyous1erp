<?php

/**
 * NiceYou SoftOne ERP - WEB-FIFO staging table access.
 *
 * Framework-free persistence for `addon_niceyous1erp_webfifo` so the row
 * handling runs against FakeDb in the unit suite (same split as
 * ADDON_NICEYOUS1ERP_CUSTOMERMAP). ADDON_NICEYOUS1ERP_WEBFIFO owns the ERP
 * round trip and delegates here.
 *
 * Staging is a single atomic upsert per row (INSERT ... ON DUPLICATE KEY
 * UPDATE) instead of SELECT-then-INSERT/UPDATE. Production incident
 * 2026-09-17: the cron's DB handle died during the multi-second WEB-FIFO
 * browser call ("MySQL server has gone away"), the existence SELECT came
 * back false from the parameter-less gone-away replay, and the follow-up
 * INSERT hit 1062 "Duplicate entry for key PRIMARY". An upsert cannot
 * take that wrong branch.
 */
class ADDON_NICEYOUS1ERP_WEBFIFOSTORE
{
  // Column layout of the WEB-FIFO browser list on the NiceYou installation.
  const COL_MTRL = 2;
  const COL_NAME = 4;
  const COL_PRICE = 5;

  /**
   * Reconnect a DB handle that died while we waited on the ERP. Long HTTP
   * round trips outlive MySQL's wait_timeout / the proxy idle timeout on
   * the cron path, and the very next query would fail with 2006.
   *
   * GALERA: never reconnect inside an open transaction — Connect() can land
   * on another node and the caller's TX died with the old connection; let
   * those statements fail loudly instead of applying in autocommit.
   */
  public static function ensureDbConnectionAlive(): void
  {
    $db = $GLOBALS['db'] ?? null;

    if ($db === null
      || !method_exists($db, 'isConnectionAlive')
      || !method_exists($db, 'Connect')
      || !empty($db->_transaction_counter)) {
      return;
    }

    if (!$db->isConnectionAlive()) {
      $db->Connect();
    }
  }

  /**
   * Stage ERP browser rows into the WEB-FIFO table. Rows without an MTRL
   * are skipped. Every row is attempted; when any upsert fails an
   * exception summarising the failures is thrown at the end so the caller
   * logs one meaningful error instead of silently under-staging.
   *
   * @param array $rows raw getBrowserData rows (positional cells)
   * @param int   $now  unix timestamp written to last_update
   * @return int number of staged rows
   * @throws RuntimeException when one or more rows could not be staged
   */
  public function stage(array $rows, int $now): int
  {
    $staged = 0;
    $failed = 0;
    $lastError = '';

    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $mtrl = trim((string)($row[self::COL_MTRL] ?? ''));
      if ($mtrl === '') {
        continue;
      }

      $name = (string)($row[self::COL_NAME] ?? '');
      $price = (float)($row[self::COL_PRICE] ?? 0);

      if ($this->upsert($mtrl, $name, $price, $now)) {
        $staged++;
      } else {
        $failed++;
        $lastError = (string)$GLOBALS['db']->GetErrorMsg();
      }
    }

    if ($failed > 0) {
      throw new RuntimeException(
        'WEB-FIFO staging failed for ' . $failed . ' of ' . ($staged + $failed) . ' rows'
        . ($lastError !== '' ? ' (last error: ' . $lastError . ')' : '')
      );
    }

    return $staged;
  }

  /**
   * Atomic insert-or-update of one staged row. Re-arms `applied` so the
   * next Apply() pass writes the (possibly unchanged) price again.
   */
  private function upsert(string $mtrl, string $name, float $price, int $now): bool
  {
    $query = "INSERT INTO [|PREFIX|]addon_niceyous1erp_webfifo (mtrl, name, purchase_price, last_update, applied)
      VALUES (?, ?, ?, ?, 0)
      ON DUPLICATE KEY UPDATE name = VALUES(name), purchase_price = VALUES(purchase_price), last_update = VALUES(last_update), applied = 0;";

    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $mtrl, PDO::PARAM_STR);
    $GLOBALS['db']->bindParam($result, 2, $name, PDO::PARAM_STR);
    $GLOBALS['db']->bindParam($result, 3, $price, PDO::PARAM_STR);
    $GLOBALS['db']->bindParam($result, 4, $now, PDO::PARAM_INT);

    return (bool)$GLOBALS['db']->Execute($result);
  }

  /**
   * Apply staged purchase prices to the mapped products' cost price and
   * flag every pending row as applied (mapped or not).
   *
   * @return int number of updated products
   */
  public function applyPending(): int
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
