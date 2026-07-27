<?php

/**
 * NiceYou SoftOne ERP - order sync (eshop -> ERP).
 *
 * Technical skeleton follows the SoftOne addon's ORDERS_SOFTONE (sync types,
 * receipt/report bookkeeping, one retry on a failed update); the payload
 * shapes follow the NiceYou business model:
 *   - customer upsert with local map + ERP dedup by email then phone
 *   - SALDOC with receipt/invoice series, FINCODE = order id
 *   - MTRDOC with SOCARRIER courier code
 *   - ITELINES priced tax-inclusive (receipts) / tax-exclusive (invoices)
 *   - EXPANAL expense rows for shipping + COD fee, net of VAT
 */
class ADDON_NICEYOUS1ERP_ORDERS extends ADDON_NICEYOUS1ERP
{
  public const IS_MANUAL_SYNC = 1;
  public const IS_NORMAL_SYNC = 2;
  public const IS_UPDATE_STATUS_SYNC = 3;
  public const IS_ORDER_UPDATE_SYNC = 4;
  public const IS_SHIPMENT_CREATED_SYNC = 5;

  private $api;
  private $cfg = [];

  private $orderId;
  private $isInvoice = false;
  private $trdr = '';
  private $orderInfo;

  private $syncType = self::IS_NORMAL_SYNC;

  public function SetSyncType($syncType = self::IS_NORMAL_SYNC)
  {
    $this->syncType = $syncType;
  }

  private function getSyncTypeString(): string
  {
    return match ($this->syncType) {
      self::IS_MANUAL_SYNC => 'Manual',
      self::IS_NORMAL_SYNC => 'Normal',
      self::IS_UPDATE_STATUS_SYNC => 'Update Status',
      self::IS_ORDER_UPDATE_SYNC => 'Order Update',
      self::IS_SHIPMENT_CREATED_SYNC => 'Shipment Created',
      default => 'Unknown',
    };
  }

  public function HandleOrderToken($ordToken)
  {
    $order = LoadPendingOrdersByToken($ordToken);
    return $this->HandleOrder($order);
  }

  public function HandleOrder($order)
  {
    if (!$order) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'HandleOrder empty order provided');
      return false;
    }

    try {
      $this->api = $this->ConnectApi();
    } catch (Exception $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'HandleOrder Login Failed', $e->getMessage());
      return false;
    }

    $this->cfg = $this->BuildPayloadConfig();
    $this->LoadPaymentShippingAssocs();

    try {
      return $this->BuildOrder($order);
    } catch (Exception $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'HandleOrder Error', $e->getMessage());
      return ['result' => false, 'message' => $e->getMessage()];
    }
  }

  /**
   * @throws Exception
   */
  private function BuildOrder($order)
  {
    $orderRow = current($order['orders']);
    $this->orderId = (int)$orderRow['orderid'];

    $orderInfo = GetOrder($this->orderId, true, true);

    // Only physical products travel to the ERP.
    $orderInfo['products'] = array_filter($orderInfo['products'], function ($product) {
      return $product['ordprodtype'] == 'physical';
    });

    $this->orderInfo = $orderInfo;
    $this->isInvoice = ADDON_NICEYOUS1ERP_PAYLOADS::isInvoiceOrder($orderInfo);

    $this->trdr = $this->UpsertCustomer($orderInfo);
    if ($this->trdr === '') {
      $this->OrderReport('', 'Customer sync failed (' . $this->getSyncTypeString() . ')', '', MSG_ERROR);
      return ['result' => false, 'message' => GetLang($this->langVar . 'CustomerError')];
    }

    return $this->attemptSendOrder(false);
  }

  /**
   * NiceYou customer flow: local map first; otherwise search the ERP by
   * email, then by phone; then setData CUSTOMER (insert or update) and
   * remember the mapping for registered customers.
   */
  private function UpsertCustomer(array $orderInfo): string
  {
    $customerId = (int)$orderInfo['ordcustid'];
    $customerKey = '';
    $mapped = false;

    if ($customerId > 0) {
      $query = "SELECT erp_trdr FROM [|PREFIX|]addon_niceyous1erp_customer_map WHERE customerid = ?;";
      $result = $GLOBALS['db']->Query($query);
      $GLOBALS['db']->bindParam($result, 1, $customerId, PDO::PARAM_INT);
      if ($row = $GLOBALS['db']->FetchOne($result)) {
        $customerKey = (string)$row['erp_trdr'];
        $mapped = true;
      }
    }

    if ($customerKey === '' && !empty($orderInfo['ordbillemail'])) {
      $customerKey = $this->api->findFirstRowId('CUSTOMER', 'WSItems', 'CUSTOMER.EMAIL="' . $orderInfo['ordbillemail'] . '"');
    }

    if ($customerKey === '' && !empty($orderInfo['ordbillphone'])) {
      $customerKey = $this->api->findFirstRowId('CUSTOMER', 'WSItems', 'CUSTOMER.PHONE01=' . $orderInfo['ordbillphone']);
    }

    $payload = ADDON_NICEYOUS1ERP_PAYLOADS::customer($orderInfo, $this->isInvoice, $this->cfg);

    try {
      $response = $this->api->setData('CUSTOMER', $customerKey, ['CUSTOMER' => [$payload]]);
    } catch (Exception $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'UpsertCustomer error', $e->getMessage());
      return '';
    }

    if (empty($response['success'])) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'UpsertCustomer rejected', (string)($response['error'] ?? ''));
      return '';
    }

    $trdr = $customerKey !== '' ? $customerKey : (string)$response['id'];

    if ($customerId > 0 && !$mapped && $trdr !== '') {
      $saveData = [
        'customerid' => $customerId,
        'erp_trdr' => $trdr,
        'last_update' => time(),
      ];
      $GLOBALS['db']->InsertIgnoreQuery('addon_niceyous1erp_customer_map', $saveData);
    }

    return $trdr;
  }

  private function attemptSendOrder(bool $isRetry)
  {
    $orderInfo = $this->orderInfo;

    $receiptId = $isRetry ? '' : $this->FindReceiptIdByOrderId();
    $isNewOrder = ($receiptId === '');

    $paymentModule = (string)$orderInfo['orderpaymentmodule'];
    $paymentCode = ADDON_NICEYOUS1ERP_PAYLOADS::moduleCode($paymentModule, $this->PaymentShippingAssocs, $this->cfg['defaultPaymentCode']);
    $isCod = ADDON_NICEYOUS1ERP_PAYLOADS::isCodModule($paymentModule, $this->cfg);

    if (ADDON_NICEYOUS1ERP_PAYLOADS::isIrisTransaction($this->loadCompletedTransaction($paymentModule))) {
      $paymentCode = (string)$this->cfg['irisPaymentCode'];
    }

    $discountTotal = (float)($orderInfo['orddiscountamount'] ?? 0);

    $data = [];
    $data['SALDOC'] = [ADDON_NICEYOUS1ERP_PAYLOADS::saldocHeader(
      $this->isInvoice,
      $this->trdr,
      $paymentCode,
      $this->orderId,
      $discountTotal,
      $this->cfg
    )];

    $shipping = current($orderInfo['products']);
    $carrierCode = ADDON_NICEYOUS1ERP_PAYLOADS::moduleCode((string)($shipping['shipment_module'] ?? ''), $this->PaymentShippingAssocs, $this->cfg['defaultCarrierCode']);
    $data['MTRDOC'] = [ADDON_NICEYOUS1ERP_PAYLOADS::mtrdoc($carrierCode, $this->cfg)];

    [$iteLines, $lineNum] = ADDON_NICEYOUS1ERP_PAYLOADS::orderLines($this->resolveLineInputs($orderInfo['products']), $this->isInvoice, $this->cfg);

    if (empty($iteLines)) {
      $this->OrderReport('', 'No ERP-mapped products on order (' . $this->getSyncTypeString() . ')', '', MSG_ERROR);
      return ['result' => false, 'message' => GetLang($this->langVar . 'NoMappedProducts')];
    }

    $data['ITELINES'] = $iteLines;

    $shippingIncTax = (float)$orderInfo['shipping_cost_inc_tax']
      + (float)($orderInfo['handling_cost_inc_tax'] ?? 0)
      + (float)($orderInfo['wrapping_cost_inc_tax'] ?? 0);

    [$expenseRows] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses($shippingIncTax, $isCod, $lineNum, $this->cfg);
    if (!empty($expenseRows)) {
      $data['EXPANAL'] = $expenseRows;
    }

    try {
      $response = $this->api->setData('SALDOC', $receiptId, $data);
    } catch (Exception $e) {
      $this->OrderReport('', 'Connection error: ' . $e->getMessage() . ' (' . $this->getSyncTypeString() . ')', json_encode($data, JSON_UNESCAPED_UNICODE), MSG_ERROR);
      return ['result' => false, 'message' => $e->getMessage()];
    }

    $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE);
    $messageBegin = $isNewOrder ? 'New ' : 'Existing ';

    if (!empty($response['success'])) {
      $this->SaveReceiptId((string)$response['id']);
      $this->OrderReport((string)$response['id'], $messageBegin . 'Order Synced (' . $this->getSyncTypeString() . ')', $payloadJson, MSG_SUCCESS);
      return ['result' => true, 'message' => GetLang($this->langVar . 'OrderSynced', ['softOneID' => $response['id']])];
    }

    $errorMessage = (string)($response['error'] ?? 'Unknown ERP error');

    // A stale receipt id (deleted document ERP-side) fails the update path;
    // retry once as a fresh document — same recovery as the SoftOne addon.
    if (!$isRetry && !$isNewOrder) {
      $this->OrderReport('', $messageBegin . 'Order Not Accepted - ' . $errorMessage . ' - Retrying (' . $this->getSyncTypeString() . ')', $payloadJson, MSG_ERROR);
      return $this->attemptSendOrder(true);
    }

    $this->OrderReport('', $messageBegin . 'Order Not Accepted - ' . $errorMessage . ' (' . $this->getSyncTypeString() . ')', $payloadJson, MSG_ERROR);
    return ['result' => false, 'message' => $errorMessage];
  }

  /**
   * Resolve order products to ERP line inputs via the product map.
   */
  private function resolveLineInputs(array $products): array
  {
    $lines = [];

    foreach ($products as $product) {
      $productId = (int)$product['ordprodid'];
      $combinationId = isset($product['ordprodvariationid']) && isId($product['ordprodvariationid'])
        ? (int)$product['ordprodvariationid']
        : 0;

      $lines[] = [
        'mtrl' => $this->GetProductMtrl($productId, $combinationId),
        'qty' => (int)$product['ordprodqty'],
        'price_inc' => (float)$product['dis_price_inc_tax'],
        'vat_rate' => (float)($product['vat_rate'] ?? 0),
        'tax_class_id' => $this->productTaxClass($productId),
      ];
    }

    return $lines;
  }

  private function productTaxClass(int $productId): int
  {
    $query = "SELECT tax_class_id FROM [|PREFIX|]products WHERE productid = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $productId, PDO::PARAM_INT);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return (int)$row['tax_class_id'];
    }
    return 0;
  }

  /**
   * Completed gateway transaction for this order, or null when the order
   * has none (offline payment) or it never completed.
   */
  private function loadCompletedTransaction(string $paymentModule): ?array
  {
    $transaction = GetClass('TRANSACTION')->LoadByTransactionOrderId($this->orderId, $paymentModule);

    if (!$transaction || (int)$transaction['status'] !== TRANS_STATUS_COMPLETED) {
      return null;
    }

    return $transaction;
  }

  private function FindReceiptIdByOrderId(): string
  {
    $query = "SELECT receipt_id FROM [|PREFIX|]addon_niceyous1erp_order_receipts WHERE fk_order_id = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $this->orderId, PDO::PARAM_INT);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return (string)$row['receipt_id'];
    }
    return '';
  }

  private function SaveReceiptId(string $receiptId): void
  {
    $saveData = [
      'fk_order_id' => $this->orderId,
      'receipt_id' => $receiptId,
      'datetime' => time(),
    ];
    $GLOBALS['db']->InsertIgnoreQuery('addon_niceyous1erp_order_receipts', $saveData);
  }

  private function OrderReport($receiptId = '', $message = '', $payload = '', $result_type = MSG_SUCCESS): void
  {
    $saveData = [
      'naga_order_id' => $this->orderId,
      'receipt_id' => $receiptId,
      'syncType' => $this->syncType,
      'message' => $message,
      'payload' => $payload,
      'result_type' => $result_type,
      'datetime' => time(),
    ];

    $GLOBALS['db']->InsertQuery('addon_niceyous1erp_sync_orders_report', $saveData);
  }
}
