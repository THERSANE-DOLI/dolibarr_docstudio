# CHANGELOG DOCSTUDIO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## 0.4

- FIX: PDF editor, rotating a page emptied the editor (no page, tools inactive) :   0.4.1
  - the page counter of pdf.js is shared by every PDF opened: the old document is now closed before the rotated one
    is opened; the editor comes back to the document shown after a failed saving
  - mode of the current tool applied once the editors of pdf.js are ready

- NEW: PDF editor, texts with fonts, straight highlight, rotation and split in the toolbar :   0.4.0
  - texts of DocStudio instead of the text tool of pdf.js: movable by their handle, editable until saving,
    font, size, color and bold for the new text and the selected one, plain text only, written in the pages
    when saving (only the characters used are embedded)
  - setup tab « Fonts »: the 3 standard fonts of PDF, and TrueType / OpenType fonts uploaded by the administrator
    (signature of the file checked, stored on the server, nothing fetched from the Internet)
  - highlight: on the text or free hand (pdf.js), or straight line (horizontal or vertical when close to it,
    any angle with Shift)
  - rotation of the current page left or right in the toolbar, applied at once and visible everywhere
  - split into several files: scissors between the pages of the panel or in the toolbar, files named
    name-1.pdf, name-2.pdf...
  - Ctrl + wheel zooms the PDF only, in the editor and in the viewer
  - no « Alt text » button on the images
  - @pdf-lib/fontkit 1.1.1 embedded (MIT)

## 0.3

- NEW: PDF editor without server (annotations, pages, crop, redaction) :   0.3.0
  - pencil « Edit with DocStudio » on the lines of the lists of documents where the user may delete the file,
    editor page with its own content security policy, back to the list after saving
  - annotations with the editors of pdf.js: text, drawing, highlight, image
  - pages: rotate, delete, reorder by drag and drop in the panel of the pages
  - crop of a page or of every page (the content outside of the frame is only hidden, said in the editor)
  - redaction: pages with a redacted zone replaced by their image, new file without metadata, bookmarks,
    attachments nor previous versions, then checked (the hidden text must be nowhere in the file, else nothing is
    saved); real impact explained before saving
  - saving as a copy next to the original or instead of it: CSRF token, permission to write of document.php,
    content checked on the server (real PDF), antivirus, single extension in the name, ECM index kept (share link
    of a replaced file kept), hooks docstudioBeforeSave (PHP) and docstudio:beforeSave (JavaScript)
  - « Editing » in the file associations (PDF editor by default, « no editing » possible, choices for the users)
  - pdf-lib 1.17.1 embedded (MIT)

## 0.2

- NEW: file associations, search, thumbnails and print in the PDF viewer :   0.2.0
  - setup tab « File associations »: for each file type, what opens it from the magnifier of the lists (viewer of
    DocStudio, preview of Dolibarr or no preview), preview of Dolibarr by default for PDF and images, with the
    security benefits of DocStudio explained for PDF
  - choices the administrator leaves to the users, chosen on the user card (tab « Opening of files »)
  - one magnifier per line: the one of Dolibarr opens the viewer chosen, in the preview dialog of Dolibarr (the
    unalterable log of the previews of invoices still works); « Open with » of the viewer is a one time choice
  - PDF viewer: search in the text (Ctrl+F, accents ignored), thumbnails, print, automatic zoom up to 125 %

## 0.1

- NEW: viewers of documents (PDF, images, text, CSV) opened from the lists of documents :   0.1.0
  - « Open with DocStudio » link next to the files of the Documents tabs, of the Documents block of the cards and
    of the ECM, viewer in a dialog or a new tab
  - same permissions as document.php, strict checks of the path and of the data directory
  - external users: permission to view the documents of their own thirdparty only
  - content always sent as an attachment with a sandbox policy, viewer page with a strict Content Security Policy
  - registry of viewers and sources of files for other modules (hooks docstudioRegisterViewers and
    docstudioRegisterProviders), JavaScript hooks docstudio:beforeOpen and docstudio:opened
