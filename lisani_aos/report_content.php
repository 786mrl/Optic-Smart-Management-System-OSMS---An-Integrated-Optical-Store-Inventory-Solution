<?php
// This file is included by index.php, REPLACING the static "Report" placeholder
// <div class="menu-section" data-section="report" ...> entirely — same pattern as
// transaction_content.php / logistic_content.php. Own IIFE, own scope.
?>
<div class="menu-section" data-section="report" style="display:none;">
<div class="card" style="width:100%;">

  <div class="tab-group" id="reportTabGroup">
    <div class="tab active" data-report-tab="finance">Finance Report</div>
    <div class="tab" data-report-tab="investor">Investor Report</div>
  </div>

  <!-- ===================== Finance Report ===================== -->
  <div id="reportFinancePane">

    <div class="rpt-toolbar">
      <div class="form-group">
        <div class="label">From</div>
        <input type="date" class="input" id="rptDateFrom">
      </div>
      <div class="form-group">
        <div class="label">To</div>
        <input type="date" class="input" id="rptDateTo">
      </div>
      <button type="button" class="btn btn-secondary" id="rptApplyFilter">Apply</button>
      <button type="button" class="btn btn-secondary" id="rptClearFilter">Clear</button>
      <button type="button" class="btn btn-secondary" id="rptExportCsv">Export CSV</button>
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
              <th>Amount (IDR)</th><th>Running Balance</th>
            </tr>
          </thead>
          <tbody id="rptLedgerBody">
            <tr><td colspan="7" class="empty-sub">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- ===================== Investor Report ===================== -->
  <div id="reportInvestorPane" style="display:none;">
    <div class="empty-state">
      <i class="ti ti-chart-infographic"></i>
      <div class="empty-title">Investor Report</div>
      <div class="empty-sub">No content yet.</div>
    </div>
  </div>

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
  #reportFinancePane, #reportInvestorPane { min-width:0; max-width:100%; }
  #reportFinancePane .card { box-sizing:border-box; max-width:100%; min-width:0; }

  .rpt-toolbar { display:flex; flex-wrap:wrap; gap:var(--space-2); align-items:flex-end; margin:var(--space-3) 0; }
  .rpt-toolbar .form-group { flex:1 1 140px; min-width:0; }
  .rpt-toolbar .input { width:100%; min-width:0; box-sizing:border-box; }

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

  var financePane = document.getElementById('reportFinancePane');
  var investorPane = document.getElementById('reportInvestorPane');
  var tabGroup = document.getElementById('reportTabGroup');
  var loaded = false;

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

  tabGroup.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-report-tab]');
    if (!btn) return;
    tabGroup.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
    btn.classList.add('active');
    var tab = btn.getAttribute('data-report-tab');
    financePane.style.display = tab === 'finance' ? '' : 'none';
    investorPane.style.display = tab === 'investor' ? '' : 'none';
    if (tab === 'finance' && !loaded) {
      loadFinanceReport();
    }
  });

  // footer.php dispatches this on every sidebar section switch (confirmed
  // 2 Okt 2026 — see "Each *_content.php is its own IIFE..." comment there),
  // so any section left stale after a show/hide can refresh itself live.
  document.addEventListener('aos:section-shown', function (e) {
    if (e.detail && e.detail.target === 'report') {
      loadFinanceReport();
    }
  });

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
    loaded = true;
    var from = document.getElementById('rptDateFrom').value;
    var to = document.getElementById('rptDateTo').value;
    var qs = [];
    if (from) qs.push('date_from=' + encodeURIComponent(from));
    if (to) qs.push('date_to=' + encodeURIComponent(to));

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
    if (!ledger.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty-sub">No transactions in this period.</td></tr>';
      return;
    }
    var running = 0;
    tbody.innerHTML = ledger.map(function (row) {
      running += row.type === 'inflow' ? row.amount : -row.amount;
      var acctLabel = row.bank ? (row.bank + (row.account_no ? ' · ' + row.account_no : '')) : '—';
      var badge = row.type === 'inflow'
        ? '<span class="badge badge-success">INFLOW</span>'
        : '<span class="badge badge-danger">OUTFLOW</span>';
      var isIn = row.type === 'inflow';
      var amtCell = '<span class="' + (isIn ? 'rpt-amt-in' : 'rpt-amt-out') + '">' +
        fmtIDRParen(isIn ? row.amount : -row.amount) + '</span>';
      var balCell = '<span class="' + (running < 0 ? 'rpt-amt-out' : '') + '">' + fmtIDRParen(running) + '</span>';
      return '<tr>' +
        '<td>' + escapeHtml(row.date) + '</td>' +
        '<td>' + badge + '</td>' +
        '<td>' + escapeHtml(acctLabel) + '</td>' +
        '<td>' + escapeHtml(row.label) + (row.original ? ' <span class="empty-sub">(' + escapeHtml(row.original) + ')</span>' : '') + '</td>' +
        '<td>' + escapeHtml(row.category || '\u2014') + '</td>' +
        '<td>' + amtCell + '</td>' +
        '<td>' + balCell + '</td>' +
        '</tr>';
    }).join('');
  }

  document.getElementById('rptApplyFilter').addEventListener('click', loadFinanceReport);
  document.getElementById('rptClearFilter').addEventListener('click', function () {
    document.getElementById('rptDateFrom').value = '';
    document.getElementById('rptDateTo').value = '';
    loadFinanceReport();
  });

  document.getElementById('rptExportCsv').addEventListener('click', function () {
    var ledger = window._rptLastLedger || [];
    var rows = [['Date', 'Type', 'Bank', 'Account Number', 'Description', 'Category', 'Amount IDR']];
    ledger.forEach(function (r) {
      rows.push([r.date, r.type, r.bank || '', r.account_no || '', r.label, r.category || '', r.amount]);
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