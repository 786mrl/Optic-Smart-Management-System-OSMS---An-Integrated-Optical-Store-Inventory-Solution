<?php
// This file is included by index.php, REPLACING the static "Report" placeholder
// <div class="menu-section" data-section="report" ...> entirely — same pattern as
// transaction_content.php / logistic_content.php. Own IIFE, own scope.
// No tabs (removed 12 Okt 2026) — this is just the Finance Report, full stop.
?>
<div class="menu-section" data-section="report" style="display:none;">
<div class="card" style="width:100%;">

  <!-- ===================== Finance Report ===================== -->
  <div id="reportFinancePane">

    <div class="rpt-toolbar">
      <div class="rpt-toolbar-fields">
        <div class="form-group">
          <div class="label">From</div>
          <input type="date" class="input" id="rptDateFrom">
        </div>
        <div class="form-group">
          <div class="label">To</div>
          <input type="date" class="input" id="rptDateTo">
        </div>
        <div class="form-group">
          <div class="label">Activity Code</div>
          <select class="select" id="rptActivityFilter">
            <option value="">All Activity Codes</option>
          </select>
        </div>
      </div>
      <div class="rpt-toolbar-actions">
        <button type="button" class="btn btn-secondary" id="rptApplyFilter">Apply</button>
        <button type="button" class="btn btn-secondary" id="rptClearFilter">Clear</button>
        <button type="button" class="btn btn-secondary" id="rptExportCsv">Export CSV</button>
      </div>
    </div>

    <details class="card rpt-collapsible" data-rpt-accordion>
      <summary class="empty-title" style="text-align:left; cursor:pointer;">Totals</summary>
      <div class="rpt-summary-grid" id="rptTotalsGrid">
        <div class="empty-sub">Loading...</div>
      </div>
    </details>

    <details class="card rpt-collapsible" data-rpt-accordion>
      <summary class="empty-title" style="text-align:left; cursor:pointer;">Bank Accounts</summary>
      <div class="rpt-summary-grid" id="rptAccountsGrid">
        <div class="empty-sub">Loading...</div>
      </div>
    </details>

    <div class="rpt-charts-grid">
      <div class="card rpt-chart-card">
        <div class="empty-title" style="text-align:left;">Inflow vs Outflow per Month</div>
        <canvas id="rptChartMonthly" height="220"></canvas>
      </div>
      <div class="card rpt-chart-card">
        <div class="empty-title" style="text-align:left;">Cumulative Balance</div>
        <canvas id="rptChartCumulative" height="220"></canvas>
      </div>
      <div class="card rpt-chart-card">
        <div class="empty-title" style="text-align:left;">Balance by Account</div>
        <canvas id="rptChartAccounts" height="220"></canvas>
      </div>
    </div>

    <div class="card" style="margin-top:var(--space-3);">
      <div class="empty-title" style="text-align:left;">Accounts Payable / Receivable Snapshot</div>
      <div id="rptReceivables" class="rpt-receivables">
        <div class="empty-sub">Loading...</div>
      </div>
    </div>

    <div class="card" style="margin-top:var(--space-3);">
      <div class="empty-title" style="text-align:left;">Ledger</div>
      <div class="table-wrapper">
        <table class="table" id="rptLedgerTable">
          <thead>
            <tr>
              <th>Date</th><th>Type</th><th>Account</th><th>Description</th><th>Category</th>
              <th>Amount (IDR)</th><th>Running Balance</th><th>Notes</th>
            </tr>
          </thead>
          <tbody id="rptLedgerBody">
            <tr><td colspan="8" class="empty-sub">Loading...</td></tr>
          </tbody>
          <tfoot id="rptLedgerFoot"></tfoot>
        </table>
      </div>
    </div>

  </div>

</div>
</div>

<!-- Detail panel for a "Customer Credit Balance" ledger row — shows how
     that credit source's money currently sits (still fully available, or
     applied to one or more later invoices), instead of two extra visible
     ledger rows per application (user, 8 Nov 2026). -->
<div id="rptCreditDetailOverlay" class="hidden">
  <div id="rptCreditDetailPanel">
    <h3>Customer Credit Balance</h3>
    <div id="rptCreditDetailBody"></div>
    <button type="button" class="btn btn-secondary rpt-cd-close" id="rptCreditDetailClose">Close</button>
  </div>
</div>

<style>
  /* ---- Containment: the report must never make the PAGE scroll sideways.
     Only .table-wrapper (tables) may scroll horizontally. ---- */
  .menu-section[data-section="report"] { min-width:0; max-width:100%; }
  .menu-section[data-section="report"] > .card {
    box-sizing:border-box; max-width:100%; min-width:0;
    overflow-x:clip; /* safety net: anything that still leaks is clipped, never widens the page */
  }
  #reportFinancePane { min-width:0; max-width:100%; }
  #reportFinancePane .card { box-sizing:border-box; max-width:100%; min-width:0; }

  .rpt-toolbar { display:flex; flex-direction:column; gap:var(--space-2); margin:var(--space-3) 0; }
  .rpt-toolbar-fields { display:flex; flex-wrap:wrap; gap:var(--space-2); align-items:flex-end; }
  .rpt-toolbar-fields .form-group { flex:1 1 140px; min-width:0; }
  .rpt-toolbar-fields .input, .rpt-toolbar-fields .select { width:100%; min-width:0; box-sizing:border-box; }
  .rpt-toolbar-actions { display:flex; flex-wrap:wrap; gap:var(--space-2); }

  .rpt-collapsible { margin-bottom:var(--space-3); }
  .rpt-collapsible summary { margin-bottom:var(--space-2); list-style:none; }
  .rpt-collapsible summary::-webkit-details-marker { display:none; }
  .rpt-collapsible summary::before { content:'▾ '; }
  .rpt-collapsible:not([open]) summary::before { content:'▸ '; }

  /* min(Npx,100%) = a grid column can never be wider than its container */
  .rpt-summary-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(180px,100%),1fr)); gap:var(--space-2); }
  .rpt-summary-card { background:var(--surface-2,#1a1a1a); border-radius:var(--radius-md,10px); padding:var(--space-3); min-width:0; overflow-wrap:anywhere; }
  .rpt-summary-card .rpt-summary-label { font-size:12px; opacity:.7; margin-bottom:4px; }
  .rpt-summary-card .rpt-summary-value { font-size:20px; font-weight:600; }
  .rpt-summary-card.positive .rpt-summary-value { color:var(--success,#2ecc71); }
  .rpt-summary-card.negative .rpt-summary-value { color:var(--danger,#e74c3c); }
  /* Ledger amounts: inflow green, outflow / negative balance red (shown in parentheses, no minus sign). */
  #rptLedgerTable .rpt-amt-in  { color:var(--success,#2ecc71); }
  #rptLedgerTable .rpt-amt-out { color:var(--danger,#e74c3c); }
  #rptLedgerTable tfoot .rpt-ledger-total-row td { border-top:2px solid var(--border,#333); font-weight:600; }
  /* Credit-balance row pairs (same group_id — "remaining" + "used" split of
     one transaction) are tinted together with no border between them, so
     they read as one connected block instead of two unrelated rows. */
  #rptLedgerTable .rpt-ledger-grouped td { background:var(--surface-2,#1a1a1a); }
  #rptLedgerTable .rpt-ledger-grouped-start td { border-top:1px dashed var(--border,#333); }
  #rptLedgerTable .rpt-ledger-grouped-cont td { border-top:none; }
  .rpt-ledger-note { font-size:12px; opacity:.8; font-style:italic; }
  /* Description column's second line — the transaction's own free-text note. */
  .rpt-desc-note { font-size:12px; opacity:.7; }
  /* A "Customer Credit Balance" row (source=invoice_payment_credit) opens a
     detail panel on click — see renderLedger()/openCreditDetail(). The dark
     group background already marks it visually (user, 8 Nov 2026: that's
     enough, no extra styling needed); cursor:pointer is the only addition. */
  #rptLedgerTable .rpt-ledger-clickable { cursor:pointer; }
  /* Reference chip on a refund row, linking back to its originating
     "Customer Credit Balance" row (user, 8 Nov 2026). */
  .rpt-credit-ref-chip {
    display:inline-block; font-size:11px; opacity:.85; cursor:pointer;
    border:1px solid var(--border,#333); border-radius:4px; padding:1px 6px;
    margin-top:2px;
  }
  .rpt-credit-ref-chip:hover { opacity:1; border-color:var(--success,#2ecc71); }
  #rptCreditDetailOverlay {
    position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000;
    display:flex; align-items:center; justify-content:center; padding:var(--space-3);
  }
  #rptCreditDetailOverlay.hidden { display:none; }
  #rptCreditDetailPanel {
    background:var(--surface,#161616); border:1px solid var(--border,#333); border-radius:8px;
    max-width:560px; width:100%; max-height:80vh; overflow-y:auto; padding:var(--space-3);
  }
  #rptCreditDetailPanel h3 { margin:0 0 var(--space-2); font-size:16px; }
  #rptCreditDetailPanel .rpt-cd-row {
    display:flex; justify-content:space-between; gap:var(--space-2);
    padding:8px 0; border-bottom:1px solid var(--border,#2a2a2a);
  }
  #rptCreditDetailPanel .rpt-cd-row:last-child { border-bottom:none; }
  #rptCreditDetailPanel .rpt-cd-sub { font-size:12px; opacity:.7; }
  /* "Original amount" (context, muted) vs "Remaining now" (the figure that
     matters — bold + the same green used for inflow amounts elsewhere in
     this report) need to read as clearly different rows (user, 8 Nov 2026:
     "biar nggak bingung lihatnya"). */
  #rptCreditDetailPanel .rpt-cd-original { opacity:.65; font-size:13px; }
  #rptCreditDetailPanel .rpt-cd-highlight { font-weight:700; }
  #rptCreditDetailPanel .rpt-cd-highlight .rpt-cd-amount { color:var(--success,#2ecc71); font-size:17px; }
  #rptCreditDetailPanel .rpt-cd-close {
    display:block; margin:var(--space-3) auto 0; width:auto; min-width:120px;
    text-align:center;
  }

  /* Chart.js needs a position:relative parent with min-width:0, otherwise the
     canvas keeps its old (wide) size and drags the whole page wider. */
  .rpt-charts-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(300px,100%),1fr)); gap:var(--space-3); }
  .rpt-chart-card { padding:var(--space-3); position:relative; min-width:0; overflow:hidden; }
  .rpt-chart-card canvas { max-width:100%; }

  .rpt-receivables { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(220px,100%),1fr)); gap:var(--space-2); min-width:0; }

  /* ---- Tables: the ONLY thing allowed to scroll horizontally.
     Applies at every width (not just phones) so a half-screen desktop
     window behaves the same as a tablet. ---- */
  #reportFinancePane .table-wrapper {
    display:block;
    width:100%; max-width:100%; min-width:0; box-sizing:border-box;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
  }
  #rptLedgerTable { width:100%; min-width:760px; border-collapse:collapse; }
  .rpt-receivables table { width:100%; min-width:420px; border-collapse:collapse; }

  /* The app theme turns .table into a stacked card list on small screens.
     These tables must stay real tables so the wrapper can scroll them. */
  #rptLedgerTable, .rpt-receivables table { display:table !important; }
  #rptLedgerTable thead, .rpt-receivables table thead { display:table-header-group !important; }
  #rptLedgerTable tbody, .rpt-receivables table tbody { display:table-row-group !important; }
  #rptLedgerTable tfoot { display:table-footer-group !important; }
  #rptLedgerTable tr, .rpt-receivables table tr { display:table-row !important; }
  #rptLedgerTable th, #rptLedgerTable td,
  .rpt-receivables table th, .rpt-receivables table td { display:table-cell !important; }

  #rptLedgerTable th, #rptLedgerTable td,
  .rpt-receivables table th, .rpt-receivables table td { width:auto !important; }

  @media (max-width: 768px) {
    #rptLedgerTable, .rpt-receivables table { white-space:nowrap; }
  }
</style>

<!--
  Chart.js is loaded from our own server, not a CDN: cdnjs.cloudflare.com is
  blocked on this network (confirmed 2 Okt 2026 — see PROJECT_NOTES.md).
  Download chart.umd.min.js (v4.4.4) from a machine with working internet,
  e.g. https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js
  and place it at lisani_aos/assets/vendor/chart.umd.min.js on the server.
-->
<script src="assets/vendor/chart.umd.min.js"></script>
<script>
(function () {
  'use strict';

  // Looked up by credit_source_id when a "Customer Credit Balance" ledger
  // row is clicked — see renderLedger() and openCreditDetail() below.
  var ledgerCreditRows = {};

  // Accordion: opening one of the two summary cards (Totals / Bank Accounts)
  // closes the other, instead of both staying open independently.
  document.querySelectorAll('[data-rpt-accordion]').forEach(function (el) {
    el.addEventListener('toggle', function () {
      if (!el.open) return;
      document.querySelectorAll('[data-rpt-accordion]').forEach(function (other) {
        if (other !== el) other.open = false;
      });
    });
  });

  // footer.php dispatches this on every sidebar section switch (confirmed
  // 2 Okt 2026 — see "Each *_content.php is its own IIFE..." comment there),
  // so any section left stale after a show/hide can refresh itself live.
  document.addEventListener('aos:section-shown', function (e) {
    if (e.detail && e.detail.target === 'report') {
      loadFinanceReport();
    }
  });

  // Activity Code filter dropdown — loaded once, reuses the same endpoint as
  // Transactions > Activity Code > Preview (list_activity_codes.php), so we
  // don't duplicate field names or add a new endpoint.
  function loadActivityCodeFilter() {
    fetch('ajax/list_activity_codes.php')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) return;
        var sel = document.getElementById('rptActivityFilter');
        res.data.forEach(function (a) {
          var opt = document.createElement('option');
          opt.value = a.id;
          opt.textContent = a.activity_code + ' \u2014 ' + a.activity_name;
          sel.appendChild(opt);
        });
      })
      .catch(function () { /* filter stays "All" only — non-fatal */ });
  }
  loadActivityCodeFilter();

  function fmtIDR(n) {
    var sign = n < 0 ? '-' : '';
    return sign + 'Rp ' + Math.abs(Math.round(n)).toLocaleString('id-ID');
  }

  // Accounting style for the ledger: negatives in parentheses instead of a minus sign.
  function fmtIDRParen(n) {
    var txt = 'Rp ' + Math.abs(Math.round(n)).toLocaleString('id-ID');
    return n < 0 ? '(' + txt + ')' : txt;
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  var chartMonthly = null, chartCumulative = null, chartAccounts = null;

  function loadFinanceReport() {
    var from = document.getElementById('rptDateFrom').value;
    var to = document.getElementById('rptDateTo').value;
    var activityId = document.getElementById('rptActivityFilter').value;
    var qs = [];
    if (from) qs.push('date_from=' + encodeURIComponent(from));
    if (to) qs.push('date_to=' + encodeURIComponent(to));
    if (activityId) qs.push('activity_id=' + encodeURIComponent(activityId));

    document.getElementById('rptTotalsGrid').innerHTML = '<div class="empty-sub">Loading...</div>';
    document.getElementById('rptAccountsGrid').innerHTML = '<div class="empty-sub">Loading...</div>';
    document.getElementById('rptLedgerBody').innerHTML = '<tr><td colspan="7" class="empty-sub">Loading...</td></tr>';

    fetch('ajax/get_finance_report.php' + (qs.length ? '?' + qs.join('&') : ''))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) {
          var msg = '<div class="empty-sub">' + escapeHtml(res.message || 'Failed to load report.') + '</div>';
          document.getElementById('rptTotalsGrid').innerHTML = msg;
          document.getElementById('rptAccountsGrid').innerHTML = '';
          return;
        }
        renderReport(res.data);
      })
      .catch(function () {
        var msg = '<div class="empty-sub">Connection error while loading the report.</div>';
        document.getElementById('rptTotalsGrid').innerHTML = msg;
        document.getElementById('rptAccountsGrid').innerHTML = '';
      });
  }

  function renderReport(data) {
    renderSummary(data);
    try {
      renderCharts(data);
    } catch (err) {
      // Chart.js is loaded from a CDN (cdnjs.cloudflare.com) — if the network
      // blocks it, `Chart` is undefined and this throws. Don't let that take
      // down the summary/ledger/receivables below it.
      console.error('Finance report charts failed to render:', err);
      document.querySelectorAll('.rpt-chart-card canvas').forEach(function (c) {
        c.insertAdjacentHTML('afterend', '<div class="empty-sub">Chart library failed to load (blocked network?).</div>');
      });
    }
    renderReceivables(data.receivables);
    renderLedger(data.ledger);
    window._rptLastLedger = data.ledger; // used by CSV export
  }

  function renderCardsInto(elId, cards) {
    var grid = document.getElementById(elId);
    grid.innerHTML = '';
    cards.forEach(function (c) {
      var div = document.createElement('div');
      div.className = 'rpt-summary-card ' + c.cls;
      div.innerHTML = '<div class="rpt-summary-label">' + escapeHtml(c.label) + '</div>' +
                       '<div class="rpt-summary-value">' + fmtIDR(c.value) + '</div>';
      grid.appendChild(div);
    });
  }

  function renderSummary(data) {
    renderCardsInto('rptTotalsGrid', [
      { label: 'Total Inflow', value: data.totals.inflow, cls: 'positive' },
      { label: 'Total Outflow', value: data.totals.outflow, cls: 'negative' },
      { label: 'Net', value: data.totals.net, cls: data.totals.net >= 0 ? 'positive' : 'negative' },
    ]);

    var accountCards = data.accounts.map(function (acc) {
      return { label: acc.bank_name + ' ' + acc.account_number, value: acc.balance, cls: acc.balance >= 0 ? 'positive' : 'negative' };
    });
    if (data.unassigned.inflow || data.unassigned.outflow) {
      accountCards.push({ label: 'Unmatched Account', value: data.unassigned.balance, cls: data.unassigned.balance >= 0 ? 'positive' : 'negative' });
    }
    renderCardsInto('rptAccountsGrid', accountCards);
  }

  function renderCharts(data) {
    if (typeof Chart === 'undefined') {
      throw new Error('Chart.js not loaded (window.Chart is undefined)');
    }
    var months = data.monthly.map(function (m) { return m.month; });
    var inflows = data.monthly.map(function (m) { return m.inflow; });
    var outflows = data.monthly.map(function (m) { return m.outflow; });
    var cumulative = [];
    var running = 0;
    data.monthly.forEach(function (m) { running += (m.inflow - m.outflow); cumulative.push(running); });

    if (chartMonthly) chartMonthly.destroy();
    chartMonthly = new Chart(document.getElementById('rptChartMonthly'), {
      type: 'bar',
      data: {
        labels: months,
        datasets: [
          { label: 'Inflow', data: inflows, backgroundColor: '#2ecc71' },
          { label: 'Outflow', data: outflows, backgroundColor: '#e74c3c' },
        ],
      },
      options: { responsive: true, scales: { y: { beginAtZero: true } } },
    });

    if (chartCumulative) chartCumulative.destroy();
    chartCumulative = new Chart(document.getElementById('rptChartCumulative'), {
      type: 'line',
      data: {
        labels: months,
        datasets: [{ label: 'Cumulative Balance', data: cumulative, borderColor: '#3498db', tension: 0.25, fill: false }],
      },
      options: { responsive: true },
    });

    var accLabels = data.accounts.map(function (a) { return a.bank_name + ' ' + a.account_number; });
    var accValues = data.accounts.map(function (a) { return a.balance; });
    if (data.unassigned.inflow || data.unassigned.outflow) {
      accLabels.push('Unmatched');
      accValues.push(data.unassigned.balance);
    }
    if (chartAccounts) chartAccounts.destroy();
    chartAccounts = new Chart(document.getElementById('rptChartAccounts'), {
      type: 'bar',
      data: { labels: accLabels, datasets: [{ label: 'Balance', data: accValues, backgroundColor: '#9b59b6' }] },
      options: { responsive: true, indexAxis: 'y' },
    });
  }

  function renderReceivables(rec) {
    var el = document.getElementById('rptReceivables');
    var topRows = rec.top_open.map(function (r) {
      return '<tr><td>' + escapeHtml(r.customer_name) + '</td><td>' + escapeHtml(r.invoice_number) + '</td><td>' + fmtIDR(r.outstanding) + '</td></tr>';
    }).join('');
    el.innerHTML =
      '<div class="rpt-summary-card"><div class="rpt-summary-label">Open Invoices</div><div class="rpt-summary-value">' + rec.open_invoices_count + '</div></div>' +
      '<div class="rpt-summary-card negative"><div class="rpt-summary-label">Outstanding (Open Invoices)</div><div class="rpt-summary-value">' + fmtIDR(rec.open_invoices_total) + '</div></div>' +
      '<div class="rpt-summary-card positive"><div class="rpt-summary-label">Customer Credit Balance</div><div class="rpt-summary-value">' + fmtIDR(rec.credit_balance_total) + '</div></div>' +
      (topRows ? '<div style="grid-column:1/-1;" class="table-wrapper"><table class="table"><thead><tr><th>Customer</th><th>Invoice</th><th>Outstanding</th></tr></thead><tbody>' + topRows + '</tbody></table></div>' : '');
  }

  function renderLedger(ledger) {
    var tbody = document.getElementById('rptLedgerBody');
    var tfoot = document.getElementById('rptLedgerFoot');
    if (!ledger.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="empty-sub">No transactions in this period.</td></tr>';
      tfoot.innerHTML = '';
      return;
    }
    // Ledger arrives oldest -> newest already (get_finance_report.php sorts
    // ASC per source + a final usort ASC), so running balance here just
    // accumulates in that same order — no re-sort needed.
    //
    // Credit-balance rows (row.ledger_note set, e.g. "credit_remaining" /
    // "credit_used" sources) carry amount:0 on purpose — that money was
    // already counted once as a real inflow when it first reached the
    // account, so it must NOT move Running Balance again here (user, 1 Nov
    // 2026: "running balance tetap 60, kan saya cuman memecah..."). The
    // actual figure to print in the Amount column for these rows is
    // row.display_amount instead.
    // Rows whose source is 'invoice_payment_credit' open a detail panel on
    // click instead of showing their applications/refunds as separate
    // ledger rows (user, 8 Nov 2026). Keyed by credit_source_id, built in
    // its own pass BEFORE the render pass below, so a refund row's
    // reference chip can look up its originating credit row's date/label
    // regardless of which one happens to sort first in the ledger array.
    ledgerCreditRows = {}; // reassign the outer var — no re-declare, keeps one shared map
    ledger.forEach(function (r) {
      if (r.source === 'invoice_payment_credit' && r.credit_source_id != null) {
        ledgerCreditRows[r.credit_source_id] = r;
      }
    });
    var running = 0;
    var prevGroupId = null;
    tbody.innerHTML = ledger.map(function (row) {
      running += row.type === 'inflow' ? row.amount : -row.amount;
      var acctLabel = row.bank ? (row.bank + (row.account_no ? ' · ' + row.account_no : '')) : '—';
      var badge = row.type === 'inflow'
        ? '<span class="badge badge-success">INFLOW</span>'
        : '<span class="badge badge-danger">OUTFLOW</span>';
      var isIn = row.type === 'inflow';
      var shownAmount = row.ledger_note ? (row.display_amount || 0) : row.amount;
      var amtCell = '<span class="' + (isIn ? 'rpt-amt-in' : 'rpt-amt-out') + '">' +
        fmtIDRParen(isIn ? shownAmount : -shownAmount) + '</span>';
      var balCell = '<span class="' + (running < 0 ? 'rpt-amt-out' : '') + '">' + fmtIDRParen(running) + '</span>';
      var notesCell = row.ledger_note ? '<span class="rpt-ledger-note">' + escapeHtml(row.ledger_note) + '</span>' : '\u2014';
      // Description column: label on top, the transaction's own free-text
      // note (row.notes) on a second line underneath when non-empty (user
      // decision, 8 Nov 2026 — revised from the earlier "replace" approach).
      var descNotes = (row.notes && String(row.notes).trim() !== '') ? String(row.notes).trim() : '';
      var descHtml = escapeHtml(row.label) +
        (descNotes ? '<br><span class="rpt-desc-note">' + escapeHtml(descNotes) + '</span>' : '');

      // A refund row drawn from credit balance gets a small reference chip
      // per source it was drawn from, so it's easy to tell which "Customer
      // Credit Balance" row it belongs to even though the two won't
      // generally sit next to each other by date (user, 8 Nov 2026). Click
      // the chip to open that row's detail panel.
      if (row.source === 'refund' && row.credit_source_refs && row.credit_source_refs.length) {
        var chips = row.credit_source_refs.map(function (ref) {
          var src = ledgerCreditRows[ref.credit_source_id];
          var chipLabel = src ? ('Credit Balance — ' + src.date) : ('Credit source #' + ref.credit_source_id);
          return '<span class="rpt-credit-ref-chip" data-credit-source-id="' + ref.credit_source_id + '">\u21b3 ' +
            escapeHtml(chipLabel) + '</span>';
        }).join('');
        descHtml += '<br>' + chips;
      }

      // Rows sharing one group_id (a credit application's "remaining" +
      // "used" pair) are rendered as a visually joined block — same
      // background tint, no border between them — so they read as one
      // transaction split in two (user, 1 Nov 2026: "buat seolah-olah baris
      // 1 dan 2 itu seperti satu baris, jadi terkesan dari transaksi yang
      // sama"), while still being two real <tr> rows underneath.
      var groupClass = '';
      if (row.group_id) {
        groupClass = ' rpt-ledger-grouped' + (row.group_id === prevGroupId ? ' rpt-ledger-grouped-cont' : ' rpt-ledger-grouped-start');
      }
      prevGroupId = row.group_id || null;

      // "Customer Credit Balance" rows open the detail panel on click (user,
      // 8 Nov 2026) — ledgerCreditRows itself is already built above.
      var clickAttr = '';
      if (row.source === 'invoice_payment_credit' && row.credit_source_id != null) {
        groupClass += ' rpt-ledger-clickable';
        clickAttr = ' data-credit-source-id="' + row.credit_source_id + '"';
      }

      return '<tr class="' + groupClass.trim() + '"' + clickAttr + '>' +
        '<td>' + escapeHtml(row.date) + '</td>' +
        '<td>' + badge + '</td>' +
        '<td>' + escapeHtml(acctLabel) + '</td>' +
        '<td>' + descHtml + (row.original ? ' <span class="empty-sub">(' + escapeHtml(row.original) + ')</span>' : '') + '</td>' +
        '<td>' + escapeHtml(row.category || '\u2014') + '</td>' +
        '<td>' + amtCell + '</td>' +
        '<td>' + balCell + '</td>' +
        '<td>' + notesCell + '</td>' +
        '</tr>';
    }).join('');

    // running now holds the final balance, which equals the net total (sum
    // of all signed amounts) since balance always starts at 0.
    var totalCell = '<span class="' + (running < 0 ? 'rpt-amt-out' : 'rpt-amt-in') + '">' + fmtIDRParen(running) + '</span>';
    tfoot.innerHTML = '<tr class="rpt-ledger-total-row">' +
      '<td colspan="5" style="text-align:right; font-weight:600;">Total</td>' +
      '<td>' + totalCell + '</td>' +
      '<td>' + totalCell + '</td>' +
      '<td></td>' +
      '</tr>';
  }

  // Detail panel for a "Customer Credit Balance" ledger row (user, 8 Nov
  // 2026 — replaces the old "remaining"/"used" extra ledger rows). One
  // delegated listener on the tbody, since rows are replaced wholesale on
  // every renderLedger() call.
  function openCreditDetail(row) {
    var body = document.getElementById('rptCreditDetailBody');
    var html = '<div class="rpt-cd-row rpt-cd-original"><div>Original amount</div><div>' + fmtIDR(row.amount) + '</div></div>';
    if (!row.usage || !row.usage.length) {
      html += '<div class="rpt-cd-row"><div>Status</div><div>Not used yet — full amount still available</div></div>';
    } else {
      row.usage.forEach(function (u) {
        var line = u.kind === 'refunded'
          ? ('Refunded' + (u.method ? ' (' + u.method + ')' : ''))
          : ('Applied to ' + u.customer_name + ' (' + u.invoice_number + ')');
        html += '<div class="rpt-cd-row"><div>' +
          escapeHtml(u.date) + '<br><span class="rpt-cd-sub">' + escapeHtml(line) +
          '</span></div><div>' + fmtIDR(u.amount) + '</div></div>';
      });
    }
    html += '<div class="rpt-cd-row rpt-cd-highlight"><div>Remaining now</div><div class="rpt-cd-amount">' +
      fmtIDR(row.remaining_amount) + '</div></div>';
    // customer_credit_refunds only exists from 8 Nov 2026 onward — a refund
    // made before that migration was run has no row there, so it can't show
    // up above even though it really did draw from this credit.
    html += '<div class="rpt-cd-sub" style="margin-top:8px;">Note: a refund made before refund-tracing was added (8 Nov 2026) won\'t show here.</div>';
    body.innerHTML = html;
    document.getElementById('rptCreditDetailOverlay').classList.remove('hidden');
  }
  // Matches both the credit row itself (data attr on the <tr>) and a
  // refund row's reference chip (data attr on a nested <span>) — whichever
  // is closer to where the click landed.
  document.getElementById('rptLedgerBody').addEventListener('click', function (e) {
    var el = e.target.closest('[data-credit-source-id]');
    if (!el) return;
    var row = ledgerCreditRows[el.getAttribute('data-credit-source-id')];
    if (row) openCreditDetail(row);
  });
  document.getElementById('rptCreditDetailClose').addEventListener('click', function () {
    document.getElementById('rptCreditDetailOverlay').classList.add('hidden');
  });
  document.getElementById('rptCreditDetailOverlay').addEventListener('click', function (e) {
    if (e.target.id === 'rptCreditDetailOverlay') e.target.classList.add('hidden');
  });

  document.getElementById('rptApplyFilter').addEventListener('click', loadFinanceReport);
  document.getElementById('rptClearFilter').addEventListener('click', function () {
    document.getElementById('rptDateFrom').value = '';
    document.getElementById('rptDateTo').value = '';
    document.getElementById('rptActivityFilter').value = '';
    loadFinanceReport();
  });

  document.getElementById('rptExportCsv').addEventListener('click', function () {
    var ledger = window._rptLastLedger || [];
    var rows = [['Date', 'Type', 'Bank', 'Account Number', 'Description', 'Category', 'Amount IDR', 'Notes']];
    ledger.forEach(function (r) {
      var amt = r.ledger_note ? (r.display_amount || 0) : r.amount;
      var noteTxt = (r.notes && String(r.notes).trim() !== '') ? String(r.notes).trim() : '';
      var desc = noteTxt ? (r.label + '\n' + noteTxt) : r.label;
      rows.push([r.date, r.type, r.bank || '', r.account_no || '', desc, r.category || '', amt, r.ledger_note || '']);
    });
    var csv = rows.map(function (r) {
      return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'finance_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
  });

})();
</script>