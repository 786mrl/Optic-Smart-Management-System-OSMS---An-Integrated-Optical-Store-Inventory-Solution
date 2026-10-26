<?php
// This file is included by index.php, same pattern as settings_content.php /
// transaction_content.php: it wraps its own
// <div class="menu-section" data-section="document_tools"> entirely.
// Do not wrap it again from index.php, and keep the modal as a sibling of
// that wrapper (outside it) so it still renders while the section is
// display:none.
//
// Signature Library (merged with the former Signature Editor, 21 Okt 2026 -
// see the comment right above #cardSignatures below) started as a straight
// copy of the Settings > Company Documents pattern (settings_content.php +
// ajax/*_document.php); table is signature_library, storage is now
// storage/signature/{company_name}/{origin}/. All element ids are prefixed
// "sig"/"docTools" (instead of "doc"/"settings") because both this file and
// settings_content.php are included on the same page at once, and ids must
// stay unique across the whole document.
?>
<div class="menu-section" data-section="document_tools" style="display:none;">
<div class="settings-stack">

  <!-- Signature Library (merged with the former Signature Editor - 21 Okt
       2026: separate manual-upload form removed, editor is now the ONLY
       way to add a signature; its canvas gained Crop + Erase Part, the
       Draw tab was dropped, and Remove White Background got +/- step
       buttons. Storage/grouping changed from Judul Kegiatan to
       Company Name + Origin, plus a per-signature "Main" flag. See
       PROJECT_NOTES.md §8. -->
  <div class="card doctools-collapsible" id="cardSignatures">
    <button type="button" class="doctools-collapsible-header" id="cardSignaturesHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Signature Library</div>
        <div class="header-sub">Upload a photo, crop/erase/remove its background, then save straight to the library</div>
      </div>
      <i class="ti ti-chevron-down doctools-collapsible-chevron"></i>
    </button>

    <div class="doctools-collapsible-body" id="cardSignaturesBody">
      <div class="doctools-collapsible-body-inner">

        <div class="sig-canvas-outer" id="sigEditorCanvasOuter">
          <div class="sig-canvas-zoom-wrap" id="sigEditorCanvasWrap">
            <canvas id="sigEditorCanvas" width="600" height="220"></canvas>
          </div>
        </div>

        <!-- 21 Okt 2026: toolbar dirapikan jadi grup-grup terpisah (card kecil
             per grup, label di atas tiap grup) - sebelumnya Upload/Crop/Erase/
             Remove Background semua nyampur jadi satu baris panjang. Tiap
             grup punya header kecil ("Photo", "Crop", "Erase Part", "Remove
             White Background") supaya jelas tombol mana milik fitur apa. -->
        <div class="sig-editor-toolbar">

          <div class="sig-editor-group">
            <div class="sig-editor-group-label">Photo</div>
            <div class="sig-editor-group-controls">
              <input type="file" class="input" id="sigEditorFileInput" accept="image/*" style="max-width:220px;">
              <button type="button" class="btn btn-secondary" id="sigClearUploadBtn"><i class="ti ti-trash"></i> Clear All</button>
            </div>
          </div>

          <div class="sig-editor-group">
            <div class="sig-editor-group-label">Zoom</div>
            <div class="sig-editor-group-controls">
              <button type="button" class="btn btn-secondary doc-zoom-btn" id="sigZoomOutBtn" aria-label="Zoom out"><i class="ti ti-zoom-out"></i></button>
              <input type="range" id="sigZoomSlider" min="50" max="1000" step="5" value="100" style="width:140px;">
              <button type="button" class="btn btn-secondary doc-zoom-btn" id="sigZoomInBtn" aria-label="Zoom in"><i class="ti ti-zoom-in"></i></button>
              <span class="header-sub" id="sigZoomLevel">100%</span>
              <button type="button" class="btn btn-secondary" id="sigZoomResetBtn">Reset</button>
            </div>
          </div>

          <div class="sig-editor-group">
            <div class="sig-editor-group-label">Crop</div>
            <div class="sig-editor-group-controls">
              <button type="button" class="btn btn-secondary" id="sigCropBtn"><i class="ti ti-crop"></i> Crop</button>
              <button type="button" class="btn btn-secondary" id="sigCropUndoBtn" style="display:none;"><i class="ti ti-arrow-back-up"></i> Undo Crop</button>
            </div>
            <div class="sig-editor-group-controls" id="sigEditorCropControls" style="display:none;">
              <span class="header-sub">Drag to select the crop area, then drag its edges/corners to adjust, or drag inside to move it.</span>
              <button type="button" class="btn btn-primary" id="sigCropApplyBtn">Apply Crop</button>
              <button type="button" class="btn btn-secondary" id="sigCropCancelBtn">Cancel Crop</button>
            </div>
          </div>

          <div class="sig-editor-group">
            <div class="sig-editor-group-label">Erase Part</div>
            <div class="sig-editor-group-controls">
              <button type="button" class="btn btn-secondary" id="sigEraseBtn"><i class="ti ti-eraser"></i> Erase Part</button>
              <button type="button" class="btn btn-secondary" id="sigEraseUndoBtn" style="display:none;"><i class="ti ti-arrow-back-up"></i> Undo Erase</button>
              <label class="label" id="sigEraseSizeLabel" style="margin:0;display:none;">Eraser Size</label>
              <input type="range" id="sigEraseSize" min="4" max="40" value="12" style="display:none;width:90px;">
            </div>
          </div>

          <div class="sig-editor-group">
            <div class="sig-editor-group-label">Remove White Background</div>
            <div class="sig-editor-group-controls">
              <button type="button" class="btn btn-secondary doc-zoom-btn" id="sigThresholdMinusBtn" aria-label="Lower threshold">-</button>
              <input type="range" id="sigBgThreshold" min="0" max="255" value="235" style="width:140px;">
              <button type="button" class="btn btn-secondary doc-zoom-btn" id="sigThresholdPlusBtn" aria-label="Raise threshold">+</button>
              <span class="header-sub" id="sigBgThresholdValue">235</span>
            </div>
          </div>

          <div class="sig-editor-group">
            <div class="sig-editor-group-label">Ink Contrast</div>
            <div class="sig-editor-group-controls">
              <button type="button" class="btn btn-secondary doc-zoom-btn" id="sigInkContrastMinusBtn" aria-label="Lower ink contrast">-</button>
              <input type="range" id="sigInkContrast" min="0" max="100" value="0" style="width:140px;">
              <button type="button" class="btn btn-secondary doc-zoom-btn" id="sigInkContrastPlusBtn" aria-label="Raise ink contrast">+</button>
              <span class="header-sub" id="sigInkContrastValue">0</span>
            </div>
          </div>

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
              <label class="label">Company Name</label>
              <input type="text" class="input" id="sigEditorCompanyInput" placeholder="e.g. PT Lisani Optik" required>
            </div>
            <div class="form-group">
              <label class="label">Origin</label>
              <input type="text" class="input" id="sigEditorOriginInput" placeholder="e.g. Jakarta, Indonesia" required>
            </div>
          </div>
          <div class="header-sub" id="sigEditorCompanyHint" style="display:none;">Menentukan folder penyimpanan di storage/signature/.../, tidak bisa diubah lewat Edit.</div>

          <label class="doc-editor-checkbox" style="margin-top:10px;">
            <input type="checkbox" id="sigEditorMainCheckbox"> Set as Main signature (always on top of the list)
          </label>

          <div id="sigEditorMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="button" class="btn btn-secondary" id="sigCancelEditBtn" style="display:none;">Cancel Edit</button>
            <button type="submit" class="btn btn-primary" id="sigEditorSaveBtn">Save to Library</button>
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

  <!-- Letterhead Library (Stage 9, 24 Oct 2026). 1 entry = header
       (required) + footer (optional). No canvas editor like Signature -
       the user just uploads a ready-made letterhead PNG. See
       PROJECT_NOTES.md §8 / rangkuman_tool_ttd_dokumen.md §5. -->
  <div class="card doctools-collapsible" id="cardLetterheads">
    <button type="button" class="doctools-collapsible-header" id="cardLetterheadsHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Letterhead Library</div>
        <div class="header-sub">Upload a header image (and optionally a footer), then save straight to the library</div>
      </div>
      <i class="ti ti-chevron-down doctools-collapsible-chevron"></i>
    </button>

    <div class="doctools-collapsible-body" id="cardLetterheadsBody">
      <div class="doctools-collapsible-body-inner">

        <form id="lhSaveForm" class="settings-form">
          <div class="settings-form-grid">
            <div class="form-group">
              <label class="label">Letterhead Name</label>
              <input type="text" class="input input-uppercase" id="lhNameInput" placeholder="E.G. OFFICIAL LETTERHEAD 2026" required>
            </div>
            <div class="form-group">
              <label class="label">Company Name</label>
              <input type="text" class="input input-uppercase" id="lhCompanyInput" placeholder="E.G. PT LISANI OPTIK" required>
            </div>
            <div class="form-group">
              <label class="label">Origin</label>
              <input type="text" class="input input-uppercase" id="lhOriginInput" placeholder="E.G. JAKARTA" required>
            </div>
          </div>
          <div class="header-sub" id="lhCompanyHint">Determines the storage folder at storage/letterhead/.../, cannot be changed after saving.</div>

          <div class="settings-form-grid" style="margin-top:10px;">
            <div class="form-group">
              <label class="label">Header Image (required)</label>
              <input type="file" class="input" id="lhHeaderFileInput" accept="image/*" required>
            </div>
            <div class="form-group">
              <label class="label">Footer Image (optional)</label>
              <input type="file" class="input" id="lhFooterFileInput" accept="image/*">
            </div>
          </div>

          <label class="doc-editor-checkbox" style="margin-top:10px;">
            <input type="checkbox" id="lhMainCheckbox"> Set as Main letterhead (always on top of the list)
          </label>

          <div id="lhSaveMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="submit" class="btn btn-primary" id="lhSaveBtn">Save to Library</button>
          </div>
        </form>

        <div class="doc-accordion" id="lhAccordionList" style="margin-top:18px;">
          <div class="empty-sub">Loading...</div>
        </div>

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
              <i class="ti ti-crop"></i> Block: Cut Area
            </button>
            <button type="button" class="btn btn-secondary" id="docGuideBoxBtn" disabled>
              <i class="ti ti-box-margin"></i> Add Guide Box
            </button>
            <select class="select doc-guide-box-select" id="docGuideBoxPresetSelect" disabled aria-label="Guide Box size"></select>
          </div>

          <!-- 24 Okt 2026: garis bantu horizontal/vertikal untuk membantu
               pemposisian - bisa ditambah berkali-kali (tidak dibatasi 1),
               masing-masing bisa digeser bebas sepanjang satu sumbu
               (horizontal hanya naik/turun, vertikal hanya kiri/kanan),
               lihat addGuideLine()/attachGuideLineClamp() di JS. Sama
               seperti Guide Box: hanya alat bantu visual, dikecualikan saat
               flatten/Save (lihat renderPageToDataURL()). -->
          <div class="doc-editor-toolbar-group" title="Guide Lines">
            <button type="button" class="btn btn-secondary" id="docAddHGuideBtn" disabled>
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="12" x2="21" y2="12"/></svg>
              Add Horizontal Guide
            </button>
            <button type="button" class="btn btn-secondary" id="docAddVGuideBtn" disabled>
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="3" x2="12" y2="21"/></svg>
              Add Vertical Guide
            </button>
          </div>

          <!-- 24 Okt 2026: muncul HANYA selagi ada pending block rect (sudah
               digambar, belum di-Apply) - lihat enterPendingAdjustMode() di
               JS. Rect-nya sendiri adalah fabric.Rect biasa yang selectable,
               jadi geser/resize per-sisi pakai kontrol bawaan Fabric, tidak
               perlu hit-testing manual seperti Crop di Signature Editor. -->
          <div class="doc-editor-toolbar-group" id="docBlockAdjustGroup" title="Adjust Block" style="display:none;">
            <span class="header-sub">Drag the box or its handles to adjust, then Apply.</span>
            <button type="button" class="btn btn-primary" id="docBlockApplyBtn">Apply Block</button>
            <button type="button" class="btn btn-secondary" id="docBlockCancelBtn">Cancel Block</button>
          </div>

          <div class="doc-editor-toolbar-group" title="Actions">
            <button type="button" class="btn btn-secondary" id="docInsertSigBtn" disabled>
              <i class="ti ti-signature"></i> Insert Signature
            </button>
            <button type="button" class="btn btn-secondary" id="docInsertLetterheadBtn" disabled>
              <i class="ti ti-file-text"></i> Insert Letterhead
            </button>
            <button type="button" class="btn btn-secondary" id="docUndoBtn" disabled>
              <i class="ti ti-arrow-back-up"></i> Undo
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

<!-- Letterhead picker for Document Editor's "Insert Letterhead" button -
     own overlay/ids, same pattern as docSigPickerOverlay above. -->
<div class="modal-overlay" id="docLhPickerOverlay" style="display:none;">
  <div class="modal">
    <div class="panel-header">
      <div class="panel-title">Insert Letterhead</div>
    </div>
    <!-- 26 Okt 2026: opsi "Apply to all pages" - belum dites user. Default
         CHECKED karena ini yang diminta user jadi perilaku utama; user bisa
         uncheck kalau cuma mau menempel di halaman yang sedang dibuka
         (perilaku lama). Dibaca sekali saat klik item letterhead di bawah,
         lihat insertLetterheadOntoCanvas(). -->
    <label class="settings-checkbox-label" style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
      <input type="checkbox" id="docLhApplyAllPages" checked>
      <span>Apply to all pages</span>
    </label>
    <div class="doc-sig-pick-grid" id="docLhPickerList">
      <div class="empty-sub">Loading...</div>
    </div>
    <div class="settings-form-actions">
      <button type="button" class="btn btn-secondary" id="docLhPickerCancel">Cancel</button>
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

  /* #6 (21 Okt 2026): group wrapper per Company Name di atas
     .doc-accordion-item biasa - sengaja dibuat mandiri (bukan nested
     accordion-item) supaya bisa dibuka/ditutup independen tanpa exclusive
     seperti openSigItem()/closeSigItem() per item di dalamnya. */
  .doc-group {
    border-radius: var(--radius-md, 10px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    overflow: hidden;
  }
  .doc-group + .doc-group { margin-top: 10px; }
  .doc-group-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    background: rgba(255, 255, 255, 0.04);
  }
  .doc-group-title { flex: 1; font-weight: 700; display: flex; align-items: center; }
  .doc-group-chevron { font-size: 18px; transition: transform 0.2s ease; flex-shrink: 0; }
  .doc-group.open .doc-group-chevron { transform: rotate(180deg); }
  .doc-group-body { max-height: 0; overflow: hidden; transition: max-height 0.2s ease; }
  .doc-group-body .doc-accordion { padding: 10px; }

  /* 21 Okt 2026: nama class diganti dari .sig-editor-canvas-wrap jadi
     .sig-canvas-outer/.sig-canvas-zoom-wrap (dedicated, bukan reuse) -
     .sig-editor-canvas-wrap masih dipakai docEditorCanvasWrap di Document
     Editor (digabung dengan .doc-editor-canvas-wrap miliknya sendiri),
     jadi sengaja TIDAK dipakai ulang di sini supaya style Zoom/background
     putih baru TIDAK bocor ke kanvas Document Editor (lihat gotcha soal
     reuse nama class tanpa scope di PROJECT_NOTES.md §3).
     #sigEditorCanvasOuter adalah viewport yang scroll (untuk Zoom In/Out) -
     .sig-canvas-zoom-wrap di dalamnya yang diperbesar via width (%), BUKAN
     transform:scale, supaya koordinat pointer tetap bisa dihitung lewat
     getBoundingClientRect() seperti biasa di canvasPointFromEvent() (scale
     canvas.width/rect.width otomatis menyesuaikan, tidak perlu ubah logic
     crop/erase sama sekali). */
  .sig-canvas-outer {
    width: 100%;
    max-width: 600px;
    /* Container size is independent from the Zoom slider now (21 Okt 2026,
       user request): previously this had no fixed height, so it just grew
       taller along with .sig-canvas-zoom-wrap's zoomed-up width, making the
       container LOOK like it was zooming too. Now it has its own fixed
       height and the user can drag-resize it directly from its bottom-right
       corner (native CSS `resize`, no JS needed) - independent of whatever
       zoom % the canvas inside is at. overflow:auto scrolls to reach any
       part of the canvas that's bigger than this box, both from zoom and
       from manual resize. */
    height: 360px;
    min-width: 260px;
    min-height: 160px;
    resize: both;
    overflow: auto;
    border: 1px dashed rgba(255, 255, 255, 0.18);
    border-radius: var(--radius-md, 10px);
  }
  .sig-canvas-zoom-wrap {
    /* Sized to the canvas's own pixel dimensions (via JS, see
       syncZoomWrapBaseSize()) scaled by zoomPercent - NOT width:100% of the
       outer container anymore, so resizing the outer box no longer stretches
       the canvas, and zooming the canvas no longer resizes the outer box. */
    width: 600px;
    transition: width 0.15s ease, height 0.15s ease;
    /* 21 Okt 2026: diganti dari checkerboard gelap ke putih polos + garis
       kotak-kotak halus (bukan checkerboard transparansi lagi) - kebanyakan
       ttd warnanya hitam, jadi butuh latar terang biar jelas saat crop/erase.
       Garis kotak-kotak di sini murni garis bantu visual (grid), BUKAN
       indikator transparansi lagi. */
    background-color: #ffffff;
    background-image:
      linear-gradient(to right, rgba(0,0,0,0.06) 1px, transparent 1px),
      linear-gradient(to bottom, rgba(0,0,0,0.06) 1px, transparent 1px);
    background-size: 20px 20px;
  }
  #sigEditorCanvas { display: block; width: 100%; height: auto; touch-action: none; cursor: crosshair; }

  .sig-editor-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
  }
  .sig-editor-controls .btn.active { background: var(--primary, #6c5ce7); border-color: transparent; color: #fff; }

  /* 21 Okt 2026: toolbar canvas dirapikan jadi kartu-kartu kecil per grup
     (Photo/Zoom/Crop/Erase Part/Remove White Background) - gantinya baris
     tombol panjang yang nyampur semua fitur jadi satu. */
  .sig-editor-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
  }
  .sig-editor-group {
    flex: 1 1 220px;
    min-width: 200px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-md, 10px);
    background: rgba(255, 255, 255, 0.02);
    padding: 10px 12px;
  }
  .sig-editor-group-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted, #9a9a9a);
    margin-bottom: 8px;
  }
  .sig-editor-group-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
  .sig-editor-group-controls + .sig-editor-group-controls { margin-top: 8px; }
  .sig-editor-group-controls .btn { display: inline-flex; align-items: center; gap: 6px; width: auto; }
  .sig-editor-group-controls .btn.active { background: var(--primary, #6c5ce7); border-color: transparent; color: #fff; }

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
    display: none !important;
  }
  /* Signature picker ("Insert Signature") must out-stack the fullscreen
     workspace itself (z-index 2147483647 above) - it's reparented to
     <body> right after the workspace in setFullscreen() so DOM order also
     puts it on top, but the explicit z-index here is what actually wins
     the stacking fight against theme.css's normal .modal-overlay z-index. */
  #docSigPickerOverlay.modal-overlay {
    z-index: 2147483647 !important;
  }
  /* Same fix, same reason, for the Letterhead picker (Stage 9). Missing
     this rule is exactly why "Insert Letterhead" appeared to do nothing
     while in Fullscreen (25 Oct 2026 bug report) - the overlay WAS being
     reparented to <body> in setFullscreen() (see docLhPickerOriginalParent
     below), but without this explicit z-index it still lost the stacking
     fight against theme.css's normal .modal-overlay z-index, same as the
     signature picker did before this rule existed for it. */
  #docLhPickerOverlay.modal-overlay {
    z-index: 2147483647 !important;
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
     SIGNATURE LIBRARY (merged with the former Signature Editor, 21 Okt
     2026 - see the HTML comment above #cardSignatures)
     ===================================================================== */
  var sigEditorNameInput = document.getElementById('sigEditorNameInput');
  var sigEditorDateInput = document.getElementById('sigEditorDateInput');
  // #4 (21 Okt 2026): default Date = hari ini saat kartu baru dibuka (bukan
  // cuma saat reset setelah Save/Cancel - lihat todayDateInputValue() di
  // bawah untuk versi yang dipakai ulang oleh resetSignatureForm()).
  (function setInitialDateDefault() {
    var now = new Date();
    var y = now.getFullYear();
    var m = String(now.getMonth() + 1).padStart(2, '0');
    var d = String(now.getDate()).padStart(2, '0');
    sigEditorDateInput.value = y + '-' + m + '-' + d;
  })();
  var sigEditorCompanyInput = document.getElementById('sigEditorCompanyInput');
  var sigEditorOriginInput = document.getElementById('sigEditorOriginInput');
  var sigEditorCompanyHint = document.getElementById('sigEditorCompanyHint');
  var sigEditorMainCheckbox = document.getElementById('sigEditorMainCheckbox');
  var sigEditorSaveForm = document.getElementById('sigEditorSaveForm');
  var sigEditorMessage = document.getElementById('sigEditorMessage');
  var sigEditorSaveBtn = document.getElementById('sigEditorSaveBtn');
  var sigCancelEditBtn = document.getElementById('sigCancelEditBtn');
  var sigAccordionList = document.getElementById('sigAccordionList');
  var sigSelectAll = document.getElementById('sigSelectAll');
  var sigSelectedCount = document.getElementById('sigSelectedCount');
  var sigShareBtn = document.getElementById('sigShareBtn');

  var editingSignatureId = null;
  var signaturesCache = [];

  function yearFromDateInput(value) {
    if (!value) return CURRENT_YEAR_FALLBACK;
    var y = parseInt(value.split('-')[0], 10);
    return isNaN(y) ? CURRENT_YEAR_FALLBACK : y;
  }

  // #4 (21 Okt 2026): default Date ke hari ini (local time, bukan UTC) tiap
  // form di-reset (buka pertama kali / Save selesai / Cancel Edit) - hanya
  // dipakai saat ADD, openEditSignatureForm() tetap override dengan
  // sig.signature_date yang sesungguhnya.
  function todayDateInputValue() {
    var now = new Date();
    var y = now.getFullYear();
    var m = String(now.getMonth() + 1).padStart(2, '0');
    var d = String(now.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function resetSignatureForm() {
    sigEditorSaveForm.reset();
    editingSignatureId = null;
    sigEditorCompanyInput.disabled = false;
    sigEditorOriginInput.disabled = false;
    sigEditorCompanyHint.style.display = 'none';
    sigEditorSaveBtn.textContent = 'Save to Library';
    sigCancelEditBtn.style.display = 'none';
    sigEditorMessage.textContent = '';
    sigEditorMessage.className = 'settings-message';
    clearEditorCanvas();
    sigEditorDateInput.value = todayDateInputValue();
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

  // #6: tiap item buka/tutup, grup induknya (.doc-group-body) juga perlu
  // re-sync max-height, sama seperti gotcha #2 di PROJECT_NOTES §3 (isi
  // collapsible berubah saat terbuka -> re-sync max-height-nya).
  function resyncParentGroupHeight(item) {
    var group = item.closest('.doc-group');
    if (!group || !group.classList.contains('open')) return;
    var groupBody = group.querySelector('.doc-group-body');
    groupBody.style.maxHeight = groupBody.scrollHeight + 'px';
  }

  function closeSigItem(item) {
    item.classList.remove('open');
    var body = item.querySelector('.doc-accordion-body');
    body.style.maxHeight = null;
    resyncParentGroupHeight(item);
  }

  function openSigItem(item) {
    sigAccordionList.querySelectorAll('.doc-accordion-item').forEach(function (other) {
      if (other !== item) closeSigItem(other);
    });
    item.classList.add('open');
    var body = item.querySelector('.doc-accordion-body');
    body.style.maxHeight = body.scrollHeight + 'px';
    resyncParentGroupHeight(item);
  }

  // #6 (21 Okt 2026): satu signature accordion-item, dipisah dari
  // renderSignatureList() supaya bisa dipakai ulang per grup Company.
  function buildSigAccordionItem(sig) {
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
    if (sig.is_main == 1 || sig.is_main === true) {
      var mainBadge = document.createElement('span');
      mainBadge.className = 'badge badge-success';
      mainBadge.style.marginLeft = '8px';
      mainBadge.textContent = 'MAIN';
      name.appendChild(mainBadge);
    }

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

    var rowCompany = document.createElement('div');
    rowCompany.className = 'doc-meta-row';
    rowCompany.innerHTML = '<span class="doc-meta-label">Company Name</span><span>' +
      escapeHtml(sig.company_name) + '</span>';

    var rowOrigin = document.createElement('div');
    rowOrigin.className = 'doc-meta-row';
    rowOrigin.innerHTML = '<span class="doc-meta-label">Origin</span><span>' +
      escapeHtml(sig.origin) + '</span>';

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
    bodyInner.appendChild(rowCompany);
    bodyInner.appendChild(rowOrigin);
    bodyInner.appendChild(rowFile);
    bodyInner.appendChild(actions);
    body.appendChild(bodyInner);

    item.appendChild(header);
    item.appendChild(body);
    return item;
  }

  function closeSigGroup(group) {
    group.classList.remove('open');
    var body = group.querySelector('.doc-group-body');
    body.style.maxHeight = null;
  }

  function openSigGroup(group) {
    group.classList.add('open');
    var body = group.querySelector('.doc-group-body');
    body.style.maxHeight = body.scrollHeight + 'px';
  }

  // #6 (21 Okt 2026): list dikelompokkan per Company Name - header grup
  // collapsible (dibuka/ditutup independen per grup, bukan exclusive
  // seperti accordion item di dalamnya), signature-signature di bawahnya.
  // Urutan grup & urutan dalam grup MENGIKUTI urutan dari signaturesCache
  // apa adanya (sudah terurut is_main DESC, signature_date DESC dari
  // list_signatures.php) - group-by di sini cuma mengelompokkan ulang
  // tampilannya, TIDAK mengubah urutan/prioritas Main yang sudah ada.
  function renderSignatureList() {
    sigAccordionList.innerHTML = '';
    if (signaturesCache.length === 0) {
      sigAccordionList.innerHTML = '<div class="empty-sub">No signatures yet.</div>';
      updateSigSelectionUI();
      return;
    }

    var groupOrder = [];
    var groupMap = {};
    signaturesCache.forEach(function (sig) {
      var key = sig.company_name || '';
      if (!groupMap[key]) {
        groupMap[key] = [];
        groupOrder.push(key);
      }
      groupMap[key].push(sig);
    });

    groupOrder.forEach(function (companyName, groupIndex) {
      var sigsInGroup = groupMap[companyName];

      var group = document.createElement('div');
      group.className = 'doc-group';

      var groupHeader = document.createElement('div');
      groupHeader.className = 'doc-group-header';

      var groupTitle = document.createElement('div');
      groupTitle.className = 'doc-group-title';
      groupTitle.textContent = companyName || '(No Company Name)';

      var groupCount = document.createElement('span');
      groupCount.className = 'badge';
      groupCount.style.marginLeft = '8px';
      groupCount.textContent = sigsInGroup.length;
      groupTitle.appendChild(groupCount);

      var groupChevron = document.createElement('i');
      groupChevron.className = 'ti ti-chevron-down doc-group-chevron';

      groupHeader.appendChild(groupTitle);
      groupHeader.appendChild(groupChevron);
      groupHeader.addEventListener('click', function () {
        if (group.classList.contains('open')) {
          closeSigGroup(group);
        } else {
          openSigGroup(group);
        }
        refreshOpenHeight(document.getElementById('cardSignatures'));
      });

      var groupBody = document.createElement('div');
      groupBody.className = 'doc-group-body';

      var groupBodyInner = document.createElement('div');
      groupBodyInner.className = 'doc-accordion';

      sigsInGroup.forEach(function (sig) {
        groupBodyInner.appendChild(buildSigAccordionItem(sig));
      });

      groupBody.appendChild(groupBodyInner);
      group.appendChild(groupHeader);
      group.appendChild(groupBody);
      sigAccordionList.appendChild(group);

      // First group open by default so the list doesn't look empty on load;
      // the rest start collapsed (same spirit as single accordion items).
      if (groupIndex === 0) openSigGroup(group);
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
    sigEditorNameInput.value = sig.signature_name;
    sigEditorDateInput.value = sig.signature_date;
    // Company Name/Origin menentukan folder di disk - belum ada fitur
    // pindah folder, jadi saat edit cuma ditampilkan, tidak bisa diubah.
    sigEditorCompanyInput.value = sig.company_name;
    sigEditorOriginInput.value = sig.origin;
    sigEditorCompanyInput.disabled = true;
    sigEditorOriginInput.disabled = true;
    sigEditorCompanyHint.style.display = 'block';
    sigEditorMainCheckbox.checked = (sig.is_main == 1 || sig.is_main === true);
    clearEditorCanvas();
    sigEditorSaveBtn.textContent = 'Update Signature';
    sigCancelEditBtn.style.display = 'inline-flex';
    sigEditorMessage.textContent = '';
    sigEditorMessage.className = 'settings-message';
    sigEditorNameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

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
     SIGNATURE EDITOR (canvas) - upload a photo of a signature, Crop to
     the relevant area, Erase Part to wipe stray marks, and remove the
     white/paper background with a threshold slider (+/- step buttons
     alongside it), then save straight to the library via
     ajax/upload_signature.php (canvas exported as a PNG Blob, sent as
     the 'file' field). The "Draw" tab that used to live here was
     dropped entirely (21 Okt 2026 - user said it's not needed).
     This same form/canvas doubles as the Edit form for an existing
     signature's name/date/Main flag - see openEditSignatureForm() above
     and the submit handler below. ---- */
  var editorCanvas = document.getElementById('sigEditorCanvas');
  var editorCtx = editorCanvas.getContext('2d');
  var editorFileInput = document.getElementById('sigEditorFileInput');
  var cropBtn = document.getElementById('sigCropBtn');
  var cropUndoBtn = document.getElementById('sigCropUndoBtn');
  var cropControls = document.getElementById('sigEditorCropControls');
  var cropApplyBtn = document.getElementById('sigCropApplyBtn');
  var cropCancelBtn = document.getElementById('sigCropCancelBtn');
  var eraseBtn = document.getElementById('sigEraseBtn');
  var eraseUndoBtn = document.getElementById('sigEraseUndoBtn');
  var eraseSizeLabel = document.getElementById('sigEraseSizeLabel');
  var eraseSizeInput = document.getElementById('sigEraseSize');
  var bgThresholdInput = document.getElementById('sigBgThreshold');
  var bgThresholdValue = document.getElementById('sigBgThresholdValue');
  var thresholdMinusBtn = document.getElementById('sigThresholdMinusBtn');
  var thresholdPlusBtn = document.getElementById('sigThresholdPlusBtn');
  var inkContrastInput = document.getElementById('sigInkContrast');
  var inkContrastValue = document.getElementById('sigInkContrastValue');
  var inkContrastMinusBtn = document.getElementById('sigInkContrastMinusBtn');
  var inkContrastPlusBtn = document.getElementById('sigInkContrastPlusBtn');
  var clearUploadBtn = document.getElementById('sigClearUploadBtn');
  var canvasOuter = document.getElementById('sigEditorCanvasOuter');
  var canvasWrap = document.getElementById('sigEditorCanvasWrap');
  var zoomInBtn = document.getElementById('sigZoomInBtn');
  var zoomOutBtn = document.getElementById('sigZoomOutBtn');
  var zoomResetBtn = document.getElementById('sigZoomResetBtn');
  var zoomSlider = document.getElementById('sigZoomSlider');
  var zoomLevelLabel = document.getElementById('sigZoomLevel');

  var DEFAULT_CANVAS_W = 600, DEFAULT_CANVAS_H = 220;
  var hasContent = false;
  // Full-resolution pixel data of the current (post-crop) photo, before
  // threshold/erase are applied, so both can always re-derive from this
  // instead of degrading an already-processed image.
  var originalPhotoImageData = null;
  // Parallel 1-byte-per-pixel mask (same w*h as originalPhotoImageData):
  // 1 = user erased this pixel with "Erase Part", kept transparent no
  // matter what the threshold slider says.
  var erasedMask = null;

  var cropMode = false, cropDragging = false, cropStart = null, cropRect = null;
  // #2 (21 Okt 2026): crop box sekarang bisa diedit lewat 8 resize handle
  // (sudut+tepi) atau digeser (drag dari tengah) SEBELUM Apply Crop - bukan
  // cuma gambar ulang kotak baru tiap drag. cropHandleHit menyimpan handle
  // mana yang sedang di-drag ('nw','n','ne','e','se','s','sw','w','move')
  // atau null kalau sedang menggambar kotak baru dari nol.
  var cropHandleHit = null;
  var cropDragOffset = null; // dipakai saat handle === 'move': {dx, dy} dari pojok kiri-atas kotak
  var CROP_HANDLE_SIZE = 10; // radius deteksi handle dalam px canvas
  // Snapshot untuk Undo Crop (#2): simpan originalPhotoImageData+erasedMask+
  // ukuran canvas SEBELUM Apply Crop memotongnya, supaya bisa dikembalikan
  // 1 langkah. Hanya menyimpan 1 langkah terakhir (bukan history berlapis) -
  // Crop jarang dipakai berkali-kali berturut-turut seperti Erase, jadi
  // tetap 1 langkah (beda sengaja dari Erase yang sekarang history stack).
  var cropUndoSnapshot = null;

  // Zoom In/Out (21 Okt 2026) - murni ukuran TAMPILAN canvas (CSS width%
  // dari .sig-canvas-zoom-wrap di dalam viewport scroll
  // #sigEditorCanvasOuter), TIDAK mengubah resolusi pixel canvas asli
  // (editorCanvas.width/height tetap sama) - jadi tidak memengaruhi
  // originalPhotoImageData/erasedMask atau hasil Crop/Erase sama sekali.
  var ZOOM_MIN = 50, ZOOM_MAX = 1000, ZOOM_STEP = 25;
  var zoomPercent = 100;

  var eraseMode = false, eraseDragging = false;
  // #3 (21 Okt 2026, direvisi lagi 21 Okt 2026 jadi HISTORY STACK - user
  // minta Undo Erase bisa dipakai berkali-kali, bukan cuma 1 langkah):
  // tiap stroke brush (dari pointerdown sampai pointerup/leave) push 1
  // snapshot erasedMask SEBELUM stroke itu ke eraseUndoStack. Tiap klik
  // Undo Erase pop 1 snapshot dan pasang balik sebagai erasedMask, jadi
  // bisa mundur selangkah demi selangkah sampai stack habis. Direset
  // (dikosongkan) oleh clearEditorCanvas() dan saat upload foto baru -
  // tidak masuk akal mundur ke stroke dari foto yang berbeda.
  var eraseUndoStack = [];

  function resetCanvasSize() {
    editorCanvas.width = DEFAULT_CANVAS_W;
    editorCanvas.height = DEFAULT_CANVAS_H;
  }

  function clearEditorCanvas() {
    resetCanvasSize();
    editorCtx.clearRect(0, 0, editorCanvas.width, editorCanvas.height);
    hasContent = false;
    originalPhotoImageData = null;
    erasedMask = null;
    editorFileInput.value = '';
    cropUndoSnapshot = null;
    eraseUndoStack = [];
    updateCropUndoBtn();
    updateEraseUndoBtn();
    exitCropMode();
    setEraseMode(false);
    setZoomPercent(100);
  }

  function updateCropUndoBtn() {
    cropUndoBtn.style.display = cropUndoSnapshot ? 'inline-flex' : 'none';
  }
  // #3: tombol Undo Erase tetap kelihatan selama stack masih ada isinya
  // (bisa diklik berkali-kali), baru hilang saat stack kosong.
  function updateEraseUndoBtn() {
    eraseUndoBtn.style.display = eraseUndoStack.length > 0 ? 'inline-flex' : 'none';
  }

  /* ---- Zoom In/Out: ubah width (px) .sig-canvas-zoom-wrap, canvas pixel
     asli tidak berubah (lihat komentar di deklarasi zoomPercent di atas).
     canvasPointFromEvent() otomatis tetap akurat karena masih pakai rasio
     canvas.width/rect.width.
     21 Okt 2026 (revisi "container tidak ikut zoom"): sebelumnya width
     wrap dihitung SEBAGAI PERSENTASE DARI CONTAINER (#sigEditorCanvasOuter,
     width:100%), jadi membesarkan zoom otomatis "mendorong" lebar efektif
     konten melebihi container lalu meluap lewat scroll - keliatannya
     seperti container ikut membesar/mengecil mengikuti zoom. Sekarang
     container (.sig-canvas-outer) punya ukuran sendiri yang TETAP (fixed
     height + resizable manual via CSS `resize:both`, lihat <style> di
     atas), dan wrap dihitung sebagai PIXEL ABSOLUT = lebar asli canvas
     (editorCanvas.width) × zoomPercent/100 - jadi item (canvas) yang
     membesar/mengecil saat zoom, container-nya diam, cukup di-scroll
     (overflow:auto) kalau item lebih besar dari container. */
  // syncSlider=false dipakai saat dipanggil DARI slider's own 'input' event
  // itu sendiri, supaya tidak muter balik set value ke slider yang sedang
  // digeser user (percuma, tapi aman) - selain itu (tombol -/+/Reset, atau
  // clearEditorCanvas()) selalu true supaya slider ikut pindah posisinya.
  function setZoomPercent(pct, syncSlider) {
    zoomPercent = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, pct));
    var baseW = editorCanvas.width || DEFAULT_CANVAS_W;
    canvasWrap.style.width = Math.round(baseW * zoomPercent / 100) + 'px';
    zoomLevelLabel.textContent = zoomPercent + '%';
    if (syncSlider !== false) zoomSlider.value = zoomPercent;
  }
  zoomInBtn.addEventListener('click', function () { setZoomPercent(zoomPercent + ZOOM_STEP); });
  zoomOutBtn.addEventListener('click', function () { setZoomPercent(zoomPercent - ZOOM_STEP); });
  zoomResetBtn.addEventListener('click', function () { setZoomPercent(100); });
  // Slider: diatur bebas (bukan cuma step tombol -/+/Reset) - drag ke
  // persentase berapa pun antara 50%-1000% langsung diterapkan.
  zoomSlider.addEventListener('input', function () {
    setZoomPercent(parseInt(zoomSlider.value, 10) || 100, false);
  });

  /* ---- Render = threshold (near-white -> transparent) + erase mask,
     always re-derived from originalPhotoImageData so neither step
     degrades the image when repeated. ---- */
  /* ---- #Ink Contrast (21 Okt 2026): slider terpisah dari threshold
     background. threshold hanya menentukan transparan/tidak; slider ini
     menebalkan pixel TINTA (yang lolos/tidak-transparan) yang sering
     pudar/abu-abu dari hasil foto - sekaligus 2 efek sesuai permintaan
     user ("keduanya saja"): (a) RGB didorong ke arah hitam (0,0,0)
     sebanding jarak dari putih, (b) alpha didorong ke arah 255 (solid).
     factor 0 = tidak berubah sama sekali (default, backward-compatible
     dengan behavior lama), factor 1 = RGB jadi hitam pekat + alpha solid.
     Selalu dihitung ulang dari originalPhotoImageData (non-destructive,
     pola sama seperti threshold - lihat komentar di atas). ---- */
  function renderEditor() {
    if (!originalPhotoImageData) return;
    var threshold = parseInt(bgThresholdInput.value, 10);
    var inkFactor = (parseInt(inkContrastInput.value, 10) || 0) / 100;
    var src = originalPhotoImageData.data;
    var w = originalPhotoImageData.width, h = originalPhotoImageData.height;
    var out = editorCtx.createImageData(w, h);
    var dst = out.data;
    for (var i = 0, p = 0; i < src.length; i += 4, p++) {
      var r = src[i], g = src[i + 1], b = src[i + 2], a = src[i + 3];
      var transparent = (r >= threshold && g >= threshold && b >= threshold) ||
        (erasedMask && erasedMask[p] === 1);
      if (!transparent && inkFactor > 0) {
        r = r * (1 - inkFactor);
        g = g * (1 - inkFactor);
        b = b * (1 - inkFactor);
        a = a + (255 - a) * inkFactor;
      }
      dst[i] = r; dst[i + 1] = g; dst[i + 2] = b;
      dst[i + 3] = transparent ? 0 : a;
    }
    editorCtx.putImageData(out, 0, 0);
  }

  function canvasPointFromEvent(e) {
    var rect = editorCanvas.getBoundingClientRect();
    var scaleX = editorCanvas.width / rect.width;
    var scaleY = editorCanvas.height / rect.height;
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY,
    };
  }

  /* ---- Upload Photo: draw the photo onto the canvas (fit/center),
     keep its raw pixels in originalPhotoImageData. ---- */
  editorFileInput.addEventListener('change', function () {
    var file = editorFileInput.files && editorFileInput.files[0];
    if (!file) return;

    var img = new Image();
    img.onload = function () {
      resetCanvasSize();
      exitCropMode();
      setEraseMode(false);
      var cw = editorCanvas.width, ch = editorCanvas.height;
      editorCtx.clearRect(0, 0, cw, ch);
      var scale = Math.min(cw / img.width, ch / img.height);
      var w = img.width * scale, h = img.height * scale;
      var x = (cw - w) / 2, y = (ch - h) / 2;
      editorCtx.drawImage(img, x, y, w, h);
      originalPhotoImageData = editorCtx.getImageData(0, 0, cw, ch);
      erasedMask = new Uint8Array(cw * ch);
      hasContent = true;
      cropUndoSnapshot = null;
      eraseUndoStack = [];
      updateCropUndoBtn();
      updateEraseUndoBtn();
      renderEditor();
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(file);
  });

  clearUploadBtn.addEventListener('click', clearEditorCanvas);

  /* ---- #4: Remove White Background threshold, with +/- step buttons
     alongside the slider so it can be nudged without dragging. ---- */
  bgThresholdInput.addEventListener('input', function () {
    bgThresholdValue.textContent = bgThresholdInput.value;
    renderEditor();
  });
  function stepThreshold(delta) {
    var next = (parseInt(bgThresholdInput.value, 10) || 0) + delta;
    next = Math.max(0, Math.min(255, next));
    bgThresholdInput.value = next;
    bgThresholdValue.textContent = next;
    renderEditor();
  }
  thresholdMinusBtn.addEventListener('click', function () { stepThreshold(-5); });
  thresholdPlusBtn.addEventListener('click', function () { stepThreshold(5); });

  /* ---- Ink Contrast: slider terpisah untuk menebalkan warna tinta
     (lihat komentar di renderEditor()), pola event sama seperti
     threshold di atas. ---- */
  inkContrastInput.addEventListener('input', function () {
    inkContrastValue.textContent = inkContrastInput.value;
    renderEditor();
  });
  function stepInkContrast(delta) {
    var next = (parseInt(inkContrastInput.value, 10) || 0) + delta;
    next = Math.max(0, Math.min(100, next));
    inkContrastInput.value = next;
    inkContrastValue.textContent = next;
    renderEditor();
  }
  inkContrastMinusBtn.addEventListener('click', function () { stepInkContrast(-5); });
  inkContrastPlusBtn.addEventListener('click', function () { stepInkContrast(5); });

  /* ---- #3: Erase Part - brush that permanently marks pixels transparent
     via erasedMask, independent of the threshold slider. Undo Erase (21 Okt
     2026) restores the mask to how it was right before the last stroke. ---- */
  function setEraseMode(on) {
    eraseMode = on;
    eraseBtn.classList.toggle('active', on);
    eraseSizeLabel.style.display = on ? 'inline' : 'none';
    eraseSizeInput.style.display = on ? 'inline-block' : 'none';
    if (on) exitCropMode();
  }
  eraseBtn.addEventListener('click', function () { setEraseMode(!eraseMode); });

  function eraseAt(x, y) {
    if (!erasedMask) return;
    var w = editorCanvas.width, h = editorCanvas.height;
    var radius = parseInt(eraseSizeInput.value, 10) || 10;
    var minX = Math.max(0, Math.floor(x - radius)), maxX = Math.min(w - 1, Math.ceil(x + radius));
    var minY = Math.max(0, Math.floor(y - radius)), maxY = Math.min(h - 1, Math.ceil(y + radius));
    for (var py = minY; py <= maxY; py++) {
      for (var px = minX; px <= maxX; px++) {
        var dx = px - x, dy = py - y;
        if (dx * dx + dy * dy <= radius * radius) erasedMask[py * w + px] = 1;
      }
    }
  }

  // #3: pop 1 snapshot dari stack tiap klik - bisa dipakai berkali-kali
  // berturut-turut sampai stack kosong (mundur ke kondisi sebelum Erase
  // Part pertama kali dipakai di foto ini).
  eraseUndoBtn.addEventListener('click', function () {
    if (eraseUndoStack.length === 0 || !erasedMask) return;
    erasedMask = eraseUndoStack.pop();
    updateEraseUndoBtn();
    renderEditor();
  });

  /* ---- #2: Crop - drag a rectangle, then adjust it via 8 resize handles
     (corners/edges) or move it by dragging inside, before Apply Crop trims
     the canvas (and both originalPhotoImageData + erasedMask) down to that
     area. Undo Crop (21 Okt 2026) restores the pre-crop snapshot. ---- */
  function enterCropMode() {
    if (!hasContent) return;
    cropMode = true;
    cropRect = null;
    setEraseMode(false);
    cropBtn.classList.add('active');
    cropControls.style.display = 'flex';
  }
  function exitCropMode() {
    cropMode = false;
    cropDragging = false;
    cropHandleHit = null;
    cropDragOffset = null;
    cropStart = null;
    cropRect = null;
    cropBtn.classList.remove('active');
    cropControls.style.display = 'none';
    editorCanvas.style.cursor = 'crosshair';
    if (hasContent) renderEditor();
  }
  cropBtn.addEventListener('click', function () {
    if (cropMode) { exitCropMode(); } else { enterCropMode(); }
  });
  cropCancelBtn.addEventListener('click', exitCropMode);

  cropUndoBtn.addEventListener('click', function () {
    if (!cropUndoSnapshot) return;
    editorCanvas.width = cropUndoSnapshot.w;
    editorCanvas.height = cropUndoSnapshot.h;
    originalPhotoImageData = cropUndoSnapshot.imageData;
    erasedMask = cropUndoSnapshot.mask;
    cropUndoSnapshot = null;
    updateCropUndoBtn();
    exitCropMode();
    renderEditor();
  });

  function normalizedCropRect() {
    if (!cropRect) return null;
    var x = Math.min(cropRect.x, cropRect.x + cropRect.w);
    var y = Math.min(cropRect.y, cropRect.y + cropRect.h);
    return { x: x, y: y, w: Math.abs(cropRect.w), h: Math.abs(cropRect.h) };
  }

  // Returns which handle (if any) the point is over, for an existing
  // cropRect: 'nw'|'n'|'ne'|'e'|'se'|'s'|'sw'|'w' (edge/corner resize) or
  // 'move' (inside the box, not near an edge).
  function hitTestCropHandle(p) {
    var r = normalizedCropRect();
    if (!r) return null;
    var hs = CROP_HANDLE_SIZE;
    var left = r.x, right = r.x + r.w, top = r.y, bottom = r.y + r.h;
    var midX = r.x + r.w / 2, midY = r.y + r.h / 2;
    var corners = [
      { id: 'nw', x: left, y: top }, { id: 'ne', x: right, y: top },
      { id: 'sw', x: left, y: bottom }, { id: 'se', x: right, y: bottom },
      { id: 'n', x: midX, y: top }, { id: 's', x: midX, y: bottom },
      { id: 'w', x: left, y: midY }, { id: 'e', x: right, y: midY },
    ];
    for (var i = 0; i < corners.length; i++) {
      var c = corners[i];
      if (Math.abs(p.x - c.x) <= hs && Math.abs(p.y - c.y) <= hs) return c.id;
    }
    if (p.x >= left && p.x <= right && p.y >= top && p.y <= bottom) return 'move';
    return null;
  }

  function cursorForHandle(h) {
    switch (h) {
      case 'nw': case 'se': return 'nwse-resize';
      case 'ne': case 'sw': return 'nesw-resize';
      case 'n': case 's': return 'ns-resize';
      case 'e': case 'w': return 'ew-resize';
      case 'move': return 'move';
      default: return 'crosshair';
    }
  }

  function clampCropRectToCanvas() {
    if (!cropRect) return;
    var r = normalizedCropRect();
    r.x = Math.max(0, Math.min(r.x, editorCanvas.width - 1));
    r.y = Math.max(0, Math.min(r.y, editorCanvas.height - 1));
    r.w = Math.max(4, Math.min(r.w, editorCanvas.width - r.x));
    r.h = Math.max(4, Math.min(r.h, editorCanvas.height - r.y));
    cropRect = r;
  }

  function drawCropOverlay() {
    renderEditor();
    var r = normalizedCropRect();
    if (!r) return;
    editorCtx.save();
    editorCtx.strokeStyle = '#6c5ce7';
    editorCtx.lineWidth = 2;
    editorCtx.setLineDash([6, 4]);
    editorCtx.strokeRect(r.x, r.y, r.w, r.h);
    editorCtx.setLineDash([]);
    // Resize handles, drawn as small filled squares on every corner/edge
    // midpoint so the user can see exactly where to grab.
    editorCtx.fillStyle = '#6c5ce7';
    var hs = 7;
    var left = r.x, right = r.x + r.w, top = r.y, bottom = r.y + r.h;
    var midX = r.x + r.w / 2, midY = r.y + r.h / 2;
    var pts = [[left, top], [right, top], [left, bottom], [right, bottom], [midX, top], [midX, bottom], [left, midY], [right, midY]];
    pts.forEach(function (pt) {
      editorCtx.fillRect(pt[0] - hs / 2, pt[1] - hs / 2, hs, hs);
    });
    editorCtx.restore();
  }

  cropApplyBtn.addEventListener('click', function () {
    var r = normalizedCropRect();
    if (!r || r.w < 4 || r.h < 4 || !originalPhotoImageData) {
      exitCropMode();
      return;
    }
    // Snapshot BEFORE cropping so Undo Crop can restore it.
    cropUndoSnapshot = {
      w: editorCanvas.width,
      h: editorCanvas.height,
      imageData: originalPhotoImageData,
      mask: erasedMask,
    };
    updateCropUndoBtn();

    var w = editorCanvas.width;
    var srcData = originalPhotoImageData.data;
    var sx = Math.round(r.x), sy = Math.round(r.y);
    var newW = Math.round(r.w), newH = Math.round(r.h);
    var newImgData = editorCtx.createImageData(newW, newH);
    var newMask = new Uint8Array(newW * newH);
    for (var row = 0; row < newH; row++) {
      for (var col = 0; col < newW; col++) {
        var srcX = sx + col, srcY = sy + row;
        var srcIdx = (srcY * w + srcX) * 4;
        var dstIdx = (row * newW + col) * 4;
        newImgData.data[dstIdx] = srcData[srcIdx];
        newImgData.data[dstIdx + 1] = srcData[srcIdx + 1];
        newImgData.data[dstIdx + 2] = srcData[srcIdx + 2];
        newImgData.data[dstIdx + 3] = srcData[srcIdx + 3];
        newMask[row * newW + col] = erasedMask[srcY * w + srcX];
      }
    }
    editorCanvas.width = newW;
    editorCanvas.height = newH;
    originalPhotoImageData = newImgData;
    erasedMask = newMask;
    // Canvas's native pixel size just changed (crop) - refresh the zoom
    // wrap's px width so it still matches zoomPercent of the NEW size,
    // not the pre-crop one.
    setZoomPercent(zoomPercent);
    exitCropMode();
    renderEditor();
  });

  /* ---- Shared pointer handlers: branch by mode (crop draw/resize/move,
     or erase brush); plain Upload mode with neither active does nothing. ---- */
  editorCanvas.addEventListener('pointerdown', function (e) {
    if (!hasContent) return;
    var p = canvasPointFromEvent(e);
    if (cropMode) {
      var hit = cropRect ? hitTestCropHandle(p) : null;
      if (hit) {
        // Adjusting an existing box: resize from a handle, or move it.
        cropHandleHit = hit;
        cropDragging = true;
        var r = normalizedCropRect();
        if (hit === 'move') {
          cropDragOffset = { dx: p.x - r.x, dy: p.y - r.y, w: r.w, h: r.h };
        } else {
          cropStart = { x: r.x, y: r.y, w: r.w, h: r.h };
        }
      } else {
        // No box yet, or clicked outside it: start a brand-new box.
        cropHandleHit = null;
        cropDragging = true;
        cropStart = p;
        cropRect = { x: p.x, y: p.y, w: 0, h: 0 };
      }
      editorCanvas.setPointerCapture(e.pointerId);
      drawCropOverlay();
    } else if (eraseMode) {
      // New stroke starting: push the mask-before-this-stroke onto the
      // undo stack (once per stroke, not per dab), so each Undo Erase
      // click reverts exactly one whole stroke, repeatable.
      if (erasedMask) {
        eraseUndoStack.push(erasedMask.slice());
        // Batasi stack supaya tidak numpuk memori tanpa batas kalau user
        // erase berkali-kali sekali duduk - 30 langkah mundur sudah lebih
        // dari cukup untuk kebutuhan koreksi normal.
        if (eraseUndoStack.length > 30) eraseUndoStack.shift();
      }
      updateEraseUndoBtn();
      eraseDragging = true;
      eraseAt(p.x, p.y);
      renderEditor();
      editorCanvas.setPointerCapture(e.pointerId);
    }
  });

  editorCanvas.addEventListener('pointermove', function (e) {
    var p = canvasPointFromEvent(e);
    if (cropMode && cropDragging) {
      if (cropHandleHit === 'move' && cropDragOffset) {
        var newX = p.x - cropDragOffset.dx, newY = p.y - cropDragOffset.dy;
        cropRect = { x: newX, y: newY, w: cropDragOffset.w, h: cropDragOffset.h };
        clampCropRectToCanvas();
      } else if (cropHandleHit && cropStart) {
        // Resize from the grabbed handle; opposite edge(s) stay fixed.
        var left = cropStart.x, top = cropStart.y, right = cropStart.x + cropStart.w, bottom = cropStart.y + cropStart.h;
        if (cropHandleHit.indexOf('w') !== -1) left = p.x;
        if (cropHandleHit.indexOf('e') !== -1) right = p.x;
        if (cropHandleHit.indexOf('n') !== -1) top = p.y;
        if (cropHandleHit.indexOf('s') !== -1) bottom = p.y;
        cropRect = { x: left, y: top, w: right - left, h: bottom - top };
      } else if (cropStart) {
        // Drawing a brand-new box from scratch.
        cropRect = {
          x: Math.min(cropStart.x, p.x),
          y: Math.min(cropStart.y, p.y),
          w: Math.abs(p.x - cropStart.x),
          h: Math.abs(p.y - cropStart.y),
        };
      }
      drawCropOverlay();
    } else if (cropMode && !cropDragging) {
      var hit = cropRect ? hitTestCropHandle(p) : null;
      editorCanvas.style.cursor = cursorForHandle(hit);
    } else if (eraseMode && eraseDragging) {
      eraseAt(p.x, p.y);
      renderEditor();
    }
  });

  function stopPointerAction() {
    if (cropDragging && cropRect) clampCropRectToCanvas();
    cropDragging = false;
    cropHandleHit = null;
    cropDragOffset = null;
    eraseDragging = false;
  }
  editorCanvas.addEventListener('pointerup', stopPointerAction);
  editorCanvas.addEventListener('pointercancel', stopPointerAction);
  editorCanvas.addEventListener('pointerleave', stopPointerAction);

  /* ---- Save to Library / Update: create via upload_signature.php when
     adding a brand-new signature (canvas content required), or update
     name/date/Main via update_signature.php when editingSignatureId is
     set (no canvas/file involved - same restriction as before: Company
     Name/Origin can't be changed here because they determine the folder
     on disk). ---- */
  sigEditorSaveForm.addEventListener('submit', function (e) {
    e.preventDefault();
    sigEditorMessage.textContent = '';
    sigEditorMessage.className = 'settings-message';

    if (editingSignatureId) {
      var sigId = editingSignatureId;
      openConfirmModal(
        'Confirm Edit',
        'You are about to update this signature\'s name/date/Main flag. Enter your password to continue.',
        function () {
          var fd = new FormData();
          fd.append('id', sigId);
          fd.append('signature_name', sigEditorNameInput.value.trim());
          fd.append('signature_date', sigEditorDateInput.value);
          fd.append('is_main', sigEditorMainCheckbox.checked ? '1' : '0');

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

    if (!hasContent) {
      sigEditorMessage.textContent = 'Please upload a photo first.';
      sigEditorMessage.className = 'settings-message settings-message-error';
      return;
    }
    if (!sigEditorCompanyInput.value.trim() || !sigEditorOriginInput.value.trim()) {
      sigEditorMessage.textContent = 'Please fill in Company Name and Origin.';
      sigEditorMessage.className = 'settings-message settings-message-error';
      return;
    }

    sigEditorSaveBtn.disabled = true;
    editorCanvas.toBlob(function (blob) {
      if (!blob) {
        sigEditorSaveBtn.disabled = false;
        sigEditorMessage.textContent = 'Failed to export signature image.';
        sigEditorMessage.className = 'settings-message settings-message-error';
        return;
      }

      var fd = new FormData();
      var finalName = sigEditorNameInput.value.trim();
      var year = yearFromDateInput(sigEditorDateInput.value);
      fd.append('signature_name', finalName ? (finalName + '_' + year) : finalName);
      fd.append('signature_date', sigEditorDateInput.value);
      fd.append('company_name', sigEditorCompanyInput.value.trim());
      fd.append('origin', sigEditorOriginInput.value.trim());
      fd.append('is_main', sigEditorMainCheckbox.checked ? '1' : '0');
      fd.append('file', blob, 'signature.png');

      fetch('ajax/upload_signature.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          sigEditorSaveBtn.disabled = false;
          if (!data.success) {
            sigEditorMessage.textContent = data.message || 'Failed to save signature.';
            sigEditorMessage.className = 'settings-message settings-message-error';
            return;
          }
          sigEditorMessage.textContent = 'Saved to library.';
          sigEditorMessage.className = 'settings-message settings-message-success';
          resetSignatureForm();
          loadSignatures();
        })
        .catch(function () {
          sigEditorSaveBtn.disabled = false;
          sigEditorMessage.textContent = 'Network error.';
          sigEditorMessage.className = 'settings-message settings-message-error';
        });
    }, 'image/png');
  });

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
  var docAddHGuideBtn = document.getElementById('docAddHGuideBtn');
  var docAddVGuideBtn = document.getElementById('docAddVGuideBtn');
  var docGuideBoxBtn = document.getElementById('docGuideBoxBtn');
  var docInsertSigBtn = document.getElementById('docInsertSigBtn');
  var docUndoBtn = document.getElementById('docUndoBtn');
  var docDeleteSelectedBtn = document.getElementById('docDeleteSelectedBtn');
  var docClearAllBtn = document.getElementById('docClearAllBtn');
  var docBlockAdjustGroup = document.getElementById('docBlockAdjustGroup');
  var docBlockApplyBtn = document.getElementById('docBlockApplyBtn');
  var docBlockCancelBtn = document.getElementById('docBlockCancelBtn');
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
  var docInsertLetterheadBtn = document.getElementById('docInsertLetterheadBtn');
  var docLhPickerOverlay = document.getElementById('docLhPickerOverlay');
  var docLhPickerList = document.getElementById('docLhPickerList');
  var docLhPickerCancel = document.getElementById('docLhPickerCancel');
  var docLhApplyAllPages = document.getElementById('docLhApplyAllPages');

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
  var docDrawMode = null; // null | 'block'
  var docDrawingRect = null;
  var docDrawStart = null;
  // 24 Okt 2026: rect yang baru digambar (mouse:up) TIDAK langsung dipotong
  // lagi - dijadikan fabric object selectable ("pending"), user bisa
  // geser/resize tiap sisinya pakai kontrol bawaan Fabric sebelum klik
  // "Apply Block" (lihat enterPendingAdjustMode()/applyPendingBlock()).
  // docPendingBlockType ikut diingat terpisah dari docDrawMode karena
  // docDrawMode sendiri sudah di-null-kan begitu rect awal selesai digambar.
  var pendingBlockRect = null;
  var pendingBlockType = null;
  var guideBox = null;
  var insertedSignatureIds = {}; // set-like map, id -> true, collected across all pages
  var insertedLetterheadIds = {}; // same idea, for letterheads
  // 26 Okt 2026: counter dipakai untuk bikin letterheadSyncId unik tiap kali
  // Insert Letterhead dipanggil - lihat insertLetterheadOntoCanvas() dan
  // syncLetterheadTransform() di bawah (fitur "edit letterhead ikut sync ke
  // semua halaman").
  var docLetterheadSyncCounter = 0;

  // 24 Okt 2026: Undo untuk Document Editor - history stack PER HALAMAN
  // (disimpan di docPages[i].undoStack, bukan satu stack global, supaya
  // pindah halaman tidak mencampur riwayat undo halaman lain). Snapshot
  // diambil via docFabricCanvas.toJSON() SEBELUM aksi yang mengubah canvas
  // (potong blok, insert ttd, delete, tambah guide box) - sama pola dengan
  // eraseUndoStack di Signature Editor (push sebelum aksi, pop utk undo,
  // dibatasi 20 langkah). Tombol Undo-nya tunggal utk semua jenis aksi di
  // atas (bukan per-jenis seperti Undo Crop/Undo Erase terpisah di
  // Signature Editor) karena semuanya sama-sama memodifikasi 1 canvas yang
  // sama dan snapshot JSON sudah mencakup state canvas secara utuh.
  var DOC_UNDO_LIMIT = 20;

  // Zoom (Fabric's own setZoom + setDimensions, not CSS scaling - keeps
  // mouse coordinates for drawing/dragging accurate at any zoom level).
  // NOTE (21 Okt 2026 bugfix): these used to be named ZOOM_MIN/ZOOM_MAX/
  // ZOOM_STEP, identical to the Signature Editor's own zoom constants
  // declared earlier in this SAME top-level IIFE (both are `var`, both in
  // the same function scope). Whichever block ran last on page load silently
  // overwrote the other's values - in practice the Document Editor's
  // 0.1/3/0.15 clobbered the Signature Editor's 50/1000/25, so dragging the
  // Signature Editor's zoom SLIDER got clamped to a 0.1-3 range instead of
  // 50-1000 (looked like "zoom shrinks and can't be enlarged again"). Renamed
  // with a DOC_ prefix so the two blocks can never collide again - if you add
  // more module-scoped constants in this file, give each section's
  // constants a distinct prefix for the same reason.
  var currentZoom = 1;
  var DOC_ZOOM_MIN = 0.1;
  var DOC_ZOOM_MAX = 3;
  var DOC_ZOOM_STEP = 0.15;

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
    [docBlockTextBtn, docAddHGuideBtn, docAddVGuideBtn, docGuideBoxBtn, docGuideBoxPresetSelect, docInsertSigBtn, docInsertLetterheadBtn, docClearAllBtn,
     docZoomOutBtn, docZoomInBtn, docZoomFitBtn, docFullscreenBtn].forEach(function (btn) {
      btn.disabled = !enabled;
    });
    docEditorSaveBtn.disabled = !enabled;
    if (!enabled) docUndoBtn.disabled = true;
    docEditorHint.textContent = enabled
      ? 'Draw a block, adjust its edges, then Apply before inserting a signature from the library. Switch pages with the tabs above if the document has more than one.'
      : 'Upload a document first to enable the tools above.';
  }

  function resetDocEditorState() {
    docPages = [];
    currentPageIndex = -1;
    docDrawMode = null;
    docDrawingRect = null;
    docDrawStart = null;
    pendingBlockRect = null;
    pendingBlockType = null;
    docBlockAdjustGroup.style.display = 'none';
    guideBox = null;
    insertedSignatureIds = {};
    insertedLetterheadIds = {};
    currentZoom = 1;
    docBlockTextBtn.classList.remove('active');
    docDeleteSelectedBtn.disabled = true;
    docUndoBtn.disabled = true;
    docPageThumbsWrap.innerHTML = '';
    docPageThumbsWrap.style.display = 'none';
  }

  /* ---- Undo: snapshot-based, 1 stack per page (page.undoStack). Dipanggil
     SEBELUM aksi yang mengubah canvas (potong blok, insert ttd, delete,
     tambah guide box) - lihat masing-masing pemanggil di bawah. ---- */
  function pushDocUndoSnapshot() {
    var page = currentPage();
    if (!page || !docFabricCanvas) return;
    if (!page.undoStack) page.undoStack = [];
    page.undoStack.push(docFabricCanvas.toJSON(['blockType', 'isGuideBox', 'isGuideLine', 'guideOrientation', 'letterheadSyncId', 'letterheadPart']));
    if (page.undoStack.length > DOC_UNDO_LIMIT) page.undoStack.shift();
    updateDocUndoBtn();
  }

  function updateDocUndoBtn() {
    var page = currentPage();
    docUndoBtn.disabled = !page || !page.undoStack || page.undoStack.length === 0;
  }

  docUndoBtn.addEventListener('click', function () {
    var page = currentPage();
    if (!page || !page.undoStack || page.undoStack.length === 0 || !docFabricCanvas) return;
    // Aksi yang sedang pending (block belum di-Apply) dibatalkan dulu -
    // rect pending itu bukan bagian dari snapshot manapun (belum pernah
    // "committed"), jadi tidak masuk akal ikut di-undo/redo.
    if (pendingBlockRect) cancelPendingBlock();
    var snapshot = page.undoStack.pop();
    docFabricCanvas.loadFromJSON(snapshot, function () {
      guideBox = null;
      docFabricCanvas.getObjects().forEach(function (o) {
        if (o.isGuideBox) {
          guideBox = o;
        }
        if (o.isGuideLine) {
          attachGuideLineClamp(o);
        }
        // 26 Okt 2026 (susulan): Undo mengembalikan posisi letterhead di
        // halaman INI ke snapshot sebelumnya - supaya halaman lain yang
        // sebelumnya sudah ke-sync ke posisi yang di-Undo ini ikut
        // dikembalikan juga, bukan cuma halaman ini, jalankan sinkronisasi
        // yang sama seperti sehabis drag/resize untuk tiap layer letterhead
        // yang baru saja dimuat ulang. No-op untuk objek lain (guide
        // box/line, block text patch, signature) karena
        // syncLetterheadTransform() keluar langsung kalau tidak ada
        // letterheadSyncId.
        syncLetterheadTransform(o);
      });
      docFabricCanvas.requestRenderAll();
      updateDocUndoBtn();
      highlightActiveThumbnail();
    });
  });

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
        resolve({ workingCanvas: canvas, width: w, height: h, json: null, undoStack: [] });
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
        return { workingCanvas: canvas, width: canvas.width, height: canvas.height, json: null, undoStack: [] };
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
      docPages[currentPageIndex].json = docFabricCanvas.toJSON(['blockType', 'isGuideBox', 'isGuideLine', 'guideOrientation', 'letterheadSyncId', 'letterheadPart']);
    }
  }

  function switchToPage(index) {
    if (!docPages[index]) return;
    // A pending (not-yet-Applied) block rect belongs only to the canvas
    // being left - it was never saved into page.json by syncCurrentPageState
    // (not committed yet), so switching pages would silently drop it from
    // view while leaving docBlockAdjustGroup shown. Cancel it explicitly.
    if (pendingBlockRect) cancelPendingBlock();
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
      docFabricCanvas.getObjects().forEach(function (o) {
        if (o.isGuideBox) {
          guideBox = o;
        }
        if (o.isGuideLine) {
          attachGuideLineClamp(o);
        }
      });
      highlightActiveThumbnail();
      fitZoomToContainer();
      updateDocUndoBtn();
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
    zoom = Math.max(DOC_ZOOM_MIN, Math.min(DOC_ZOOM_MAX, zoom));
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
    var fit;
    if (docEditorIsFullscreen) {
      // Fullscreen: fill the available WIDTH only, no matter how tall the
      // page ends up - that's what "really full" (vs. the old behavior,
      // which was identical to the normal "Fit to Page" min(width,height)
      // fit) means here. Any vertical overflow just scrolls inside
      // .doc-editor-canvas-wrap (already overflow:auto).
      fit = available / page.width;
    } else {
      // Outside fullscreen the wrap is capped by CSS max-height (75vh), so
      // keep fitting both width AND height like before.
      var availableHeight = docEditorCanvasWrap.clientHeight - 4;
      if (availableHeight <= 0) availableHeight = Math.max(1000, Math.round(window.innerHeight * 0.65));
      fit = Math.min(available / page.width, availableHeight / page.height);
    }
    applyZoom(fit);
    // 24 Okt 2026 FIX (fullscreen bug): Fabric caches the canvas's on-screen
    // bounding-rect offset (used by getPointer() to translate mouse clientX/
    // clientY into canvas coordinates) and only recalculates it on its own
    // 'resize' handling - NOT when the canvas's DOM container is simply
    // reparented elsewhere, which is exactly what setFullscreen() does
    // (moves #docEditorWorkspace, canvas included, to <body> with
    // position:fixed). Outside fullscreen this never came up because the
    // canvas never moved. Inside fullscreen the cached offset stayed stuck
    // at the canvas's old inline position, so every click/drag landed at
    // the wrong coordinates - Block: Top Text/Name looked like "nothing
    // happens" because the rect WAS being drawn, just off in empty space
    // outside the visible canvas. calcOffset() forces a fresh
    // getBoundingClientRect() read; called here because fitZoomToContainer
    // already runs after every layout-affecting change (page switch, zoom,
    // and - via its own rAF - entering/exiting fullscreen).
    if (docFabricCanvas) docFabricCanvas.calcOffset();
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
  // #2: docSigPickerOverlay lives in the DOM BEFORE the workspace gets
  // reparented to <body> on fullscreen - once the workspace becomes body's
  // LAST child (z-index 2147483647), the overlay (still sitting earlier in
  // the document, at whatever z-index theme.css gives .modal-overlay) ends
  // up stacked UNDER it, so "Insert Signature" looked like it did nothing
  // in fullscreen. Reparenting the overlay to <body> right after the
  // workspace (same trick as the workspace itself) fixes the stacking.
  var docSigPickerOriginalParent = null;
  var docSigPickerOriginalNextSibling = null;
  // Same reparenting trick, same reason, for the Letterhead picker added in
  // Stage 9 - without this, "Insert Letterhead" would silently do nothing
  // while in Fullscreen, exactly like Insert Signature used to.
  var docLhPickerOriginalParent = null;
  var docLhPickerOriginalNextSibling = null;

  function setFullscreen(on) {
    docEditorIsFullscreen = on;
    if (on) {
      docWorkspaceOriginalParent = docEditorWorkspace.parentNode;
      docWorkspaceOriginalNextSibling = docEditorWorkspace.nextSibling;
      document.body.appendChild(docEditorWorkspace);
      docSigPickerOriginalParent = docSigPickerOverlay.parentNode;
      docSigPickerOriginalNextSibling = docSigPickerOverlay.nextSibling;
      document.body.appendChild(docSigPickerOverlay); // after the workspace - must stack above it
      docLhPickerOriginalParent = docLhPickerOverlay.parentNode;
      docLhPickerOriginalNextSibling = docLhPickerOverlay.nextSibling;
      document.body.appendChild(docLhPickerOverlay);
    } else {
      if (docWorkspaceOriginalParent) {
        // insertBefore(el, null) is just appendChild, so this still works
        // even if the workspace used to be the last child.
        docWorkspaceOriginalParent.insertBefore(docEditorWorkspace, docWorkspaceOriginalNextSibling);
      }
      if (docSigPickerOriginalParent) {
        docSigPickerOriginalParent.insertBefore(docSigPickerOverlay, docSigPickerOriginalNextSibling);
      }
      if (docLhPickerOriginalParent) {
        docLhPickerOriginalParent.insertBefore(docLhPickerOverlay, docLhPickerOriginalNextSibling);
      }
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

    // Arrow-key nudge for the currently selected canvas object (25 Oct 2026,
    // user request - "both letterhead and signature"). Implemented against
    // whatever is active rather than those two specifically, so it also
    // works for guide lines/guide box/text block patches for free, with no
    // per-layer-type duplication. 1px per press, 10px with Shift held.
    // Ignored while typing in a text field (Document Name, Letterhead Name,
    // etc.) so it doesn't fight with normal cursor movement there.
    if (!docFabricCanvas) return;
    var targetTag = (e.target && e.target.tagName) || '';
    if (targetTag === 'INPUT' || targetTag === 'TEXTAREA' || (e.target && e.target.isContentEditable)) return;
    var activeObj = docFabricCanvas.getActiveObject();
    if (!activeObj) return;
    var nudge = e.shiftKey ? 10 : 1;
    var didNudge = true;
    switch (e.key) {
      case 'ArrowLeft': activeObj.left -= nudge; break;
      case 'ArrowRight': activeObj.left += nudge; break;
      case 'ArrowUp': activeObj.top -= nudge; break;
      case 'ArrowDown': activeObj.top += nudge; break;
      default: didNudge = false;
    }
    if (didNudge) {
      e.preventDefault(); // stop the page behind the canvas from scrolling
      activeObj.setCoords();
      docFabricCanvas.requestRenderAll();
      // 26 Okt 2026: nudge sets left/top directly and never fires Fabric's
      // 'object:modified' event (unlike a mouse drag), so the letterhead
      // sync wired in wireDocCanvasSelection() would otherwise miss
      // keyboard nudges entirely - call it explicitly here too.
      syncLetterheadTransform(activeObj);
    }
  });

  // Resizing/rotating the browser window while in fullscreen changes the
  // canvas's on-screen position without any reparenting - re-run the same
  // fit+calcOffset pass so pointer coordinates stay correct (see the
  // calcOffset() comment inside fitZoomToContainer()).
  window.addEventListener('resize', function () {
    if (docEditorIsFullscreen && docEditorReady()) fitZoomToContainer();
  });

  docZoomInBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    applyZoom(currentZoom + DOC_ZOOM_STEP);
  });
  docZoomOutBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    applyZoom(currentZoom - DOC_ZOOM_STEP);
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

    // Keep the pending block rect from being dragged/resized off the page
    // while the user is adjusting it (before Apply). Only acts on
    // pendingBlockRect - every other object (signature layers, guide box,
    // already-cut blocks) is unaffected.
    function clampPendingRect(e) {
      if (!pendingBlockRect || e.target !== pendingBlockRect) return;
      var page = currentPage();
      if (!page) return;
      var r = pendingBlockRect;
      // Shrink first (so a resize that pushes past the right/bottom edge
      // gets capped), then reposition (so a move/resize-from-top-left that
      // pushes past the left/top edge gets capped too).
      var w = Math.min(r.getScaledWidth(), page.width);
      var h = Math.min(r.getScaledHeight(), page.height);
      if (w !== r.getScaledWidth() && r.width) r.set('scaleX', w / r.width);
      if (h !== r.getScaledHeight() && r.height) r.set('scaleY', h / r.height);
      var left = Math.max(0, Math.min(r.left, page.width - w));
      var top = Math.max(0, Math.min(r.top, page.height - h));
      r.set({ left: left, top: top });
      r.setCoords();
    }
    docFabricCanvas.on('object:moving', clampPendingRect);
    docFabricCanvas.on('object:scaling', clampPendingRect);

    // 26 Okt 2026: "edit letterhead ikut sync ke semua halaman" - fires
    // after a drag/resize/rotate is released (not during). See
    // syncLetterheadTransform(); no-op for any object that isn't a
    // letterhead layer (function returns early).
    docFabricCanvas.on('object:modified', function (e) { syncLetterheadTransform(e.target); });

    // 26 Okt 2026 (susulan ketiga, laporan user: "undo malah menghilangkan
    // letterhead, bukan balik ke posisi sebelum digeser"). Akar masalah:
    // pushDocUndoSnapshot() SEBELUMNYA cuma dipanggil sebelum aksi eksplisit
    // (insert/delete/potong blok/dst, lihat komentar di
    // pushDocUndoSnapshot()) - drag/resize TIDAK pernah push snapshotnya
    // sendiri. Jadi kalau urutannya Insert Letterhead -> geser -> Undo,
    // satu-satunya snapshot di stack adalah keadaan SEBELUM insert (tanpa
    // letterhead sama sekali) - makanya letterhead-nya hilang total, bukan
    // cuma balik posisi. Perbaikan KHUSUS layer letterhead (bukan semua
    // tipe objek, supaya tetap surgical dan tidak mengubah perilaku
    // undo untuk signature/guide box/block text yang belum pernah
    // dilaporkan bermasalah): begitu user mulai menyentuh (mousedown) salah
    // satu layer letterhead untuk digeser/di-resize, simpan dulu
    // snapshot-nya (posisi SEBELUM gestur ini) - jadi Undo pertama cukup
    // mengembalikan posisi sebelum geser terakhir, bukan langsung
    // menghapus letterhead-nya. Snapshot ini juga otomatis mengandung
    // letterheadSyncId/letterheadPart (ikut di-toJSON di
    // pushDocUndoSnapshot(), lihat perubahan susulan sebelumnya), jadi
    // ter-sync ke halaman lain juga lewat forEach+syncLetterheadTransform()
    // di handler docUndoBtn saat snapshot ini nanti di-Undo.
    docFabricCanvas.on('mouse:down', function (opt) {
      if (opt.target && opt.target.letterheadSyncId) {
        pushDocUndoSnapshot();
      }
    });
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
      var rect = docDrawingRect;
      var blockType = docDrawMode;
      docDrawingRect = null;

      if (rect.width > 4 && rect.height > 4) {
        // 24 Okt 2026: TIDAK langsung dipotong lagi - rect ini dijadikan
        // object adjustable (enterPendingAdjustMode), baru dipotong
        // beneran saat "Apply Block" diklik (applyPendingBlock()).
        enterPendingAdjustMode(rect, blockType);
        docDrawMode = null;
        docBlockTextBtn.classList.remove('active');
        docEditorCanvasEl.style.cursor = '';
      } else {
        // Drag too small to count (stray click) - discard the sliver rect
        // and KEEP the tool armed, same spirit as before: one press stays
        // armed until an actual block is drawn, or the button is clicked
        // again to cancel it.
        docFabricCanvas.remove(rect);
      }
      docFabricCanvas.requestRenderAll();
    });
  }

  /* ---- #24 Okt 2026: adjustable pending block - the just-drawn rect
     becomes a normal selectable/resizable Fabric object (its own built-in
     corner + edge-midpoint handles cover "move it, resize every side",
     no manual hit-testing needed like the Signature Editor's Crop does on
     a plain <canvas>). Rotation is turned off (a block only needs to move/
     resize, never rotate). The two new toolbar buttons (Apply/Cancel)
     appear only while this is active; the Block button stays usable so
     the user can restart with a fresh rect (that cancels the current one
     first, see docBlockTextBtn handler below). ---- */
  function enterPendingAdjustMode(rect, blockType) {
    rect.set({
      selectable: true, evented: true, hasControls: true, hasBorders: true,
      lockRotation: true, strokeUniform: true,
    });
    rect.setControlsVisibility({ mtr: false });
    pendingBlockRect = rect;
    pendingBlockType = blockType;
    docFabricCanvas.setActiveObject(rect);
    docBlockAdjustGroup.style.display = 'flex';
  }

  function cancelPendingBlock() {
    if (pendingBlockRect && docFabricCanvas) {
      docFabricCanvas.remove(pendingBlockRect);
      docFabricCanvas.requestRenderAll();
    }
    pendingBlockRect = null;
    pendingBlockType = null;
    docBlockAdjustGroup.style.display = 'none';
  }

  function applyPendingBlock() {
    if (!pendingBlockRect || !docFabricCanvas) return;
    var r = pendingBlockRect;
    var rect = {
      left: r.left,
      top: r.top,
      width: r.getScaledWidth(),
      height: r.getScaledHeight(),
    };
    var blockType = pendingBlockType;
    docFabricCanvas.remove(r);
    pendingBlockRect = null;
    pendingBlockType = null;
    docBlockAdjustGroup.style.display = 'none';

    if (rect.width > 4 && rect.height > 4) {
      cutBlockToLayer(rect, blockType);
    }
  }

  docBlockApplyBtn.addEventListener('click', applyPendingBlock);
  docBlockCancelBtn.addEventListener('click', cancelPendingBlock);

  function startBlockDraw(mode, btn) {
    if (!docEditorReady()) return;
    // Starting a fresh block while a previous one is still pending
    // adjustment (not yet Applied) discards that one first - drawing a
    // second box without resolving the first would leave an orphaned rect
    // on the canvas.
    if (pendingBlockRect) cancelPendingBlock();
    var turningOn = docDrawMode !== mode;
    docDrawMode = null;
    docBlockTextBtn.classList.remove('active');
    if (turningOn) {
      docDrawMode = mode;
      btn.classList.add('active');
      docEditorCanvasEl.style.cursor = 'crosshair'; // clear "ready to draw" feedback
    } else {
      docEditorCanvasEl.style.cursor = '';
    }
  }

  docBlockTextBtn.addEventListener('click', function () { startBlockDraw('block', docBlockTextBtn); });

  function cutBlockToLayer(rect, blockType) {
    var page = currentPage();
    if (!page) return;
    var x = Math.max(0, Math.round(rect.left));
    var y = Math.max(0, Math.round(rect.top));
    var w = Math.min(Math.round(rect.width), page.width - x);
    var h = Math.min(Math.round(rect.height), page.height - y);
    if (w <= 0 || h <= 0) return;

    pushDocUndoSnapshot();

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
      docFabricCanvas.requestRenderAll();
    });
  }

  /* ---- Guide lines (24 Okt 2026): garis bantu horizontal/vertikal untuk
     pemposisian, bisa ditambah lebih dari satu. Direntangkan penuh satu sisi
     halaman dan dikunci hanya bisa digeser di satu sumbu (horizontal =
     naik/turun saja, vertikal = kiri/kanan saja); tidak bisa di-resize/
     rotate (hasControls:false + lock*). `padding` memperbesar area klik/
     drag tanpa mengubah tampilan garis yang tipis. Murni alat bantu visual
     seperti Guide Box - dikecualikan saat flatten/Save
     (lihat renderPageToDataURL()) dan ikut ditandai di toJSON (isGuideLine/
     guideOrientation) supaya tersimpan per halaman & lewat Undo, tapi event
     listener-nya (clamp) harus di-reattach manual tiap kali di-load ulang
     dari JSON (lihat pemanggil attachGuideLineClamp() di afterLoad()/Undo). ---- */
  function attachGuideLineClamp(line) {
    line.on('moving', function () {
      var page = currentPage();
      if (!page) return;
      if (line.guideOrientation === 'h') {
        line.set('top', Math.max(0, Math.min(line.top, page.height)));
      } else {
        line.set('left', Math.max(0, Math.min(line.left, page.width)));
      }
      line.setCoords();
    });
  }

  function addGuideLine(orientation) {
    if (!docEditorReady()) return;
    var page = currentPage();
    pushDocUndoSnapshot();
    var line;
    var commonOpts = {
      stroke: '#00d2ff', strokeWidth: 1, strokeDashArray: [6, 4],
      selectable: true, evented: true, hasControls: false, hasBorders: false,
      lockScalingX: true, lockScalingY: true, lockRotation: true,
      padding: 8, isGuideLine: true, guideOrientation: orientation,
    };
    if (orientation === 'h') {
      var y = Math.round(page.height / 2);
      line = new fabric.Line([0, y, page.width, y], Object.assign({ lockMovementX: true, hoverCursor: 'ns-resize' }, commonOpts));
    } else {
      var x = Math.round(page.width / 2);
      line = new fabric.Line([x, 0, x, page.height], Object.assign({ lockMovementY: true, hoverCursor: 'ew-resize' }, commonOpts));
    }
    docFabricCanvas.add(line);
    docFabricCanvas.setActiveObject(line);
    attachGuideLineClamp(line);
    docFabricCanvas.requestRenderAll();
  }

  docAddHGuideBtn.addEventListener('click', function () { addGuideLine('h'); });
  docAddVGuideBtn.addEventListener('click', function () { addGuideLine('v'); });

  docGuideBoxBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    pushDocUndoSnapshot();
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
    docFabricCanvas.add(guideBox);
    docFabricCanvas.setActiveObject(guideBox);
    docFabricCanvas.requestRenderAll();
  });

  docDeleteSelectedBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    var active = docFabricCanvas.getActiveObject();
    if (!active) return;
    pushDocUndoSnapshot();
    if (active === guideBox) guideBox = null;
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
      pushDocUndoSnapshot();
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

  /* ---- Insert Letterhead: reuses ajax/list_letterheads.php and
     ajax/download_letterhead.php. Header is placed full-width pinned to the
     top, footer (if the chosen letterhead has one) full-width pinned to the
     bottom - both as separate fabric.Image objects so the user can freely
     drag/resize each one afterward, same as a signature. ---- */
  docInsertLetterheadBtn.addEventListener('click', function () {
    if (!docEditorReady()) return;
    docLhPickerList.innerHTML = '<div class="empty-sub">Loading...</div>';
    showModal(docLhPickerOverlay);

    fetch('ajax/list_letterheads.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success || !data.letterheads.length) {
          docLhPickerList.innerHTML = '<div class="empty-sub">No letterheads in the library yet.</div>';
          return;
        }
        docLhPickerList.innerHTML = '';
        data.letterheads.forEach(function (lh) {
          var item = document.createElement('div');
          item.className = 'doc-sig-pick-item';
          var img = document.createElement('img');
          img.src = 'ajax/download_letterhead.php?id=' + lh.id + '&part=header';
          img.alt = lh.letterhead_name;
          var label = document.createElement('span');
          label.textContent = lh.letterhead_name + (lh.has_footer ? ' (+ footer)' : '');
          item.appendChild(img);
          item.appendChild(label);
          item.addEventListener('click', function () { insertLetterheadOntoCanvas(lh); });
          docLhPickerList.appendChild(item);
        });
      })
      .catch(function () {
        docLhPickerList.innerHTML = '<div class="empty-sub">Failed to load letterheads.</div>';
      });
  });

  docLhPickerCancel.addEventListener('click', function () { hideModal(docLhPickerOverlay); });

  // Places one image full-width, pinned to top (align='top') or bottom
  // (align='bottom') of the current page, preserving aspect ratio. This
  // only sets its STARTING position/size - it stays a normal
  // selectable/resizable fabric object afterward, same as a signature.
  //
  // centeredScaling:true (25 Oct 2026 fix) - without this, fabric's default
  // corner-resize anchors the OPPOSITE corner/side, so dragging a side
  // handle on a full-width image (left edge at 0, right edge at page.width)
  // keeps one edge pinned while the other moves - which reads as the image
  // "jumping"/drifting instead of resizing in place. With centeredScaling,
  // the image's own center point stays fixed while resizing, which is the
  // predictable behavior wanted for a header/footer bar. Signatures don't
  // get this (they keep fabric's default), since a signature is usually
  // resized by a corner, not a full-bleed edge.
  // Shared placement math (26 Okt 2026, factored out of placeLetterheadPart
  // so applyLetterheadToOtherPages() below computes the EXACT same
  // left/top/scale for pages that are not the live canvas - jangan ubah
  // formula ini di satu tempat tanpa yang lain, keduanya harus tetap sama).
  function letterheadPlacementProps(page, img, align) {
    var scale = page.width / img.width;
    var scaledH = img.height * scale;
    var top = align === 'bottom' ? (page.height - scaledH) : 0;
    return { left: 0, top: top, scaleX: scale, scaleY: scale, centeredScaling: true };
  }

  function placeLetterheadPart(url, align, syncId, part) {
    var page = currentPage();
    fabric.Image.fromURL(url, function (img) {
      if (!img || !img.width) {
        docEditorMessage.textContent = 'Failed to load the selected letterhead image.';
        docEditorMessage.className = 'settings-message settings-message-error';
        return;
      }
      img.set(letterheadPlacementProps(page, img, align));
      // 26 Okt 2026: tag dengan syncId+part supaya bisa dicari/disamakan di
      // halaman lain saat diedit - lihat syncLetterheadTransform().
      img.set({ letterheadSyncId: syncId, letterheadPart: part });
      docFabricCanvas.add(img);
      // 25 Oct 2026 fix: "letterhead suddenly ends up behind the paper" bug
      // report. add() alone is SUPPOSED to put a new object on top (last =
      // topmost in fabric's stacking), but since the two async image loads
      // here (header + footer) can resolve in either order relative to
      // whatever else is happening on the canvas, force it explicitly to
      // the front instead of relying on add()'s default position.
      docFabricCanvas.bringToFront(img);
      docFabricCanvas.requestRenderAll();
    }, { crossOrigin: 'anonymous' });
  }

  // 26 Okt 2026 - "Apply to all pages" (belum dites user): halaman lain
  // bukan canvas Fabric yang hidup (cuma docPages[i].json kalau sudah
  // pernah dibuka, atau cuma bitmap asli di .workingCanvas kalau belum
  // pernah dibuka sama sekali - lihat switchToPage()), jadi tidak bisa
  // dipakaikan placeLetterheadPart() langsung. Untuk tiap halaman lain:
  // bikin fabric.StaticCanvas offscreen berisi state halaman itu (dari
  // .json kalau ada, atau dari .workingCanvas kalau belum), tempel
  // header/footer dengan rumus PERSIS sama (letterheadPlacementProps), lalu
  // serialize balik ke .json - sama persis dengan apa yang
  // syncCurrentPageState() lakukan untuk halaman yang sedang dibuka, cuma
  // di sini dilakukan manual karena canvasnya tidak live.
  function addLetterheadToPageData(page, lh, syncId) {
    return new Promise(function (resolve) {
      var tmp = new fabric.StaticCanvas(null, { width: page.width, height: page.height });

      function placeParts() {
        var urls = [{ url: 'ajax/download_letterhead.php?id=' + lh.id + '&part=header', align: 'top', part: 'header' }];
        if (lh.has_footer) {
          urls.push({ url: 'ajax/download_letterhead.php?id=' + lh.id + '&part=footer', align: 'bottom', part: 'footer' });
        }
        Promise.all(urls.map(function (partDef) {
          return new Promise(function (res) {
            fabric.Image.fromURL(partDef.url, function (img) {
              if (img && img.width) {
                img.set(letterheadPlacementProps(page, img, partDef.align));
                // 26 Okt 2026: tag sama seperti placeLetterheadPart() di atas,
                // syncId yang SAMA dipakai di semua halaman untuk satu kali
                // Insert Letterhead supaya edit di satu halaman bisa dicari
                // kembarannya di halaman lain - lihat syncLetterheadTransform().
                img.set({ letterheadSyncId: syncId, letterheadPart: partDef.part });
                tmp.add(img);
                tmp.bringToFront(img);
              }
              res();
            }, { crossOrigin: 'anonymous' });
          });
        })).then(function () {
          tmp.renderAll();
          page.json = tmp.toJSON(['blockType', 'isGuideBox', 'isGuideLine', 'guideOrientation', 'letterheadSyncId', 'letterheadPart']);
          tmp.dispose();
          resolve();
        });
      }

      if (page.json) {
        tmp.loadFromJSON(page.json, placeParts);
      } else {
        fabric.Image.fromURL(page.workingCanvas.toDataURL('image/png'), function (bgImg) {
          bgImg.set({ left: 0, top: 0, selectable: false, evented: false, hasControls: false });
          tmp.add(bgImg);
          tmp.sendToBack(bgImg);
          placeParts();
        });
      }
    });
  }

  function applyLetterheadToOtherPages(lh, syncId) {
    var others = [];
    docPages.forEach(function (page, idx) {
      if (idx !== currentPageIndex) others.push(page);
    });
    if (!others.length) return;
    Promise.all(others.map(function (page) { return addLetterheadToPageData(page, lh, syncId); }))
      .then(function () {
        highlightActiveThumbnail(); // refresh "has-edits" dot on thumbnails
        docEditorMessage.textContent = 'Letterhead applied to all ' + docPages.length + ' page(s).';
        docEditorMessage.className = 'settings-message settings-message-success';
      });
  }

  // 26 Okt 2026: begitu letterhead diinsert dengan "Apply to all pages", tiap
  // salinan (header/footer) di tiap halaman ditag dengan letterheadSyncId
  // yang SAMA (dibuat sekali per klik di sini) + letterheadPart ('header'
  // atau 'footer'). Dipakai supaya geser/resize salah satu salinan bisa
  // dicari kembarannya di halaman lain dan disamakan - lihat
  // syncLetterheadTransform(). Kalau "Apply to all pages" TIDAK dicentang,
  // tetap ditag (tidak merugikan), cuma tidak ada salinan lain untuk disync.
  function insertLetterheadOntoCanvas(lh) {
    hideModal(docLhPickerOverlay);
    pushDocUndoSnapshot();
    var applyAll = !!(docLhApplyAllPages && docLhApplyAllPages.checked);
    var syncId = 'lh' + lh.id + '_' + Date.now() + '_' + (docLetterheadSyncCounter++);
    placeLetterheadPart('ajax/download_letterhead.php?id=' + lh.id + '&part=header', 'top', syncId, 'header');
    if (lh.has_footer) {
      placeLetterheadPart('ajax/download_letterhead.php?id=' + lh.id + '&part=footer', 'bottom', syncId, 'footer');
    }
    insertedLetterheadIds[lh.id] = true;
    if (applyAll) {
      applyLetterheadToOtherPages(lh, syncId);
    }
  }

  // 26 Okt 2026: dipanggil setiap satu letterhead layer selesai
  // digeser/di-resize (event 'object:modified' dari canvas live, atau
  // manual setelah arrow-key nudge - keduanya wire di bawah). Menyamakan
  // left/top/scaleX/scaleY/angle/flip ke salinan letterhead yang sama
  // (syncId+part sama) di SEMUA halaman LAIN yang punya .json (halaman yang
  // sedang live/current sudah otomatis benar, tidak disentuh di sini).
  // Halaman yang belum pernah dibuka (json null) dilewati - seharusnya
  // tidak terjadi untuk letterhead hasil "Apply to all pages" karena
  // addLetterheadToPageData() sudah mengisi .json semua halaman saat
  // insert, tapi dicek untuk jaga-jaga.
  function syncLetterheadTransform(obj) {
    if (!obj || !obj.letterheadSyncId) return;
    var syncId = obj.letterheadSyncId;
    var part = obj.letterheadPart;
    var left = obj.left, top = obj.top, scaleX = obj.scaleX, scaleY = obj.scaleY,
        angle = obj.angle, flipX = obj.flipX, flipY = obj.flipY;
    docPages.forEach(function (page, idx) {
      if (idx === currentPageIndex) return;
      if (!page.json || !page.json.objects) return;
      page.json.objects.forEach(function (o) {
        if (o.letterheadSyncId === syncId && o.letterheadPart === part) {
          o.left = left; o.top = top; o.scaleX = scaleX; o.scaleY = scaleY;
          o.angle = angle; o.flipX = flipX; o.flipY = flipY;
        }
      });
    });
  }

  /* ---- Save: sync + flatten EVERY page (minus guide boxes/guide lines,
     which are only a visual aid) to a PNG, send them all to ajax/save_edited_document.php
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
        tmp.getObjects().filter(function (o) { return o.isGuideBox || o.isGuideLine; }).forEach(function (o) { tmp.remove(o); });
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
        Object.keys(insertedLetterheadIds).forEach(function (id) { fd.append('letterhead_ids[]', id); });
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
  // query above, so wire it explicitly.
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

  /* =====================================================================
     LETTERHEAD LIBRARY (Stage 9, 24 Oct 2026). Same shared confirm modal
     (openConfirmModal/confirmOverlay/confirmModalError) and showModal/
     hideModal helpers as Signature Library above. v1: upload + list +
     delete only (no Edit/Select-All/Share yet, unlike Signature Library).
     Collapsible also isn't matched by the generic #cardSignatures query,
     so it's wired explicitly here too.
     ===================================================================== */
  (function () {
    var lhCard = document.getElementById('cardLetterheads');
    var lhHeader = document.getElementById('cardLetterheadsHeader');
    lhHeader.addEventListener('click', function () {
      if (lhCard.classList.contains('open')) {
        closeCollapsible(lhCard);
      } else {
        openCollapsible(lhCard);
        loadLetterheads();
      }
    });

    var lhSaveForm = document.getElementById('lhSaveForm');
    var lhNameInput = document.getElementById('lhNameInput');
    var lhCompanyInput = document.getElementById('lhCompanyInput');
    var lhOriginInput = document.getElementById('lhOriginInput');
    var lhHeaderFileInput = document.getElementById('lhHeaderFileInput');
    var lhFooterFileInput = document.getElementById('lhFooterFileInput');
    var lhMainCheckbox = document.getElementById('lhMainCheckbox');
    var lhSaveMessage = document.getElementById('lhSaveMessage');
    var lhSaveBtn = document.getElementById('lhSaveBtn');
    var lhAccordionList = document.getElementById('lhAccordionList');

    // Force these fields to uppercase as the user types (not just a CSS
    // text-transform, which only affects the visual, not what gets sent
    // to the server). Cursor stays at the end, which is fine for these
    // short single-line fields - no mid-text editing scenario to preserve.
    [lhNameInput, lhCompanyInput, lhOriginInput].forEach(function (el) {
      el.addEventListener('input', function () {
        el.value = el.value.toUpperCase();
      });
    });

    // Bug fix (25 Oct 2026): the collapsible's max-height is fixed to
    // whatever height the "Loading..." placeholder had at the moment the
    // card was opened (see openCollapsible()). Re-rendering the list
    // afterward (here, or after Save/Delete) changes the content height
    // without updating that max-height, so the real list got visually cut
    // off. refreshOpenHeight() recalculates it - must be called after
    // EVERY innerHTML change below that can alter this card's height.
    function loadLetterheads() {
      lhAccordionList.innerHTML = '<div class="empty-sub">Loading...</div>';
      fetch('ajax/list_letterheads.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.success || !data.letterheads.length) {
            lhAccordionList.innerHTML = '<div class="empty-sub">No letterheads yet.</div>';
            refreshOpenHeight(lhCard);
            return;
          }
          lhAccordionList.innerHTML = '';
          data.letterheads.forEach(function (lh) {
            var row = document.createElement('div');
            row.className = 'accordion-item';
            row.innerHTML =
              '<div class="accordion-row" style="display:flex;align-items:center;gap:12px;padding:10px 0;">' +
              '<img src="ajax/download_letterhead.php?id=' + lh.id + '&part=header" alt="" style="height:36px;max-width:120px;object-fit:contain;background:#fff;border-radius:4px;">' +
              '<div style="flex:1;">' +
              '<div>' + lh.letterhead_name + (lh.is_main ? ' <span class="badge badge-success">Main</span>' : '') + '</div>' +
              '<div class="header-sub">' + lh.company_name + ' &middot; ' + lh.origin + (lh.has_footer ? ' &middot; has footer' : '') + '</div>' +
              '</div>' +
              '<button type="button" class="btn btn-danger lh-delete-btn" data-id="' + lh.id + '" data-name="' + lh.letterhead_name + '"><i class="ti ti-trash"></i> Delete</button>' +
              '</div>';
            lhAccordionList.appendChild(row);
          });

          lhAccordionList.querySelectorAll('.lh-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
              confirmDeleteLetterhead(btn.getAttribute('data-id'), btn.getAttribute('data-name'));
            });
          });
          refreshOpenHeight(lhCard);
        })
        .catch(function () {
          lhAccordionList.innerHTML = '<div class="empty-sub">Failed to load letterheads.</div>';
          refreshOpenHeight(lhCard);
        });
    };

    lhSaveForm.addEventListener('submit', function (e) {
      e.preventDefault();
      lhSaveMessage.textContent = '';
      lhSaveMessage.className = 'settings-message';

      var fd = new FormData();
      fd.append('letterhead_name', lhNameInput.value.trim());
      fd.append('company_name', lhCompanyInput.value.trim());
      fd.append('origin', lhOriginInput.value.trim());
      fd.append('is_main', lhMainCheckbox.checked ? '1' : '0');
      if (lhHeaderFileInput.files[0]) fd.append('header_file', lhHeaderFileInput.files[0]);
      if (lhFooterFileInput.files[0]) fd.append('footer_file', lhFooterFileInput.files[0]);

      lhSaveBtn.disabled = true;
      fetch('ajax/upload_letterhead.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          lhSaveBtn.disabled = false;
          if (!data.success) {
            lhSaveMessage.textContent = data.message || 'Failed to save letterhead.';
            lhSaveMessage.className = 'settings-message settings-message-error';
            return;
          }
          lhSaveMessage.textContent = 'Saved to library.';
          lhSaveMessage.className = 'settings-message settings-message-success';
          lhSaveForm.reset();
          loadLetterheads();
        })
        .catch(function () {
          lhSaveBtn.disabled = false;
          lhSaveMessage.textContent = 'Network error.';
          lhSaveMessage.className = 'settings-message settings-message-error';
        });
    });

    // Same shared confirm modal (openConfirmModal) as Signature Library's
    // confirmDeleteSignature() above - see that function for reference.
    function confirmDeleteLetterhead(id, name) {
      openConfirmModal(
        'Delete Letterhead',
        'This will permanently delete "' + name + '" and its file(s). This action cannot be undone. Enter your password to confirm.',
        function () {
          var fd = new FormData();
          fd.append('id', id);
          fetch('ajax/delete_letterhead.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (!data.success) {
                confirmModalError(data.message || 'Failed to delete letterhead.');
                return;
              }
              hideModal(confirmOverlay);
              loadLetterheads();
            })
            .catch(function () { confirmModalError('Network error.'); });
        }
      );
    }
  })();
})();
</script>