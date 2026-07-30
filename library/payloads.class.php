<?php

/**
 * NiceYou SoftOne ERP - payload builders.
 *
 * Pure static helpers that translate NagaCommerce data into the SoftOne
 * s1services payload shapes used by the NiceYou business model (ported from
 * the PrestaShop "erpintegrationsoft" module):
 *   - ITEM / ITEEXTRA / ITEDOCDATA  (product push, eshop -> ERP)
 *   - CUSTOMER                      (customer upsert on order sync)
 *   - SALDOC / MTRDOC / ITELINES / EXPANAL (order push)
 *
 * No framework dependencies on purpose so the business rules stay unit-testable.
 */
class ADDON_NICEYOUS1ERP_PAYLOADS
{

  /**
   * Default configuration (NiceYou production values). Every code is
   * overridable through the addon settings; these are the fallbacks.
   */
  public static function defaultConfig(): array
  {
    return [
      'codePrefix' => 'WEB',
      'customerCodePrefix' => 'WEB',
      'defaultVat' => '1410|ΦΠΑ 24%',
      'vatByTaxClass' => [],
      'defaultErpCategory' => '999',
      'seriesReceipt' => '6003',
      'seriesInvoice' => '6004',
      'defaultPaymentCode' => '1000',
      'irisPaymentCode' => '1014',
      'defaultCarrierCode' => '1',
      'shipmentCode' => '103',
      'shipKindCode' => '1000',
      'trucksCode' => '4',
      'trdBusinessCode' => '1009',
      'expenseVatPercent' => 24.0,
      'shippingExpenseCode' => '104',
      'codExpenseCode' => '105',
      'codModules' => ['checkout_cashondelivery'],
      'countryMap' => ['GR' => '1000|Ελλάς', 'CY' => '1014|Ελλάς'],
      'lineNumStart' => 9000000,
    ];
  }

  public static function mergeConfig(array $overrides): array
  {
    $cfg = self::defaultConfig();
    foreach ($overrides as $key => $value) {
      if ($value === '' || $value === null) {
        continue;
      }
      $cfg[$key] = $value;
    }
    return $cfg;
  }

  /* ---------------------------------------------------------------- ITEM */

  /**
   * Full ITEM payload for a product not yet known to the ERP.
   * $t is a queued transaction row (see ADDON_NICEYOUS1ERP_PRODUCTS).
   */
  public static function itemInsert(array $t, array $cfg): array
  {
    $item = [
      'NAME' => self::itemName($t),
      'CODE' => $cfg['codePrefix'] . $t['sku'],
      'CODE1' => (string)($t['ean'] ?? ''),
      'ISACTIVE' => '1',
      'MTRCATEGORY' => (string)($t['erp_cat_id'] !== '' && $t['erp_cat_id'] !== null ? $t['erp_cat_id'] : $cfg['defaultErpCategory']),
      'VAT' => self::vatLabelForTaxClass($t['tax_class_id'] ?? 0, $cfg),
      'MTRACN' => '101|Εμπόρευμα',
      'MTRUNIT1' => '101|Τεμ.',
      'PRICER' => (float)$t['price'],
      'REMARKS' => (string)($t['description'] ?? ''),
      'VARCHAR02' => (string)$t['name'],
      'APVCODE' => (string)$t['sku'],
    ];

    return [
      'ITEM' => [$item],
      'ITEEXTRA' => [['VARCHAR01' => (string)$t['name']]],
    ];
  }

  /**
   * Partial ITEM payload for an already-mapped product. The NiceYou model
   * deliberately only refreshes price, description and the eshop SKU
   * reference so ERP-side edits to name/category/VAT are never clobbered.
   * The cover image rides along in the same call as ITEDOCDATA.
   */
  public static function itemUpdate(array $t, string $erpId, array $cfg): array
  {
    $data = [
      'ITEM' => [[
        'PRICER' => (float)$t['price'],
        'REMARKS' => (string)($t['description'] ?? ''),
        'APVCODE' => (string)$t['sku'],
      ]],
      'ITEEXTRA' => [['VARCHAR01' => (string)$t['name']]],
    ];

    if (!empty($t['image'])) {
      $data['ITEDOCDATA'] = [self::itemImageRow($erpId, $t['image'])];
    }

    return $data;
  }

  /**
   * ITEDOCDATA attachment row that links the eshop cover image URL to the
   * ERP item (SOSOURCE 51 = item attachments).
   */
  public static function itemImageRow(string $erpId, string $imageUrl): array
  {
    return [
      'REFOBJID' => $erpId,
      'SOSOURCE' => '51',
      'LNUM' => '0',
      'LINENUM' => '0',
      'SOFNAME' => $imageUrl,
      'DBWHOUSED' => '0',
      'DEFXTRDOC' => '0',
      'XDOCTYPE' => '0',
      'SOMD' => '0',
    ];
  }

  public static function itemName(array $t): string
  {
    $name = trim((string)$t['name']);
    if (!empty($t['combinationid']) && trim((string)($t['combination_name'] ?? '')) !== '') {
      $name .= ' ' . trim((string)$t['combination_name']);
    }
    return $name;
  }

  /* ------------------------------------------------------------ CUSTOMER */

  /**
   * True when the buyer asked for an invoice: VAT number, tax office and
   * company name must all be present on the billing details.
   */
  public static function isInvoiceOrder(array $orderInfo): bool
  {
    return !empty($orderInfo['ordbillsocialsecnumber'])
      && !empty($orderInfo['ordbilldoy'])
      && !empty($orderInfo['ordbillcompany']);
  }

  /**
   * CUSTOMER payload from the order billing details (NiceYou shape:
   * PHONE01, fixed currency/branch, TRDBUSINESS from settings, invoice
   * fields only when an invoice was requested).
   *
   * CODE travels only on inserts: WEB{customerid} for registered customers,
   * WEBG{orderid} for guests (ordcustid=0 — the order id keeps guest codes
   * unique). Updates never send CODE so an ERP customer matched by
   * email/phone keeps whatever code it already has.
   */
  public static function customer(array $orderInfo, bool $isInvoice, array $cfg, bool $isUpdate = false): array
  {
    $customer = [
      'NAME' => trim($orderInfo['ordbillfirstname'] . ' ' . $orderInfo['ordbilllastname']),
      'EMAIL' => (string)$orderInfo['ordbillemail'],
      'PHONE01' => (string)$orderInfo['ordbillphone'],
      'ADDRESS' => trim($orderInfo['ordbillstreet1'] . ' ' . ($orderInfo['ordbillstreet1num'] ?? '')),
      'CITY' => (string)$orderInfo['ordbillsuburb'],
      'ZIP' => (string)$orderInfo['ordbillzip'],
      'SOCURRENCY' => '100|EURO',
      'BRANCH' => '1000|Εδρα',
      'ISACTIVE' => '1',
      'TRDBUSINESS' => (string)$cfg['trdBusinessCode'],
    ];

    if (!$isUpdate) {
      $customerId = (int)$orderInfo['ordcustid'];
      $customer = [
        'CODE' => $customerId > 0
          ? $cfg['customerCodePrefix'] . $customerId
          : $cfg['customerCodePrefix'] . 'G' . (int)$orderInfo['orderid'],
      ] + $customer;
    }

    $countryCode = strtoupper((string)($orderInfo['ordbillcountrycode'] ?? ''));
    if (isset($cfg['countryMap'][$countryCode])) {
      $customer['COUNTRY'] = $cfg['countryMap'][$countryCode];
    }

    if ($isInvoice) {
      $customer['AFM'] = (string)str_replace(['"', ' '], '', $orderInfo['ordbillsocialsecnumber']);
      $customer['IRSDATA'] = (string)$orderInfo['ordbilldoy'];
      $customer['JOBTYPETRD'] = (string)($orderInfo['ordbillcompanyactivity'] ?? '');
      $customer['SOTITLE'] = (string)$orderInfo['ordbillcompany'];
    }

    return $customer;
  }

  /* -------------------------------------------------------------- SALDOC */

  /**
   * SALDOC header. Series flips between the receipt and the invoice
   * document series, FINCODE carries the eshop order id back to the ERP.
   */
  public static function saldocHeader(bool $isInvoice, string $trdr, string $paymentCode, $orderId, float $discountTotal, array $cfg): array
  {
    $saldoc = [
      'SERIES' => (string)($isInvoice ? $cfg['seriesInvoice'] : $cfg['seriesReceipt']),
      'TRDR' => $trdr,
      'PAYMENT' => $paymentCode,
      'SHIPMENT' => (string)$cfg['shipmentCode'],
      'FINCODE' => (string)$orderId,
      'SHIPKIND' => (string)$cfg['shipKindCode'],
      'DISC1VAL' => (float) $discountTotal
    ];

    return $saldoc;
  }

  /**
   * MTRDOC block carrying the courier code (SOCARRIER).
   */
  public static function mtrdoc(string $carrierCode, array $cfg): array
  {
    return [
      'SOCARRIER' => $carrierCode,
      'TRUCKS' => (string)$cfg['trucksCode'],
      'TRUCKSNO' => '',
    ];
  }

  /**
   * ITELINES from resolved line inputs. Each input line:
   *   ['mtrl' => string, 'qty' => int, 'price_inc' => float,
   *    'vat_rate' => float, 'tax_class_id' => int]
   * Receipts carry tax-inclusive prices; invoices tax-exclusive (NiceYou rule).
   * Lines without an ERP mapping (empty mtrl) are skipped.
   * Returns [lines, nextLineNum].
   */
  public static function orderLines(array $lines, bool $isInvoice, array $cfg): array
  {
    $iteLines = [];
    $lineNum = (int)$cfg['lineNumStart'];

    foreach ($lines as $line) {
      if (empty($line['mtrl'])) {
        continue;
      }

      $lineNum++;
      $price = (float)$line['price_inc'];
      if ($isInvoice) {
        $vatRate = (float)($line['vat_rate'] ?? $cfg['expenseVatPercent']);
        $price = round($price / (1 + $vatRate / 100), 2);
      }

      $iteLines[] = [
        'LINENUM' => $lineNum,
        'MTRL' => (string)$line['mtrl'],
        'VAT' => self::vatCodeForTaxClass($line['tax_class_id'] ?? 0, $cfg),
        'DISC1VAL' => 0,
        'QTY1' => (int)$line['qty'],
        'PRICE' => $price,
      ];
    }

    return [$iteLines, $lineNum];
  }

  /**
   * EXPANAL expense rows: shipping cost and (when paid cash-on-delivery)
   * the COD fee, both net of VAT — the NiceYou model books these as ERP
   * expense lines, not as pseudo-products.
   * $codFeeIncTax is the fee actually charged on the order (the payment
   * module's additional cost, tax inclusive) — a free-COD order books none.
   * Returns [rows, nextLineNum].
   */
  public static function expenses(float $shippingIncTax, bool $isCod, float $codFeeIncTax, int $startLineNum, array $cfg): array
  {
    $rows = [];
    $lineNum = $startLineNum;

    if ($shippingIncTax > 0) {
      $lineNum++;
      $net = self::netOfVat($shippingIncTax, (float)$cfg['expenseVatPercent']);
      $rows[] = [
        'LINENUM' => $lineNum,
        'EXPN' => (string)$cfg['shippingExpenseCode'],
        'SOVAL' => $net,
        'EXPVAL' => $net,
      ];
    }

    if ($isCod && $codFeeIncTax > 0) {
      $lineNum++;
      $net = self::netOfVat($codFeeIncTax, (float)$cfg['expenseVatPercent']);
      $rows[] = [
        'LINENUM' => $lineNum,
        'EXPN' => (string)$cfg['codExpenseCode'],
        'SOVAL' => $net,
        'EXPVAL' => $net,
      ];
    }

    return [$rows, $lineNum];
  }

  /* ------------------------------------------------------------- helpers */

  public static function vatLabelForTaxClass($taxClassId, array $cfg): string
  {
    $key = (string)(int)$taxClassId;
    if (isset($cfg['vatByTaxClass'][$key]) && $cfg['vatByTaxClass'][$key] !== '') {
      return (string)$cfg['vatByTaxClass'][$key];
    }
    return (string)$cfg['defaultVat'];
  }

  /**
   * Numeric VAT code (order lines want "1410", not "1410|ΦΠΑ 24%").
   */
  public static function vatCodeForTaxClass($taxClassId, array $cfg): string
  {
    $label = self::vatLabelForTaxClass($taxClassId, $cfg);
    $parts = explode('|', $label);
    return trim($parts[0]);
  }

  public static function moduleCode($module, array $assocs, $default): string
  {
    if (isset($assocs[$module]) && trim((string)$assocs[$module]) !== '') {
      return (string)$assocs[$module];
    }
    return (string)$default;
  }

  public static function isCodModule($module, array $cfg): bool
  {
    return in_array((string)$module, array_map('trim', (array)$cfg['codModules']), true);
  }

  /**
   * True when a gateway transaction was actually paid via IRIS. IRIS rides
   * on the card gateway modules (Viva), so the module→code association can't
   * tell it apart; the stored gateway response can. vivasmartcheckout stores
   * the new-API camelCase response (bankId), legacy vivawallet the old-API
   * PascalCase one (BankId) — both mark IRIS as NET_IRIS.
   */
  public static function isIrisTransaction($transaction): bool
  {
    if (!is_array($transaction) || empty($transaction['extrainfo'])) {
      return false;
    }

    $extraInfo = json_decode((string)$transaction['extrainfo'], true);
    if (!is_array($extraInfo)) {
      return false;
    }

    $bankId = $extraInfo['response']['bankId'] ?? $extraInfo['response']['BankId'] ?? '';

    return $bankId === 'NET_IRIS';
  }

  public static function netOfVat(float $gross, float $vatPercent): float
  {
    if ($vatPercent <= 0) {
      return round($gross, 2);
    }
    return round($gross / (1 + $vatPercent / 100), 2);
  }

  /**
   * SoftOne browser rows key their first cell as "OBJECT;ID" — return the ID.
   */
  public static function browserRowId($cell): string
  {
    $parts = explode(';', (string)$cell);
    return isset($parts[1]) ? trim($parts[1]) : '';
  }

  /**
   * Effective price of a variation combination given the parent price and
   * the combination price modifier.
   */
  public static function combinationPrice(float $prodPrice, string $priceDiff, float $vcPrice): float
  {
    switch ($priceDiff) {
      case 'fixed':
        return $vcPrice;
      case 'add':
        return $prodPrice + $vcPrice;
      case 'subtract':
        return max(0, $prodPrice - $vcPrice);
      default:
        return $prodPrice;
    }
  }

  public static function combinationLabel(array $optionValues): string
  {
    return trim(implode(' ', array_filter(array_map('trim', $optionValues))));
  }

  /**
   * Debug log of raw ERP replies for one transaction, keyed by call
   * ('item', 'image'). Truncated so a pathological ERP reply can't bloat
   * the transactions table.
   */
  public static function debugResponseJson(array $responses, int $maxLength = 65535): string
  {
    $json = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
      $json = json_encode(['encode_error' => json_last_error_msg()]);
    }

    if (mb_strlen($json) > $maxLength) {
      $json = mb_substr($json, 0, $maxLength);
    }

    return $json;
  }
}
