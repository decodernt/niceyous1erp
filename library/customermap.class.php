<?php

/**
 * NiceYou SoftOne ERP - customer map admin access.
 *
 * Read/remove access to `addon_niceyous1erp_customer_map` (eshop customer
 * -> ERP TRDR) for the "Customers" admin tab. Deliberately NOT extending the
 * addon class and free of framework helpers, so it runs against FakeDb in
 * the unit suite. Date formatting is left to the caller (ng_date).
 *
 * Removing a mapping is safe: the next order pushed for that customer goes
 * through UpsertCustomer() again, which searches the ERP by email and phone
 * and re-creates the map row (or inserts a fresh ERP customer if none
 * matches).
 */
class ADDON_NICEYOUS1ERP_CUSTOMERMAP
{
  const PAGE_SIZE = 50;

  /**
   * One page of mapped customers joined to the eshop customer row.
   *
   * @param string $search matches customer name (first + last), email,
   *                       exact ERP TRDR, or exact eshop customer id
   * @param int    $page   1-based
   * @return array{total:int, page:int, totalPages:int, rows:array}
   */
  public function search(string $search, int $page): array
  {
    $search = trim($search);
    $page = max(1, $page);

    $from = "
      FROM [|PREFIX|]addon_niceyous1erp_customer_map m
      LEFT JOIN [|PREFIX|]customers c ON (c.customerid = m.customerid)";

    $where = '';
    if ($search !== '') {
      $where = "
      WHERE (CONCAT(c.custconfirstname, ' ', c.custconlastname) LIKE ?
        OR c.custconemail LIKE ?
        OR m.erp_trdr = ?
        OR m.customerid = ?)";
    }

    $total = $this->countRows("SELECT COUNT(*) AS c $from $where;", $search);

    $totalPages = max(1, (int)ceil($total / self::PAGE_SIZE));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * self::PAGE_SIZE;

    $query = "
      SELECT m.customerid, m.erp_trdr, m.last_update,
             c.custconfirstname, c.custconlastname, c.custconemail, c.custconphone
      $from $where
      ORDER BY m.last_update DESC, m.customerid ASC
      LIMIT " . $offset . ', ' . self::PAGE_SIZE . ';';
    $result = $GLOBALS['db']->Query($query);
    $this->bindSearch($result, $search);

    $rows = [];
    foreach ((array)$GLOBALS['db']->FetchAll($result) as $row) {
      $rows[] = [
        'customerid' => (int)$row['customerid'],
        'erp_trdr' => (string)$row['erp_trdr'],
        'last_update' => (int)($row['last_update'] ?? 0),
        'name' => trim((string)($row['custconfirstname'] ?? '') . ' ' . (string)($row['custconlastname'] ?? '')),
        'email' => (string)($row['custconemail'] ?? ''),
        'phone' => (string)($row['custconphone'] ?? ''),
        'orphan' => !isset($row['custconemail']),
      ];
    }

    return [
      'total' => $total,
      'page' => $page,
      'totalPages' => $totalPages,
      'rows' => $rows,
    ];
  }

  /**
   * Drop the eshop customer -> ERP TRDR link. Returns true when a row was
   * actually deleted.
   */
  public function remove(int $customerId): bool
  {
    if ($customerId <= 0) {
      return false;
    }

    $query = "DELETE FROM [|PREFIX|]addon_niceyous1erp_customer_map WHERE customerid = ?;";
    $result = $GLOBALS['db']->Query($query);
    $GLOBALS['db']->bindParam($result, 1, $customerId, PDO::PARAM_INT);
    $GLOBALS['db']->Execute($result);

    return (int)$GLOBALS['db']->CountResults($result) === 1;
  }

  private function countRows(string $query, string $search): int
  {
    $result = $GLOBALS['db']->Query($query);
    $this->bindSearch($result, $search);
    if ($row = $GLOBALS['db']->FetchOne($result)) {
      return (int)$row['c'];
    }
    return 0;
  }

  private function bindSearch($result, string $search): void
  {
    if ($search === '') {
      return;
    }
    $like = '%' . $search . '%';
    $customerId = ctype_digit($search) ? (int)$search : 0;
    $GLOBALS['db']->bindParam($result, 1, $like, PDO::PARAM_STR);
    $GLOBALS['db']->bindParam($result, 2, $like, PDO::PARAM_STR);
    $GLOBALS['db']->bindParam($result, 3, $search, PDO::PARAM_STR);
    $GLOBALS['db']->bindParam($result, 4, $customerId, PDO::PARAM_INT);
  }
}
