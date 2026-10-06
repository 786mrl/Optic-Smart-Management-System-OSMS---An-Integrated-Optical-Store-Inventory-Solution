<?php
// lisani_aos/investor_content.php
// Section "Investor" di sidebar. Di-include dari index.php di dalam .content,
// seperti *_content.php lainnya. Data dari ajax/investor_api.php.
?>
<div class="menu-section" data-section="investor" style="display:none;">
  <div style="width:100%;display:flex;flex-direction:column;gap:16px;min-width:0;">

    <div id="invMsg" class="badge badge-success" style="display:none;align-self:flex-start;"></div>

    <div class="tab-group">
      <button type="button" class="tab active" data-inv-tab="investors">Investors</button>
      <button type="button" class="tab" data-inv-tab="allocation">Project Allocation</button>
      <button type="button" class="tab" data-inv-tab="report">Investment Report</button>
      <button type="button" class="tab" data-inv-tab="profit">Profit &amp; Rolled Capital</button>
    </div>

    <!-- ============ TAB: INVESTORS ============ -->
    <div data-inv-panel="investors" style="display:flex;flex-direction:column;gap:16px;">

      <div class="card">
        <div class="form-group">
          <div class="label">Investor Name</div>
          <input type="text" class="input input-uppercase" id="invName" placeholder="Investor name">
        </div>
        <input type="hidden" id="invId" value="0">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="invSaveBtn">Save Investor</button>
          <button type="button" class="btn btn-secondary" id="invClearBtn">Clear</button>
        </div>
      </div>

      <div class="card">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Investor</th>
                <th style="text-align:right;">Total Deposits (IDR)</th>
                <th style="text-align:right;">Non-Project Expenses (IDR)</th>
                <th style="text-align:right;">ICU (IDR)</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="invTableBody"></tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="form-group">
          <div class="label">Selected Investor</div>
          <select class="select" id="invPick"></select>
        </div>
      </div>

      <!-- Setoran -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Deposits</div>
        <input type="hidden" id="depId" value="0">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px;">
          <div class="form-group">
            <div class="label">Deposit Date</div>
            <input type="date" class="input" id="depDate">
          </div>
          <div class="form-group">
            <div class="label">Currency</div>
            <select class="select" id="depCurrency">
              <option value="IDR">IDR</option>
              <option value="USD">USD</option>
              <option value="EUR">EUR</option>
              <option value="SGD">SGD</option>
              <option value="MYR">MYR</option>
              <option value="AUD">AUD</option>
              <option value="JPY">JPY</option>
              <option value="CNY">CNY</option>
            </select>
          </div>
          <div class="form-group">
            <div class="label">Amount</div>
            <input type="text" class="input input-number-comma" id="depAmount" placeholder="0">
          </div>
          <div class="form-group" id="depRateWrap" style="display:none;">
            <div class="label">Exchange Rate (to IDR)</div>
            <input type="text" class="input input-number-comma" id="depRate" placeholder="0">
          </div>
          <div class="form-group">
            <div class="label">Notes</div>
            <input type="text" class="input input-uppercase" id="depNotes" placeholder="Notes">
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="depSaveBtn">Save Deposit</button>
          <button type="button" class="btn btn-secondary" id="depClearBtn">Clear</button>
        </div>
        <div class="table-wrapper" style="margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Currency</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:right;">Rate</th>
                <th style="text-align:right;">IDR</th>
                <th>Notes</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="depBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Pengeluaran non-project -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Non-Project Expenses</div>
        <input type="hidden" id="supId" value="0">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px;">
          <div class="form-group">
            <div class="label">Category</div>
            <select class="select" id="supCategory">
              <option value="return_capital">Return of capital</option>
              <option value="aid">Aid / assistance</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <div class="label">Date</div>
            <input type="date" class="input" id="supDate">
          </div>
          <div class="form-group">
            <div class="label">Amount (IDR)</div>
            <input type="text" class="input input-number-comma" id="supAmount" placeholder="0">
          </div>
          <div class="form-group">
            <div class="label">Notes</div>
            <input type="text" class="input input-uppercase" id="supNotes" placeholder="Notes">
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="supSaveBtn">Save Expense</button>
          <button type="button" class="btn btn-secondary" id="supClearBtn">Clear</button>
        </div>
        <div class="table-wrapper" style="margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Category</th>
                <th style="text-align:right;">Amount (IDR)</th>
                <th>Notes</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="supBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ TAB: PROJECT ALLOCATION ============ -->
    <div data-inv-panel="allocation" style="display:none;flex-direction:column;gap:16px;">

      <!-- Alokasi investor ke activity -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Allocation per Investor</div>
        <div class="form-group">
          <div class="label">Investor</div>
          <select class="select" id="allocPick"></select>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:12px;">
          <div class="form-group">
            <div class="label">Activity Code</div>
            <select class="select" id="allocActivity"></select>
          </div>
          <div class="form-group">
            <div class="label">Share of Investor Money (%)</div>
            <input type="text" class="input input-number-comma" id="allocPercent" placeholder="0">
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <button type="button" class="btn btn-primary" id="allocSaveBtn">Save Allocation</button>
          <span id="allocTotal" class="badge badge-warning"></span>
        </div>
        <div class="table-wrapper" style="margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th style="text-align:right;">Share (%)</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="allocBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Penautan pengeluaran project -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Project Expenses (Link Transactions to Activity Code)</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:12px;">
          <div class="form-group">
            <div class="label">Transaction</div>
            <select class="select" id="linkTx"></select>
          </div>
          <div class="form-group">
            <div class="label">Activity Code</div>
            <select class="select" id="linkActivity"></select>
          </div>
          <div class="form-group">
            <div class="label">Amount (IDR)</div>
            <input type="text" class="input input-number-comma" id="linkAmount" placeholder="0">
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="linkSaveBtn">Link Expense</button>
        </div>
        <div class="table-wrapper" style="margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Notes</th>
                <th style="text-align:right;">Total (IDR)</th>
                <th style="text-align:right;">Linked (IDR)</th>
                <th style="text-align:right;">Remaining (IDR)</th>
                <th>Linked To</th>
              </tr>
            </thead>
            <tbody id="txBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Pengaturan pdp per project -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Profit Distribution % per Activity Code (pdp)</div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th style="text-align:right;">Total Cost (IDR)</th>
                <th style="text-align:right;">pdp (%)</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="pdpBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ TAB: INVESTMENT REPORT ============ -->
    <div data-inv-panel="report" style="display:none;flex-direction:column;gap:16px;">
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investment Report per Activity Code</div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th style="text-align:right;">Total Cost</th>
                <th style="text-align:right;">Investor Fund Used</th>
                <th style="text-align:right;">Company Additional</th>
                <th style="text-align:right;">Sales Actual</th>
                <th style="text-align:right;">Gross Profit</th>
                <th style="text-align:right;">Zakat 2.5%</th>
                <th style="text-align:right;">Net Profit</th>
                <th style="text-align:right;">pdp</th>
                <th style="text-align:right;">Investor Distribution</th>
              </tr>
            </thead>
            <tbody id="repBody"></tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investor Share per Activity Code</div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th>Investor</th>
                <th style="text-align:right;">Money Used</th>
                <th style="text-align:right;">Ratio (%)</th>
                <th style="text-align:right;">Profit</th>
              </tr>
            </thead>
            <tbody id="repInvBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ TAB: PROFIT & ROLLED CAPITAL ============ -->
    <div data-inv-panel="profit" style="display:none;flex-direction:column;gap:16px;">

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Profit Payment and Rolled Capital</div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Investor</th>
                <th style="text-align:right;">Total Profit (TP)</th>
                <th style="text-align:right;">Paid Profit (PP)</th>
                <th style="text-align:right;">ICU</th>
                <th style="text-align:right;">Rolled Capital</th>
              </tr>
            </thead>
            <tbody id="profBody"></tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Record Profit Payment</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px;">
          <div class="form-group">
            <div class="label">Investor</div>
            <select class="select" id="payPick"></select>
          </div>
          <div class="form-group">
            <div class="label">Payment Date</div>
            <input type="date" class="input" id="payDate">
          </div>
          <div class="form-group">
            <div class="label">Amount (IDR)</div>
            <input type="text" class="input input-number-comma" id="payAmount" placeholder="0">
          </div>
          <div class="form-group">
            <div class="label">Notes</div>
            <input type="text" class="input input-uppercase" id="payNotes" placeholder="Notes">
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="paySaveBtn">Save Payment</button>
        </div>
        <div class="table-wrapper" style="margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Investor</th>
                <th style="text-align:right;">Amount (IDR)</th>
                <th>Notes</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="payBody"></tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal konfirmasi password untuk aksi hapus (pola a). Di luar wrapper section. -->
<div class="modal-overlay" id="invPwOverlay" style="display:none;">
  <div class="modal" style="max-width:360px;">
    <div class="modal-header" id="invPwTitle">Confirm password</div>
    <div class="form-group">
      <div class="label">Password</div>
      <input type="password" class="input" id="invPwInput" autocomplete="current-password">
    </div>
    <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" class="btn btn-secondary" id="invPwCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="invPwOk">Confirm</button>
    </div>
  </div>
</div>

<script>
(function () {
  var API = 'ajax/investor_api.php';
  var root = document.querySelector('[data-section="investor"]');
  if (!root) return;

  var state = null;
  var pendingAction = null;

  function byId(id) { return document.getElementById(id); }
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }
  function num(v) {
    var n = parseFloat(String(v === null || v === undefined ? '' : v).replace(/,/g, ''));
    return isNaN(n) ? 0 : n;
  }
  function fmt(n) {
    return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function today() { return new Date().toISOString().slice(0, 10); }
  function selId(id) { return parseInt(byId(id).value, 10) || 0; }

  var CATEGORY_LABEL = { return_capital: 'Return of capital', aid: 'Aid / assistance', other: 'Other' };

  // ---------- messages & requests ----------
  function msg(text, isErr) {
    var el = byId('invMsg');
    el.textContent = text;
    el.className = 'badge ' + (isErr ? 'badge-danger' : 'badge-success');
    el.style.display = 'inline-flex';
    clearTimeout(msg.t);
    msg.t = setTimeout(function () { el.style.display = 'none'; }, 4000);
  }

  function request(action, fields, isPost) {
    if (isPost) {
      var body = new URLSearchParams();
      body.append('action', action);
      Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
      return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
    }
    return fetch(API + '?action=' + encodeURIComponent(action), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function load() {
    request('list', null, false).then(function (res) {
      if (!res.ok) { msg(res.message || 'Failed to load.', true); return; }
      state = res.data;
      render();
    }).catch(function () { msg('Connection error.', true); });
  }

  function run(action, fields, okText) {
    return request(action, fields, true).then(function (res) {
      if (!res.ok) { msg(res.message || 'Failed.', true); return false; }
      if (okText) msg(okText, false);
      load();
      return true;
    }).catch(function () { msg('Connection error.', true); return false; });
  }

  // ---------- password modal for destructive actions ----------
  function askPassword(title, action, fields, okText) {
    pendingAction = { action: action, fields: fields || {}, okText: okText };
    byId('invPwTitle').textContent = title;
    byId('invPwInput').value = '';
    byId('invPwOverlay').style.display = 'flex';
    byId('invPwInput').focus();
  }
  byId('invPwCancel').addEventListener('click', function () {
    byId('invPwOverlay').style.display = 'none';
    pendingAction = null;
  });
  byId('invPwOk').addEventListener('click', function () {
    if (!pendingAction) return;
    var p = pendingAction;
    var fields = Object.assign({}, p.fields, { password: byId('invPwInput').value });
    byId('invPwOverlay').style.display = 'none';
    pendingAction = null;
    run(p.action, fields, p.okText);
  });

  // ---------- tabs ----------
  root.querySelectorAll('[data-inv-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-inv-tab');
      root.querySelectorAll('[data-inv-tab]').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      root.querySelectorAll('[data-inv-panel]').forEach(function (p) {
        var on = p.getAttribute('data-inv-panel') === target;
        p.style.display = on ? 'flex' : 'none';
      });
    });
  });

  // ---------- render ----------
  function render() {
    fillSelects();
    renderInvestors();
    renderDeposits();
    renderSupport();
    renderAllocation();
    renderTransactions();
    renderPdp();
    renderReport();
    renderProfit();
  }

  function fillSelects() {
    var investorSelects = ['invPick', 'allocPick', 'payPick'];
    investorSelects.forEach(function (id) {
      var sel = byId(id);
      var prev = sel.value;
      sel.innerHTML = '';
      state.investors.forEach(function (inv) {
        var o = document.createElement('option');
        o.value = inv.id;
        o.textContent = inv.name;
        sel.appendChild(o);
      });
      if (prev && state.investors.some(function (i) { return String(i.id) === prev; })) {
        sel.value = prev;
      }
    });

    var actSelects = ['allocActivity', 'linkActivity'];
    actSelects.forEach(function (id) {
      var sel = byId(id);
      var prev = sel.value;
      sel.innerHTML = '';
      state.activity_options.forEach(function (a) {
        var o = document.createElement('option');
        o.value = a.id;
        o.textContent = a.label;
        sel.appendChild(o);
      });
      if (prev) sel.value = prev;
    });

    var txSel = byId('linkTx');
    var prevTx = txSel.value;
    txSel.innerHTML = '';
    state.transactions.filter(function (t) { return t.remaining > 0.005; }).forEach(function (t) {
      var o = document.createElement('option');
      o.value = t.id;
      o.textContent = t.date + ' | ' + (t.notes || '-') + ' | remaining ' + fmt(t.remaining);
      txSel.appendChild(o);
    });
    if (prevTx) txSel.value = prevTx;
  }

  function investorName(id) {
    var inv = state.investors.filter(function (i) { return i.id === id; })[0];
    return inv ? inv.name : '-';
  }

  function renderInvestors() {
    var tbody = byId('invTableBody');
    tbody.innerHTML = '';
    if (!state.investors.length) {
      tbody.innerHTML = '<tr><td colspan="5">No investors yet.</td></tr>';
      return;
    }
    state.investors.forEach(function (inv) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(inv.name) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.deposits) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.support) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.icu) + '</td>' +
        '<td>' +
          '<button type="button" class="btn btn-secondary" data-act="select-inv" data-id="' + inv.id + '">Select</button> ' +
          '<button type="button" class="btn btn-secondary" data-act="edit-inv" data-id="' + inv.id + '">Edit</button> ' +
          '<button type="button" class="btn btn-danger" data-act="del-inv" data-id="' + inv.id + '">Delete</button>' +
        '</td>';
      tbody.appendChild(tr);
    });
  }

  function renderDeposits() {
    var investorId = selId('invPick');
    var tbody = byId('depBody');
    tbody.innerHTML = '';
    var rows = state.deposits.filter(function (d) { return d.investor_id === investorId; });
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="7">No deposits for this investor.</td></tr>';
      return;
    }
    rows.forEach(function (d) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(d.date) + '</td>' +
        '<td>' + esc(d.currency) + '</td>' +
        '<td style="text-align:right;">' + fmt(d.amount) + '</td>' +
        '<td style="text-align:right;">' + (d.rate === null ? '-' : fmt(d.rate)) + '</td>' +
        '<td style="text-align:right;">' + fmt(d.final) + '</td>' +
        '<td>' + esc(d.notes) + '</td>' +
        '<td>' +
          '<button type="button" class="btn btn-secondary" data-act="edit-dep" data-id="' + d.id + '">Edit</button> ' +
          '<button type="button" class="btn btn-danger" data-act="del-dep" data-id="' + d.id + '">Delete</button>' +
        '</td>';
      tbody.appendChild(tr);
    });
  }

  function renderSupport() {
    var investorId = selId('invPick');
    var tbody = byId('supBody');
    tbody.innerHTML = '';
    var rows = state.support.filter(function (s) { return s.investor_id === investorId; });
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5">No non-project expenses for this investor.</td></tr>';
      return;
    }
    rows.forEach(function (s) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(s.date) + '</td>' +
        '<td>' + esc(CATEGORY_LABEL[s.category] || s.category) + '</td>' +
        '<td style="text-align:right;">' + fmt(s.amount) + '</td>' +
        '<td>' + esc(s.notes) + '</td>' +
        '<td><button type="button" class="btn btn-danger" data-act="del-sup" data-id="' + s.id + '">Delete</button></td>';
      tbody.appendChild(tr);
    });
  }

  function renderAllocation() {
    var investorId = selId('allocPick');
    var tbody = byId('allocBody');
    tbody.innerHTML = '';
    var rows = state.allocations.filter(function (a) { return a.investor_id === investorId; });
    var total = 0;
    rows.forEach(function (a) {
      total += a.percent;
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(a.activity_label) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.percent) + '</td>' +
        '<td><button type="button" class="btn btn-danger" data-act="del-alloc" data-id="' + a.id + '">Delete</button></td>';
      tbody.appendChild(tr);
    });
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="3">No allocation for this investor.</td></tr>';
    }
    byId('allocTotal').textContent = 'Allocated: ' + fmt(total) + '% of 100%';
  }

  function renderTransactions() {
    var tbody = byId('txBody');
    tbody.innerHTML = '';
    if (!state.transactions.length) {
      tbody.innerHTML = '<tr><td colspan="6">No disbursement transactions.</td></tr>';
      return;
    }
    state.transactions.forEach(function (t) {
      var links = t.links.map(function (l) {
        return '<div>' + esc(l.activity_label) + ': ' + fmt(l.amount) +
          ' <button type="button" class="btn btn-danger" data-act="unlink" data-id="' + l.id + '">Unlink</button></div>';
      }).join('');
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(t.date) + '</td>' +
        '<td>' + esc(t.notes) + '</td>' +
        '<td style="text-align:right;">' + fmt(t.final) + '</td>' +
        '<td style="text-align:right;">' + fmt(t.linked) + '</td>' +
        '<td style="text-align:right;">' + fmt(t.remaining) + '</td>' +
        '<td>' + (links || '<span class="badge badge-warning">UNLINKED</span>') + '</td>';
      tbody.appendChild(tr);
    });
  }

  function renderPdp() {
    var tbody = byId('pdpBody');
    tbody.innerHTML = '';
    if (!state.activities.length) {
      tbody.innerHTML = '<tr><td colspan="4">No activity codes.</td></tr>';
      return;
    }
    state.activities.forEach(function (a) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(a.label) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.cost) + '</td>' +
        '<td style="text-align:right;"><input type="text" class="input input-number-comma" style="max-width:120px;margin-left:auto;" data-pdp-for="' + a.id + '" value="' + a.pdp + '"></td>' +
        '<td><button type="button" class="btn btn-secondary" data-act="save-pdp" data-id="' + a.id + '">Save</button></td>';
      tbody.appendChild(tr);
    });
  }

  function renderReport() {
    var tbody = byId('repBody');
    var tbodyInv = byId('repInvBody');
    tbody.innerHTML = '';
    tbodyInv.innerHTML = '';
    if (!state.activities.length) {
      tbody.innerHTML = '<tr><td colspan="10">No activity codes.</td></tr>';
      return;
    }
    state.activities.forEach(function (a) {
      var badge = a.net < 0 ? ' <span class="badge badge-danger">RUGI</span>' : '';
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(a.label) + badge + '</td>' +
        '<td style="text-align:right;">' + fmt(a.cost) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.investor_used) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.company) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.sales) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.gross) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.zakat) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.net) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.pdp) + '</td>' +
        '<td style="text-align:right;">' + fmt(a.distribution) + '</td>';
      tbody.appendChild(tr);

      a.investors.forEach(function (r) {
        var tr2 = document.createElement('tr');
        tr2.innerHTML =
          '<td>' + esc(a.label) + '</td>' +
          '<td>' + esc(r.investor_name) + '</td>' +
          '<td style="text-align:right;">' + fmt(r.used) + '</td>' +
          '<td style="text-align:right;">' + fmt(r.ratio) + '</td>' +
          '<td style="text-align:right;">' + fmt(r.profit) + '</td>';
        tbodyInv.appendChild(tr2);
      });
    });
    if (!tbodyInv.children.length) {
      tbodyInv.innerHTML = '<tr><td colspan="5">No investor allocation yet.</td></tr>';
    }
  }

  function renderProfit() {
    var tbody = byId('profBody');
    tbody.innerHTML = '';
    state.investors.forEach(function (inv) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(inv.name) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.tp) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.pp) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.icu) + '</td>' +
        '<td style="text-align:right;">' + fmt(inv.rolled) + '</td>';
      tbody.appendChild(tr);
    });
    if (!state.investors.length) {
      tbody.innerHTML = '<tr><td colspan="5">No investors yet.</td></tr>';
    }

    var payBody = byId('payBody');
    payBody.innerHTML = '';
    state.payments.forEach(function (p) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(p.date) + '</td>' +
        '<td>' + esc(investorName(p.investor_id)) + '</td>' +
        '<td style="text-align:right;">' + fmt(p.amount) + '</td>' +
        '<td>' + esc(p.notes) + '</td>' +
        '<td><button type="button" class="btn btn-danger" data-act="del-pay" data-id="' + p.id + '">Delete</button></td>';
      payBody.appendChild(tr);
    });
    if (!state.payments.length) {
      payBody.innerHTML = '<tr><td colspan="5">No profit payments yet.</td></tr>';
    }
  }

  // ---------- form actions ----------
  byId('invSaveBtn').addEventListener('click', function () {
    run('save_investor', {
      id: byId('invId').value,
      investor_name: byId('invName').value
    }, 'Investor saved.').then(function (ok) { if (ok) clearInvestorForm(); });
  });
  byId('invClearBtn').addEventListener('click', clearInvestorForm);
  function clearInvestorForm() {
    byId('invId').value = '0';
    byId('invName').value = '';
  }

  byId('invPick').addEventListener('change', function () { renderDeposits(); renderSupport(); });

  byId('depCurrency').addEventListener('change', function () {
    byId('depRateWrap').style.display = byId('depCurrency').value === 'IDR' ? 'none' : 'block';
  });
  byId('depSaveBtn').addEventListener('click', function () {
    run('save_deposit', {
      id: byId('depId').value,
      investor_id: selId('invPick'),
      deposit_date: byId('depDate').value,
      currency: byId('depCurrency').value,
      amount: byId('depAmount').value,
      exchange_rate: byId('depRate').value,
      notes: byId('depNotes').value
    }, 'Deposit saved.').then(function (ok) { if (ok) clearDepositForm(); });
  });
  byId('depClearBtn').addEventListener('click', clearDepositForm);
  function clearDepositForm() {
    byId('depId').value = '0';
    byId('depDate').value = today();
    byId('depCurrency').value = 'IDR';
    byId('depRateWrap').style.display = 'none';
    byId('depAmount').value = '';
    byId('depRate').value = '';
    byId('depNotes').value = '';
  }

  byId('supSaveBtn').addEventListener('click', function () {
    run('save_support', {
      id: byId('supId').value,
      investor_id: selId('invPick'),
      expense_date: byId('supDate').value,
      category: byId('supCategory').value,
      amount: byId('supAmount').value,
      notes: byId('supNotes').value
    }, 'Expense saved.').then(function (ok) { if (ok) clearSupportForm(); });
  });
  byId('supClearBtn').addEventListener('click', clearSupportForm);
  function clearSupportForm() {
    byId('supId').value = '0';
    byId('supDate').value = today();
    byId('supAmount').value = '';
    byId('supNotes').value = '';
  }

  byId('allocPick').addEventListener('change', renderAllocation);
  byId('allocSaveBtn').addEventListener('click', function () {
    run('save_allocation', {
      investor_id: selId('allocPick'),
      activity_id: selId('allocActivity'),
      allocation_percent: byId('allocPercent').value
    }, 'Allocation saved.').then(function (ok) { if (ok) byId('allocPercent').value = ''; });
  });

  byId('linkTx').addEventListener('change', function () {
    var txId = selId('linkTx');
    var t = state.transactions.filter(function (x) { return x.id === txId; })[0];
    if (t) byId('linkAmount').value = fmt(t.remaining).replace(/\.00$/, '');
  });
  byId('linkSaveBtn').addEventListener('click', function () {
    run('link_transaction', {
      transaction_id: selId('linkTx'),
      activity_id: selId('linkActivity'),
      amount: byId('linkAmount').value
    }, 'Expense linked.').then(function (ok) { if (ok) byId('linkAmount').value = ''; });
  });

  byId('payPick').addEventListener('change', function () { /* no-op: pick only */ });
  byId('paySaveBtn').addEventListener('click', function () {
    run('save_profit_payment', {
      investor_id: selId('payPick'),
      payment_date: byId('payDate').value,
      amount: byId('payAmount').value,
      notes: byId('payNotes').value
    }, 'Payment saved.').then(function (ok) { if (ok) byId('payAmount').value = ''; });
  });

  // ---------- delegated clicks inside this module ----------
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn) return;
    var act = btn.getAttribute('data-act');
    var id = parseInt(btn.getAttribute('data-id'), 10) || 0;

    if (act === 'select-inv') {
      byId('invPick').value = id;
      renderDeposits(); renderSupport();
    } else if (act === 'edit-inv') {
      var inv = state.investors.filter(function (i) { return i.id === id; })[0];
      if (inv) { byId('invId').value = inv.id; byId('invName').value = inv.name; }
    } else if (act === 'del-inv') {
      askPassword('Delete investor', 'delete_investor', { id: id }, 'Investor deleted.');
    } else if (act === 'edit-dep') {
      var d = state.deposits.filter(function (x) { return x.id === id; })[0];
      if (!d) return;
      byId('depId').value = d.id;
      byId('depDate').value = d.date;
      byId('depCurrency').value = d.currency;
      byId('depRateWrap').style.display = d.currency === 'IDR' ? 'none' : 'block';
      byId('depAmount').value = d.amount;
      byId('depRate').value = d.rate === null ? '' : d.rate;
      byId('depNotes').value = d.notes;
    } else if (act === 'del-dep') {
      askPassword('Delete deposit', 'delete_deposit', { id: id }, 'Deposit deleted.');
    } else if (act === 'del-sup') {
      askPassword('Delete expense', 'delete_support', { id: id }, 'Expense deleted.');
    } else if (act === 'del-alloc') {
      askPassword('Delete allocation', 'delete_allocation', { id: id }, 'Allocation deleted.');
    } else if (act === 'unlink') {
      askPassword('Unlink expense', 'unlink_transaction', { id: id }, 'Expense unlinked.');
    } else if (act === 'del-pay') {
      askPassword('Delete profit payment', 'delete_profit_payment', { id: id }, 'Payment deleted.');
    } else if (act === 'save-pdp') {
      var input = root.querySelector('[data-pdp-for="' + id + '"]');
      run('save_pdp', {
        activity_id: id,
        profit_distribution_percent: input ? input.value : 0
      }, 'pdp saved.');
    }
  });

  // Reload when the section is shown (footer.php dispatches this event).
  document.addEventListener('aos:section-shown', function (e) {
    if (e.detail && e.detail.target === 'investor') {
      load();
    }
  });

  // Defaults
  byId('depDate').value = today();
  byId('supDate').value = today();
  byId('payDate').value = today();
})();
</script>
