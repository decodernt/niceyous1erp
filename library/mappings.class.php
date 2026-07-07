<?php

/**
 * NiceYou SoftOne ERP - one-time bootstrap mappings.
 *
 * Run once when a store with an existing catalog is first connected:
 *   - BootstrapProducts(): walk the ERP item list (WSItems) and pair items
 *     to eshop products by EAN/barcode, filling the product map.
 *   - BootstrapCategories(): walk the ERP item-category list and pair
 *     categories to eshop categories by exact name, filling the category map.
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

  const PAGE_SIZE = 5000;

  /**
   * Returns [mapped, skipped].
   */
  public function BootstrapProducts(): array
  {
    $api = $this->ConnectApi();

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

        [$productId, $combinationId] = $this->findProductByEan($ean);

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

      $categoryId = null;
      $catTitle = null;

      $query = "SELECT categoryid, catname FROM [|PREFIX|]categories WHERE catname = ? LIMIT 1;";
      $result = $GLOBALS['db']->Query($query);
      $GLOBALS['db']->bindParam($result, 1, $erpTitle, PDO::PARAM_STR);
      if ($catRow = $GLOBALS['db']->FetchOne($result)) {
        $categoryId = (int)$catRow['categoryid'];
        $catTitle = (string)$catRow['catname'];
        $mapped++;
      } else {
        $unmatched++;
      }

      $existsQuery = "SELECT mapid FROM [|PREFIX|]addon_niceyous1erp_category_map WHERE erp_cat_id = ?;";
      $existsResult = $GLOBALS['db']->Query($existsQuery);
      $GLOBALS['db']->bindParam($existsResult, 1, $erpCatId, PDO::PARAM_STR);

      if ($existsRow = $GLOBALS['db']->FetchOne($existsResult)) {
        $saveData = [
          'categoryid' => $categoryId,
          'erp_title' => $erpTitle,
          'cat_title' => $catTitle,
        ];
        $GLOBALS['db']->UpdateQuery('addon_niceyous1erp_category_map', $saveData, 'mapid = ' . (int)$existsRow['mapid'], true);
      } else {
        $saveData = [
          'categoryid' => $categoryId,
          'erp_cat_id' => $erpCatId,
          'erp_title' => $erpTitle,
          'cat_title' => $catTitle,
        ];
        $GLOBALS['db']->InsertQuery('addon_niceyous1erp_category_map', $saveData, true);
      }
    }

    return [$mapped, $unmatched];
  }

  /**
   * Match an eshop product (or variation combination) by EAN/barcode.
   * Returns [productid, combinationid] — [0, 0] when nothing matches.
   */
  private function findProductByEan(string $ean): array
  {
    $query = "SELECT productid FROM [|PREFIX|]products WHERE european_article_number = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $ean, PDO::PARAM_STR);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return [(int)$row['productid'], 0];
    }

    $query = "SELECT vcproductid, combinationid FROM [|PREFIX|]product_variation_combinations WHERE vcbarcode = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $ean, PDO::PARAM_STR);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return [(int)$row['vcproductid'], (int)$row['combinationid']];
    }

    return [0, 0];
  }
}
