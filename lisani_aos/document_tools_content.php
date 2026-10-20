<?php
// This file is included by index.php, same pattern as settings_content.php /
// transaction_content.php: it wraps its own
// <div class="menu-section" data-section="document_tools"> entirely.
// Do not wrap it again from index.php, and keep the modal as a sibling of
// that wrapper (outside it) so it still renders while the section is
// display:none.
//
// Signature Library below is a straight copy of the Settings > Company
// Documents pattern (settings_content.php + ajax/*_document.php) - only the
// storage folder (storage/document_tools/signatures/ instead of
// storage/company/legal_document/) and the table (signature_library instead
// of company_documents) are new. All element ids are prefixed "sig"/
// "docTools" (instead of "doc"/"settings") because both this file and
// settings_content.php are included on the same page at once, and ids must
// stay unique across the whole document.
?>
<div class="menu-section" data-section="document_tools" style="display:none;">
<div class="settings-stack">

  <!-- Signature Library -->
  <div class="card doctools-collapsible" id="cardSignatures">
    <button type="button" class="doctools-collapsible-header" id="cardSignaturesHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Signature Library</div>
        <div class="header-sub">Upload, download, and manage signature images (PNG/JPG)</div>
      </div>
      <i class="ti ti-chevron-down doctools-collapsible-chevron"></i>
    </button>

    <div class="doctools-collapsible-body" id="cardSignaturesBody">
      <div class="doctools-collapsible-body-inner">

        <form id="sigUploadForm" class="settings-form">
          <div class="settings-form-grid">
            <div class="form-group">
              <label class="label">Signature Name</label>
              <input type="text" class="input" id="sigNameInput" placeholder="e.g. John Doe" required>
            </div>
            <div class="form-group">
              <label class="label">Date</label>
              <input type="date" class="input" id="sigDateInput" required>
            </div>
            <div class="form-group">
              <label class="label">File</label>
              <input type="file" class="input" id="sigFileInput" accept="image/*,.pdf" required>
            </div>
          </div>

          <div class="form-group">
            <label class="label">Judul Kegiatan</label>
            <input type="text" class="input" id="sigActivityInput" list="sigActivityOptions"
                   placeholder="Pilih yang sudah ada, atau ketik judul baru" required>
            <div class="header-sub" id="sigActivityHint" style="display:none;">Menentukan folder penyimpanan di storage/company_activity/.../</div>
          </div>

          <div class="form-group">
            <label class="label">Final File Name (edit before saving)</label>
            <input type="text" class="input" id="sigFinalNameInput" placeholder="Will be generated automatically">
          </div>

          <div id="sigUploadMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="button" class="btn btn-secondary" id="sigCancelEditBtn" style="display:none;">Cancel Edit</button>
            <button type="submit" class="btn btn-primary" id="sigSubmitBtn">Upload Signature</button>
          </div>
        </form>

        <div class="doc-list-toolbar">
          <label class="doc-select-all-label">
            <input type="checkbox" id="sigSelectAll"> Select All
          </label>
          <div class="doc-list-toolbar-right">
            <span class="header-sub" id="sigSelectedCount">0 selected</span>
            <button type="button" class="btn-wa-icon" id="sigShareBtn" title="Share via WhatsApp" style="display:none;">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.39 1.26 4.83L2 22l5.42-1.42c1.38.75 2.96 1.18 4.62 1.18h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2zm5.83 14.02c-.25.7-1.29 1.32-1.78 1.4-.46.07-.99.1-1.6-.1-.37-.12-.85-.28-1.45-.55-2.56-1.11-4.23-3.68-4.36-3.85-.13-.17-1.04-1.38-1.04-2.63 0-1.25.66-1.87.89-2.12.23-.25.5-.31.67-.31.17 0 .33 0 .48.01.15.01.36-.06.56.43.21.51.71 1.76.77 1.89.06.13.1.28.02.45-.08.17-.13.28-.25.43-.13.15-.27.34-.38.46-.13.13-.26.27-.11.53.15.26.67 1.1 1.43 1.78.98.87 1.81 1.14 2.07 1.27.26.13.41.11.56-.07.15-.18.63-.74.8-.99.17-.25.34-.21.56-.13.23.08 1.46.69 1.71.82.25.13.41.19.47.3.06.11.06.63-.19 1.33z"/></svg>
            </button>
          </div>
        </div>

        <div class="doc-accordion" id="sigAccordionList">
          <div class="empty-sub">Loading...</div>
        </div>

      </div>
    </div>
  </div>

  <!-- Signature Editor -->
  <div class="card doctools-collapsible" id="cardSigEditor">
    <button type="button" class="doctools-collapsible-header" id="cardSigEditorHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Signature Editor</div>
        <div class="header-sub">Draw a signature, or upload a photo and remove its white background, then save straight to the library</div>
      </div>
      <i class="ti ti-chevron-down doctools-collapsible-chevron"></i>
    </button>

    <div class="doctools-collapsible-body" id="cardSigEditorBody">
      <div class="doctools-collapsible-body-inner">

        <div class="sig-editor-tabs">
          <button type="button" class="sig-editor-tab active" id="sigEditorTabDraw" data-mode="draw">
            <i class="ti ti-pencil"></i> Draw
          </button>
          <button type="button" class="sig-editor-tab" id="sigEditorTabUpload" data-mode="upload">
            <i class="ti ti-upload"></i> Upload Photo
          </button>
        </div>

        <div class="sig-editor-canvas-wrap">
          <canvas id="sigEditorCanvas" width="600" height="220"></canvas>
        </div>

        <div class="sig-editor-controls" id="sigEditorDrawControls">
          <label class="label" style="margin:0;">Pen Size</label>
          <input type="range" id="sigPenSize" min="1" max="12" value="3">
          <button type="button" class="btn btn-secondary" id="sigClearBtn"><i class="ti ti-eraser"></i> Clear</button>
        </div>

        <div class="sig-editor-controls" id="sigEditorUploadControls" style="display:none;">
          <input type="file" class="input" id="sigEditorFileInput" accept="image/*" style="max-width:260px;">
          <label class="label" style="margin:0;">Remove White Background</label>
          <input type="range" id="sigBgThreshold" min="0" max="255" value="235">
          <span class="header-sub" id="sigBgThresholdValue">235</span>
          <button type="button" class="btn btn-secondary" id="sigClearUploadBtn"><i class="ti ti-eraser"></i> Clear</button>
        </div>

        <form id="sigEditorSaveForm" class="settings-form" style="margin-top:18px;">
          <div class="settings-form-grid">
            <div class="form-group">
              <label class="label">Signature Name</label>
              <input type="text" class="input" id="sigEditorNameInput" placeholder="e.g. John Doe" required>
            </div>
            <div class="form-group">
              <label class="label">Date</label>
              <input type="date" class="input" id="sigEditorDateInput" required>
            </div>
            <div class="form-group">
              <label class="label">Judul Kegiatan</label>
              <input type="text" class="input" id="sigEditorActivityInput" list="sigActivityOptions"
                     placeholder="Pilih yang sudah ada, atau ketik judul baru" required>
            </div>
          </div>

          <div id="sigEditorMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="submit" class="btn btn-primary" id="sigEditorSaveBtn">Save to Library</button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- Document Editor -->
  <div class="card doctools-collapsible" id="cardDocEditor">
    <button type="button" class="doctools-collapsible-header" id="cardDocEditorHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Document Editor</div>
        <div class="header-sub">Upload a document, move its text blocks to make room, then paste a signature from the library</div>
      </div>
      <i class="ti ti-chevron-down doctools-collapsible-chevron"></i>
    </button>

    <div class="doctools-collapsible-body" id="cardDocEditorBody">
      <div class="doctools-collapsible-body-inner">

        <!-- Everything below (upload, toolbar, canvas, save form) lives inside
             one workspace div so "Fullscreen" can expand ALL of it together -
             the user still has every control (incl. the save form) without
             leaving fullscreen. See #docEditorWorkspace.doc-editor-fullscreen
             CSS + setFullscreen() JS. -->
        <div class="doc-editor-workspace" id="docEditorWorkspace">

        <div class="form-group" id="docUploadFormGroup" style="max-width:320px;">
          <label class="label">Upload Document (JPG/PNG/PDF)</label>
          <input type="file" class="input" id="docEditorFileInput" accept="image/*,application/pdf">
          <div class="header-sub">DOCX belum didukung - konversi dulu ke PDF, atau screenshot per halaman.</div>
        </div>

        <div class="doc-page-thumbs-wrap" id="docPageThumbsWrap" style="display:none;"></div>

        <div class="doc-editor-toolbar">
          <div class="doc-editor-toolbar-group" title="Blocks">
            <button type="button" class="btn btn-secondary" id="docBlockTextBtn" disabled>
              <i class="ti ti-crop"></i> Block: Top Text
            </button>
            <button type="button" class="btn btn-secondary" id="docBlockNamaBtn" disabled>
              <i class="ti ti-crop"></i> Block: Name
            </button>
            <button type="button" class="btn btn-secondary" id="docGuideBoxBtn" disabled>
              <i class="ti ti-box-margin"></i> Add Guide Box
            </button>
            <select class="select doc-guide-box-select" id="docGuideBoxPresetSelect" disabled aria-label="Guide Box size"></select>
            <label class="doc-editor-checkbox">
              <input type="checkbox" id="docAutoShiftCheckbox" checked> Auto-shift Name with Guide Box
            </label>
          </div>

          <div class="doc-editor-toolbar-group" title="Actions">
            <button type="button" class="btn btn-secondary" id="docInsertSigBtn" disabled>
              <i class="ti ti-signature"></i> Insert Signature
            </button>
            <button type="button" class="btn btn-danger" id="docDeleteSelectedBtn" disabled>
              <i class="ti ti-trash"></i> Delete Selected
            </button>
            <button type="button" class="btn btn-secondary" id="docClearAllBtn" disabled>
              <i class="ti ti-eraser"></i> Clear All
            </button>
          </div>

          <div class="doc-editor-toolbar-group doc-editor-zoom-group" title="Zoom &amp; View">
            <button type="button" class="btn btn-secondary doc-zoom-btn" id="docZoomOutBtn" disabled aria-label="Zoom Out">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <span class="doc-zoom-level" id="docZoomLevel">100%</span>
            <button type="button" class="btn btn-secondary doc-zoom-btn" id="docZoomInBtn" disabled aria-label="Zoom In">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <button type="button" class="btn btn-secondary" id="docZoomFitBtn" disabled>
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
              Fit to Page
            </button>
            <button type="button" class="btn btn-secondary" id="docFullscreenBtn" disabled>
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" id="docFullscreenIcon"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/></svg>
              <span id="docFullscreenLabel">Fullscreen</span>
            </button>
          </div>
        </div>
        <div class="header-sub" id="docEditorHint">Upload a document first to enable the tools above.</div>

        <div class="sig-editor-canvas-wrap doc-editor-canvas-wrap" id="docEditorCanvasWrap" style="display:none;">
          <canvas id="docEditorCanvas"></canvas>
        </div>

        <form id="docEditorSaveForm" class="settings-form" style="margin-top:18px;">
          <div class="settings-form-grid">
            <div class="form-group">
              <label class="label">Document Name</label>
              <input type="text" class="input" id="docEditorNameInput" placeholder="e.g. Surat Pernyataan - Budi" required>
            </div>
            <div class="form-group">
              <label class="label">Date</label>
              <input type="date" class="input" id="docEditorDateInput" required>
            </div>
            <div class="form-group">
              <label class="label">Judul Kegiatan</label>
              <input type="text" class="input" id="docEditorActivityInput" list="sigActivityOptions"
                     placeholder="Pilih yang sudah ada, atau ketik judul baru" required>
            </div>
          </div>

          <div id="docEditorMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="submit" class="btn btn-primary" id="docEditorSaveBtn" disabled>Save Edited Document</button>
          </div>
        </form>

        </div><!-- /#docEditorWorkspace -->

      </div>
    </div>
  </div>

</div>
</div>

<!-- Signature picker for Document Editor's "Insert Signature" button - own
     overlay/ids so it never collides with docToolsConfirmOverlay above. -->
<div class="modal-overlay" id="docSigPickerOverlay" style="display:none;">
  <div class="modal">
    <div class="panel-header">
      <div class="panel-title">Insert Signature</div>
    </div>
    <div class="doc-sig-pick-grid" id="docSigPickerList">
      <div class="empty-sub">Loading...</div>
    </div>
    <div class="settings-form-actions">
      <button type="button" class="btn btn-secondary" id="docSigPickerCancel">Cancel</button>
    </div>
  </div>
</div>

<!-- Shared across Signature Library's upload form AND the Signature Editor
     below - populated once by loadActivities() from
     ajax/list_activities.php (json_file/document_activities.json). -->
<datalist id="sigActivityOptions"></datalist>

<!-- Confirm modal (reused for Edit / Delete / Share) - own ids so it never
     collides with Settings' #settingsConfirmOverlay on the same page. -->
<div class="modal-overlay" id="docToolsConfirmOverlay" style="display:none;">
  <div class="modal">
    <div class="panel-header">
      <div class="panel-title" id="docToolsConfirmTitle">Confirm Action</div>
    </div>
    <p class="settings-confirm-message" id="docToolsConfirmMessage"></p>
    <div class="form-group">
      <label class="label">Password</label>
      <input type="password" class="input" id="docToolsConfirmPassword" placeholder="Enter your password">
    </div>
    <div id="docToolsConfirmError" class="settings-message settings-message-error" style="display:none;"></div>
    <div class="settings-form-actions">
      <button type="button" class="btn btn-secondary" id="docToolsConfirmCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="docToolsConfirmSubmit">Confirm</button>
    </div>
  </div>
</div>

<!-- Fabric.js powers the Document Editor's layer/drag/resize canvas below
     (block selection, moved blocks, guide box, pasted signature are all
     fabric objects). Loaded once; guarded in case this file is ever
     included twice on the same page. Same cdnjs-can-be-blocked gotcha as
     Chart.js (PROJECT_NOTES.md §3 gotcha #8) - the Document Editor's init
     code below wraps its use in try/catch and shows a clear message
     instead of silently failing. -->
<!-- PDF.js renders an uploaded PDF's pages to canvas, entirely client-side -
     no server-side Imagick/pdftoppm/Ghostscript needed (rangkuman bagian 9,
     Opsi A). This is what makes multi-page PDF upload possible without
     knowing what's installed on the XAMPP/Termux server. -->
<script>
  if (typeof fabric === 'undefined' && !document.getElementById('fabricJsLib')) {
    document.write('<scr' + 'ipt id="fabricJsLib" src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"><\/scr' + 'ipt>');
  }
  if (typeof pdfjsLib === 'undefined' && !document.getElementById('pdfJsLib')) {
    document.write('<scr' + 'ipt id="pdfJsLib" src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"><\/scr' + 'ipt>');
  }
</script>

<style>
  /* Identical rules to settings_content.php's own <style> block - duplicated
     here on purpose (each *_content.php is a self-contained IIFE/file that
     doesn't share CSS/JS with the others, see PROJECT_NOTES.md §3). Safe to
     duplicate: same selectors, same rules, no conflict with Settings' copy. */
  .settings-stack { display: flex; flex-direction: column; width: 100%; }

  .doctools-collapsible { padding: 0; overflow: hidden; margin-bottom: 20px; width: 100%; }
  .doctools-collapsible-header {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 20px;
    text-align: left;
    font: inherit;
    color: inherit;
  }
  .doctools-collapsible-chevron { transition: transform 0.2s ease; font-size: 20px; }
  .doctools-collapsible.open .doctools-collapsible-chevron { transform: rotate(180deg); }
  .doctools-collapsible-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.25s ease;
  }
  .doctools-collapsible-body-inner { padding: 0 20px 20px 20px; }

  .settings-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
  }
  .settings-form { margin-bottom: 20px; }
  .settings-form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; }
  .settings-message { margin-top: 8px; font-size: 13px; }
  .settings-message-error { color: var(--danger, #e5484d); }
  .settings-message-success { color: var(--success, #3dd68c); }
  .settings-confirm-message { margin: 4px 0 14px 0; font-size: 14px; line-height: 1.5; }

  .doc-list-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 16px 0 10px 0;
  }
  .doc-list-toolbar-right { display: flex; align-items: center; gap: 10px; }
  .doc-select-all-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted, #9a9a9a);
    cursor: pointer;
  }
  .btn-wa-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: #25d366;
    color: #ffffff;
    font-size: 18px;
    cursor: pointer;
    flex-shrink: 0;
  }
  .btn-wa-icon:hover { filter: brightness(1.05); }

  .doc-accordion { display: flex; flex-direction: column; gap: 10px; }
  .doc-accordion-item {
    border-radius: var(--radius-md, 10px);
    background: var(--bg-surface-alt, rgba(255, 255, 255, 0.03));
    overflow: hidden;
  }
  .doc-accordion-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    cursor: pointer;
  }
  .doc-accordion-name { flex: 1; font-weight: 600; }
  .doc-accordion-chevron { font-size: 18px; transition: transform 0.2s ease; flex-shrink: 0; }
  .doc-accordion-item.open .doc-accordion-chevron { transform: rotate(180deg); }
  .doc-accordion-body { max-height: 0; overflow: hidden; transition: max-height 0.2s ease; }
  .doc-accordion-body-inner { padding: 0 16px 16px 16px; }
  .doc-meta-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 14px;
  }
  .doc-meta-row .doc-meta-label { color: var(--text-muted, #9a9a9a); }
  .doc-accordion-actions {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
  }
  .doc-accordion-actions .btn { display: inline-flex; align-items: center; gap: 6px; width: auto; }

  .sig-editor-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
  .sig-editor-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: var(--radius-md, 10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: transparent;
    color: inherit;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }
  .sig-editor-tab.active { background: var(--primary, #6c5ce7); border-color: transparent; color: #fff; }

  .sig-editor-canvas-wrap {
    width: 100%;
    max-width: 600px;
    border: 1px dashed rgba(255, 255, 255, 0.18);
    border-radius: var(--radius-md, 10px);
    overflow: hidden;
    /* Checkerboard so transparent areas of the signature are visible. */
    background-image:
      linear-gradient(45deg, rgba(255,255,255,0.06) 25%, transparent 25%),
      linear-gradient(-45deg, rgba(255,255,255,0.06) 25%, transparent 25%),
      linear-gradient(45deg, transparent 75%, rgba(255,255,255,0.06) 75%),
      linear-gradient(-45deg, transparent 75%, rgba(255,255,255,0.06) 75%);
    background-size: 20px 20px;
    background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
  }
  #sigEditorCanvas { display: block; width: 100%; height: auto; touch-action: none; cursor: crosshair; }

  .sig-editor-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
  }

  .doc-editor-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 16px;
    margin: 14px 0 6px 0;
  }
  .doc-editor-toolbar .btn { display: inline-flex; align-items: center; gap: 6px; width: auto; }
  .doc-editor-toolbar-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-md, 10px);
    background: rgba(255, 255, 255, 0.02);
  }
  .doc-editor-zoom-group { margin-left: auto; }
  .doc-zoom-btn { padding: 0 !important; width: 34px; height: 34px; justify-content: center; }
  .doc-zoom-level {
    min-width: 46px;
    text-align: center;
    font-size: 13px;
    color: var(--text-muted, #9a9a9a);
    user-select: none;
  }
  .doc-guide-box-select { width: auto; min-width: 150px; }

  /* Fullscreen workspace: everything (upload, toolbar, canvas, save form)
     expands to cover the viewport so the whole editing flow - including
     typing the Document Name/Activity and hitting Save - fits on one
     screen without scrolling the page behind it. Toggled by
     #docFullscreenBtn (setFullscreen() in JS); auto-entered right after a
     document finishes uploading. z-index kept moderate (500) so it still
     sits under the app's real modals (.modal-overlay, confirm dialogs) -
     NOT verified against the actual topbar/sidebar z-index, check this if
     either still shows on top. */
  .doc-editor-workspace { position: relative; }
  .doc-editor-workspace.doc-editor-fullscreen {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    margin: 0 !important;
    max-width: none !important;
    max-height: none !important;
    z-index: 2147483647 !important; /* max safe int32 - must beat anything else on the page */
    background: var(--bg, #121218);
    padding: 16px;
    overflow-y: auto;
    display: flex !important;
    flex-direction: column;
    box-sizing: border-box;
  }
  .doc-editor-workspace.doc-editor-fullscreen .doc-editor-canvas-wrap {
    flex: 1 1 auto;
    max-height: none;
    min-height: 0; /* flex children default to min-height:auto, which can refuse to
                       shrink below their content size and starve the rest of the
                       column - this is what made the canvas "disappear" on a
                       narrower/shorter window instead of just getting smaller */
  }
  /* Everything not needed once you're actively editing - upload field, both
     variants of the hint text - gets out of the way in fullscreen, so the
     toolbar sits right at the very top and the canvas gets the rest. */
  .doc-editor-fullscreen #docUploadFormGroup,
  .doc-editor-fullscreen #docEditorHint {
    display: none !important;
  }
  /* The #1 cause of "fullscreen disappears on a narrower window": the
     toolbar wrapping onto 2-3 lines ate the vertical space the canvas
     needed. In fullscreen it now stays ONE line and scrolls sideways
     instead - canvas height stays predictable no matter the window size. */
  .doc-editor-fullscreen .doc-editor-toolbar {
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 4px; /* room so a scrollbar doesn't sit flush under the buttons */
  }
  .doc-editor-fullscreen .doc-editor-toolbar-group {
    flex: 0 0 auto;
    flex-wrap: nowrap;
  }
  .doc-editor-fullscreen .doc-editor-zoom-group { margin-left: 0; }
  /* Save form: compact single-ish row instead of the normal spaced-out
     grid, so it stays a thin strip under the canvas rather than competing
     with it for height. */
  .doc-editor-fullscreen #docEditorSaveForm {
    flex: 0 0 auto;
    margin-top: 10px !important;
  }
  .doc-editor-fullscreen #docEditorSaveForm .settings-form-grid {
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
  }
  .doc-editor-checkbox {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-muted, #9a9a9a);
    cursor: pointer;
    white-space: nowrap;
  }

  .doc-page-thumbs-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0 4px 0;
  }
  .doc-page-thumb {
    padding: 6px 12px;
    border-radius: var(--radius-md, 10px);
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: transparent;
    color: inherit;
    cursor: pointer;
    font: inherit;
    font-size: 12px;
  }
  .doc-page-thumb.active { background: var(--primary, #6c5ce7); border-color: transparent; color: #fff; }
  .doc-page-thumb.has-edits::after { content: ' •'; }

  .doc-editor-canvas-wrap {
    max-width: 100%;
    max-height: 75vh;
    margin-top: 10px;
    overflow: auto;
    display: flex;
    justify-content: center;
    align-items: center;
  }
  /* Sizing is driven entirely by Fabric's zoom (setZoom + setDimensions) so
     pointer coordinates stay accurate - no CSS max-width/height here, that
     would re-squish the canvas on top of Fabric's own scaling. */
  #docEditorCanvas { display: block; touch-action: none; }

  .doc-sig-pick-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 12px;
    max-height: 50vh;
    overflow-y: auto;
    margin: 6px 0 14px 0;
  }
  .doc-sig-pick-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 8px;
    border-radius: var(--radius-md, 10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    cursor: pointer;
    text-align: center;
    font-size: 12px;
  }
  .doc-sig-pick-item:hover { border-color: var(--primary, #6c5ce7); }
  .doc-sig-pick-item img {
    max-width: 100%;
    height: 60px;
    object-fit: contain;
    background:
      linear-gradient(45deg, rgba(255,255,255,0.06) 25%, transparent 25%),
      linear-gradient(-45deg, rgba(255,255,255,0.06) 25%, transparent 25%),
      linear-gradient(45deg, transparent 75%, rgba(255,255,255,0.06) 75%),
      linear-gradient(-45deg, transparent 75%, rgba(255,255,255,0.06) 75%);
    background-size: 14px 14px;
    background-position: 0 0, 0 7px, 7px -7px, -7px 0px;
  }

  /* Same label-above-value override as Settings, scoped to this section only. */
  @media (max-width: 1023px) {
    .menu-section[data-section="document_tools"] td[data-label] {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
    }
  }
</style>

<script>
(function () {
  var CURRENT_YEAR_FALLBACK = new Date().getFullYear();

  /* ---------- Judul Kegiatan: shared datalist (list_activities.php) ---------- */
  function loadActivities() {
    fetch('ajax/list_activities.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) return;
        var datalist = document.getElementById('sigActivityOptions');
        datalist.innerHTML = '';
        data.activities.forEach(function (title) {
          var opt = document.createElement('option');
          opt.value = title;
          datalist.appendChild(opt);
        });
      })
      .catch(function () { /* non-fatal: field still works as free text */ });
  }
  loadActivities();

  /* ---------- Collapsible card (same mechanics as Settings, own scope) ---------- */
  var collapsibles = document.querySelectorAll('#cardSignatures.doctools-collapsible');

  function closeCollapsible(el) {
    var body = el.querySelector('.doctools-collapsible-body');
    body.style.maxHeight = body.scrollHeight + 'px';
    void body.offsetHeight;
    requestAnimationFrame(function () {
      el.classList.remove('open');
      body.style.maxHeight = '0px';
    });
  }

  function openCollapsible(el) {
    el.classList.add('open');
    var body = el.querySelector('.doctools-collapsible-body');
    body.style.maxHeight = body.scrollHeight + 'px';
  }

  collapsibles.forEach(function (el) {
    var header = el.querySelector('.doctools-collapsible-header');
    header.addEventListener('click', function () {
      if (el.classList.contains('open')) {
        closeCollapsible(el);
      } else {
        openCollapsible(el);
        if (el.id === 'cardSignatures') loadSignatures();
      }
    });
  });

  function refreshOpenHeight(cardEl) {
    if (!cardEl.classList.contains('open')) return;
    var body = cardEl.querySelector('.doctools-collapsible-body');
    body.style.maxHeight = 'none';
  }

  /* ---------- Shared confirm (warning + password) modal, own ids ---------- */
  var confirmOverlay = document.getElementById('docToolsConfirmOverlay');
  var confirmTitle = document.getElementById('docToolsConfirmTitle');
  var confirmMessage = document.getElementById('docToolsConfirmMessage');
  var confirmPassword = document.getElementById('docToolsConfirmPassword');
  var confirmError = document.getElementById('docToolsConfirmError');
  var confirmSubmitBtn = document.getElementById('docToolsConfirmSubmit');
  var pendingConfirmAction = null;

  function showModal(overlay) { overlay.style.display = 'flex'; }
  function hideModal(overlay) { overlay.style.display = 'none'; }

  function openConfirmModal(title, message, onConfirm) {
    confirmTitle.textContent = title;
    confirmMessage.textContent = message;
    confirmPassword.value = '';
    confirmError.style.display = 'none';
    pendingConfirmAction = onConfirm;
    showModal(confirmOverlay);
    confirmPassword.focus();
  }

  document.getElementById('docToolsConfirmCancel').addEventListener('click', function () {
    hideModal(confirmOverlay);
    pendingConfirmAction = null;
  });

  // Same shared endpoint as Settings: ajax/verify_password.php sets
  // $_SESSION['aos_reverify_at'] on success, checked by every destructive
  // endpoint below via ajax/_require_reverify.php.
  confirmSubmitBtn.addEventListener('click', function () {
    var password = confirmPassword.value;
    if (!password) {
      confirmModalError('Password is required.');
      return;
    }

    confirmError.style.display = 'none';
    confirmSubmitBtn.disabled = true;

    var fd = new FormData();
    fd.append('password', password);

    fetch('ajax/verify_password.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        confirmSubmitBtn.disabled = false;
        if (!data.ok) {
          confirmModalError(data.message || 'Incorrect password.');
          return;
        }
        if (typeof pendingConfirmAction === 'function') {
          pendingConfirmAction();
        }
      })
      .catch(function () {
        confirmSubmitBtn.disabled = false;
        confirmModalError('Network error.');
      });
  });

  function confirmModalError(message) {
    confirmError.textContent = message;
    confirmError.style.display = 'block';
  }

  /* =====================================================================
     SIGNATURE LIBRARY
     ===================================================================== */
  var sigNameInput = document.getElementById('sigNameInput');
  var sigDateInput = document.getElementById('sigDateInput');
  var sigFileInput = document.getElementById('sigFileInput');
  var sigActivityInput = document.getElementById('sigActivityInput');
  var sigFinalNameInput = document.getElementById('sigFinalNameInput');
  var sigUploadForm = document.getElementById('sigUploadForm');
  var sigUploadMessage = document.getElementById('sigUploadMessage');
  var sigSubmitBtn = document.getElementById('sigSubmitBtn');
  var sigCancelEditBtn = document.getElementById('sigCancelEditBtn');
  var sigAccordionList = document.getElementById('sigAccordionList');
  var sigSelectAll = document.getElementById('sigSelectAll');
  var sigSelectedCount = document.getElementById('sigSelectedCount');
  var sigShareBtn = document.getElementById('sigShareBtn');

  var editingSignatureId = null;
  var signaturesCache = [];
  var finalNameManuallyEdited = false;

  function yearFromDateInput(value) {
    if (!value) return CURRENT_YEAR_FALLBACK;
    var y = parseInt(value.split('-')[0], 10);
    return isNaN(y) ? CURRENT_YEAR_FALLBACK : y;
  }

  function updateFinalNamePreview() {
    if (finalNameManuallyEdited) return;
    var name = sigNameInput.value.trim();
    var year = yearFromDateInput(sigDateInput.value);
    sigFinalNameInput.value = name ? (name + '_' + year) : '';
  }

  sigNameInput.addEventListener('input', updateFinalNamePreview);
  sigDateInput.addEventListener('input', updateFinalNamePreview);
  sigFinalNameInput.addEventListener('input', function () {
    finalNameManuallyEdited = true;
  });

  function resetSignatureForm() {
    sigUploadForm.reset();
    finalNameManuallyEdited = false;
    sigFinalNameInput.value = '';
    editingSignatureId = null;
    sigFileInput.required = true;
    sigFileInput.closest('.form-group').style.display = '';
    sigActivityInput.disabled = false;
    sigActivityInput.required = true;
    sigSubmitBtn.textContent = 'Upload Signature';
    sigCancelEditBtn.style.display = 'none';
    sigUploadMessage.textContent = '';
    sigUploadMessage.className = 'settings-message';
  }

  sigCancelEditBtn.addEventListener('click', resetSignatureForm);

  function loadSignatures() {
    sigAccordionList.innerHTML = '<div class="empty-sub">Loading...</div>';
    fetch('ajax/list_signatures.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          sigAccordionList.innerHTML = '<div class="empty-sub">Failed to load signatures.</div>';
          return;
        }
        signaturesCache = data.signatures;
        renderSignatureList();
        refreshOpenHeight(document.getElementById('cardSignatures'));
      })
      .catch(function () {
        sigAccordionList.innerHTML = '<div class="empty-sub">Failed to load signatures.</div>';
      });
  }

  function closeSigItem(item) {
    item.classList.remove('open');
    var body = item.querySelector('.doc-accordion-body');
    body.style.maxHeight = null;
  }

  function openSigItem(item) {
    sigAccordionList.querySelectorAll('.doc-accordion-item').forEach(function (other) {
      if (other !== item) closeSigItem(other);
    });
    item.classList.add('open');
    var body = item.querySelector('.doc-accordion-body');
    body.style.maxHeight = body.scrollHeight + 'px';
  }

  function renderSignatureList() {
    sigAccordionList.innerHTML = '';
    if (signaturesCache.length === 0) {
      sigAccordionList.innerHTML = '<div class="empty-sub">No signatures yet.</div>';
      updateSigSelectionUI();
      return;
    }

    signaturesCache.forEach(function (sig) {
      var item = document.createElement('div');
      item.className = 'doc-accordion-item';

      var header = document.createElement('div');
      header.className = 'doc-accordion-header';

      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'sig-row-checkbox';
      cb.value = sig.id;
      cb.addEventListener('click', function (e) { e.stopPropagation(); });
      cb.addEventListener('change', updateSigSelectionUI);

      var name = document.createElement('div');
      name.className = 'doc-accordion-name';
      name.textContent = sig.signature_name;

      var chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down doc-accordion-chevron';

      header.appendChild(cb);
      header.appendChild(name);
      header.appendChild(chevron);
      header.addEventListener('click', function () {
        if (item.classList.contains('open')) {
          closeSigItem(item);
        } else {
          openSigItem(item);
        }
        refreshOpenHeight(document.getElementById('cardSignatures'));
      });

      var body = document.createElement('div');
      body.className = 'doc-accordion-body';

      var bodyInner = document.createElement('div');
      bodyInner.className = 'doc-accordion-body-inner';

      var rowDate = document.createElement('div');
      rowDate.className = 'doc-meta-row';
      rowDate.innerHTML = '<span class="doc-meta-label">Date</span><span>' +
        escapeHtml(sig.signature_date) + '</span>';

      var rowActivity = document.createElement('div');
      rowActivity.className = 'doc-meta-row';
      rowActivity.innerHTML = '<span class="doc-meta-label">Kegiatan</span><span>' +
        escapeHtml(sig.activity_title) + '</span>';

      var rowFile = document.createElement('div');
      rowFile.className = 'doc-meta-row';
      rowFile.innerHTML = '<span class="doc-meta-label">Original File</span><span>' +
        escapeHtml(sig.original_filename) + '</span>';

      var actions = document.createElement('div');
      actions.className = 'doc-accordion-actions';

      var btnDownload = document.createElement('a');
      btnDownload.href = 'ajax/download_signature.php?id=' + sig.id;
      btnDownload.className = 'btn btn-secondary';
      btnDownload.innerHTML = '<i class="ti ti-download"></i> Download';

      var btnEdit = document.createElement('button');
      btnEdit.type = 'button';
      btnEdit.className = 'btn btn-secondary';
      btnEdit.innerHTML = '<i class="ti ti-pencil"></i> Edit';
      btnEdit.addEventListener('click', function (e) {
        e.stopPropagation();
        openEditSignatureForm(sig);
      });

      var btnDelete = document.createElement('button');
      btnDelete.type = 'button';
      btnDelete.className = 'btn btn-danger';
      btnDelete.innerHTML = '<i class="ti ti-trash"></i> Delete';
      btnDelete.addEventListener('click', function (e) {
        e.stopPropagation();
        confirmDeleteSignature(sig);
      });

      actions.appendChild(btnDownload);
      actions.appendChild(btnEdit);
      actions.appendChild(btnDelete);

      bodyInner.appendChild(rowDate);
      bodyInner.appendChild(rowActivity);
      bodyInner.appendChild(rowFile);
      bodyInner.appendChild(actions);
      body.appendChild(bodyInner);

      item.appendChild(header);
      item.appendChild(body);
      sigAccordionList.appendChild(item);
    });

    updateSigSelectionUI();
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function getSelectedSigIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.sig-row-checkbox:checked')).map(function (cb) {
      return cb.value;
    });
  }

  function updateSigSelectionUI() {
    var selected = getSelectedSigIds();
    sigSelectedCount.textContent = selected.length + ' selected';
    sigShareBtn.style.display = selected.length > 0 ? 'inline-flex' : 'none';
  }

  sigSelectAll.addEventListener('change', function () {
    document.querySelectorAll('.sig-row-checkbox').forEach(function (cb) {
      cb.checked = sigSelectAll.checked;
    });
    updateSigSelectionUI();
  });

  function openEditSignatureForm(sig) {
    editingSignatureId = sig.id;
    sigNameInput.value = sig.signature_name;
    sigDateInput.value = sig.signature_date;
    finalNameManuallyEdited = true;
    sigFinalNameInput.value = sig.signature_name;
    sigFileInput.required = false;
    sigFileInput.closest('.form-group').style.display = 'none';
    // Judul Kegiatan menentukan folder di disk - belum ada fitur pindah
    // folder, jadi saat edit cuma ditampilkan, tidak bisa diubah di sini.
    sigActivityInput.value = sig.activity_title;
    sigActivityInput.disabled = true;
    sigActivityInput.required = false;
    sigSubmitBtn.textContent = 'Update Signature';
    sigCancelEditBtn.style.display = 'inline-flex';
    sigUploadMessage.textContent = '';
    sigNameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  sigUploadForm.addEventListener('submit', function (e) {
    e.preventDefault();
    sigUploadMessage.textContent = '';
    sigUploadMessage.className = 'settings-message';

    if (editingSignatureId) {
      var sigId = editingSignatureId;
      openConfirmModal(
        'Confirm Edit',
        'You are about to update this signature\'s name/date. Enter your password to continue.',
        function () {
          var fd = new FormData();
          fd.append('id', sigId);
          fd.append('signature_name', sigFinalNameInput.value.trim() || sigNameInput.value.trim());
          fd.append('signature_date', sigDateInput.value);

          fetch('ajax/update_signature.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (!data.success) {
                confirmModalError(data.message || 'Failed to update signature.');
                return;
              }
              hideModal(confirmOverlay);
              resetSignatureForm();
              loadSignatures();
            })
            .catch(function () { confirmModalError('Network error.'); });
        }
      );
      return;
    }

    // Create (no password needed).
    if (!sigFileInput.files || sigFileInput.files.length === 0) {
      sigUploadMessage.textContent = 'Please choose a file.';
      sigUploadMessage.className = 'settings-message settings-message-error';
      return;
    }

    if (!sigActivityInput.value.trim()) {
      sigUploadMessage.textContent = 'Please fill in Judul Kegiatan.';
      sigUploadMessage.className = 'settings-message settings-message-error';
      return;
    }

    var fd = new FormData();
    fd.append('signature_name', sigFinalNameInput.value.trim() || sigNameInput.value.trim());
    fd.append('signature_date', sigDateInput.value);
    fd.append('activity_title', sigActivityInput.value.trim());
    fd.append('file', sigFileInput.files[0]);

    sigSubmitBtn.disabled = true;
    fetch('ajax/upload_signature.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        sigSubmitBtn.disabled = false;
        if (!data.success) {
          sigUploadMessage.textContent = data.message || 'Failed to upload signature.';
          sigUploadMessage.className = 'settings-message settings-message-error';
          return;
        }
        resetSignatureForm();
        loadSignatures();
        loadActivities();
      })
      .catch(function () {
        sigSubmitBtn.disabled = false;
        sigUploadMessage.textContent = 'Network error.';
        sigUploadMessage.className = 'settings-message settings-message-error';
      });
  });

  function confirmDeleteSignature(sig) {
    openConfirmModal(
      'Delete Signature',
      'This will permanently delete "' + sig.signature_name + '" and its file. This action cannot be undone. Enter your password to confirm.',
      function () {
        var fd = new FormData();
        fd.append('id', sig.id);
        fetch('ajax/delete_signature.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              confirmModalError(data.message || 'Failed to delete signature.');
              return;
            }
            hideModal(confirmOverlay);
            loadSignatures();
          })
          .catch(function () { confirmModalError('Network error.'); });
      }
    );
  }

  sigShareBtn.addEventListener('click', function () {
    var ids = getSelectedSigIds();
    if (ids.length === 0) return;
    openConfirmModal(
      'Share via WhatsApp',
      'This will download ' + ids.length + ' selected signature(s) to your device, then open WhatsApp with a text list so you can attach the files yourself. Enter your password to continue.',
      function () {
        var fd = new FormData();
        ids.forEach(function (id) { fd.append('ids[]', id); });

        fetch('ajax/share_signatures.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              confirmModalError(data.message || 'Failed to prepare share.');
              return;
            }
            hideModal(confirmOverlay);

            data.documents.forEach(function (doc) {
              var a = document.createElement('a');
              a.href = doc.download_url;
              a.download = '';
              document.body.appendChild(a);
              a.click();
              document.body.removeChild(a);
            });

            var lines = ['Signature Library:'];
            data.documents.forEach(function (doc) {
              lines.push('- ' + doc.document_name + ' (' + doc.document_date + ')');
            });
            var waText = encodeURIComponent(lines.join('\n'));
            window.open('https://wa.me/?text=' + waText, '_blank');
          })
          .catch(function () { confirmModalError('Network error.'); });
      }
    );
  });

  /* =====================================================================
     SIGNATURE EDITOR (canvas) - draw a signature freehand, or upload a
     photo of one and strip its (usually white/paper) background, then
     save straight to the library via the SAME ajax/upload_signature.php
     endpoint used above (the canvas is just exported as a PNG Blob and
     sent as the 'file' field).
     ===================================================================== */
  var editorCanvas = document.getElementById('sigEditorCanvas');
  var editorCtx = editorCanvas.getContext('2d');
  var tabDraw = document.getElementById('sigEditorTabDraw');
  var tabUpload = document.getElementById('sigEditorTabUpload');
  var drawControls = document.getElementById('sigEditorDrawControls');
  var uploadControls = document.getElementById('sigEditorUploadControls');
  var penSizeInput = document.getElementById('sigPenSize');
  var clearBtn = document.getElementById('sigClearBtn');
  var editorFileInput = document.getElementById('sigEditorFileInput');
  var bgThresholdInput = document.getElementById('sigBgThreshold');
  var bgThresholdValue = document.getElementById('sigBgThresholdValue');
  var clearUploadBtn = document.getElementById('sigClearUploadBtn');
  var editorSaveForm = document.getElementById('sigEditorSaveForm');
  var editorNameInput = document.getElementById('sigEditorNameInput');
  var editorDateInput = document.getElementById('sigEditorDateInput');
  var editorActivityInput = document.getElementById('sigEditorActivityInput');
  var editorMessage = document.getElementById('sigEditorMessage');
  var editorSaveBtn = document.getElementById('sigEditorSaveBtn');

  var editorMode = 'draw';
  var isDrawing = false;
  var hasContent = false;
  // Full-resolution pixel data of the last uploaded photo (before any
  // threshold is applied), so moving the slider always re-derives from the
  // original instead of degrading an already-processed image.
  var originalPhotoImageData = null;

  function clearEditorCanvas() {
    editorCtx.clearRect(0, 0, editorCanvas.width, editorCanvas.height);
    hasContent = false;
    originalPhotoImageData = null;
  }

  function setEditorMode(mode) {
    editorMode = mode;
    tabDraw.classList.toggle('active', mode === 'draw');
    tabUpload.classList.toggle('active', mode === 'upload');
    drawControls.style.display = mode === 'draw' ? 'flex' : 'none';
    uploadControls.style.display = mode === 'upload' ? 'flex' : 'none';
    clearEditorCanvas();
    editorFileInput.value = '';
  }

  tabDraw.addEventListener('click', function () { setEditorMode('draw'); });
  tabUpload.addEventListener('click', function () { setEditorMode('upload'); });

  /* ---- Draw mode: freehand strokes directly on a transparent canvas,
     so no background-removal step is needed for drawn signatures. ---- */
  function canvasPointFromEvent(e) {
    var rect = editorCanvas.getBoundingClientRect();
    var scaleX = editorCanvas.width / rect.width;
    var scaleY = editorCanvas.height / rect.height;
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY,
    };
  }

  editorCanvas.addEventListener('pointerdown', function (e) {
    if (editorMode !== 'draw') return;
    isDrawing = true;
    hasContent = true;
    var p = canvasPointFromEvent(e);
    editorCtx.beginPath();
    editorCtx.moveTo(p.x, p.y);
    editorCanvas.setPointerCapture(e.pointerId);
  });

  editorCanvas.addEventListener('pointermove', function (e) {
    if (editorMode !== 'draw' || !isDrawing) return;
    var p = canvasPointFromEvent(e);
    editorCtx.lineCap = 'round';
    editorCtx.lineJoin = 'round';
    editorCtx.lineWidth = parseInt(penSizeInput.value, 10) || 3;
    editorCtx.strokeStyle = '#000000';
    editorCtx.lineTo(p.x, p.y);
    editorCtx.stroke();
  });

  function stopDrawing() { isDrawing = false; }
  editorCanvas.addEventListener('pointerup', stopDrawing);
  editorCanvas.addEventListener('pointercancel', stopDrawing);
  editorCanvas.addEventListener('pointerleave', stopDrawing);

  clearBtn.addEventListener('click', clearEditorCanvas);
  clearUploadBtn.addEventListener('click', function () {
    clearEditorCanvas();
    editorFileInput.value = '';
  });

  /* ---- Upload Photo mode: draw the photo onto the canvas (fit/center),
     keep its raw pixels in originalPhotoImageData, then derive a
     transparent version from that on every slider move. ---- */
  editorFileInput.addEventListener('change', function () {
    var file = editorFileInput.files && editorFileInput.files[0];
    if (!file) return;

    var img = new Image();
    img.onload = function () {
      clearEditorCanvas();
      var cw = editorCanvas.width, ch = editorCanvas.height;
      var scale = Math.min(cw / img.width, ch / img.height);
      var w = img.width * scale, h = img.height * scale;
      var x = (cw - w) / 2, y = (ch - h) / 2;
      editorCtx.drawImage(img, x, y, w, h);
      originalPhotoImageData = editorCtx.getImageData(0, 0, cw, ch);
      hasContent = true;
      applyBackgroundThreshold(parseInt(bgThresholdInput.value, 10));
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(file);
  });

  function applyBackgroundThreshold(threshold) {
    if (!originalPhotoImageData) return;
    var src = originalPhotoImageData.data;
    var out = editorCtx.createImageData(originalPhotoImageData.width, originalPhotoImageData.height);
    var dst = out.data;
    for (var i = 0; i < src.length; i += 4) {
      var r = src[i], g = src[i + 1], b = src[i + 2], a = src[i + 3];
      dst[i] = r; dst[i + 1] = g; dst[i + 2] = b;
      // Near-white (paper/background) and above the threshold -> transparent.
      dst[i + 3] = (r >= threshold && g >= threshold && b >= threshold) ? 0 : a;
    }
    editorCtx.putImageData(out, 0, 0);
  }

  bgThresholdInput.addEventListener('input', function () {
    bgThresholdValue.textContent = bgThresholdInput.value;
    applyBackgroundThreshold(parseInt(bgThresholdInput.value, 10));
  });

  /* ---- Save to Library: export canvas as PNG, reuse upload_signature.php ---- */
  editorSaveForm.addEventListener('submit', function (e) {
    e.preventDefault();
    editorMessage.textContent = '';
    editorMessage.className = 'settings-message';

    if (!hasContent) {
      editorMessage.textContent = editorMode === 'draw'
        ? 'Please draw a signature first.'
        : 'Please upload a photo first.';
      editorMessage.className = 'settings-message settings-message-error';
      return;
    }

    editorSaveBtn.disabled = true;
    editorCanvas.toBlob(function (blob) {
      if (!blob) {
        editorSaveBtn.disabled = false;
        editorMessage.textContent = 'Failed to export signature image.';
        editorMessage.className = 'settings-message settings-message-error';
        return;
      }

      var fd = new FormData();
      var finalName = editorNameInput.value.trim();
      var year = yearFromDateInput(editorDateInput.value);
      fd.append('signature_name', finalName ? (finalName + '_' + year) : finalName);
      fd.append('signature_date', editorDateInput.value);
      fd.append('activity_title', editorActivityInput.value.trim());
      fd.append('file', blob, 'signature.png');

      fetch('ajax/upload_signature.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          editorSaveBtn.disabled = false;
          if (!data.success) {
            editorMessage.textContent = data.message || 'Failed to save signature.';
            editorMessage.className = 'settings-message settings-message-error';
            return;
          }
          editorMessage.textContent = 'Saved to library.';
          editorMessage.className = 'settings-message settings-message-success';
          editorSaveForm.reset();
          clearEditorCanvas();
          loadSignatures();
          loadActivities();
        })
        .catch(function () {
          editorSaveBtn.disabled = false;
          editorMessage.textContent = 'Network error.';
          editorMessage.className = 'settings-message settings-message-error';
        });
    }, 'image/png');
  });

  // The editor card also needs to run loadSignatures()/nothing special on
  // open, but it DOES need its collapsible wired up - the generic loop at
  // the top of this file only matches #cardSignatures, so wire this one
  // explicitly here instead of broadening that selector.
  (function () {
    var editorCard = document.getElementById('cardSigEditor');
    var editorHeader = document.getElementById('cardSigEditorHeader');
    editorHeader.addEventListener('click', function () {
      if (editorCard.classList.contains('open')) {
        closeCollapsible(editorCard);
      } else {
        openCollapsible(editorCard);
      }
    });
  })();

  // Refresh if the user navigates away and back to this section (same
  // generic pattern as other sections - see footer.php's 'aos:section-shown').
  document.addEventListener('aos:section-shown', function (e) {
    if (e.detail && e.detail.target === 'document_tools' &&
        document.getElementById('cardSignatures').classList.contains('open')) {
      loadSignatures();
    }
  });

  /* =====================================================================
     DOCUMENT EDITOR (Tahap 8, rangkuman_tool_ttd_dokumen.md) - upload a
     document (image OR multi-page PDF; DOCX deferred - see
     PROJECT_NOTES.md log), pick which page to work on, block-select a piece
     of it ("Teks Atas" or "Nama") which is immediately cut into its own
     draggable Fabric layer (the hole left behind is patched with a sampled
     fill color - the background bitmap itself is never touched, so cutting
     is always done from the pristine original even after switching pages
     back and forth). A resizable "Guide Box" shows how much room a
     signature needs; with "Auto-shift Nama" on, moving/resizing it shifts
     that page's Nama block by the same delta. Multiple signatures can be
     pasted, on any page, via the Signature Library (reusing
     ajax/list_signatures.php + ajax/download_signature.php from above).
     Each page keeps its own Fabric state (serialized with fabric's own
     toJSON/loadFromJSON when switching pages). On Save, every page is
     flattened to a PNG and all of them are sent together to
     ajax/save_edited_document.php, which combines them into ONE PDF with
     FPDF (same library already used for invoices in optic_pos/fpdf/) - see
     sql/migration_edited_documents.sql for the table this writes to.
     ===================================================================== */
  var docEditorFileInput = document.getElementById('docEditorFileInput');
  var docPageThumbsWrap = document.getElementById('docPageThumbsWrap');
  var docEditorCanvasWrap = document.getElementById('docEditorCanvasWrap');
  var docEditorCanvasEl = document.getElementById('docEditorCanvas');
  var docBlockTextBtn = document.getElementById('docBlockTextBtn');
  var docBlockNamaBtn = document.getElementById('docBlockNamaBtn');
  var docGuideBoxBtn = document.getElementById('docGuideBoxBtn');
  var docAutoShiftCheckbox = document.getElementById('docAutoShiftCheckbox');
  var docInsertSigBtn = document.getElementById('docInsertSigBtn');
  var docDeleteSelectedBtn = document.getElementById('docDeleteSelectedBtn');
  var docClearAllBtn = document.getElementById('docClearAllBtn');
  var docZoomOutBtn = document.getElementById('docZoomOutBtn');
  var docZoomInBtn = document.getElementById('docZoomInBtn');
  var docZoomFitBtn = document.getElementById('docZoomFitBtn');
  var docZoomLevel = document.getElementById('docZoomLevel');
  var docFullscreenBtn = document.getElementById('docFullscreenBtn');
  var docFullscreenIcon = document.getElementById('docFullscreenIcon');
  var docFullscreenLabel = document.getElementById('docFullscreenLabel');
  var docEditorWorkspace = document.getElementById('docEditorWorkspace');
  var docGuideBoxPresetSelect = document.getElementById('docGuideBoxPresetSelect');
  var docEditorHint = document.getElementById('docEditorHint');
  var docEditorSaveForm = document.getElementById('docEditorSaveForm');
  var docEditorNameInput = document.getElementById('docEditorNameInput');
  var docEditorDateInput = document.getElementById('docEditorDateInput');
  var docEditorActivityInput = document.getElementById('docEditorActivityInput');
  var docEditorMessage = document.getElementById('docEditorMessage');
  var docEditorSaveBtn = document.getElementById('docEditorSaveBtn');
  var docSigPickerOverlay = document.getElementById('docSigPickerOverlay');
  var docSigPickerList = document.getElementById('docSigPickerList');
  var docSigPickerCancel = document.getElementById('docSigPickerCancel');

  var MAX_DOC_WIDTH = 1400; // working-resolution cap per page, see PROJECT_NOTES.md note on 150-200 DPI being enough

  if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
  }

  // One entry per page: { workingCanvas: <canvas> (pristine original bitmap,
  // used for sampling/cropping), width, height, json: <fabric JSON or null
  // until the page has been opened/edited once> }.
  var docPages = [];
  var currentPageIndex = -1;
  var docFabricCanvas = null;
  var docSourceFilename = '';
  var docDrawMode = null; // null | 'text' | 'nama'
  var docDrawingRect = null;
  var docDrawStart = null;
  var guideBox = null;
  var guideBoxInitialTop = 0;
  var namaLayer = null;
  var namaLayerInitialTop = 0;
  var insertedSignatureIds = {}; // set-like map, id -> true, collected across all pages

  // Zoom (Fabric's own setZoom + setDimensions, not CSS scaling - keeps
  // mouse coordinates for drawing/dragging accurate at any zoom level).
  var currentZoom = 1;
  var ZOOM_MIN = 0.1;
  var ZOOM_MAX = 3;
  var ZOOM_STEP = 0.15;

  var docEditorIsFullscreen = false;
  var docBodyOverflowBeforeFullscreen = '';
  var docAutoFullscreenPending = false; // see startEditingPages() + switchToPage()'s afterLoad()
  var ICON_MAXIMIZE_D = 'M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3';
  var ICON_MINIMIZE_D = 'M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3';

  // Guide box presets (inches), loaded from ajax/list_guide_box_presets.php
  // (json_file/guide_box_presets.json - admin can edit/add sizes there
  // directly, no code change needed). DOC_DPI MUST match $ASSUMED_DPI in
  // save_edited_document.php: the working canvas's pixels are what gets
  // exported and are assumed to be this many px per inch when FPDF sizes
  // the final PDF page, so a guide box drawn at e.g. 1.46in here has to use
  // the same px/inch to end up 1.46in on the printed page.
  var DOC_DPI = 150;
  var docGuideBoxPresets = [
    { id: 'standard-1', label: '1.46" x 2.18"', width_in: 1.46, height_in: 2.18 },
    { id: 'standard-2', label: '1.46" x 3.05"', width_in: 1.46, height_in: 3.05 },
  ]; // built-in fallback, overwritten by loadGuideBoxPresets() if the endpoint answers

  function inchesToPx(inches) { return Math.round(inches * DOC_DPI); }

  /* ---------- Guide Box size presets (list_guide_box_presets.php ->
     json_file/guide_box_presets.json). Falls back to the built-in
     docGuideBoxPresets array (set above) if the endpoint is unreachable, so
     "Add Guide Box" still works either way. Must run AFTER
     docGuideBoxPresetSelect (above) is assigned - this was previously
     called near the top of the script, before that var was set, which
     threw on every page load and silently broke every button's event
     binding in this whole IIFE (Signature Library/Editor included, not
     just Document Editor). ---------- */
  function renderGuideBoxPresetOptions() {
    docGuideBoxPresetSelect.innerHTML = '';
    docGuideBoxPresets.forEach(function (preset) {
      var opt = document.createElement('option');
      opt.value = preset.id;
      opt.textContent = preset.label;
      docGuideBoxPresetSelect.appendChild(opt);
    });
  }
  function loadGuideBoxPresets() {
    renderGuideBoxPresetOptions(); // built-in defaults first, in case fetch fails/is slow
    fetch('ajax/list_guide_box_presets.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success || !Array.isArray(data.presets) || data.presets.length === 0) return;
        docGuideBoxPresets = data.presets;
        renderGuideBoxPresetOptions();
      })
      .catch(function () { /* non-fatal: built-in defaults stay in place */ });
  }
  loadGuideBoxPresets();

  function docEditorReady() { return !!docFabricCanvas && currentPageIndex >= 0; }
  function currentPage() { return docPages[currentPageIndex]; }

  function setDocToolbarEnabled(enabled) {
    [docBlockTextBtn, docBlockNamaBtn, docGuideBoxBtn, docGuideBoxPresetSelect, docInsertSigBtn, docClearAllBtn,
     docZoomOutBtn, docZoomInBtn, docZoomFitBtn, docFullscreenBtn].forEach(function (btn) {
      btn.disabled = !enabled;
    });
    docEditorSaveBtn.disabled = !enabled;
    docEditorHint.textContent = enabled
      ? 'Draw a block, drag it into place, then insert a signature from the library. Switch pages with the tabs above if the document has more than one.'
      : 'Upload a document first to enable the tools above.';
  }

  function resetDocEditorState() {
    docPages = [];
    currentPageIndex = -1;
    docDrawMode = null;
    docDrawingRect = null;
    docDrawStart = null;
    guideBox = null;
    namaLayer = null;
    insertedSignatureIds = {};
    currentZoom = 1;
    docBlockTextBtn.classList.remove('active');
    docBlockNamaBtn.classList.remove('active');
    docDeleteSelectedBtn.disabled = true;
    docPageThumbsWrap.innerHTML = '';
    docPageThumbsWrap.style.display = 'none';
  }

  /* ---- Upload: image -> single page; PDF -> one page per PDF page via
     PDF.js (pure client-side, no server conversion needed). ---- */
  docEditorFileInput.addEventListener('change', function () {
    var file = docEditorFileInput.files && docEditorFileInput.files[0];
    if (!file) return;
    docSourceFilename = file.name;
    docEditorMessage.textContent = '';
    docEditorMessage.className = 'settings-message';

    var isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);

    if (isPdf) {
      if (typeof pdfjsLib === 'undefined') {
        docEditorMessage.textContent = 'PDF library (PDF.js) failed to load - check your network/ad-blocker and reload the page.';
        docEditorMessage.className = 'settings-message settings-message-error';
        return;
      }
      loadPdfPages(file)
        .then(function (pages) { startEditingPages(pages); })
        .catch(function () {
          docEditorMessage.textContent = 'Failed to read the PDF file.';
          docEditorMessage.className = 'settings-message settings-message-error';
        });
    } else {
      loadImagePage(file)
        .then(function (page) { startEditingPages([page]); })
        .catch(function () {
          docEditorMessage.textContent = 'Failed to read the image file.';
          docEditorMessage.className = 'settings-message settings-message-error';
        });
    }
  });

  function loadImagePage(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, MAX_DOC_WIDTH / img.width);
        var w = Math.round(img.width * scale), h = Math.round(img.height * scale);
        var canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        URL.revokeObjectURL(img.src);
        resolve({ workingCanvas: canvas, width: w, height: h, json: null });
      };
      img.onerror = reject;
      img.src = URL.createObjectURL(file);
    });
  }

  function loadPdfPages(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () {
        var typedArray = new Uint8Array(reader.result);
        pdfjsLib.getDocument({ data: typedArray }).promise.then(function (pdfDoc) {
          var pagePromises = [];
          for (var i = 1; i <= pdfDoc.numPages; i++) {
            pagePromises.push(renderPdfPageToWorkingCanvas(pdfDoc, i));
          }
          Promise.all(pagePromises).then(resolve).catch(reject);
        }).catch(reject);
      };
      reader.onerror = reject;
      reader.readAsArrayBuffer(file);
    });
  }

  function renderPdfPageToWorkingCanvas(pdfDoc, pageNum) {
    return pdfDoc.getPage(pageNum).then(function (page) {
      var baseViewport = page.getViewport({ scale: 1 });
      var scale = Math.min(2, MAX_DOC_WIDTH / baseViewport.width);
      var viewport = page.getViewport({ scale: scale });
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(viewport.width);
      canvas.height = Math.round(viewport.height);
      return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise.then(function () {
        return { workingCanvas: canvas, width: canvas.width, height: canvas.height, json: null };
      });
    });
  }

  function startEditingPages(pages) {
    if (typeof fabric === 'undefined') {
      docEditorMessage.textContent = 'Document Editor library (Fabric.js) failed to load - check your network/ad-blocker and reload the page.';
      docEditorMessage.className = 'settings-message settings-message-error';
      return;
    }
    resetDocEditorState();
    docPages = pages;
    docEditorCanvasWrap.style.display = 'block';
    buildPageThumbnails();
    setDocToolbarEnabled(true);
    // #1: used to call setFullscreen(true) right here, BEFORE switchToPage(0)
    // - i.e. before the canvas/page image even existed yet. Reparenting the
    // (still-empty) workspace into fullscreen first, then having Fabric
    // build the canvas and load the image into it after the move, could
    // leave the preview blank until something else (like exiting
    // fullscreen) forced a reflow. Now we wait for the page to actually
    // finish loading/rendering in its normal spot first (see the
    // docAutoFullscreenPending flag inside switchToPage's afterLoad), and
    // only THEN move into fullscreen - so there's always real pixel
    // content before the reparent happens.
    docAutoFullscreenPending = true;
    switchToPage(0);
    refreshOpenHeight(document.getElementById('cardDocEditor'));
  }

  function buildPageThumbnails() {
    docPageThumbsWrap.innerHTML = '';
    docPageThumbsWrap.style.display = docPages.length > 1 ? 'flex' : 'none';
    docPages.forEach(function (page, idx) {
      var thumb = document.createElement('button');
      thumb.type = 'button';
      thumb.className = 'doc-page-thumb';
      thumb.textContent = 'Hal ' + (idx + 1);
      thumb.addEventListener('click', function () { switchToPage(idx); });
      docPageThumbsWrap.appendChild(thumb);
    });
    highlightActiveThumbnail();
  }

  function highlightActiveThumbnail() {
    var thumbs = docPageThumbsWrap.querySelectorAll('.doc-page-thumb');
    thumbs.forEach(function (t, idx) {
      t.classList.toggle('active', idx === currentPageIndex);
      t.classList.toggle('has-edits', !!(docPages[idx] && docPages[idx].json));
    });
  }

  /* ---- Switch pages: serialize the page being left (so edits survive),
     then load the target page's saved state (or its pristine bitmap if it
     has never been opened/edited yet). ---- */
  function syncCurrentPageState() {
    if (docFabricCanvas && currentPageIndex >= 0 && docPages[currentPageIndex]) {
      docPages[currentPageIndex].json = docFabricCanvas.toJSON(['blockType', 'isGuideBox']);
    }
  }

  function switchToPage(index) {
    if (!docPages[index]) return;
    syncCurrentPageState();
    if (docFabricCanvas) {
      docFabricCanvas.dispose();
      docFabricCanvas = null;
    }

    currentPageIndex = index;
    var page = docPages[index];
    docEditorCanvasEl.width = page.width;
    docEditorCanvasEl.height = page.height;

    docFabricCanvas = new fabric.Canvas('docEditorCanvas', { selection: true });
    docFabricCanvas.setWidth(page.width);
    docFabricCanvas.setHeight(page.height);
    wireDocCanvasDrawing();
    wireDocCanvasSelection();

    function afterLoad() {
      guideBox = null;
      namaLayer = null;
      docFabricCanvas.getObjects().forEach(function (o) {
        if (o.isGuideBox) {
          guideBox = o;
          guideBoxInitialTop = o.top;
          attachGuideBoxEvents(o);
        }
        if (o.blockType === 'nama') {
          namaLayer = o;
          namaLayerInitialTop = o.top;
        }
      });
      highlightActiveThumbnail();
      fitZoomToContainer();
      docFabricCanvas.requestRenderAll();
      if (docAutoFullscreenPending) {
        docAutoFullscreenPending = false;
        setFullscreen(true); // #1: page already has real content now - safe to reparent into fullscreen
      }
    }

    if (page.json) {
      docFabricCanvas.loadFromJSON(page.json, afterLoad);
    } else {
      fabric.Image.fromURL(page.workingCanvas.toDataURL('image/png'), function (bgImg) {
        bgImg.set({ left: 0, top: 0, selectable: false, evented: false, hasControls: false });
        docFabricCanvas.add(bgImg);
        docFabricCanvas.sendToBack(bgImg);
        afterLoad();
      });
    }
  }

  /* ---- Zoom: Fabric's own setZoom + setDimensions (not a CSS transform),
     so getPointer() during drawing/dragging stays accurate at any zoom
     level. "Fit to Page" sizes the page to the wrap's available width (and
     a generous viewport-height cap), so a freshly opened/switched page is
     fully visible without scrolling. ---- */
  function applyZoom(zoom) {
    if (!docFabricCanvas) return;
    var page = currentPage();
    if (!page || !page.width || !page.height) return;
    if (!isFinite(zoom) || zoom <= 0) return;
    zoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom));
    currentZoom = zoom;
    docFabricCanvas.setZoom(zoom);
    docFabricCanvas.setDimensions({
      width: Math.round(page.width * zoom),
      height: Math.round(page.height * zoom),
    });
    docZoomLevel.textContent = Math.round(zoom * 100) + '%';
  }

  function fitZoomToContainer() {
    var page = currentPage();
    if (!page || !page.width || !page.height) return; // not loaded yet - nothing to size
    var available = docEditorCanvasWrap.clientWidth - 4; // minus wrap border
    if (available <= 0) available = page.width;
    // In fullscreen the wrap is a flex item that stretches to fill the
    // remaining viewport height, so clientHeight is already the real
    // available space; outside fullscreen it's capped by CSS max-height
    // (75vh) instead, so fall back to that same ~estimate if clientHeight
    // isn't ready yet (e.g. right after display:block, before layout).
    var availableHeight = docEditorCanvasWrap.clientHeight - 4;
    if (availableHeight <= 0) availableHeight = Math.max(300, Math.round(window.innerHeight * 0.65));
    var fit = Math.min(available / page.width, availableHeight / page.height);
    applyZoom(fit);
  }

  /* ---- Fullscreen: expands #docEditorWorkspace (upload field, toolbar,
     canvas AND the save form) to cover the viewport, so the whole flow -
     drawing blocks, inserting a signature, filling in Document Name/Date/
     Activity, Save - fits on one screen. Auto-entered right after a
     document finishes uploading (see startEditingPages); Escape or the
     same button exits it.

     IMPORTANT: #docEditorWorkspace normally lives inside
     .doctools-collapsible-body (overflow:hidden, JS-controlled max-height).
     Per spec a plain overflow:hidden ancestor should NOT clip a
     position:fixed descendant, but several mobile WebViews get this wrong
     and clip/shrink it anyway, which looks exactly like "nothing happens
     on Fullscreen". To sidestep that entirely (whatever the real cause
     turns out to be), on fullscreen we physically move the workspace to be
     a direct child of <body> - completely outside any ancestor's
     overflow/stacking context - and move it back to its original spot on
     exit. ---- */
  var docWorkspaceOriginalParent = null;
  var docWorkspaceOriginalNextSibling = null;

  function setFullscreen(on) {
    docEditorIsFullscreen = on;
    if (on) {
      docWorkspaceOriginalParent = docEditorWorkspace.parentNode;
      docWorkspaceOriginalNextSibling = docEditorWorkspace.nextSibling;
      document.body.appendChild(docEditorWorkspace);
    } else if (docWorkspaceOriginalParent) {
      // insertBefore(el, null) is just appendChild, so this still works
      // even if the workspace used to be the last child.
      docWorkspaceOriginalParent.insertBefore(docEditorWorkspace, docWorkspaceOriginalNextSibling);
    }
    docEditorWorkspace.classList.toggle('doc-editor-fullscreen', on);
    docFullscreenIcon.setAttribute('d', on ? ICON_MINIMIZE_D : ICON_MAXIMIZE_D);
    docFullscreenLabel.textContent = on ? 'Exit Fullscreen' : 'Fullscreen';
    if (on) {
      docBodyOverflowBeforeFullscreen = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = docBodyOverflowBeforeFullscreen;
    }
    // Wait a frame so the new CSS layout (fixed/flex) has settled before
    // measuring the wrap for fitZoomToContainer().
    requestAnimationFrame(function () { fitZoomToContainer(); });
  }

  docFullscreenBtn.addEventListener('click', function () {
    if (!docEditorReady() && docPages.length === 0) return;
    setFullscreen(!docEditorIsFullscreen);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && docEditorIsFullscreen) setFullscreen(false);
  });

  docZoomInBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    applyZoom(currentZoom + ZOOM_STEP);
  });
  docZoomOutBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    applyZoom(currentZoom - ZOOM_STEP);
  });
  docZoomFitBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    fitZoomToContainer();
  });

  function wireDocCanvasSelection() {
    function syncDeleteBtn() {
      var active = docFabricCanvas.getActiveObject();
      docDeleteSelectedBtn.disabled = !active;
    }
    docFabricCanvas.on('selection:created', syncDeleteBtn);
    docFabricCanvas.on('selection:updated', syncDeleteBtn);
    docFabricCanvas.on('selection:cleared', function () { docDeleteSelectedBtn.disabled = true; });
  }

  function wireDocCanvasDrawing() {
    docFabricCanvas.on('mouse:down', function (opt) {
      if (!docDrawMode) return;
      docFabricCanvas.discardActiveObject();
      var p = docFabricCanvas.getPointer(opt.e);
      docDrawStart = p;
      docDrawingRect = new fabric.Rect({
        left: p.x, top: p.y, width: 0, height: 0,
        fill: 'rgba(108,92,231,0.15)', stroke: '#6c5ce7', strokeDashArray: [5, 5],
        strokeWidth: 1, selectable: false, evented: false,
      });
      docFabricCanvas.add(docDrawingRect);
    });

    docFabricCanvas.on('mouse:move', function (opt) {
      if (!docDrawMode || !docDrawingRect) return;
      var p = docFabricCanvas.getPointer(opt.e);
      docDrawingRect.set({
        left: Math.min(p.x, docDrawStart.x),
        top: Math.min(p.y, docDrawStart.y),
        width: Math.abs(p.x - docDrawStart.x),
        height: Math.abs(p.y - docDrawStart.y),
      });
      docFabricCanvas.requestRenderAll();
    });

    docFabricCanvas.on('mouse:up', function () {
      if (!docDrawMode || !docDrawingRect) return;
      var rect = { left: docDrawingRect.left, top: docDrawingRect.top, width: docDrawingRect.width, height: docDrawingRect.height };
      docFabricCanvas.remove(docDrawingRect);
      docDrawingRect = null;

      if (rect.width > 4 && rect.height > 4) {
        cutBlockToLayer(rect, docDrawMode);
        // Block made: tool did its job, switch back to the normal pointer.
        docDrawMode = null;
        docBlockTextBtn.classList.remove('active');
        docBlockNamaBtn.classList.remove('active');
        docEditorCanvasEl.style.cursor = '';
      }
      // else: the drag was too small to count (e.g. a stray click) - KEEP
      // the tool armed instead of silently turning it off. Previously this
      // always cleared docDrawMode here, so a single press of "Block: Top
      // Text"/"Block: Name" followed by anything less than a clean drag
      // quietly disarmed the tool, making it look like the button needed
      // two presses to "really" start. Now one press stays armed until an
      // actual block is drawn, or the user clicks the button again to
      // cancel it.
      docFabricCanvas.requestRenderAll();
    });
  }

  function startBlockDraw(mode, btn) {
    if (!docEditorReady()) return;
    var turningOn = docDrawMode !== mode;
    docDrawMode = null;
    docBlockTextBtn.classList.remove('active');
    docBlockNamaBtn.classList.remove('active');
    if (turningOn) {
      docDrawMode = mode;
      btn.classList.add('active');
      docEditorCanvasEl.style.cursor = 'crosshair'; // clear "ready to draw" feedback
    } else {
      docEditorCanvasEl.style.cursor = '';
    }
  }

  docBlockTextBtn.addEventListener('click', function () { startBlockDraw('text', docBlockTextBtn); });
  docBlockNamaBtn.addEventListener('click', function () { startBlockDraw('nama', docBlockNamaBtn); });

  function cutBlockToLayer(rect, blockType) {
    var page = currentPage();
    if (!page) return;
    var x = Math.max(0, Math.round(rect.left));
    var y = Math.max(0, Math.round(rect.top));
    var w = Math.min(Math.round(rect.width), page.width - x);
    var h = Math.min(Math.round(rect.height), page.height - y);
    if (w <= 0 || h <= 0) return;

    var pageCtx = page.workingCanvas.getContext('2d');

    // Sample a pixel just inside the block's corner to patch the hole left
    // behind - good enough for a scanned white page (rangkuman §4); busier
    // photo backgrounds may need a manual touch-up afterwards.
    var sampleX = Math.min(x + 2, page.width - 1);
    var sampleY = Math.min(y + 2, page.height - 1);
    var sample = pageCtx.getImageData(sampleX, sampleY, 1, 1).data;
    var fillColor = 'rgb(' + sample[0] + ',' + sample[1] + ',' + sample[2] + ')';

    var patch = new fabric.Rect({
      left: x, top: y, width: w, height: h,
      fill: fillColor, selectable: false, evented: false,
    });
    docFabricCanvas.add(patch);
    docFabricCanvas.moveTo(patch, 1); // just above the background image (index 0)

    var cropCanvas = document.createElement('canvas');
    cropCanvas.width = w;
    cropCanvas.height = h;
    cropCanvas.getContext('2d').drawImage(page.workingCanvas, x, y, w, h, 0, 0, w, h);

    fabric.Image.fromURL(cropCanvas.toDataURL('image/png'), function (img) {
      img.set({ left: x, top: y, blockType: blockType });
      docFabricCanvas.add(img);
      docFabricCanvas.setActiveObject(img);
      if (blockType === 'nama') {
        namaLayer = img;
        namaLayerInitialTop = img.top;
      }
      docFabricCanvas.requestRenderAll();
    });
  }

  function attachGuideBoxEvents(box) {
    function applyGuideBoxShift() {
      if (!docAutoShiftCheckbox.checked || !namaLayer) return;
      var shift = box.top - guideBoxInitialTop;
      namaLayer.set('top', namaLayerInitialTop + shift);
      namaLayer.setCoords();
      docFabricCanvas.requestRenderAll();
    }
    box.on('moving', applyGuideBoxShift);
    box.on('scaling', applyGuideBoxShift);
  }

  docGuideBoxBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    if (guideBox) {
      docFabricCanvas.remove(guideBox);
    }
    var page = currentPage();
    var selectedId = docGuideBoxPresetSelect.value;
    var preset = docGuideBoxPresets.filter(function (p) { return p.id === selectedId; })[0] || docGuideBoxPresets[0];
    var w = preset ? inchesToPx(preset.width_in) : Math.round(page.width * 0.45);
    var h = preset ? inchesToPx(preset.height_in) : Math.round(page.height * 0.12);
    // Clamp so a preset bigger than the page doesn't produce an
    // off-canvas/negative-position box.
    w = Math.min(w, page.width);
    h = Math.min(h, page.height);
    guideBox = new fabric.Rect({
      left: Math.round((page.width - w) / 2),
      top: Math.round((page.height - h) / 2),
      width: w, height: h,
      fill: 'rgba(255,165,0,0.08)', stroke: '#ffa500', strokeDashArray: [6, 4], strokeWidth: 2,
      cornerColor: '#ffa500', transparentCorners: false,
      isGuideBox: true,
    });
    guideBoxInitialTop = guideBox.top;
    docFabricCanvas.add(guideBox);
    docFabricCanvas.setActiveObject(guideBox);
    attachGuideBoxEvents(guideBox);
    docFabricCanvas.requestRenderAll();
  });

  docDeleteSelectedBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    var active = docFabricCanvas.getActiveObject();
    if (!active) return;
    if (active === guideBox) guideBox = null;
    if (active === namaLayer) namaLayer = null;
    docFabricCanvas.remove(active);
    docFabricCanvas.discardActiveObject();
    docFabricCanvas.requestRenderAll();
    docDeleteSelectedBtn.disabled = true;
  });

  docClearAllBtn.addEventListener('click', function () {
    if (!docEditorReady() && docPages.length === 0) return;
    openConfirmModal(
      'Clear Document Editor',
      'This will discard the current document (all pages) and everything moved/pasted on it (not yet saved). Enter your password to continue.',
      function () {
        hideModal(confirmOverlay);
        if (docFabricCanvas) { docFabricCanvas.dispose(); docFabricCanvas = null; }
        docEditorCanvasWrap.style.display = 'none';
        docEditorFileInput.value = '';
        resetDocEditorState();
        setDocToolbarEnabled(false);
        setFullscreen(false);
      }
    );
  });

  /* ---- Insert Signature: reuses ajax/list_signatures.php and
     ajax/download_signature.php from the Signature Library above. Can be
     used on any page, any number of times - every id used anywhere gets
     recorded in insertedSignatureIds and sent along when saving. ---- */
  docInsertSigBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    docSigPickerList.innerHTML = '<div class="empty-sub">Loading...</div>';
    showModal(docSigPickerOverlay);

    fetch('ajax/list_signatures.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success || !data.signatures.length) {
          docSigPickerList.innerHTML = '<div class="empty-sub">No signatures in the library yet.</div>';
          return;
        }
        docSigPickerList.innerHTML = '';
        data.signatures.forEach(function (sig) {
          var item = document.createElement('div');
          item.className = 'doc-sig-pick-item';
          var img = document.createElement('img');
          img.src = 'ajax/download_signature.php?id=' + sig.id;
          img.alt = sig.signature_name;
          var label = document.createElement('span');
          label.textContent = sig.signature_name;
          item.appendChild(img);
          item.appendChild(label);
          item.addEventListener('click', function () { insertSignatureOntoCanvas(sig); });
          docSigPickerList.appendChild(item);
        });
      })
      .catch(function () {
        docSigPickerList.innerHTML = '<div class="empty-sub">Failed to load signatures.</div>';
      });
  });

  docSigPickerCancel.addEventListener('click', function () { hideModal(docSigPickerOverlay); });

  function insertSignatureOntoCanvas(sig) {
    hideModal(docSigPickerOverlay);
    var page = currentPage();
    var url = 'ajax/download_signature.php?id=' + sig.id;
    fabric.Image.fromURL(url, function (img) {
      if (!img || !img.width) {
        docEditorMessage.textContent = 'Failed to load the selected signature image.';
        docEditorMessage.className = 'settings-message settings-message-error';
        return;
      }
      // getScaledWidth()/getScaledHeight() instead of raw .width/.height:
      // dragging a guide box's corner handle changes its scaleX/scaleY in
      // Fabric, not .width/.height directly, so reading .width/.height here
      // would use the box's ORIGINAL size and misplace the signature the
      // moment the user resizes the guide box after placing it. .left/.top
      // are unaffected by scale (default originX/originY is left/top), so
      // those stay as-is.
      var gbW = guideBox ? guideBox.getScaledWidth() : null;
      var gbH = guideBox ? guideBox.getScaledHeight() : null;
      var targetW = guideBox ? gbW * 0.9 : page.width * 0.3;
      var scale = targetW / img.width;
      var left = guideBox ? guideBox.left + (gbW - img.width * scale) / 2 : 40;
      var top = guideBox ? guideBox.top + (gbH - img.height * scale) / 2 : 40;
      img.set({ left: left, top: top, scaleX: scale, scaleY: scale });
      docFabricCanvas.add(img);
      docFabricCanvas.setActiveObject(img);
      docFabricCanvas.requestRenderAll();
      insertedSignatureIds[sig.id] = true;
    }, { crossOrigin: 'anonymous' });
  }

  /* ---- Save: sync + flatten EVERY page (minus guide boxes, which are only
     a visual aid) to a PNG, send them all to ajax/save_edited_document.php
     which combines them into one PDF with FPDF. Pages never opened keep
     their pristine bitmap and are sent as-is. ---- */
  function renderPageToDataURL(page) {
    return new Promise(function (resolve, reject) {
      if (!page.json) {
        resolve(page.workingCanvas.toDataURL('image/png'));
        return;
      }
      var tmp = new fabric.StaticCanvas(null, { width: page.width, height: page.height });
      tmp.loadFromJSON(page.json, function () {
        tmp.getObjects().filter(function (o) { return o.isGuideBox; }).forEach(function (o) { tmp.remove(o); });
        tmp.renderAll();
        var dataUrl = tmp.toDataURL({ format: 'png' });
        tmp.dispose();
        resolve(dataUrl);
      });
    });
  }

  docEditorSaveForm.addEventListener('submit', function (e) {
    e.preventDefault();
    docEditorMessage.textContent = '';
    docEditorMessage.className = 'settings-message';

    if (!docEditorReady() && docPages.length === 0) {
      docEditorMessage.textContent = 'Please upload a document first.';
      docEditorMessage.className = 'settings-message settings-message-error';
      return;
    }

    syncCurrentPageState();
    docEditorSaveBtn.disabled = true;

    Promise.all(docPages.map(renderPageToDataURL))
      .then(function (dataUrls) {
        return Promise.all(dataUrls.map(function (u) { return fetch(u).then(function (r) { return r.blob(); }); }));
      })
      .then(function (blobs) {
        var fd = new FormData();
        fd.append('document_name', docEditorNameInput.value.trim());
        fd.append('document_date', docEditorDateInput.value);
        fd.append('activity_title', docEditorActivityInput.value.trim());
        fd.append('source_filename', docSourceFilename || 'document.pdf');
        Object.keys(insertedSignatureIds).forEach(function (id) { fd.append('signature_ids[]', id); });
        blobs.forEach(function (b, i) { fd.append('pages[]', b, 'page_' + (i + 1) + '.png'); });

        return fetch('ajax/save_edited_document.php', { method: 'POST', body: fd });
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        docEditorSaveBtn.disabled = false;
        if (!data.success) {
          docEditorMessage.textContent = data.message || 'Failed to save document.';
          docEditorMessage.className = 'settings-message settings-message-error';
          return;
        }
        docEditorMessage.textContent = 'Saved as a ' + docPages.length + '-page PDF.';
        docEditorMessage.className = 'settings-message settings-message-success';
        loadActivities();
      })
      .catch(function () {
        docEditorSaveBtn.disabled = false;
        docEditorMessage.textContent = 'Network error.';
        docEditorMessage.className = 'settings-message settings-message-error';
      });
  });

  // This card's collapsible isn't matched by the generic #cardSignatures
  // query above, same reason as #cardSigEditor - wire it explicitly.
  (function () {
    var docEditorCard = document.getElementById('cardDocEditor');
    var docEditorHeader = document.getElementById('cardDocEditorHeader');
    docEditorHeader.addEventListener('click', function () {
      if (docEditorCard.classList.contains('open')) {
        closeCollapsible(docEditorCard);
      } else {
        openCollapsible(docEditorCard);
      }
    });
  })();
})();
</script>