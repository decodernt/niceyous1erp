<?php

/**
 * NiceYou SoftOne ERP - one-time bootstrap mappings.
 *
 * Run once when a store with an existing catalog is first connected:
 *   - BootstrapProducts(): walk the ERP item list (WSItems) and pair items
 *     to eshop products by EAN/barcode, filling the product map.
 *   - BootstrapCategories(): walk the ERP item-category list and pair the
 *     entries to eshop BRANDS by exact name, filling the category map.
 *     NiceYou's MTRCATEGORY list holds brand names, not category names.
 */
class ADDON_NICEYOUS1ERP_MAPPINGS extends ADDON_NICEYOUS1ERP
{
  // Column layout of the WSItems browser lists on the NiceYou installation.
  const ITEM_COL_KEY = 0;   // "ITEM;12345"
  const ITEM_COL_CODE = 2;
  const ITEM_COL_TITLE = 3;
  const ITEM_COL_EAN = 4;

  const CAT_COL_ID = 2;
  const CAT_COL_TITLE = 4;

  const PAGE_SIZE = 1000;

  /**
   * Returns [mapped, skipped].
   */
  public function BootstrapProducts(): array
  {
    $api = $this->ConnectApi();
    $eanIndex = $this->buildEanIndex();

    $mapped = 0;
    $skipped = 0;
    $start = 0;

    while (true) {
      $rows = $api->browserRows('ITEM', 'WSItems', null, self::PAGE_SIZE, $start);
      if (empty($rows)) {
        break;
      }

      foreach ($rows as $row) {
        $erpId = ADDON_NICEYOUS1ERP_PAYLOADS::browserRowId($row[self::ITEM_COL_KEY]);
        $ean = trim((string)($row[self::ITEM_COL_EAN] ?? ''));

        if ($erpId === '' || $ean === '') {
          $skipped++;
          continue;
        }

        [$productId, $combinationId] = $eanIndex[$ean] ?? [0, 0];

        if ($productId === 0) {
          $skipped++;
          continue;
        }

        $existing = $this->GetProductMtrl($productId, $combinationId);
        if ($existing === '') {
          $this->SaveProductMtrl($productId, $combinationId, $erpId);
          $mapped++;
        } elseif ($existing !== $erpId) {
          // ERP re-keyed the item (NiceYou behavior: trust the ERP list).
          $saveData = ['erp_mtrl' => $erpId, 'last_update' => time()];
          $GLOBALS['db']->UpdateQuery(
            'addon_niceyous1erp_product_map',
            $saveData,
            'productid = ' . $productId . ' AND combinationid = ' . $combinationId
          );
          $mapped++;
        }
      }

      if (count($rows) < self::PAGE_SIZE) {
        break;
      }
      $start += self::PAGE_SIZE;
    }

    return [$mapped, $skipped];
  }

  /**
   * Returns [mapped, unmatched].
   */
  public function BootstrapCategories(): array
  {
    $api = $this->ConnectApi();
    $rows = $api->browserRows('ITECATEGORY', 'WSItems');

    $mapped = 0;
    $unmatched = 0;

    foreach ($rows as $row) {
      $erpCatId = trim((string)($row[self::CAT_COL_ID] ?? ''));
      $erpTitle = trim((string)($row[self::CAT_COL_TITLE] ?? ''));

      if ($erpCatId === '') {
        continue;
      }

      $brandId = null;
      $brandTitle = null;

      $query = "SELECT brandid, brandname FROM [|PREFIX|]brands WHERE brandname = ? LIMIT 1;";
      $result = $GLOBALS['db']->Query($query);
      $GLOBALS['db']->bindParam($result, 1, $erpTitle, PDO::PARAM_STR);
      if ($brandRow = $GLOBALS['db']->FetchOne($result)) {
        $brandId = (int)$brandRow['brandid'];
        $brandTitle = (string)$brandRow['brandname'];
        $mapped++;
      } else {
        $unmatched++;
      }

      $existsQuery = "SELECT mapid FROM [|PREFIX|]addon_niceyous1erp_category_map WHERE erp_cat_id = ?;";
      $existsResult = $GLOBALS['db']->Query($existsQuery);
      $GLOBALS['db']->bindParam($existsResult, 1, $erpCatId, PDO::PARAM_STR);

      if ($existsRow = $GLOBALS['db']->FetchOne($existsResult)) {
        $saveData = [
          'brandid' => $brandId,
          'erp_title' => $erpTitle,
          'brand_title' => $brandTitle,
        ];
        $GLOBALS['db']->UpdateQuery('addon_niceyous1erp_category_map', $saveData, 'mapid = ' . (int)$existsRow['mapid'], true);
      } else {
        $saveData = [
          'brandid' => $brandId,
          'erp_cat_id' => $erpCatId,
          'erp_title' => $erpTitle,
          'brand_title' => $brandTitle,
        ];
        $GLOBALS['db']->InsertQuery('addon_niceyous1erp_category_map', $saveData, true);
      }
    }

    return [$mapped, $unmatched];
  }

  /**
   * One in-memory lookup EAN/barcode → [productid, combinationid] instead of
   * two prepared statements per ERP item (which exhausted the request memory
   * on large catalogs). Products win over combinations, first row wins on
   * duplicates — same precedence the old per-row LIMIT 1 queries had.
   */
  private function buildEanIndex(): array
  {
    $index = [];

    $query = "SELECT productid, european_article_number FROM [|PREFIX|]products WHERE european_article_number != '' ORDER BY productid ASC;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->Execute($result);
    while ($row = $GLOBALS['db']->Fetch($result)) {
      $ean = trim((string)$row['european_article_number']);
      if ($ean !== '' && !isset($index[$ean])) {
        $index[$ean] = [(int)$row['productid'], 0];
      }
    }

    $query = "SELECT vcproductid, combinationid, vcbarcode FROM [|PREFIX|]product_variation_combinations WHERE vcbarcode != '' ORDER BY vcproductid ASC, combinationid ASC;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->Execute($result);
    while ($row = $GLOBALS['db']->Fetch($result)) {
      $barcode = trim((string)$row['vcbarcode']);
      if ($barcode !== '' && !isset($index[$barcode])) {
        $index[$barcode] = [(int)$row['vcproductid'], (int)$row['combinationid']];
      }
    }

    return $index;
  }
}
