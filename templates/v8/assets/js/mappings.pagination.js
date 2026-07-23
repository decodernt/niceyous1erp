/**
 * NiceYou SoftOne ERP — AJAX pagination for the Mappings tab tables.
 *
 * Each paginated table is a <tbody data-mappings-body="<key>"> plus a
 * <div data-mappings-pager data-mappings-table="<key>"> container. Pages are
 * fetched from route=mappingsData as JSON and rows rebuilt via the DOM API
 * (no HTML string concatenation, so values need no escaping).
 */
(function () {
  'use strict';

  var ADDON = 'addon_niceyous1erp';

  function td(value) {
    var cell = document.createElement('td');
    cell.textContent = value === null || value === undefined ? '' : String(value);
    return cell;
  }

  function tdProductLink(productId) {
    var cell = document.createElement('td');
    var link = document.createElement('a');
    link.href = 'index.php?ToDo=editProduct&productId=' + encodeURIComponent(productId);
    link.target = '_blank';
    link.textContent = productId;
    cell.appendChild(link);
    return cell;
  }

  function tdCheckbox(productId) {
    var cell = document.createElement('td');
    var input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'form-check-input';
    input.value = productId;
    input.setAttribute('data-mappings-select', '');
    cell.appendChild(input);
    return cell;
  }

  var ROW_BUILDERS = {
    unmappedProducts: function (r) {
      return [tdCheckbox(r.productid), tdProductLink(r.productid), td(r.prodname), td(r.prodcode), td(r.european_article_number)];
    },
    unmappedCombinations: function (r) {
      return [tdProductLink(r.vcproductid), td(r.prodname), td(r.combinationid), td(r.vcsku), td(r.vcbarcode)];
    },
    noEan: function (r) {
      return [tdProductLink(r.productid), td(r.prodname), td(r.prodcode)];
    },
    unmatchedCategories: function (r) {
      return [td(r.erp_cat_id), td(r.erp_title)];
    }
  };

  function renderRows(body, table, rows) {
    var builder = ROW_BUILDERS[table];
    body.innerHTML = '';
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      builder(row).forEach(function (cell) {
        tr.appendChild(cell);
      });
      body.appendChild(tr);
    });
    body.dispatchEvent(new CustomEvent('mappings:rendered'));
  }

  function pageButton(label, disabled, active, onClick) {
    var li = document.createElement('li');
    li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
    var a = document.createElement('a');
    a.className = 'page-link';
    a.href = '#';
    a.textContent = label;
    a.addEventListener('click', function (event) {
      event.preventDefault();
      if (!disabled && !active) {
        onClick();
      }
    });
    li.appendChild(a);
    return li;
  }

  function renderPager(pager, state) {
    var pages = Math.max(1, Math.ceil(state.total / state.pageSize));
    pager.innerHTML = '';

    if (pages <= 1) {
      return;
    }

    var ul = document.createElement('ul');
    ul.className = 'pagination pagination-sm mb-0';

    ul.appendChild(pageButton('«', state.page === 0, false, function () {
      state.load(0);
    }));
    ul.appendChild(pageButton('‹', state.page === 0, false, function () {
      state.load(state.page - 1);
    }));

    // sliding window of up to 5 numbered pages around the current one
    var start = Math.max(0, Math.min(state.page - 2, pages - 5));
    var end = Math.min(pages, start + 5);
    for (var i = start; i < end; i++) {
      (function (pageIndex) {
        ul.appendChild(pageButton(String(pageIndex + 1), false, pageIndex === state.page, function () {
          state.load(pageIndex);
        }));
      })(i);
    }

    ul.appendChild(pageButton('›', state.page >= pages - 1, false, function () {
      state.load(state.page + 1);
    }));
    ul.appendChild(pageButton('»', state.page >= pages - 1, false, function () {
      state.load(pages - 1);
    }));

    pager.appendChild(ul);
  }

  function init(pager) {
    var table = pager.getAttribute('data-mappings-table');
    var body = document.querySelector('[data-mappings-body="' + table + '"]');
    if (!body || !ROW_BUILDERS[table]) {
      return;
    }

    var state = {
      page: 0,
      total: parseInt(pager.getAttribute('data-total'), 10) || 0,
      pageSize: parseInt(pager.getAttribute('data-page-size'), 10) || 50,
      load: function (page) {
        var url = 'index.php?ToDo=runAddon&addon=' + ADDON +
          '&route=mappingsData&table=' + encodeURIComponent(table) +
          '&page=' + page;

        fetch(url, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (!data || !data.success) {
              return;
            }
            state.page = data.page;
            state.total = data.total;
            state.pageSize = data.pageSize;
            renderRows(body, table, data.rows || []);
            renderPager(pager, state);
          })
          .catch(function () { /* keep current page on transport errors */ });
      }
    };

    renderPager(pager, state);
  }

  function initMassDelete() {
    var button = document.querySelector('[data-mappings-delete-selected]');
    var body = document.querySelector('[data-mappings-body="unmappedProducts"]');
    var selectAll = document.querySelector('[data-mappings-select-all]');
    if (!button || !body) {
      return;
    }

    function selected() {
      return Array.prototype.slice.call(body.querySelectorAll('[data-mappings-select]:checked'));
    }

    function refresh() {
      button.disabled = selected().length === 0;
    }

    body.addEventListener('change', function (event) {
      if (event.target.hasAttribute('data-mappings-select')) {
        refresh();
      }
    });

    // page change rebuilds rows — nothing is selected any more
    body.addEventListener('mappings:rendered', function () {
      if (selectAll) {
        selectAll.checked = false;
      }
      refresh();
    });

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        body.querySelectorAll('[data-mappings-select]').forEach(function (checkbox) {
          checkbox.checked = selectAll.checked;
        });
        refresh();
      });
    }

    button.addEventListener('click', function () {
      var ids = selected().map(function (checkbox) { return checkbox.value; });
      if (!ids.length) {
        return;
      }

      var message = (button.getAttribute('data-confirm') || 'Delete :count products?').replace(':count', ids.length);
      if (!window.confirm(message)) {
        return;
      }

      button.disabled = true;

      fetch('index.php?ToDo=runAddon&addon=' + ADDON + '&route=deleteProducts', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        credentials: 'same-origin',
        body: ids.map(function (id) { return 'products[]=' + encodeURIComponent(id); }).join('&')
      })
        .then(function (response) { return response.json(); })
        .then(function () { window.location.reload(); })
        .catch(function () { window.location.reload(); });
    });

    refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-mappings-pager]').forEach(init);
    initMassDelete();
  });
})();
