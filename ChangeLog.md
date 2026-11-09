# CHANGELOG DOCSTUDIO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## 0.1

- NEW: viewers of documents (PDF, images, text, CSV) opened from the lists of documents :   0.1.0
  - « Open with DocStudio » link next to the files of the Documents tabs, of the Documents block of the cards and
    of the ECM, viewer in a dialog or a new tab
  - same permissions as document.php, strict checks of the path and of the data directory
  - external users: permission to view the documents of their own thirdparty only
  - content always sent as an attachment with a sandbox policy, viewer page with a strict Content Security Policy
  - registry of viewers and sources of files for other modules (hooks docstudioRegisterViewers and
    docstudioRegisterProviders), JavaScript hooks docstudio:beforeOpen and docstudio:opened
