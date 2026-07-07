<?php

/**
 * NiceYou SoftOne ERP - product push (eshop -> ERP).
 *
 * NiceYou business model: the eshop owns the catalog. Changed products are
 * queued into a transactions table (status TODO) from a last-modified
 * watermark, then drained one by one to the ERP as ITEM setData calls.
 * Each enabled variation combination becomes its own ERP item; products
 * without combinations push a single item with combinationid 0.
 * Before creating a new ERP item, the sender looks the EAN up in the ERP
 * (ITEM.CODE1) and adopts the existing item instead of duplicating it.
 */
class ADDON_NICEYOUS1ERP_PRODUCTS extends ADDON_NICEYOUS1ERP
{
  const QUEUE_BATCH = 500;
  const SEND_BATCH = 500;

  /**
   * Queue every product modified since the stored watermark. Returns the
   * number of queued transactions.
   */
  public function QueueChangedProducts(): int
  {
    $watermark = (int)$this->GetValue('lastProductPush');

    $query = "
      SELECT p.productid, p.prodname, p.proddesc, p.prodcode, p.european_article_number,
             p.prodprice, p.tax_class_id, p.prodcurl, p.prodlastmodified,
             COALESCE(c.combinationid, 0) AS combinationid,
             c.vcsku, c.vcbarcode, c.vcpricediff, c.vcprice, c.vcoptionids
      FROM [|PREFIX|]products p
      LEFT JOIN [|PREFIX|]product_variation_combinations c
        ON (c.vcproductid = p.productid AND c.vcenabled = 1)
      WHERE p.prodvisible = 1 AND p.prodprice > 0 AND p.prodlastmodified > ?
      ORDER BY p.prodlastmodified ASC
      LIMIT " . self::QUEUE_BATCH . ";";

    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $watermark, PDO::PARAM_INT);
    $rows = $GLOBALS['db']->FetchAll($result);

    if (empty($rows)) {
      return 0;
    }

    $queued = 0;
    $maxModified = $watermark;

    foreach ($rows as $row) {
      $transaction = $this->buildTransaction($row);

      $saveData = [
        'productid' => (int)$row['productid'],
        'combinationid' => (int)$row['combinationid'],
        'payload' => json_encode($transaction, JSON_UNESCAPED_UNICODE),
        'status' => 'TODO',
        'created' => time(),
      ];
      $GLOBALS['db']->InsertQuery('addon_niceyous1erp_transactions', $saveData);

      if (isset($saveData['last_insert_id']) && isId($saveData['last_insert_id'])) {
        $queued++;
      }

      $maxModified = max($maxModified, (int)$row['prodlastmodified']);
    }

    $this->SetModuleVar('lastProductPush', $maxModified);

    return $queued;
  }

  /**
   * Drain the TODO queue against the ERP. Returns [sent, failed].
   */
  public function SendQueuedProducts(): array
  {
    $api = $this->ConnectApi();
    $cfg = $this->BuildPayloadConfig();

    $query = "SELECT * FROM [|PREFIX|]addon_niceyous1erp_transactions WHERE status = 'TODO' ORDER BY transactionid ASC LIMIT " . self::SEND_BATCH . ";";
    $result = $GLOBALS['db']->Query($query);
    $transactions = $GLOBALS['db']->FetchAll($result);

    $sent = 0;
    $failed = 0;

    foreach ($transactions as $tr) {
      $t = json_decode($tr['payload'], true);
      if (!is_array($t)) {
        $this->markTransaction((int)$tr['transactionid'], 'ERROR', 'Corrupt payload');
        $failed++;
        continue;
      }

      $erpId = $this->GetProductMtrl((int)$t['productid'], (int)$t['combinationid']);

      // NiceYou dedup rule: adopt an ERP item that already carries this EAN.
      if ($erpId === '' && !empty($t['ean'])) {
        $erpId = $api->findFirstRowId('ITEM', 'WSItems', 'ITEM.CODE1=' . $t['ean']);
        if ($erpId !== '') {
          $this->SaveProductMtrl((int)$t['productid'], (int)$t['combinationid'], $erpId);
        }
      }

      try {
        if ($erpId === '') {
          $response = $api->setData('ITEM', '', ADDON_NICEYOUS1ERP_PAYLOADS::itemInsert($t, $cfg));

          if (!empty($response['success'])) {
            $newId = (string)$response['id'];
            $this->SaveProductMtrl((int)$t['productid'], (int)$t['combinationid'], $newId);
            $this->markTransaction((int)$tr['transactionid'], 'DONE');
            $sent++;

            // Second call attaches the cover image (matches the NiceYou flow:
            // the ITEDOCDATA row needs the freshly minted item id as its key).
            if (!empty($t['image'])) {
              $api->setData('ITEM', $newId, ['ITEDOCDATA' => [ADDON_NICEYOUS1ERP_PAYLOADS::itemImageRow($newId, $t['image'])]]);
            }
          } else {
            $this->markTransaction((int)$tr['transactionid'], 'ERROR', (string)($response['error'] ?? 'Unknown ERP error'));
            $failed++;
          }
        } else {
          $response = $api->setData('ITEM', $erpId, ADDON_NICEYOUS1ERP_PAYLOADS::itemUpdate($t, $erpId, $cfg));

          if (!empty($response['success'])) {
            $this->markTransaction((int)$tr['transactionid'], 'DONE');
            $sent++;
          } else {
            $this->markTransaction((int)$tr['transactionid'], 'ERROR', (string)($response['error'] ?? 'Unknown ERP error'));
            $failed++;
          }
        }
      } catch (Exception $e) {
        $this->markTransaction((int)$tr['transactionid'], 'ERROR', $e->getMessage());
        $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'SendQueuedProducts error', $e->getMessage());
        $failed++;
      }
    }

    return [$sent, $failed];
  }

  /**
   * Remove DONE/ERROR transactions older than the configured retention.
   */
  public function CleanUpTransactions(): void
  {
    $days = (int)$this->GetValue('CleanupDays');
    if ($days <= 0) {
      $days = 5;
    }

    $cutoff = time() - ($days * 86400);
    $GLOBALS['db']->DeleteQuery(
      'addon_niceyous1erp_transactions',
      "WHERE status IN ('DONE','ERROR') AND created < " . (int)$cutoff
    );
  }

  private function buildTransaction(array $row): array
  {
    $combinationId = (int)$row['combinationid'];

    $sku = $combinationId > 0 && trim((string)$row['vcsku']) !== '' ? trim($row['vcsku']) : trim((string)$row['prodcode']);
    $ean = $combinationId > 0 && trim((string)$row['vcbarcode']) !== '' ? trim($row['vcbarcode']) : trim((string)$row['european_article_number']);

    $price = (float)$row['prodprice'];
    if ($combinationId > 0) {
      $price = ADDON_NICEYOUS1ERP_PAYLOADS::combinationPrice($price, (string)$row['vcpricediff'], (float)$row['vcprice']);
    }

    return [
      'productid' => (int)$row['productid'],
      'combinationid' => $combinationId,
      'name' => html_entity_decode((string)$row['prodname'], ENT_QUOTES),
      'combination_name' => $combinationId > 0 ? $this->combinationLabel((string)$row['vcoptionids']) : '',
      'description' => trim(strip_tags((string)$row['proddesc'])),
      'sku' => $sku,
      'ean' => $ean,
      'price' => $price,
      'tax_class_id' => (int)$row['tax_class_id'],
      'erp_cat_id' => $this->erpCategoryForProduct((int)$row['productid']),
      'image' => $this->productImageUrl((int)$row['productid']),
      'webpage' => $this->productUrl((string)$row['prodcurl']),
    ];
  }

  private function combinationLabel(string $optionIds): string
  {
    $optionIds = trim($optionIds);
    if ($optionIds === '') {
      return '';
    }

    $ids = array_filter(array_map('intval', explode(',', $optionIds)));
    if (empty($ids)) {
      return '';
    }

    $query = "SELECT vvalue FROM [|PREFIX|]product_option_values WHERE valueid IN (" . implode(',', $ids) . ") ORDER BY vvaluesort ASC;";
    $result = $GLOBALS['db']->Query($query);
    $rows = $GLOBALS['db']->FetchAll($result);

    $values = [];
    foreach ((array)$rows as $row) {
      $values[] = (string)$row['vvalue'];
    }

    return ADDON_NICEYOUS1ERP_PAYLOADS::combinationLabel($values);
  }

  private function erpCategoryForProduct(int $productId): string
  {
    $query = "
      SELECT cm.erp_cat_id
      FROM [|PREFIX|]categoryassociations ca
      JOIN [|PREFIX|]addon_niceyous1erp_category_map cm ON cm.categoryid = ca.categoryid
      WHERE ca.productid = ?
      LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $productId, PDO::PARAM_INT);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return (string)$row['erp_cat_id'];
    }

    return '';
  }

  private function productImageUrl(int $productId): string
  {
    $image = GetProductImage($productId, 'imagefilestd');
    if (empty($image)) {
      return '';
    }

    if (strpos($image, 'http') === 0) {
      return $image;
    }

    return rtrim(GetConfig('ShopPath'), '/') . '/' . ltrim($image, '/');
  }

  private function productUrl(string $prodcurl): string
  {
    $linkPart = defined('PRODUCT_LINK_PART') ? PRODUCT_LINK_PART : 'products';
    return rtrim(GetConfig('ShopPath'), '/') . '/' . $linkPart . '/' . $prodcurl;
  }

  private function markTransaction(int $transactionId, string $status, string $message = ''): void
  {
    $saveData = [
      'status' => $status,
      'message' => $message,
    ];
    $GLOBALS['db']->UpdateQuery('addon_niceyous1erp_transactions', $saveData, 'transactionid = ' . $transactionId);
  }
}
