<?php

/**
 * NiceYou SoftOne ERP - s1services client.
 *
 * Unlike the generic SoftOne addon (which talks to custom js/api.v1 web
 * services), the NiceYou installation exposes only the stock SoftOne
 * `s1services` JSON endpoint, so everything (login, setData, browser lists)
 * goes through one URL. Uses the platform HTTP stack and the shared
 * greek-encoding fallback chain.
 */
class ADDON_NICEYOUS1ERP_API
{
  const ERR_NULL_RESPONSE = 'Null response from server';
  const ERR_LOGIN_FAILED = 'Login failed';
  const ERR_AUTH_FAILED = 'Authentication failed';

  private $baseUrl;
  private $appId;
  private $clientID = '';

  public function __construct(string $baseUrl, string $appId)
  {
    $this->baseUrl = rtrim($baseUrl, '/') . '/';
    $this->appId = $appId;
  }

  public function getClientID(): string
  {
    return $this->clientID;
  }

  /**
   * login + authenticate handshake. Stores the session clientID.
   * @throws Exception
   */
  public function login(string $username, string $password): string
  {
    $login = $this->post([
      'service' => 'login',
      'username' => $username,
      'password' => $password,
      'appId' => $this->appId,
    ]);

    if (empty($login['success'])) {
      throw new Exception(self::ERR_LOGIN_FAILED . (isset($login['error']) ? ': ' . $login['error'] : ''));
    }

    $objs = current($login['objs']);

    $auth = $this->post([
      'service' => 'authenticate',
      'clientID' => $login['clientID'],
      'COMPANY' => $objs['COMPANY'],
      'BRANCH' => $objs['BRANCH'],
      'MODULE' => $objs['MODULE'],
      'REFID' => $objs['REFID'],
    ]);

    if (empty($auth['success']) || empty($auth['clientID'])) {
      throw new Exception(self::ERR_AUTH_FAILED . (isset($auth['error']) ? ': ' . $auth['error'] : ''));
    }

    $this->clientID = $auth['clientID'];
    return $this->clientID;
  }

  /**
   * setData call. $data is the payload "data" section (e.g. ['ITEM' => [...]]).
   * Returns the decoded response array.
   * @throws Exception
   */
  public function setData(string $object, string $key, array $data): array
  {
    return $this->post([
      'service' => 'setData',
      'clientID' => $this->clientID,
      'appId' => $this->appId,
      'object' => $object,
      'key' => $key,
      'data' => $data,
    ]);
  }

  /**
   * getBrowserInfo + getBrowserData round trip. Returns the rows array
   * (empty when the list matched nothing).
   * @throws Exception
   */
  public function browserRows(string $object, string $list, ?string $filters = null, int $limit = 5000, int $start = 0): array
  {
    $info = [
      'service' => 'getBrowserInfo',
      'clientID' => $this->clientID,
      'appId' => $this->appId,
      'object' => $object,
      'list' => $list,
    ];

    if ($filters !== null && $filters !== '') {
      $info['filters'] = $filters;
    }

    $infoResponse = $this->post($info);

    if (empty($infoResponse['success']) || empty($infoResponse['totalcount'])) {
      return [];
    }

    $dataResponse = $this->post([
      'service' => 'getBrowserData',
      'clientID' => $this->clientID,
      'appId' => $this->appId,
      'reqID' => $infoResponse['reqID'],
      'start' => $start,
      'limit' => $limit,
    ]);

    if (empty($dataResponse['success']) || empty($dataResponse['rows'])) {
      return [];
    }

    return $dataResponse['rows'];
  }

  /**
   * First matching browser row id ("OBJECT;ID" first cell) or '' when the
   * filter matched nothing. Used for the EAN / email / phone dedup lookups.
   */
  public function findFirstRowId(string $object, string $list, string $filters): string
  {
    try {
      $rows = $this->browserRows($object, $list, $filters, 2);
    } catch (Exception $e) {
      $GLOBALS['NG_CLASS_LOG']->LogSystemError(['addon', 'addon_niceyous1erp'], 'findFirstRowId error', $e->getMessage());
      return '';
    }

    foreach ($rows as $row) {
      $id = ADDON_NICEYOUS1ERP_PAYLOADS::browserRowId($row[0]);
      if ($id !== '') {
        return $id;
      }
    }

    return '';
  }

  /**
   * POST to s1services and decode the (greek-encoded) JSON response.
   * @throws Exception
   */
  private function post(array $data): array
  {
    $requestOptions = new NagaCommerce_Http_RequestOptions;
    $requestOptions->headers = ['Content-Type' => 'application/json'];

    $error = '';
    $response = PostToRemoteFileAndGetResponse(
      $this->baseUrl . 's1services',
      json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      30,
      $error,
      $requestOptions
    );

    if ($error !== '') {
      throw new Exception($error);
    }

    if ($response === null || $response === false || $response === '') {
      throw new Exception(self::ERR_NULL_RESPONSE);
    }

    // Browser-list responses can run to many MB; keep only one copy of the
    // payload alive at a time (raw → converted → decoded).
    $converted = $this->convertEncoding($response);
    unset($response);

    $decoded = json_decode($converted, true);

    if (!is_array($decoded)) {
      throw new Exception('Invalid JSON response: ' . mb_substr($converted, 0, 500));
    }

    return $decoded;
  }

  private function convertEncoding($response)
  {
    $encodings = ['Windows-1253', 'ISO-8859-7', 'UTF-8'];
    $converted = $response;
    foreach ($encodings as $encoding) {
      $attempt = @iconv($encoding, 'UTF-8//IGNORE', $response);
      if ($attempt !== false) {
        $converted = $attempt;
        break;
      }
    }
    return $converted;
  }
}
