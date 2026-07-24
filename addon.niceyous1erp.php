<?php

/**
 * NiceYou SoftOne ERP Connector addon for NagaCommerce.
 *
 * Port of the NiceYou PrestaShop "erpintegrationsoft" business model onto
 * the NagaCommerce addon architecture (settings tabs, association tables,
 * events, cron, order receipts/reports — same technical patterns as the
 * generic SoftOne addon):
 *
 *   - Product push (eshop -> ERP): changed products are queued into a
 *     transactions table and drained to the ERP as ITEM setData calls,
 *     with EAN-based dedup against existing ERP items and the cover image
 *     attached via ITEDOCDATA.
 *   - Order push (eshop -> ERP): on configurable order statuses the
 *     customer is upserted (local map + ERP email/phone dedup) and a
 *     SALDOC is created/updated with courier, expense (shipping/COD) and
 *     receipt-vs-invoice series rules.
 *   - WEB-FIFO (ERP -> eshop): purchase prices are pulled from the ERP's
 *     WEB-FIFO list and applied to the products' cost price.
 *   - One-time bootstrap: product mapping by EAN, category mapping by name.
 *
 * @author: NagaCommerce
 */

require_once(dirname(__FILE__) . '/../../includes/classes/addon.class.php');

class ADDON_NICEYOUS1ERP extends ADDON
{

  protected $langVar = 'NICEYOUS1ERP_';

  protected $PaymentShippingAssocs = [];
  protected $VatClassAssocs = [];

  protected $template_data;

  private $InstallationTables = [
    'addon_niceyous1erp_transactions',
    'addon_niceyous1erp_product_map',
    'addon_niceyous1erp_category_map',
    'addon_niceyous1erp_customer_map',
    'addon_niceyous1erp_order_receipts',
    'addon_niceyous1erp_sync_orders_report',
    'addon_niceyous1erp_assocs_payship',
    'addon_niceyous1erp_assocs_vat_class',
    'addon_niceyous1erp_webfifo',
  ];

  public $V8Ready = true;

  /** Bump together with EnsureSchema() steps. */
  const SCHEMA_VERSION = 3;

  /** Rows per page in the Mappings tab tables (server render + AJAX). */
  const MAPPINGS_PAGE_SIZE = 50;

  public $cronTab = 60 * 10; // every 10 min in seconds
  public $enableCron = false;

  public function __construct()
  {
    parent::__construct();
    $this->SetId(strtolower(__CLASS__));
    $this->SetName('NiceYou SoftOne ERP');
    $this->SetCategory(MODULE::CATEGORY_ERP);
    $this->SetVersion('1.0');

    $this->RegisterMenuItem(array(
      'location' => 'mnuProducts',
      'text' => GetLang($this->langVar . 'MenuText'),
      'description' => GetLang($this->langVar . 'MenuDescription'),
      'id' => $this->id,
    ));

    $this->SetDescription(GetLang($this->langVar . 'AddonDescription'));
    $this->SetImage('softone-logo.jpg');
    $this->SetHelpText(GetLang($this->langVar . 'HelpText'));

    if (defined('NG_ADMIN_CP')) {
      $GLOBALS['OrdersButtonTable'][] = $this->ParseTemplate('orders.buttons', true);
    }

    NagaCommerce_Event::bind('OrderQuickView', [$this, 'OrderQuickView']);
    NagaCommerce_Event::bind('NewOrderCompleted', [$this, 'SendNewOrderInfo']);
    NagaCommerce_Event::bind('ShipmentCreated', [$this, 'ShipmentCreated'], 'ShipmentCreated');
    NagaCommerce_Event::bind('OrderUpdated', [$this, 'OrderUpdated']);
    NagaCommerce_Event::bind('OrderStatusChanged', [$this, 'OrderStatusChanged']);
    NagaCommerce_Event::bind('multi_delete_products', [$this, 'ProductsDeleted']);

    // Master switch: the cron only runs when auto-sync is explicitly on,
    // so a configured connection alone never starts syncing. Manual admin
    // buttons keep working regardless.
    if ($this->GetValue('BaseUrl') != '' && $this->GetValue('AutoSyncEnabled')) {
      $this->enableCron = true;
    }
  }

  /**
   * Setup the settings for this addon.
   */
  public function SetCustomVars()
  {
    $this->_variables = [
      [
        'tabname' => $this->langVar . 'Settings',
        'tabitems' => [
          'SyncOptions' => ['type' => 'label', 'label' => $this->langVar . 'SyncOptions'],

          'AutoSyncEnabled' => [
            'type' => 'checkbox',
            'required' => false,
            'name' => $this->langVar . 'AutoSyncEnabled',
            'help' => $this->langVar . 'AutoSyncEnabledHelp',
            'label' => $this->langVar . 'AutoSyncEnabledLabel'
          ],
          'PushProductsEnabled' => [
            'type' => 'checkbox',
            'required' => false,
            'name' => $this->langVar . 'PushProductsEnabled',
            'help' => $this->langVar . 'PushProductsEnabledHelp',
            'label' => $this->langVar . 'PushProductsEnabledLabel'
          ],
          'DebugProductSync' => [
            'type' => 'checkbox',
            'required' => false,
            'name' => $this->langVar . 'DebugProductSync',
            'help' => $this->langVar . 'DebugProductSyncHelp',
            'label' => $this->langVar . 'DebugProductSyncLabel'
          ],
          'WebFifoEnabled' => [
            'type' => 'checkbox',
            'required' => false,
            'name' => $this->langVar . 'WebFifoEnabled',
            'help' => $this->langVar . 'WebFifoEnabledHelp',
            'label' => $this->langVar . 'WebFifoEnabledLabel'
          ],
          'SyncOnNewOrder' => [
            'type' => 'checkbox',
            'required' => false,
            'name' => $this->langVar . 'SyncOnNewOrder',
            'help' => $this->langVar . 'SyncOnNewOrderHelp',
            'label' => $this->langVar . 'SyncOnNewOrderLabel'
          ],
          'SyncOrderStatuses' => [
            'type' => 'dropdown',
            'multiselect' => true,
            'multiselectheight' => 8,
            'required' => false,
            'name' => $this->langVar . 'SyncOrderStatuses',
            'help' => $this->langVar . 'SyncOrderStatusesHelp',
            'options' => $this->OrderStatusOptions()
          ],
          'CleanupDays' => [
            'type' => 'text',
            'required' => false,
            'default' => '5',
            'name' => $this->langVar . 'CleanupDays',
            'help' => $this->langVar . 'CleanupDaysHelp'
          ],
        ]
      ],
      [
        'tabname' => $this->langVar . 'ErpCodes',
        'tabitems' => [
          'ErpCodes' => ['type' => 'label', 'label' => $this->langVar . 'ErpCodes'],

          'SeriesReceipt' => ['type' => 'text', 'required' => false, 'default' => '6003', 'name' => $this->langVar . 'SeriesReceipt', 'help' => $this->langVar . 'SeriesReceiptHelp'],
          'SeriesInvoice' => ['type' => 'text', 'required' => false, 'default' => '6004', 'name' => $this->langVar . 'SeriesInvoice', 'help' => $this->langVar . 'SeriesInvoiceHelp'],
          'DefaultPaymentCode' => ['type' => 'text', 'required' => false, 'default' => '1000', 'name' => $this->langVar . 'DefaultPaymentCode', 'help' => $this->langVar . 'DefaultPaymentCodeHelp'],
          'IrisPaymentCode' => ['type' => 'text', 'required' => false, 'default' => '1014', 'name' => $this->langVar . 'IrisPaymentCode', 'help' => $this->langVar . 'IrisPaymentCodeHelp'],
          'DefaultCarrierCode' => ['type' => 'text', 'required' => false, 'default' => '1', 'name' => $this->langVar . 'DefaultCarrierCode', 'help' => $this->langVar . 'DefaultCarrierCodeHelp'],
          'ShipmentCode' => ['type' => 'text', 'required' => false, 'default' => '103', 'name' => $this->langVar . 'ShipmentCode', 'help' => $this->langVar . 'ShipmentCodeHelp'],
          'ShipKindCode' => ['type' => 'text', 'required' => false, 'default' => '1000', 'name' => $this->langVar . 'ShipKindCode', 'help' => $this->langVar . 'ShipKindCodeHelp'],
          'TrucksCode' => ['type' => 'text', 'required' => false, 'default' => '4', 'name' => $this->langVar . 'TrucksCode', 'help' => $this->langVar . 'TrucksCodeHelp'],
          'TrdBusinessCode' => ['type' => 'text', 'required' => false, 'default' => '1009', 'name' => $this->langVar . 'TrdBusinessCode', 'help' => $this->langVar . 'TrdBusinessCodeHelp'],
          'DefaultVatLabel' => ['type' => 'text', 'required' => false, 'default' => '1410|ΦΠΑ 24%', 'name' => $this->langVar . 'DefaultVatLabel', 'help' => $this->langVar . 'DefaultVatLabelHelp'],
          'DefaultErpCategory' => ['type' => 'text', 'required' => false, 'default' => '999', 'name' => $this->langVar . 'DefaultErpCategory', 'help' => $this->langVar . 'DefaultErpCategoryHelp'],
          'CodePrefix' => ['type' => 'text', 'required' => false, 'default' => 'WEB', 'name' => $this->langVar . 'CodePrefix', 'help' => $this->langVar . 'CodePrefixHelp'],
          'CustomerCodePrefix' => ['type' => 'text', 'required' => false, 'default' => 'WEB', 'name' => $this->langVar . 'CustomerCodePrefix', 'help' => $this->langVar . 'CustomerCodePrefixHelp'],
          'ShippingExpenseCode' => ['type' => 'text', 'required' => false, 'default' => '104', 'name' => $this->langVar . 'ShippingExpenseCode', 'help' => $this->langVar . 'ShippingExpenseCodeHelp'],
          'CodExpenseCode' => ['type' => 'text', 'required' => false, 'default' => '105', 'name' => $this->langVar . 'CodExpenseCode', 'help' => $this->langVar . 'CodExpenseCodeHelp'],
          'CodFeeAmount' => ['type' => 'text', 'required' => false, 'default' => '2.90', 'name' => $this->langVar . 'CodFeeAmount', 'help' => $this->langVar . 'CodFeeAmountHelp'],
          'CodModules' => ['type' => 'text', 'required' => false, 'default' => 'checkout_cashondelivery', 'name' => $this->langVar . 'CodModules', 'help' => $this->langVar . 'CodModulesHelp'],
          'ExpenseVatPercent' => ['type' => 'text', 'required' => false, 'default' => '24', 'name' => $this->langVar . 'ExpenseVatPercent', 'help' => $this->langVar . 'ExpenseVatPercentHelp'],
        ]
      ],
      [
        'tabname' => $this->langVar . 'ConnectionSettings',
        'tabitems' => [
          'BaseUrl' => [
            'type' => 'text',
            'required' => true,
            'name' => $this->langVar . 'BaseUrl',
            'help' => $this->langVar . 'BaseUrlHelp'
          ],
          'username' => [
            'type' => 'text',
            'required' => true,
            'name' => $this->langVar . 'Username',
            'help' => $this->langVar . 'UsernameHelp'
          ],
          'password' => [
            'type' => 'text',
            'required' => true,
            'name' => $this->langVar . 'Password',
            'help' => $this->langVar . 'PasswordHelp'
          ],
          'appId' => [
            'type' => 'text',
            'required' => true,
            'name' => $this->langVar . 'AppId',
            'help' => $this->langVar . 'AppIdHelp'
          ],
          'assignPaymentOptions' => [
            'type' => 'custom',
            'callback' => 'LoadPaymentAssociations',
            'name' => GetLang($this->langVar . 'PaymentAssocs'),
            'required' => true,
            'help' => GetLang($this->langVar . 'PaymentAssocsHelp')
          ],
          'assignShippingOptions' => [
            'type' => 'custom',
            'callback' => 'LoadShippingAssociations',
            'name' => GetLang($this->langVar . 'ShippingAssocs'),
            'required' => true,
            'help' => GetLang($this->langVar . 'ShippingAssocsHelp')
          ],
          'assignVatClassOptions' => [
            'type' => 'custom',
            'callback' => 'LoadVatClassAssociations',
            'name' => GetLang($this->langVar . 'VatClassAssocs'),
            'required' => true,
            'help' => GetLang($this->langVar . 'VatClassAssocsHelp')
          ],
          'lastProductPush' => [
            'visible' => true,
            'type' => 'text',
            'required' => false,
            'readonly' => false,
            'name' => $this->langVar . 'LastProductPush'
          ],
          'lastJobRun' => [
            'visible' => false,
            'type' => 'text',
            'name' => GetLang($this->langVar . 'LastImport'),
            'required' => false,
            'readonly' => true
          ],
        ]
      ]
    ];

    $this->LoadPaymentShippingAssocs();
    $this->LoadVatClassAssocs();
  }

  public function init()
  {
    if (!$this->CheckInstallationParams($this->InstallationTables)) {
      $this->RunFirstInstallation();
    }
    $this->EnsureSchema();
    $this->ShowSaveAndCancelButtons(true);
  }

  public function EntryPoint()
  {
    if (!$this->CheckInstallationParams($this->InstallationTables)) {
      $this->RunFirstInstallation();
    }
    $this->EnsureSchema();

    if ($BackgroundProcess = $this->CheckPushProcess()) {
      MessageBox($BackgroundProcess, MSG_WARNING);
    }

    $this->template_data = array(
      'Module' => array(
        'ModuleName' => $this->GetName(),
        'ModuleId' => $this->GetId(),
        'ModuleLogo' => $this->GetImage(),
        'ModuleTabs' => array(
          'Home' => array(
            'name' => $this->langVar . 'Home',
            'link' => 'index.php?ToDo=runAddon&addon=' . $this->id,
            'active' => false
          ),
          'Mappings' => array(
            'name' => $this->langVar . 'Mappings',
            'link' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=viewMappings',
            'active' => false
          ),
          'Mapped' => array(
            'name' => $this->langVar . 'Mapped',
            'link' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=viewMapped',
            'active' => false
          ),
          'Transactions' => array(
            'name' => $this->langVar . 'Transactions',
            'link' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=viewTransactions',
            'active' => false
          ),
          'OrdersReports' => array(
            'name' => $this->langVar . 'OrderReports',
            'link' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=viewOrderReports',
            'active' => false
          ),
          'OrderDocuments' => array(
            'name' => $this->langVar . 'OrderDocuments',
            'link' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=viewOrderDocuments',
            'active' => false
          )
        )
      ),
      'LastProductPush' => $this->GetValue('lastProductPush') ? ng_date('d/m/Y H:i', (int)$this->GetValue('lastProductPush')) : '-',
      'BackgroundProcess' => $this->CheckPushProcess(),
      'Buttons' => [
        [
          'type' => 'a',
          'args' => ['href' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=Push', 'class' => 'btn btn-sm btn-outline-primary btn-outline me-5'],
          'title' => GetLang($this->langVar . 'PushProducts')
        ],
        [
          'type' => 'a',
          'args' => ['href' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=WebFifoSync', 'class' => 'btn btn-sm btn-outline-primary btn-outline me-5'],
          'title' => GetLang($this->langVar . 'WebFifoSync')
        ],
        [
          'type' => 'a',
          'args' => ['href' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=BootstrapProducts', 'class' => 'btn btn-sm btn-outline-warning btn-outline me-5'],
          'title' => GetLang($this->langVar . 'BootstrapProducts')
        ],
        [
          'type' => 'a',
          'args' => ['href' => 'index.php?ToDo=runAddon&addon=' . $this->id . '&route=BootstrapCategories', 'class' => 'btn btn-sm btn-outline-warning btn-outline me-5'],
          'title' => GetLang($this->langVar . 'BootstrapCategories')
        ],
        [
          'type' => 'a',
          'args' => ['href' => 'index.php?ToDo=viewAddonSettings&addon=' . $this->id, 'class' => 'btn btn-sm btn-primary'],
          'title' => GetLang('AddonSettings')
        ]
      ]
    );

    switch ($this->getRoute()) {

      case 'push':
        $this->runCronJob();
        break;

      case 'webfifosync':
        $this->WebFifoSync();
        break;

      case 'bootstrapproducts':
        $this->BootstrapProductsAction();
        break;

      case 'bootstrapcategories':
        $this->BootstrapCategoriesAction();
        break;

      case 'viewmappings':
        $this->viewMappings();
        break;

      case 'viewmapped':
        $this->viewMappedReport();
        break;

      case 'mappingsdata':
        $this->mappingsData();
        break;

      case 'deleteproducts':
        $this->deleteProductsAction();
        break;

      case 'removeproductmapping':
        $this->removeProductMapping();
        break;

      case 'removecategorymapping':
        $this->removeCategoryMapping();
        break;

      case 'viewtransactions':
        $this->viewTransactions();
        break;

      case 'vieworderreports':
        $this->viewOrderReports();
        break;

      case 'vieworderdocuments':
        $this->viewOrderDocuments();
        break;

      case 'deleteorderdocument':
        $this->deleteOrderDocument();
        break;

      case 'abortsession':
        $this->terminatePushProcess();
        break;

      case 'home':
      default:
        $this->SummaryPage();
        break;
    }
  }

  /* ------------------------------------------------------- associations */

  protected function LoadPaymentShippingAssocs()
  {
    $query = "SELECT MODULE,CODE FROM [|PREFIX|]{$this->GetId()}_assocs_payship;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->Execute($result);
    while ($row = $GLOBALS['db']->Fetch($result)) {
      $this->PaymentShippingAssocs[$row['MODULE']] = $row['CODE'];
    }
  }

  protected function LoadVatClassAssocs()
  {
    $query = "SELECT TAX_CLASS_ID,VAT_CODE FROM [|PREFIX|]{$this->GetId()}_assocs_vat_class;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->Execute($result);
    while ($row = $GLOBALS['db']->Fetch($result)) {
      $this->VatClassAssocs[$row['TAX_CLASS_ID']] = $row['VAT_CODE'];
    }
  }

  public function LoadPaymentAssociations()
  {
    $data['inputName'] = $this->GetId() . '[assignPaymentOptions]';
    $data['placeholder'] = GetLang($this->langVar . 'ErpCodePlaceholder');

    foreach (GetAvailableModules('checkout') as $module) {
      $data['naga']['items'][$module['id']] = [
        'name' => $module['name'],
        'assoc' => $this->PaymentShippingAssocs[$module['id']] ?? ''
      ];
    }

    return $this->ParseTemplate('code.assocs', true, $data);
  }

  public function LoadShippingAssociations()
  {
    $data['inputName'] = $this->GetId() . '[assignShippingOptions]';
    $data['placeholder'] = GetLang($this->langVar . 'CarrierCodePlaceholder');

    foreach (GetAvailableModules('shipping') as $module) {
      $data['naga']['items'][$module['id']] = [
        'name' => $module['name'],
        'assoc' => $this->PaymentShippingAssocs[$module['id']] ?? ''
      ];
    }

    return $this->ParseTemplate('code.assocs', true, $data);
  }

  public function LoadVatClassAssociations()
  {
    $data['inputName'] = $this->GetId() . '[assignVatClassOptions]';
    $data['placeholder'] = GetLang($this->langVar . 'VatCodePlaceholder');

    foreach (GetClass('TAX')->getTaxClasses() as $taxClassId => $taxClassName) {
      $data['naga']['items'][$taxClassId] = [
        'name' => $taxClassName,
        'assoc' => $this->VatClassAssocs[$taxClassId] ?? ''
      ];
    }

    return $this->ParseTemplate('code.assocs', true, $data);
  }

  public function SaveModuleSettings($settings = array(), $deleteFirst = true)
  {
    $query = "DELETE FROM [|PREFIX|]{$this->GetId()}_assocs_payship;";
    $GLOBALS['db']->Execute($GLOBALS['db']->Query($query));

    $query = "DELETE FROM [|PREFIX|]{$this->GetId()}_assocs_vat_class;";
    $GLOBALS['db']->Execute($GLOBALS['db']->Query($query));

    foreach (array_keys($settings) as $setting) {
      switch ($setting) {
        case 'assignPaymentOptions':
        case 'assignShippingOptions':
          foreach ($settings[$setting] as $key => $option) {
            if (trim((string)$option) === '') {
              continue;
            }
            $saveData = [
              'MODULE' => $key,
              'CODE' => trim((string)$option),
              'LAST_UPDATE' => time()
            ];
            $GLOBALS['db']->InsertQuery($this->GetId() . '_assocs_payship', $saveData);
          }
          break;
        case 'assignVatClassOptions':
          foreach ($settings[$setting] as $key => $option) {
            if (trim((string)$option) === '') {
              continue;
            }
            $saveData = [
              'TAX_CLASS_ID' => $key,
              'VAT_CODE' => trim((string)$option),
              'LAST_UPDATE' => time()
            ];
            $GLOBALS['db']->InsertQuery($this->GetId() . '_assocs_vat_class', $saveData);
          }
          break;
      }
    }

    return parent::SaveModuleSettings($settings);
  }

  /* ------------------------------------------------------ shared helpers */

  /**
   * Version-gated schema upgrades for installs that predate them (guarded on
   * cron paths too, not just admin visits). v2: the ERP category map's eshop
   * side becomes BRANDS — NiceYou's MTRCATEGORY list holds brand names, so
   * matching against eshop categories was wrong. Existing category-based rows
   * are wiped; re-run Bootstrap Categories after this lands.
   * v3: transactions gain a `response` column holding the raw ERP reply when
   * the DebugProductSync setting is on.
   */
  protected function EnsureSchema(): void
  {
    if ((int)$this->GetValue('schemaVersion') >= self::SCHEMA_VERSION) {
      return;
    }

    $table = '[|PREFIX|]addon_niceyous1erp_category_map';

    $result = $GLOBALS['db']->Query("SHOW COLUMNS FROM $table LIKE 'brandid';");
    if (!$GLOBALS['db']->FetchOne($result)) {
      $alter = $GLOBALS['db']->Query("ALTER TABLE $table
        ADD COLUMN `brandid` int(11) NULL AFTER `mapid`,
        ADD COLUMN `brand_title` varchar(500) NULL;");
      $GLOBALS['db']->Execute($alter);

      $wipe = $GLOBALS['db']->Query("DELETE FROM $table;");
      $GLOBALS['db']->Execute($wipe);
    }

    $result = $GLOBALS['db']->Query("SHOW COLUMNS FROM $table LIKE 'categoryid';");
    if ($GLOBALS['db']->FetchOne($result)) {
      $alter = $GLOBALS['db']->Query("ALTER TABLE $table DROP COLUMN `categoryid`, DROP COLUMN `cat_title`;");
      $GLOBALS['db']->Execute($alter);
    }

    $transactions = '[|PREFIX|]addon_niceyous1erp_transactions';

    $result = $GLOBALS['db']->Query("SHOW COLUMNS FROM $transactions LIKE 'response';");
    if (!$GLOBALS['db']->FetchOne($result)) {
      $alter = $GLOBALS['db']->Query("ALTER TABLE $transactions ADD COLUMN `response` mediumtext NULL AFTER `message`;");
      $GLOBALS['db']->Execute($alter);
    }

    $this->SetModuleVar('schemaVersion', self::SCHEMA_VERSION);
  }

  /**
   * Store order statuses as dropdown options (label => statusid).
   */
  protected function OrderStatusOptions(): array
  {
    $options = [];

    $query = "SELECT statusid, statusdesc FROM [|PREFIX|]order_status ORDER BY statusid ASC;";
    $result = $GLOBALS['db']->Query($query);
    foreach ((array)$GLOBALS['db']->FetchAll($result) as $row) {
      $options[GetLang($row['statusdesc'])] = (string)$row['statusid'];
    }

    return $options;
  }

  /**
   * Selected sync statuses as string ids. GetValue returns an array when
   * more than one multiselect option is saved, a plain string otherwise;
   * legacy comma-separated values from the old text field still parse.
   */
  public function SyncOrderStatusIds(): array
  {
    $value = $this->GetValue('SyncOrderStatuses');
    $statuses = is_array($value) ? $value : explode(',', (string)$value);

    return array_values(array_filter(array_map('trim', array_map('strval', $statuses)), 'strlen'));
  }

  /**
   * Logged-in s1services client.
   * @throws Exception
   */
  public function ConnectApi(): ADDON_NICEYOUS1ERP_API
  {
    $api = new ADDON_NICEYOUS1ERP_API($this->GetValue('BaseUrl'), $this->GetValue('appId'));

    try {
      $api->login($this->GetValue('username'), $this->GetValue('password'));
    } catch (Exception $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'AuthError', $e->getMessage());
      throw new Exception($e->getMessage(), 0, $e);
    }

    return $api;
  }

  /**
   * Settings + association tables flattened into the payload config
   * consumed by ADDON_NICEYOUS1ERP_PAYLOADS.
   */
  public function BuildPayloadConfig(): array
  {
    if (empty($this->VatClassAssocs)) {
      $this->LoadVatClassAssocs();
    }

    $codModules = array_filter(array_map('trim', explode(',', (string)$this->GetValue('CodModules'))));

    return ADDON_NICEYOUS1ERP_PAYLOADS::mergeConfig([
      'codePrefix' => $this->GetValue('CodePrefix'),
      'customerCodePrefix' => $this->GetValue('CustomerCodePrefix'),
      'defaultVat' => $this->GetValue('DefaultVatLabel'),
      'vatByTaxClass' => $this->VatClassAssocs,
      'defaultErpCategory' => $this->GetValue('DefaultErpCategory'),
      'seriesReceipt' => $this->GetValue('SeriesReceipt'),
      'seriesInvoice' => $this->GetValue('SeriesInvoice'),
      'defaultPaymentCode' => $this->GetValue('DefaultPaymentCode'),
      'irisPaymentCode' => $this->GetValue('IrisPaymentCode'),
      'defaultCarrierCode' => $this->GetValue('DefaultCarrierCode'),
      'shipmentCode' => $this->GetValue('ShipmentCode'),
      'shipKindCode' => $this->GetValue('ShipKindCode'),
      'trucksCode' => $this->GetValue('TrucksCode'),
      'trdBusinessCode' => $this->GetValue('TrdBusinessCode'),
      'expenseVatPercent' => (float)$this->GetValue('ExpenseVatPercent') ?: null,
      'shippingExpenseCode' => $this->GetValue('ShippingExpenseCode'),
      'codExpenseCode' => $this->GetValue('CodExpenseCode'),
      'codFeeAmount' => (float)$this->GetValue('CodFeeAmount') ?: null,
      'codModules' => !empty($codModules) ? $codModules : null,
    ]);
  }

  public function GetProductMtrl(int $productId, int $combinationId): string
  {
    $query = "SELECT erp_mtrl FROM [|PREFIX|]addon_niceyous1erp_product_map WHERE productid = ? AND combinationid = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $productId, PDO::PARAM_INT);
    $GLOBALS['db']->bindParam($result, 2, $combinationId, PDO::PARAM_INT);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return (string)$row['erp_mtrl'];
    }
    return '';
  }

  public function SaveProductMtrl(int $productId, int $combinationId, string $erpMtrl): void
  {
    $saveData = [
      'productid' => $productId,
      'combinationid' => $combinationId,
      'erp_mtrl' => $erpMtrl,
      'last_update' => time(),
    ];
    $GLOBALS['db']->InsertIgnoreQuery('addon_niceyous1erp_product_map', $saveData);
  }

  protected function SetModuleVar(string $name, $value): void
  {
    $GLOBALS['db']->DeleteQuery('module_vars', "WHERE modulename = '" . $this->GetId() . "' AND variablename = '" . $GLOBALS['db']->Quote($name) . "'");

    $saveData = [
      'variablename' => $name,
      'variableval' => (string)$value,
      'modulename' => $this->GetId()
    ];
    $GLOBALS['db']->InsertQuery('module_vars', $saveData);
    $GLOBALS['ISC_CLASS_DATA_STORE']->UpdateAddonModuleVars();
  }

  /* --------------------------------------------------------------- cron */

  public function runCronJob()
  {
    if (!$this->CheckPushProcess()) {
      $exec_url = DOCROOT . '/xml.php';
      $shell_argv = array(
        'climode',
        sha1('systemized' . GetConfig('ShopPath')),
        'runAddon',
        $this->id,
        'CronSync'
      );

      $shell_args = implode(' ', $shell_argv);

      shell_exec(SHELL_EXEC_PHP_PATH . " {$exec_url} {$shell_args} > /dev/null 2>&1 &");
    }

    if (php_sapi_name() !== 'cli') {
      header('Location:index.php?ToDo=runAddon&addon=' . $this->id);
    }
  }

  /**
   * Background worker: queue + drain product pushes, then WEB-FIFO.
   * Invoked via xml.php climode (see runCronJob) and by the admin buttons.
   */
  public function CronSync()
  {
    $this->SetModuleVar('lastJobRun', time());

    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M');

    $this->EnsureSchema();

    require_once(__DIR__ . '/library/products.class.php');
    require_once(__DIR__ . '/library/webfifo.class.php');

    if ($this->GetValue('PushProductsEnabled')) {
      $products = new ADDON_NICEYOUS1ERP_PRODUCTS();
      try {
        $queued = $products->QueueChangedProducts();
        [$sent, $failed] = $products->SendQueuedProducts();
        $products->CleanUpTransactions();
        $GLOBALS['NG_CLASS_LOG']->LogSystemDebug(['addon', $this->GetId()], 'Product push finished', "queued: $queued, sent: $sent, failed: $failed");
      } catch (Exception $e) {
        $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'Product push error', $e->getMessage());
      }
    }

    if ($this->GetValue('WebFifoEnabled')) {
      $webfifo = new ADDON_NICEYOUS1ERP_WEBFIFO();
      try {
        $staged = $webfifo->Fetch();
        $updated = $webfifo->Apply();
        $GLOBALS['NG_CLASS_LOG']->LogSystemDebug(['addon', $this->GetId()], 'WEB-FIFO finished', "staged: $staged, applied: $updated");
      } catch (Exception $e) {
        $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'WEB-FIFO error', $e->getMessage());
      }
    }
  }

  protected function CheckPushProcess()
  {
    $docroot = str_replace('/', '\/', DOCROOT);
    $id = $this->GetId();
    $return = '';

    if (shell_exec("ps -ef | egrep -i '" . $docroot . ".*?" . $id . " CronSync' ")) {
      $return = GetLang('BackgroundUpdateInProcess', array('caller' => $id, 'abortUrl' => 'index.php?ToDo=runAddon&addon=' . $id . '&route=abortSession'));
    }

    return $return;
  }

  protected function terminatePushProcess()
  {
    $docroot = str_replace('/', '\/', DOCROOT);
    $id = $this->GetId();

    $inputString = shell_exec("ps -ef | egrep -i '" . $docroot . ".*?" . $id . " CronSync'");
    if (preg_match('/\b(\d+)\b/', $inputString, $matches)) {
      shell_exec("kill -9 {$matches[1]}");
      FlashMessage(GetLang($this->langVar . 'PushSessionAborted'), MSG_SUCCESS, 'index.php?ToDo=runAddon&addon=' . $id);
    }
  }

  /* ------------------------------------------------------ admin actions */

  private function WebFifoSync()
  {
    $redirectUrl = "/admin/index.php?ToDo=runAddon&addon=$this->id";

    require_once(__DIR__ . '/library/webfifo.class.php');

    try {
      ini_set('max_execution_time', 0);
      ini_set('memory_limit', '1024M');
      $webfifo = new ADDON_NICEYOUS1ERP_WEBFIFO();
      $staged = $webfifo->Fetch();
      $updated = $webfifo->Apply();
      FlashMessage(GetLang($this->langVar . 'WebFifoDone', ['staged' => $staged, 'updated' => $updated]), MSG_SUCCESS, $redirectUrl);
    } catch (Throwable $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'WebFifoSync Error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
      FlashMessage($e->getMessage(), MSG_ERROR, $redirectUrl);
    }
  }

  /**
   * NOTE: deliberately NOT named BootstrapProducts. The MAPPINGS library
   * class extends this addon class, and PHP resolves a same-named PRIVATE
   * method in the calling scope over the subclass's public one — a route
   * handler named like the library method calls itself infinitely.
   */
  private function BootstrapProductsAction()
  {
    $redirectUrl = "/admin/index.php?ToDo=runAddon&addon=$this->id";

    require_once(__DIR__ . '/library/mappings.class.php');

    try {
      ini_set('max_execution_time', 0);
      ini_set('memory_limit', '1024M');
      $mappings = new ADDON_NICEYOUS1ERP_MAPPINGS();
      [$mapped, $skipped] = $mappings->BootstrapProducts();
      FlashMessage(GetLang($this->langVar . 'BootstrapProductsDone', ['mapped' => $mapped, 'skipped' => $skipped]), MSG_SUCCESS, $redirectUrl);
    } catch (Throwable $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'BootstrapProducts Error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
      FlashMessage($e->getMessage(), MSG_ERROR, $redirectUrl);
    }
  }

  private function BootstrapCategoriesAction()
  {
    $redirectUrl = "/admin/index.php?ToDo=runAddon&addon=$this->id";

    require_once(__DIR__ . '/library/mappings.class.php');

    try {
      ini_set('max_execution_time', 0);
      ini_set('memory_limit', '1024M');
      $mappings = new ADDON_NICEYOUS1ERP_MAPPINGS();
      [$mapped, $unmatched] = $mappings->BootstrapCategories();
      FlashMessage(GetLang($this->langVar . 'BootstrapCategoriesDone', ['mapped' => $mapped, 'unmatched' => $unmatched]), MSG_SUCCESS, $redirectUrl);
    } catch (Throwable $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', $this->GetId()], 'BootstrapCategories Error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
      FlashMessage($e->getMessage(), MSG_ERROR, $redirectUrl);
    }
  }

  private function SummaryPage()
  {
    $this->template_data['Module']['ModuleTabs']['Home']['active'] = true;
    $this->ParseTemplate('home', false, $this->template_data);
  }

  /**
   * Graphical mapping report: how much of the catalog is paired to the ERP
   * (products / variations by EAN, ERP categories by name) plus the rows
   * that still need attention.
   */
  private function viewMappings()
  {
    $this->template_data['Module']['ModuleTabs']['Mappings']['active'] = true;

    $map = '[|PREFIX|]addon_niceyous1erp_product_map';

    $simpleTotal = $this->countRows("
      SELECT COUNT(*) AS c FROM [|PREFIX|]products
      WHERE european_article_number != '';");
    $simpleMapped = $this->countRows("
      SELECT COUNT(*) AS c FROM [|PREFIX|]products p
      JOIN $map m ON (m.productid = p.productid AND m.combinationid = 0)
      WHERE p.european_article_number != '';");

    $comboTotal = $this->countRows("
      SELECT COUNT(*) AS c FROM [|PREFIX|]product_variation_combinations
      WHERE vcbarcode != '' AND vcenabled = 1;");
    $comboMapped = $this->countRows("
      SELECT COUNT(*) AS c FROM [|PREFIX|]product_variation_combinations c
      JOIN $map m ON (m.productid = c.vcproductid AND m.combinationid = c.combinationid)
      WHERE c.vcbarcode != '' AND c.vcenabled = 1;");

    $catTotal = $this->countRows("SELECT COUNT(*) AS c FROM [|PREFIX|]addon_niceyous1erp_category_map;");
    $catMapped = $this->countRows("SELECT COUNT(*) AS c FROM [|PREFIX|]addon_niceyous1erp_category_map WHERE brandid IS NOT NULL;");

    [$unmappedProductsTotal, $unmappedProducts] = $this->fetchMappingsList('unmappedProducts', 0);
    [$unmappedCombinationsTotal, $unmappedCombinations] = $this->fetchMappingsList('unmappedCombinations', 0);
    [$noEanCount, $noEanProducts] = $this->fetchMappingsList('noEan', 0);
    [$unmatchedCategoriesTotal, $unmatchedCategories] = $this->fetchMappingsList('unmatchedCategories', 0);

    $this->template_data['mappings'] = [
      'simple' => $this->mappingStat($simpleMapped, $simpleTotal),
      'combinations' => $this->mappingStat($comboMapped, $comboTotal),
      'categories' => $this->mappingStat($catMapped, $catTotal),
      'pageSize' => self::MAPPINGS_PAGE_SIZE,
      'noEanCount' => $noEanCount,
      'unmappedProductsTotal' => $unmappedProductsTotal,
      'unmappedCombinationsTotal' => $unmappedCombinationsTotal,
      'unmatchedCategoriesTotal' => $unmatchedCategoriesTotal,
      'unmappedProducts' => $unmappedProducts,
      'unmappedCombinations' => $unmappedCombinations,
      'unmatchedCategories' => $unmatchedCategories,
      'noEanProducts' => $noEanProducts,
    ];

    $this->ParseTemplate('mappings', false, $this->template_data);
  }

  /**
   * Mapped report: everything currently linked to the ERP, searchable, with
   * per-row removal of the mapping.
   */
  private function viewMappedReport()
  {
    $this->template_data['Module']['ModuleTabs']['Mapped']['active'] = true;

    $search = trim((string)($_REQUEST['search'] ?? ''));
    $listLimit = 200;

    $where = '';
    if ($search !== '') {
      $where = "WHERE (p.prodname LIKE ? OR p.prodcode LIKE ? OR p.european_article_number LIKE ?
        OR c.vcsku LIKE ? OR c.vcbarcode LIKE ? OR m.erp_mtrl = ?)";
    }

    $query = "
      SELECT m.productid, m.combinationid, m.erp_mtrl, m.last_update,
             p.prodname, p.prodcode, p.european_article_number,
             c.vcsku, c.vcbarcode
      FROM [|PREFIX|]addon_niceyous1erp_product_map m
      LEFT JOIN [|PREFIX|]products p ON (p.productid = m.productid)
      LEFT JOIN [|PREFIX|]product_variation_combinations c
        ON (c.vcproductid = m.productid AND c.combinationid = m.combinationid)
      $where
      ORDER BY m.last_update DESC, m.productid ASC
      LIMIT " . $listLimit . ";";
    $result = $GLOBALS['db']->Query($query);
    if ($search !== '') {
      $like = '%' . $search . '%';
      for ($i = 1; $i <= 5; $i++) {
        $GLOBALS['db']->bindParam($result, $i, $like, PDO::PARAM_STR);
      }
      $GLOBALS['db']->bindParam($result, 6, $search, PDO::PARAM_STR);
    }

    $products = [];
    foreach ((array)$GLOBALS['db']->FetchAll($result) as $row) {
      $isCombination = (int)$row['combinationid'] > 0;
      $products[] = [
        'productid' => (int)$row['productid'],
        'combinationid' => (int)$row['combinationid'],
        'prodname' => (string)$row['prodname'],
        'sku' => $isCombination && trim((string)$row['vcsku']) !== '' ? (string)$row['vcsku'] : (string)$row['prodcode'],
        'ean' => $isCombination ? (string)$row['vcbarcode'] : (string)$row['european_article_number'],
        'erp_mtrl' => (string)$row['erp_mtrl'],
        'orphan' => $row['prodname'] === null,
        'last_update' => !empty($row['last_update']) ? ng_date('d/m/Y H:i', (int)$row['last_update']) : '-',
      ];
    }

    $query = "
      SELECT mapid, brandid, erp_cat_id, erp_title, brand_title
      FROM [|PREFIX|]addon_niceyous1erp_category_map
      WHERE brandid IS NOT NULL
      ORDER BY erp_title ASC;";
    $result = $GLOBALS['db']->Query($query);
    $categories = (array)$GLOBALS['db']->FetchAll($result);

    $this->template_data['mapped'] = [
      'search' => $search,
      'listLimit' => $listLimit,
      'total' => $this->countRows("SELECT COUNT(*) AS c FROM [|PREFIX|]addon_niceyous1erp_product_map;"),
      'products' => $products,
      'categories' => $categories,
    ];

    $this->ParseTemplate('mapped', false, $this->template_data);
  }

  /**
   * Drop a product↔ERP link. The next push of that product re-adopts by EAN
   * or creates a new ERP item — same as a never-mapped product.
   */
  private function removeProductMapping()
  {
    $redirectUrl = "/admin/index.php?ToDo=runAddon&addon=$this->id&route=viewMapped";

    $productId = (int)($_REQUEST['productId'] ?? 0);
    $combinationId = (int)($_REQUEST['combinationId'] ?? -1);

    if ($productId <= 0 || $combinationId < 0) {
      FlashMessage(GetLang($this->langVar . 'InvalidMapping'), MSG_ERROR, $redirectUrl);
      return;
    }

    $GLOBALS['db']->DeleteQuery(
      'addon_niceyous1erp_product_map',
      'WHERE productid = ' . $productId . ' AND combinationid = ' . $combinationId
    );

    FlashMessage(GetLang($this->langVar . 'MappingRemoved'), MSG_SUCCESS, $redirectUrl);
  }

  /**
   * Drop a category↔ERP link. Bootstrap Categories recreates the row (matched
   * again by name, or unmatched for manual attention).
   */
  private function removeCategoryMapping()
  {
    $redirectUrl = "/admin/index.php?ToDo=runAddon&addon=$this->id&route=viewMapped";

    $mapId = (int)($_REQUEST['mapId'] ?? 0);

    if ($mapId <= 0) {
      FlashMessage(GetLang($this->langVar . 'InvalidMapping'), MSG_ERROR, $redirectUrl);
      return;
    }

    $GLOBALS['db']->DeleteQuery('addon_niceyous1erp_category_map', 'WHERE mapid = ' . $mapId);

    FlashMessage(GetLang($this->langVar . 'MappingRemoved'), MSG_SUCCESS, $redirectUrl);
  }

  /**
   * Shared list definitions for the Mappings tab — the server-rendered first
   * page and the AJAX pager use the same SQL so they cannot drift.
   */
  private function mappingsListDefs(): array
  {
    $map = '[|PREFIX|]addon_niceyous1erp_product_map';

    // Products that will genuinely reach the ERP with no EAN at all: no own
    // EAN and no enabled variation carrying a barcode (variation rows push
    // with their own barcode, so barcoded parents are covered).
    $noEanWhere = "
      p.european_article_number = '' AND p.prodvisible = 1 AND p.prodprice > 0
      AND NOT EXISTS (
        SELECT 1 FROM [|PREFIX|]product_variation_combinations c
        WHERE c.vcproductid = p.productid AND c.vcenabled = 1 AND c.vcbarcode != ''
      )";

    $unmappedProductsFrom = "
      FROM [|PREFIX|]products p
      LEFT JOIN $map m ON (m.productid = p.productid AND m.combinationid = 0)
      WHERE p.european_article_number != '' AND m.productid IS NULL";

    $unmappedCombinationsFrom = "
      FROM [|PREFIX|]product_variation_combinations c
      JOIN [|PREFIX|]products p ON (p.productid = c.vcproductid)
      LEFT JOIN $map m ON (m.productid = c.vcproductid AND m.combinationid = c.combinationid)
      WHERE c.vcbarcode != '' AND c.vcenabled = 1 AND m.productid IS NULL";

    return [
      'unmappedProducts' => [
        'count' => "SELECT COUNT(*) AS c $unmappedProductsFrom",
        'data' => "SELECT p.productid, p.prodname, p.prodcode, p.european_article_number
          $unmappedProductsFrom ORDER BY p.productid ASC",
      ],
      'unmappedCombinations' => [
        'count' => "SELECT COUNT(*) AS c $unmappedCombinationsFrom",
        'data' => "SELECT c.vcproductid, c.combinationid, c.vcsku, c.vcbarcode, p.prodname
          $unmappedCombinationsFrom ORDER BY c.vcproductid ASC, c.combinationid ASC",
      ],
      'noEan' => [
        'count' => "SELECT COUNT(*) AS c FROM [|PREFIX|]products p WHERE $noEanWhere",
        'data' => "SELECT p.productid, p.prodname, p.prodcode
          FROM [|PREFIX|]products p WHERE $noEanWhere ORDER BY p.productid ASC",
      ],
      'unmatchedCategories' => [
        'count' => "SELECT COUNT(*) AS c FROM [|PREFIX|]addon_niceyous1erp_category_map WHERE brandid IS NULL",
        'data' => "SELECT erp_cat_id, erp_title
          FROM [|PREFIX|]addon_niceyous1erp_category_map WHERE brandid IS NULL ORDER BY erp_title ASC",
      ],
    ];
  }

  /**
   * Returns [total, rows] for one Mappings list page.
   */
  private function fetchMappingsList(string $key, int $page): array
  {
    $defs = $this->mappingsListDefs();
    if (!isset($defs[$key])) {
      return [0, []];
    }

    $total = $this->countRows($defs[$key]['count'] . ';');

    $offset = max(0, $page) * self::MAPPINGS_PAGE_SIZE;
    $result = $GLOBALS['db']->Query($defs[$key]['data'] . ' LIMIT ' . $offset . ', ' . self::MAPPINGS_PAGE_SIZE . ';');
    $rows = (array)$GLOBALS['db']->FetchAll($result);

    return [$total, $rows];
  }

  /**
   * AJAX pager endpoint for the Mappings tab tables.
   */
  private function mappingsData()
  {
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
      die;
    }

    $key = (string)($_REQUEST['table'] ?? '');
    $page = max(0, (int)($_REQUEST['page'] ?? 0));

    [$total, $rows] = $this->fetchMappingsList($key, $page);

    header('Content-Type: application/json');
    echo json_encode([
      'success' => true,
      'table' => $key,
      'total' => $total,
      'page' => $page,
      'pageSize' => self::MAPPINGS_PAGE_SIZE,
      'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    die;
  }

  /**
   * Mass-delete eshop products from the Mappings tab (AJAX POST). Runs the
   * canonical admin deletion — NG_ADMIN_PRODUCT::DoDeleteProducts cleans all
   * related tables in a transaction and refuses products present in orders
   * (queuing flash warnings the reloaded page will show).
   */
  private function deleteProductsAction()
  {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
      || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
      die;
    }

    header('Content-Type: application/json');

    $productIds = array_values(array_filter(array_map('intval', (array)($_POST['products'] ?? [])), 'isId'));

    if (empty($productIds)) {
      echo json_encode(['success' => false, 'message' => GetLang($this->langVar . 'NoProductsSelected')]);
      die;
    }

    $deleted = GetClass('NG_ADMIN_PRODUCT')->DoDeleteProducts($productIds);

    if ($deleted) {
      // Map/queue cleanup happens in ProductsDeleted via the
      // multi_delete_products event DoDeleteProducts fires.
      $GLOBALS['NG_CLASS_LOG']->LogAdminAction(count($productIds) . ' products deleted via niceyous1erp mappings report');
      FlashMessage(GetLang('ProductsDeletedSuccessfully'), MSG_SUCCESS);
    }

    echo json_encode(['success' => (bool)$deleted], JSON_UNESCAPED_UNICODE);
    die;
  }

  private function mappingStat(int $mapped, int $total): array
  {
    $percent = $total > 0 ? (int)floor(min(100, $mapped / $total * 100)) : 0;

    return [
      'mapped' => $mapped,
      'total' => $total,
      'unmapped' => max(0, $total - $mapped),
      'percent' => $percent,
      'barClass' => $percent >= 90 ? 'bg-success' : ($percent >= 50 ? 'bg-warning' : 'bg-danger'),
    ];
  }

  private function countRows(string $query): int
  {
    $result = $GLOBALS['db']->Query($query);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return (int)$row['c'];
    }
    return 0;
  }

  private function viewTransactions()
  {
    $this->template_data['Module']['ModuleTabs']['Transactions']['active'] = true;

    $query = "SELECT * FROM [|PREFIX|]addon_niceyous1erp_transactions ORDER BY transactionid DESC LIMIT 200;";
    $result = $GLOBALS['db']->Query($query);
    if ($rows = $GLOBALS['db']->FetchAll($result)) {
      foreach ($rows as $row) {
        $this->template_data['transactions'][] = [
          'transactionid' => $row['transactionid'],
          'productid' => $row['productid'],
          'combinationid' => $row['combinationid'],
          'status' => $row['status'],
          'message' => $row['message'],
          'response' => (string)($row['response'] ?? ''),
          'created' => ng_date('d/m/Y H:i', (int)$row['created']),
        ];
      }
    }

    $this->ParseTemplate('transactions', false, $this->template_data);
  }

  private function viewOrderReports()
  {
    $this->template_data['Module']['ModuleTabs']['OrdersReports']['active'] = true;

    $orderId = !empty($_REQUEST['orderId']) ? (int)$_REQUEST['orderId'] : 0;

    $query = "SELECT * FROM [|PREFIX|]addon_niceyous1erp_sync_orders_report";
    if ($orderId > 0) {
      $query .= " WHERE naga_order_id = " . $orderId;
    }
    $query .= " ORDER BY ordersyncid DESC LIMIT 200;";

    $result = $GLOBALS['db']->Query($query);
    if ($rows = $GLOBALS['db']->FetchAll($result)) {
      foreach ($rows as $row) {
        $this->template_data['orderReports'][] = [
          'orderId' => $row['naga_order_id'],
          'receiptId' => $row['receipt_id'],
          'message' => $row['message'],
          'resultType' => $row['result_type'],
          'datetime' => ng_date('d/m/Y H:i', (int)$row['datetime']),
        ];
      }
    }

    $this->template_data['orderId'] = $orderId;
    $this->ParseTemplate('order.reports', false, $this->template_data);
  }

  public function viewOrderDocuments()
  {
    $this->template_data['Module']['ModuleTabs']['OrderDocuments']['active'] = true;
    $orderId = !empty($_REQUEST['orderId']) ? $_REQUEST['orderId'] : 0;

    $query = "SELECT * FROM [|PREFIX|]addon_niceyous1erp_order_receipts WHERE fk_order_id = ?;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $orderId, PDO::PARAM_INT);
    if ($rows = $GLOBALS['db']->FetchAll($result)) {
      foreach ($rows as $row) {
        $this->template_data['orderDocuments'][] = [
          'orderId' => $row['fk_order_id'],
          'documentId' => $row['receipt_id'],
          'documentType' => 'S1 Document',
          'documentDate' => ng_date('d/m/Y H:i', $row['datetime']),
        ];
      }
    }

    $this->template_data['orderId'] = $orderId;
    $this->ParseTemplate('viewOrderDocuments', false, $this->template_data);
  }

  public function deleteOrderDocument()
  {
    $redirectUrl = "/admin/index.php?ToDo=runAddon&addon=$this->id&route=viewOrderDocuments";

    $orderId = $_REQUEST['orderId'] ?? 0;
    $documentId = $_REQUEST['documentId'] ?? 0;

    if (!isId($orderId) || empty($documentId)) {
      FlashMessage(GetLang($this->langVar . 'InvalidOrderOrDocument'), MSG_ERROR, $redirectUrl);
      return;
    }

    $query = "DELETE FROM [|PREFIX|]addon_niceyous1erp_order_receipts WHERE fk_order_id = ? AND receipt_id = ?;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $orderId, PDO::PARAM_INT);
    $GLOBALS['db']->bindParam($result, 2, $documentId, PDO::PARAM_STR);
    $GLOBALS['db']->Execute($result);
    if ($GLOBALS['db']->CountResults($result) == 1) {
      FlashMessage(GetLang($this->langVar . 'OrderDocumentDeleted'), MSG_SUCCESS, $redirectUrl);
    } else {
      FlashMessage(GetLang($this->langVar . 'OrderDocumentNotDeleted', ['error' => $GLOBALS['db']->GetErrorMsg()]), MSG_ERROR, $redirectUrl);
    }
  }

  /* ------------------------------------------------------- order events */

  public function SendNewOrderInfo(NagaCommerce_Event $event)
  {
    if (!$this->GetValue('AutoSyncEnabled') || !$this->GetValue('SyncOnNewOrder')) {
      return false;
    }

    $GLOBALS['NG_CLASS_LOG']->LogSystemDebug(['addon', $this->GetId()], 'SendNewOrderInfo Triggered', json_encode($event->data));

    $ordToken = $event->data[0];

    $order = LoadPendingOrdersByToken($ordToken, true);
    if (!$order || in_array($order['status'], [ORDER_STATUS_DECLINED, ORDER_STATUS_INCOMPLETE])) {
      return false;
    }

    require_once(__DIR__ . '/library/orders.class.php');

    $NewOrder = new ADDON_NICEYOUS1ERP_ORDERS();
    $NewOrder->SetSyncType(ADDON_NICEYOUS1ERP_ORDERS::IS_NORMAL_SYNC);
    return $NewOrder->HandleOrder($order);
  }

  public function OrderUpdated(NagaCommerce_Event $event)
  {
    if (!$this->GetValue('AutoSyncEnabled')) {
      return false;
    }

    $ordToken = $event->data[0];

    $order = LoadPendingOrdersByToken($ordToken, true);
    if (!$order || in_array($order['status'], [ORDER_STATUS_DECLINED, ORDER_STATUS_INCOMPLETE])) {
      return false;
    }

    // Only resend orders the ERP already knows; a plain edit on an order
    // that never matched the sync statuses must not create a document.
    $orderRow = current($order['orders']);
    if (!$this->orderHasReceipt((int)$orderRow['orderid'])) {
      return false;
    }

    require_once(__DIR__ . '/library/orders.class.php');

    $NewOrder = new ADDON_NICEYOUS1ERP_ORDERS();
    $NewOrder->SetSyncType(ADDON_NICEYOUS1ERP_ORDERS::IS_ORDER_UPDATE_SYNC);
    return $NewOrder->HandleOrder($order);
  }

  public function OrderStatusChanged(NagaCommerce_Event $event)
  {
    if (!$this->GetValue('AutoSyncEnabled')) {
      return false;
    }

    $orderId = $event->data['orderId'] ?? null;

    if (empty($orderId)) {
      return false;
    }

    $order = LoadPendingOrderById($orderId);

    if (!$order || in_array($order['status'], [ORDER_STATUS_DECLINED, ORDER_STATUS_INCOMPLETE])) {
      return false;
    }

    // NiceYou rule: push the order only when it enters one of the
    // configured statuses (e.g. "ready to ship"). Empty setting = any status.
    $statuses = $this->SyncOrderStatusIds();
    if (!empty($statuses) && !in_array((string)$order['status'], $statuses, true)) {
      return false;
    }

    require_once(__DIR__ . '/library/orders.class.php');

    $NewOrder = new ADDON_NICEYOUS1ERP_ORDERS();
    $NewOrder->SetSyncType(ADDON_NICEYOUS1ERP_ORDERS::IS_UPDATE_STATUS_SYNC);
    return $NewOrder->HandleOrder($order);
  }

  public function ShipmentCreated(NagaCommerce_Event $event)
  {
    if (!$this->GetValue('AutoSyncEnabled')) {
      return false;
    }

    $data = $event->data['request'];
    $orderId = $data['orderId'] ?? null;

    if (empty($orderId)) {
      return false;
    }

    $order = LoadPendingOrderById($orderId);
    if (!$order || !$this->orderHasReceipt((int)$orderId)) {
      return false;
    }

    require_once(__DIR__ . '/library/orders.class.php');

    $NewOrder = new ADDON_NICEYOUS1ERP_ORDERS();
    $NewOrder->SetSyncType(ADDON_NICEYOUS1ERP_ORDERS::IS_SHIPMENT_CREATED_SYNC);
    return $NewOrder->HandleOrder($order);
  }

  public function ManualOrderSync()
  {
    if (isset($_GET['ordertoken'])) {
      require_once(__DIR__ . '/library/orders.class.php');

      $ordertoken = html_escape($_GET['ordertoken']);

      $NewOrder = new ADDON_NICEYOUS1ERP_ORDERS();
      $NewOrder->SetSyncType(ADDON_NICEYOUS1ERP_ORDERS::IS_MANUAL_SYNC);
      $response = $NewOrder->HandleOrderToken($ordertoken);
    } else {
      $response = ['result' => false, 'message' => 'No order token provided'];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    die;
  }

  public function OrderQuickView(NagaCommerce_Event $event)
  {
    $orderData = $event->data;
    $orderId = $orderData['orderid'];

    $query = "SELECT * FROM [|PREFIX|]addon_niceyous1erp_order_receipts WHERE fk_order_id = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $orderId, PDO::PARAM_INT);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      $receiptId = $row['receipt_id'];
      $event->data['orderDocuments'][] = [
        'documentid' => $receiptId,
        'documenttype' => 'S1 Document',
        'documentdate' => ng_date('d/m/Y H:i', $row['datetime']),
        'documentuniqueid' => $receiptId,
        'actions' => [
          [
            'label' => '<i class="fa fa-eye"></i>',
            'url' => 'index.php?ToDo=runAddon&addon=' . $this->GetId() . '&route=viewOrderDocuments&orderId=' . $orderId,
            'class' => 'btn-secondary btn-icon view-order-document',
            'attributes' => [
              'data-order-document-id' => $receiptId,
              'data-order-document-unique-id' => $receiptId,
              'data-order-document-type' => 'document',
            ]
          ]
        ]
      ];
    }
  }

  /**
   * Hygiene on core product deletion (admin screen, API, or our own mass
   * delete — DoDeleteProducts fires this after commit): drop the ERP map
   * rows and any queued pushes for the removed products. Deliberately NOT
   * gated by AutoSyncEnabled — it's local cleanup, nothing goes to the ERP.
   */
  public function ProductsDeleted(NagaCommerce_Event $event)
  {
    $prodIds = array_values(array_filter(array_map('intval', (array)($event->data['prodids'] ?? [])), 'isId'));

    if (empty($prodIds)) {
      return;
    }

    $idList = implode(',', $prodIds);
    $GLOBALS['db']->DeleteQuery('addon_niceyous1erp_product_map', 'WHERE productid IN (' . $idList . ')');
    $GLOBALS['db']->DeleteQuery('addon_niceyous1erp_transactions', 'WHERE productid IN (' . $idList . ')');
  }

  protected function orderHasReceipt(int $orderId): bool
  {
    $query = "SELECT receipt_id FROM [|PREFIX|]addon_niceyous1erp_order_receipts WHERE fk_order_id = ? LIMIT 1;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $orderId, PDO::PARAM_INT);
    return (bool)$GLOBALS['db']->FetchOne($result);
  }
}
