# CHANGELOG DOCSTUDIO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

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
