<?php
// lisani_aos/investor_content.php
// Section "Investor" di sidebar. Di-include dari index.php di dalam .content.
// Data dan aksi lewat ajax/investor_api.php.
?>
<style>
  .inv-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
  .inv-row > .form-group { flex: 1 1 160px; min-width: 0; margin: 0; }
  .inv-row > .inv-btns { flex: 0 0 auto; display: flex; gap: 8px; }
  .inv-sub { margin: 16px 0 8px; font-weight: 600; }
  .inv-scroll { width: 100%; overflow-x: auto; }
  .inv-scroll table { min-width: 620px; }
  .inv-picker-list { display: flex; flex-direction: column; gap: 8px; max-height: 50vh; overflow-y: auto; }
  .inv-picker-list .btn { justify-content: flex-start; text-align: left; }
  .inv-hint { opacity: .7; font-size: 13px; }
  .num { text-align: right; white-space: nowrap; }
</style>

<div class="menu-section" data-section="investor" style="display:none;">
  <div style="width:100%;display:flex;flex-direction:column;gap:16px;min-width:0;">

    <div id="invMsg" class="badge badge-success" style="display:none;align-self:flex-start;"></div>

    <div class="tab-group">
      <button type="button" class="tab active" data-inv-tab="investors">Investors</button>
      <button type="button" class="tab" data-inv-tab="fund">Investment</button>
      <button type="button" class="tab" data-inv-tab="report">Report</button>
      <button type="button" class="tab" data-inv-tab="profit">Profit Payment &amp; Rolled Capital</button>
    </div>

    <!-- ================= TAB 1: INVESTORS ================= -->
    <div data-inv-panel="investors" style="display:flex;flex-direction:column;gap:16px;">

      <div class="card">
        <div class="label" style="margin-bottom:8px;">New Investor</div>
        <input type="hidden" id="invId" value="0">
        <div class="inv-row">
          <div class="form-group">
            <div class="label">Investor Name</div>
            <input type="text" class="input input-uppercase" id="invName" placeholder="Investor name">
          </div>
          <div class="inv-btns">
            <button type="button" class="btn btn-primary" id="invSaveBtn">Save</button>
            <button type="button" class="btn btn-secondary" id="invClearBtn">Clear</button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investor List</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Investor</th>
                <th class="num">Deposits (IDR)</th>
                <th class="num">Non-Project (IDR)</th>
                <th class="num">ICU (IDR)</th>
                <th class="num">Used in Projects (IDR)</th>
                <th class="num">Total Profit (IDR)</th>
                <th class="num">Paid Profit (IDR)</th>
                <th class="num">Rolled Capital (IDR)</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="invListBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= TAB 2: INVESTMENT ================= -->
    <div data-inv-panel="fund" style="display:none;flex-direction:column;gap:16px;">

      <div class="card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
          <div class="inv-hint">Selected investor</div>
          <div style="font-size:18px;font-weight:600;" id="fundName">-</div>
        </div>
        <button type="button" class="btn btn-secondary" id="fundChangeBtn">Change Investor</button>
      </div>

      <!-- Card 1: input investasi + list + non-project -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investment Input</div>
        <input type="hidden" id="depId" value="0">
        <div class="inv-row">
          <div class="form-group">
            <div class="label">Date</div>
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
          <div class="inv-btns">
            <button type="button" class="btn btn-primary" id="depSaveBtn">Save</button>
            <button type="button" class="btn btn-secondary" id="depClearBtn">Clear</button>
          </div>
        </div>

        <div class="inv-sub">Received Investments</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Currency</th>
                <th class="num">Amount</th>
                <th class="num">Rate</th>
                <th class="num">IDR</th>
                <th>Notes</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="depBody"></tbody>
          </table>
        </div>

        <div class="inv-sub">Non-Project Expenses (returns, aid, others)</div>
        <div class="inv-row">
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
          <div class="inv-btns">
            <button type="button" class="btn btn-primary" id="supSaveBtn">Save</button>
            <button type="button" class="btn btn-secondary" id="supClearBtn">Clear</button>
          </div>
        </div>
        <div class="inv-scroll" style="margin-top:8px;">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Category</th>
                <th class="num">Amount (IDR)</th>
                <th>Notes</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="supBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Card 2: investor fund utilization -->
      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investor Fund Utilization (linked to projects)</div>
        <div class="inv-row">
          <div class="form-group">
            <div class="label">Activity Code</div>
            <select class="select" id="allocActivity"></select>
          </div>
          <div class="form-group">
            <div class="label">Share of Investor Fund (%)</div>
            <input type="text" class="input input-number-comma" id="allocPercent" placeholder="0">
          </div>
          <div class="inv-btns">
            <button type="button" class="btn btn-primary" id="allocSaveBtn">Link to Project</button>
          </div>
        </div>
        <div class="inv-hint" style="margin-top:6px;" id="allocTotal"></div>
        <div class="inv-scroll" style="margin-top:8px;">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th class="num">Share (%)</th>
                <th class="num">Fund Used (IDR)</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="allocBody"></tbody>
          </table>
        </div>

        <div class="inv-sub">Project Expenses (auto-linked via transaction Category)</div>
        <div class="inv-hint" style="margin-bottom:8px;">
          Shows disbursement transactions already linked to the activity codes above.
          Linking itself happens in Transactions (Category step), not here.
        </div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th>Date</th>
                <th>Notes</th>
                <th class="num">Amount (IDR)</th>
              </tr>
            </thead>
            <tbody id="txBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= TAB 3: REPORT ================= -->
    <div data-inv-panel="report" style="display:none;flex-direction:column;gap:16px;">

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investor Fund Usage Details</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Investor</th>
                <th class="num">Deposits (IDR)</th>
                <th class="num">Non-Project (IDR)</th>
                <th class="num">ICU (IDR)</th>
                <th class="num">Used in Projects (IDR)</th>
                <th class="num">Not Yet Allocated (IDR)</th>
              </tr>
            </thead>
            <tbody id="rptUsageBody"></tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Sales Report per Project</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th class="num">Sales Actual (IDR)</th>
                <th class="num">Total Cost (IDR)</th>
              </tr>
            </thead>
            <tbody id="rptSalesBody"></tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Investment Profit Distribution</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th class="num">Total Cost</th>
                <th class="num">Total Investor Fund</th>
                <th class="num">Company Additional</th>
                <th class="num">Sales Actual</th>
                <th class="num">Gross Profit</th>
                <th class="num">Zakat 2.5%</th>
                <th class="num">Net Profit</th>
                <th class="num">pdp (%)</th>
                <th class="num">Investor Distribution</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="rptDistBody"></tbody>
          </table>
        </div>

        <div class="inv-sub">Per Investor Share</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Activity Code</th>
                <th>Investor</th>
                <th class="num">Fund Used (IDR)</th>
                <th class="num">Ratio (%)</th>
                <th class="num">Profit (IDR)</th>
              </tr>
            </thead>
            <tbody id="rptShareBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= TAB 4: PROFIT PAYMENT & ROLLED CAPITAL ================= -->
    <div data-inv-panel="profit" style="display:none;flex-direction:column;gap:16px;">

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Profit Payment and Rolled Capital Report</div>
        <div class="inv-scroll">
          <table>
            <thead>
              <tr>
                <th>Investor</th>
                <th class="num">Total Profit (TP)</th>
                <th class="num">Paid Profit (PP)</th>
                <th class="num">ICU</th>
                <th class="num">Rolled Capital</th>
              </tr>
            </thead>
            <tbody id="profBody"></tbody>
          </table>
        </div>
        <div class="inv-hint" style="margin-top:6px;">Rolled Capital = (TP − PP) + ICU</div>
      </div>

      <div class="card">
        <div class="label" style="margin-bottom:8px;">Record Profit Payment</div>
        <div class="inv-row">
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
          <div class="inv-btns">
            <button type="button" class="btn btn-primary" id="paySaveBtn">Save</button>
          </div>
        </div>
        <div class="inv-scroll" style="margin-top:8px;">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Investor</th>
                <th class="num">Amount (IDR)</th>
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

<!-- Modal: pilih investor (muncul saat tab Investment dibuka) -->
<div class="modal-overlay" id="invPickerOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">Select Investor</div>
    <div class="inv-hint" style="margin-bottom:8px;">Choose the investor to manage investments for.</div>
    <div class="inv-picker-list" id="invPickerList"></div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;margin-top:12px;">
      <button type="button" class="btn btn-secondary" id="invPickerCancel">Close</button>
    </div>
  </div>
</div>

<!-- Modal: konfirmasi password untuk aksi hapus (pola a) -->
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
  var selectedInvestorId = 0;
  var pendingAction = null;

  function byId(id) { return document.getElementById(id); }
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }
  function fmt(n) {
    return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function today() { return new Date().toISOString().slice(0, 10); }
  function selId(id) { return parseInt(byId(id).value, 10) || 0; }
  function findInv(id) {
    for (var i = 0; i < state.investors.length; i++) {
      if (state.investors[i].id === id) return state.investors[i];
    }
    return null;
  }
  function invName(id) { var inv = findInv(id); return inv ? inv.name : '-'; }
  function emptyRow(tbodyId, cols, text) {
    byId(tbodyId).innerHTML = '<tr><td colspan="' + cols + '" class="inv-hint">' + text + '</td></tr>';
  }

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

  // ---------- password modal ----------
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

  // ---------- investor picker (modal) ----------
  function openPicker() {
    var list = byId('invPickerList');
    list.innerHTML = '';
    var investors = state ? state.investors : [];
    if (!investors.length) {
      list.innerHTML = '<div class="inv-hint">No investors yet. Add one in the Investors tab.</div>';
    }
    investors.forEach(function (inv) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-secondary';
      b.textContent = inv.name;
      b.addEventListener('click', function () {
        setInvestor(inv.id);
        byId('invPickerOverlay').style.display = 'none';
      });
      list.appendChild(b);
    });
    byId('invPickerOverlay').style.display = 'flex';
  }
  byId('invPickerCancel').addEventListener('click', function () {
    byId('invPickerOverlay').style.display = 'none';
  });
  byId('fundChangeBtn').addEventListener('click', openPicker);

  function setInvestor(id) {
    selectedInvestorId = id;
    byId('fundName').textContent = invName(id);
    renderFund();
  }

  // ---------- tabs ----------
  root.querySelectorAll('[data-inv-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-inv-tab');
      root.querySelectorAll('[data-inv-tab]').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      root.querySelectorAll('[data-inv-panel]').forEach(function (p) {
        p.style.display = p.getAttribute('data-inv-panel') === target ? 'flex' : 'none';
      });
      if (target === 'fund' && !selectedInvestorId && state && state.investors.length) {
        openPicker();
      }
    });
  });

  // ---------- render ----------
  function render() {
    fillSelects();
    renderInvestorList();
    if (selectedInvestorId && !findInv(selectedInvestorId)) selectedInvestorId = 0;
    byId('fundName').textContent = selectedInvestorId ? invName(selectedInvestorId) : '-';
    renderFund();
    renderReport();
    renderProfit();
  }

  function fillSelects() {
    var prevPay = byId('payPick').value;
    var payPick = byId('payPick');
    payPick.innerHTML = '';
    state.investors.forEach(function (inv) {
      var o = document.createElement('option');
      o.value = inv.id;
      o.textContent = inv.name;
      payPick.appendChild(o);
    });
    if (prevPay) payPick.value = prevPay;

    var sel = byId('allocActivity');
    var prevAct = sel.value;
    sel.innerHTML = '';
    state.activity_options.forEach(function (a) {
      var o = document.createElement('option');
      o.value = a.id;
      o.textContent = a.label;
      sel.appendChild(o);
    });
    if (prevAct) sel.value = prevAct;
  }

  // Tab 1
  function renderInvestorList() {
    var tbody = byId('invListBody');
    tbody.innerHTML = '';
    if (!state.investors.length) { emptyRow('invListBody', 9, 'No investors yet.'); return; }
    state.investors.forEach(function (inv) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(inv.name) + '</td>' +
        '<td class="num">' + fmt(inv.deposits) + '</td>' +
        '<td class="num">' + fmt(inv.support) + '</td>' +
        '<td class="num">' + fmt(inv.icu) + '</td>' +
        '<td class="num">' + fmt(inv.related) + '</td>' +
        '<td class="num">' + fmt(inv.tp) + '</td>' +
        '<td class="num">' + fmt(inv.pp) + '</td>' +
        '<td class="num">' + fmt(inv.rolled) + '</td>' +
        '<td>' +
          '<button type="button" class="btn btn-secondary" data-act="edit-inv" data-id="' + inv.id + '">Edit</button> ' +
          '<button type="button" class="btn btn-danger" data-act="del-inv" data-id="' + inv.id + '">Delete</button>' +
        '</td>';
      tbody.appendChild(tr);
    });
  }

  // Tab 2
  function renderFund() {
    var deps = byId('depBody');
    var sup = byId('supBody');
    var alloc = byId('allocBody');
    var tx = byId('txBody');
    deps.innerHTML = ''; sup.innerHTML = ''; alloc.innerHTML = ''; tx.innerHTML = '';
    byId('allocTotal').textContent = '';

    if (!selectedInvestorId) {
      emptyRow('depBody', 7, 'Select an investor first.');
      emptyRow('supBody', 5, 'Select an investor first.');
      emptyRow('allocBody', 4, 'Select an investor first.');
      emptyRow('txBody', 6, 'Select an investor first.');
      return;
    }

    state.deposits.filter(function (d) { return d.investor_id === selectedInvestorId; }).forEach(function (d) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(d.date) + '</td>' +
        '<td>' + esc(d.currency) + '</td>' +
        '<td class="num">' + fmt(d.amount) + '</td>' +
        '<td class="num">' + (d.rate === null ? '-' : fmt(d.rate)) + '</td>' +
        '<td class="num">' + fmt(d.final) + '</td>' +
        '<td>' + esc(d.notes) + '</td>' +
        '<td>' +
          '<button type="button" class="btn btn-secondary" data-act="edit-dep" data-id="' + d.id + '">Edit</button> ' +
          '<button type="button" class="btn btn-danger" data-act="del-dep" data-id="' + d.id + '">Delete</button>' +
        '</td>';
      deps.appendChild(tr);
    });
    if (!deps.children.length) emptyRow('depBody', 7, 'No investments received yet.');

    state.support.filter(function (s) { return s.investor_id === selectedInvestorId; }).forEach(function (s) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(s.date) + '</td>' +
        '<td>' + esc(CATEGORY_LABEL[s.category] || s.category) + '</td>' +
        '<td class="num">' + fmt(s.amount) + '</td>' +
        '<td>' + esc(s.notes) + '</td>' +
        '<td><button type="button" class="btn btn-danger" data-act="del-sup" data-id="' + s.id + '">Delete</button></td>';
      sup.appendChild(tr);
    });
    if (!sup.children.length) emptyRow('supBody', 5, 'No non-project expenses.');

    var total = 0;
    var inv = findInv(selectedInvestorId);
    state.allocations.filter(function (a) { return a.investor_id === selectedInvestorId; }).forEach(function (a) {
      total += a.percent;
      var used = 0;
      state.activities.forEach(function (act) {
        if (act.id === a.activity_id) {
          act.investors.forEach(function (r) {
            if (r.investor_id === selectedInvestorId) used = r.used;
          });
        }
      });
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(a.activity_label) + '</td>' +
        '<td class="num">' + fmt(a.percent) + '</td>' +
        '<td class="num">' + fmt(used) + '</td>' +
        '<td><button type="button" class="btn btn-danger" data-act="del-alloc" data-id="' + a.id + '">Unlink</button></td>';
      alloc.appendChild(tr);
    });
    if (!alloc.children.length) emptyRow('allocBody', 4, 'Not linked to any project yet.');
    if (inv) byId('allocTotal').textContent = 'Linked: ' + fmt(total) + '% of ICU ' + fmt(inv.icu);

    var linkedActivityIds = state.allocations
      .filter(function (a) { return a.investor_id === selectedInvestorId; })
      .map(function (a) { return a.activity_id; });
    state.activities.forEach(function (act) {
      if (linkedActivityIds.indexOf(act.id) === -1) return;
      (act.expenses || []).forEach(function (ex) {
        var tr = document.createElement('tr');
        tr.innerHTML =
          '<td>' + esc(act.label) + '</td>' +
          '<td>' + esc(ex.date) + '</td>' +
          '<td>' + esc(ex.notes) + '</td>' +
          '<td class="num">' + fmt(ex.final) + '</td>';
        tx.appendChild(tr);
      });
    });
    if (!tx.children.length) emptyRow('txBody', 4, 'No project expenses for the linked activity codes yet.');
  }

  // Tab 3
  function renderReport() {
    var usage = byId('rptUsageBody');
    var sales = byId('rptSalesBody');
    var dist = byId('rptDistBody');
    var share = byId('rptShareBody');
    usage.innerHTML = ''; sales.innerHTML = ''; dist.innerHTML = ''; share.innerHTML = '';

    state.investors.forEach(function (inv) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(inv.name) + '</td>' +
        '<td class="num">' + fmt(inv.deposits) + '</td>' +
        '<td class="num">' + fmt(inv.support) + '</td>' +
        '<td class="num">' + fmt(inv.icu) + '</td>' +
        '<td class="num">' + fmt(inv.related) + '</td>' +
        '<td class="num">' + fmt(inv.icu - inv.related) + '</td>';
      usage.appendChild(tr);
    });
    if (!usage.children.length) emptyRow('rptUsageBody', 6, 'No investors yet.');

    state.activities.forEach(function (a) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(a.label) + '</td>' +
        '<td class="num">' + fmt(a.sales) + '</td>' +
        '<td class="num">' + fmt(a.cost) + '</td>';
      sales.appendChild(tr);
    });
    if (!sales.children.length) emptyRow('rptSalesBody', 3, 'No activity codes.');

    state.activities.forEach(function (a) {
      var badge = a.net < 0 ? ' <span class="badge badge-danger">RUGI</span>' : '';
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(a.label) + badge + '</td>' +
        '<td class="num">' + fmt(a.cost) + '</td>' +
        '<td class="num">' + fmt(a.investor_used) + '</td>' +
        '<td class="num">' + fmt(a.company) + '</td>' +
        '<td class="num">' + fmt(a.sales) + '</td>' +
        '<td class="num">' + fmt(a.gross) + '</td>' +
        '<td class="num">' + fmt(a.zakat) + '</td>' +
        '<td class="num">' + fmt(a.net) + '</td>' +
        '<td class="num"><input type="text" class="input input-number-comma" style="max-width:110px;margin-left:auto;text-align:right;" data-pdp-for="' + a.id + '" value="' + a.pdp + '"></td>' +
        '<td class="num">' + fmt(a.distribution) + '</td>' +
        '<td><button type="button" class="btn btn-secondary" data-act="save-pdp" data-id="' + a.id + '">Save pdp</button></td>';
      dist.appendChild(tr);

      a.investors.forEach(function (r) {
        var tr2 = document.createElement('tr');
        tr2.innerHTML =
          '<td>' + esc(a.label) + '</td>' +
          '<td>' + esc(r.investor_name) + '</td>' +
          '<td class="num">' + fmt(r.used) + '</td>' +
          '<td class="num">' + fmt(r.ratio) + '</td>' +
          '<td class="num">' + fmt(r.profit) + '</td>';
        share.appendChild(tr2);
      });
    });
    if (!dist.children.length) emptyRow('rptDistBody', 11, 'No activity codes.');
    if (!share.children.length) emptyRow('rptShareBody', 5, 'No investor share yet.');
  }

  // Tab 4
  function renderProfit() {
    var tbody = byId('profBody');
    var pay = byId('payBody');
    tbody.innerHTML = ''; pay.innerHTML = '';

    state.investors.forEach(function (inv) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(inv.name) + '</td>' +
        '<td class="num">' + fmt(inv.tp) + '</td>' +
        '<td class="num">' + fmt(inv.pp) + '</td>' +
        '<td class="num">' + fmt(inv.icu) + '</td>' +
        '<td class="num">' + fmt(inv.rolled) + '</td>';
      tbody.appendChild(tr);
    });
    if (!tbody.children.length) emptyRow('profBody', 5, 'No investors yet.');

    state.payments.forEach(function (p) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(p.date) + '</td>' +
        '<td>' + esc(invName(p.investor_id)) + '</td>' +
        '<td class="num">' + fmt(p.amount) + '</td>' +
        '<td>' + esc(p.notes) + '</td>' +
        '<td><button type="button" class="btn btn-danger" data-act="del-pay" data-id="' + p.id + '">Delete</button></td>';
      pay.appendChild(tr);
    });
    if (!pay.children.length) emptyRow('payBody', 5, 'No profit payments yet.');
  }

  // ---------- form actions ----------
  byId('invSaveBtn').addEventListener('click', function () {
    run('save_investor', { id: byId('invId').value, investor_name: byId('invName').value }, 'Investor saved.')
      .then(function (ok) { if (ok) clearInvestorForm(); });
  });
  byId('invClearBtn').addEventListener('click', clearInvestorForm);
  function clearInvestorForm() { byId('invId').value = '0'; byId('invName').value = ''; }

  byId('depCurrency').addEventListener('change', function () {
    byId('depRateWrap').style.display = byId('depCurrency').value === 'IDR' ? 'none' : 'block';
  });
  byId('depSaveBtn').addEventListener('click', function () {
    if (!selectedInvestorId) { msg('Select an investor first.', true); return; }
    run('save_deposit', {
      id: byId('depId').value,
      investor_id: selectedInvestorId,
      deposit_date: byId('depDate').value,
      currency: byId('depCurrency').value,
      amount: byId('depAmount').value,
      exchange_rate: byId('depRate').value,
      notes: byId('depNotes').value
    }, 'Investment saved.').then(function (ok) { if (ok) clearDepositForm(); });
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
    if (!selectedInvestorId) { msg('Select an investor first.', true); return; }
    run('save_support', {
      id: byId('supId') ? byId('supId').value : 0,
      investor_id: selectedInvestorId,
      expense_date: byId('supDate').value,
      category: byId('supCategory').value,
      amount: byId('supAmount').value,
      notes: byId('supNotes').value
    }, 'Expense saved.').then(function (ok) {
      if (ok) { byId('supAmount').value = ''; byId('supNotes').value = ''; }
    });
  });
  byId('supClearBtn').addEventListener('click', function () {
    byId('supAmount').value = ''; byId('supNotes').value = ''; byId('supDate').value = today();
  });

  byId('allocSaveBtn').addEventListener('click', function () {
    if (!selectedInvestorId) { msg('Select an investor first.', true); return; }
    run('save_allocation', {
      investor_id: selectedInvestorId,
      activity_id: selId('allocActivity'),
      allocation_percent: byId('allocPercent').value
    }, 'Linked to project.').then(function (ok) { if (ok) byId('allocPercent').value = ''; });
  });

  byId('paySaveBtn').addEventListener('click', function () {
    run('save_profit_payment', {
      investor_id: selId('payPick'),
      payment_date: byId('payDate').value,
      amount: byId('payAmount').value,
      notes: byId('payNotes').value
    }, 'Payment saved.').then(function (ok) { if (ok) byId('payAmount').value = ''; });
  });

  // ---------- delegated clicks ----------
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn) return;
    var act = btn.getAttribute('data-act');
    var id = parseInt(btn.getAttribute('data-id'), 10) || 0;

    if (act === 'edit-inv') {
      var inv = findInv(id);
      if (inv) { byId('invId').value = inv.id; byId('invName').value = inv.name; }
    } else if (act === 'del-inv') {
      askPassword('Delete investor', 'delete_investor', { id: id }, 'Investor deleted.');
    } else if (act === 'edit-dep') {
      var d = null;
      state.deposits.forEach(function (x) { if (x.id === id) d = x; });
      if (!d) return;
      byId('depId').value = d.id;
      byId('depDate').value = d.date;
      byId('depCurrency').value = d.currency;
      byId('depRateWrap').style.display = d.currency === 'IDR' ? 'none' : 'block';
      byId('depAmount').value = d.amount;
      byId('depRate').value = d.rate === null ? '' : d.rate;
      byId('depNotes').value = d.notes;
    } else if (act === 'del-dep') {
      askPassword('Delete investment', 'delete_deposit', { id: id }, 'Investment deleted.');
    } else if (act === 'del-sup') {
      askPassword('Delete expense', 'delete_support', { id: id }, 'Expense deleted.');
    } else if (act === 'del-alloc') {
      askPassword('Unlink from project', 'delete_allocation', { id: id }, 'Unlinked.');
    } else if (act === 'del-pay') {
      askPassword('Delete profit payment', 'delete_profit_payment', { id: id }, 'Payment deleted.');
    } else if (act === 'save-pdp') {
      var input = root.querySelector('[data-pdp-for="' + id + '"]');
      run('save_pdp', { activity_id: id, profit_distribution_percent: input ? input.value : 0 }, 'pdp saved.');
    }
  });

  // Reload when section is shown (footer.php dispatches this event).
  document.addEventListener('aos:section-shown', function (e) {
    if (e.detail && e.detail.target === 'investor') load();
  });

  byId('depDate').value = today();
  byId('supDate').value = today();
  byId('payDate').value = today();
})();
</script>