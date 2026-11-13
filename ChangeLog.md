# CHANGELOG DOCSTUDIO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## 0.19

- FIX: office server not offered for the attachments of a protected mailbox of inbox :   0.19.3
  - the office server reads the file without the session of the user, that a protected mailbox needs (it answered
    « Resource not found or user unauthorized »): only the viewers of the browser are offered for these mails

- FIX: security audit 2026-11-10-c, points DSTU-017 to DSTU-021 :   0.19.2
  - desktop Office (dav.php): a lock is held by its user, its token never given to the others (they see the file
    locked, Office opens it read only), lock and unlock with a token for editing only
  - desktop Office never offered for a received attachment (inbox) nor for the types that may hold macros (doc,
    docm, dot, xls, xlsm, xlsb, xlt, ppt, pptm, pps, pot), refused by dav.php too
  - hash of the address of the computer in the dav tokens keyed with the secret of the tokens
  - compression: places taken before the antivirus, 2 at a time for the instance (DOCSTUDIO_MAX_COMPRESS_PARALLEL)
  - office server: « its calls are signed » remembered for the whole instance (multicompany)

- FIX: security audit 2026-11-10-b, points DSTU-012 to DSTU-015 :   0.19.1
  - Ghostscript: only an executable named gs (gswin64c / gswin32c on Windows), outside of the documents of Dolibarr,
    and allowed by $dolibarr_main_restrict_os_commands of conf.php (add « gs » to this list); the Test button says why
    it is refused
  - compression: one at a time for a user, 60 s at most for Ghostscript (DOCSTUDIO_GHOSTSCRIPT_TIMEOUT), 50 MB at most
    (DOCSTUDIO_MAX_COMPRESS_SIZE), the PDF checked by the antivirus before Ghostscript or Stirling PDF read it
  - tokens of the office server and of the desktop Office: 4 hours (DOCSTUDIO_WOPI_TOKEN_HOURS, 1 to 24), button
    « Revoke the tokens in use » of the setup (every token made before refused, with a confirmation)

- NEW: editors, copy filed in a piece for a file that only takes copies elsewhere (attachment of a mail) :   0.19.0
  - « Annotate and file » of inbox: the PDF or image editor opens on the attachment (read only), the saving puts
    a copy in the documents of the piece chosen in inbox, the mail stays as it is
  - the saving says where the copy goes (« Save a copy in Thirdparty ACME »), no replacing, back to the piece after
  - editors that write in place by themselves (office server, desktop Office) not offered for such a file
  - providers: canReplaceInPlace() and getSaveTarget() (default: in place allowed, copy next to the file)

## 0.18

- FIX: office server, address of the file with the entity in its path (OnlyOffice and Euro-Office) :   0.18.2
  - OnlyOffice adds « ?access_token= » after the address as it is: « ?entity= » made the token unreadable (401)
  - wopi.php/{entity}/files/{id}, the old wopi.php/files/{id}?entity= still read (Collabora sessions open)
  - tested with Collabora CODE 26.04, OnlyOffice Document Server 10.0 and Euro-Office 9.3.7: read, edit, lock,
    saving in Dolibarr, signature of the calls checked

- FIX: security audit 2026-11-10-b, points DSTU-010 and DSTU-011 :   0.18.1
  - signature of the office server still required when its discovery cannot be read again (server down): the last
    one read stays the reference, and once a key was seen a call without signature is refused (the Test button of
    the setup forgets the key when the office server has none any more)
  - no office server nor desktop Office when the unique id of the instance (conf.php) is missing or too short: their
    tokens would be signed with a key known by everyone; said in the setup

- NEW: « Open in Excel, Word, PowerPoint » with the Office installed on the computer :   0.18.0
  - option of the setup, off by default; offered in « Open with » (reading) and in the editors (editing) of the
    Office and OpenDocument types, never by default, for the types the administrator allows
  - links ms-excel:, ms-word:, ms-powerpoint: to a small WebDAV server (dav.php): read, lock, write back in place
    (docx, xlsx, pptx, odt, ods, odp; doc, xls, ppt and csv read only)
  - signed address valid 10 hours, for the computer that asked for it only (hash of its address in the token),
    rights of the user read again at each call, locks shared with the office server (never both writing)
  - saving checked like the office server: ZIP package of the type, size, hook docstudioBeforeSave, antivirus
  - never: creating, deleting, moving or copying a file through dav.php

## 0.17

- FIX: security audit of 0.10.2, points left (DSTU-003, 004, 005, 006, 009) :   0.17.1
  - main document of a validated invoice read through document.php of Dolibarr: its unalterable log (DOC_PREVIEW)
    and its DUPLICATA counter work as with the preview of Dolibarr
  - this document is never replaced in place by the editors (a copy instead, the choice greyed with the reason),
    hidden setting DOCSTUDIO_ALLOW_REPLACE_VALIDATED to allow it
  - saving of an image: memory asked limited by DOCSTUDIO_MAX_MEMORY (256 MB by default), refused beyond
  - fonts uploaded in the setup checked by the antivirus
  - preferences of a user of another entity changed by a superadmin only
  - messages of the saving shown without HTML entities

- NEW: office server, signature of its calls checked (WOPI proof keys) and addresses allowed :   0.17.0
  - when the discovery gives a public key, every call of wopi.php must be signed by the office server (token,
    address, time of less than 20 minutes; current and old key): a token alone is no longer enough
  - setup: addresses allowed to call Dolibarr (IPv4, IPv6, IPv4 ranges), empty for any; the Test button says whether
    the office server signs its calls and how to give it a key (Collabora: coolconfig generate-proof-key)
  - hidden setting DOCSTUDIO_WOPI_PROOF_OFF for a troubleshooting (signature only logged)

## 0.16

- FIX: viewer in the window of inbox, name of the file, download and opening in a tab shown twice :   0.16.1
  - embed=2 (asked by the hook for inbox): the page shows no name, download, opening in a tab nor choice of the
    viewer, the window of the host has them; only the tools of the viewer stay, no header at all when it has none
    (office server)

- NEW: attachments of the mails of inbox opened by DocStudio (lot 5) :   0.16.0
  - source « inbox », read only, through the access given by inbox (InboxAttachmentFile): rights of the user on the
    mailbox, antivirus of inbox first, nothing written
  - hook inboxAttachmentViewers: the viewers of DocStudio allowed for the type (office server, Office preview, PDF,
    images, text) in « Open with… » of inbox and in its viewer window
  - FIX: button « Open in a new tab » of a viewer shown in a dialog with an icon instead of a text arrow

## 0.15

- NEW: office server (WOPI): Office and OpenDocument files opened and edited in Collabora Online (lot 4) :   0.15.0
  - DocStudio is a WOPI host (wopi.php): file information, reading, saving in place, locks; also usable with
    OnlyOffice and Euro-Office (same protocol, not tested yet)
  - reading for the Office types (docx, xlsx, pptx, odt, ods, odp, doc, xls, ppt, rtf...), editing for docx, xlsx,
    pptx, odt, ods, odp with the pencil of the lists of documents; several users can edit together
  - first choice when it is set, the Office viewer of DocStudio stays available; PDF, images and text keep the viewers
    of DocStudio
  - access token signed, for one user, one file and one mode, 10 hours; the file is loaded again with the rights of the
    user at each call; saving checked again (write permission, package of the type of the file, size, antivirus, hook
    docstudioBeforeSave); a token for reading cannot write nor lock; the token is posted to the frame, never in its
    address
  - back button: the office server saves first; conflict when the file changed elsewhere meanwhile (Collabora asks)
  - setup « Office server »: address, address of Dolibarr seen by the office server, Test button (version, types)

## 0.14

- NEW: Office viewer for Word (docx), Excel (xlsx) and PowerPoint (pptx) files, in the browser (lot 3) :   0.14.0
  - magnifier on the Office files of the lists of documents (file associations: the default for these types)
  - Word and PowerPoint: pages and slides one under the other, « Page 2 / 5 » or « Slide 1 / 3 »; Excel: the sheets
    with their tabs, colors, merged cells and the values of the formulas saved by Excel (no new calculation)
  - zoom (buttons, Ctrl + wheel), fit to the width (125 % at most at the opening), search with Ctrl + F
  - read only and safe: the document is only drawn (no macro nor script runs), its links are not followed, videos do
    not play, no font is downloaded from the Internet; files up to 50 MB, message saying to download it otherwise
  - @silurus/ooxml 0.89.8 embedded (MIT, Rust/WASM parsers and Canvas renderer, 21 MB)
  - FIX: PDF viewer, thumbnails and search buttons with icons instead of text characters

## 0.13

- NEW: PDF editor, zoom buttons in the status bar like the image editor (minus, value, plus, fit to the width, 1:1) :   0.13.4

- NEW: PDF editor, hand like the image editor: Space held over the pages, or middle button, then drag :   0.13.3
  - no tool used meanwhile (text, drawing, zones...), Space stays a space while a text is typed

- FIX: image editor, the zoom buttons and 1:1 came back to « fit to the window » as soon as the scrollbars appeared :   0.13.2
  - the zoom follows the window only while fitted, a zoom chosen by the user stays (buttons, 1:1, Ctrl + wheel)
  - the point under the mouse (wheel) or the middle of the view (buttons) stays in place
  - hand: Space held over the image, or middle button, then drag to move the view (no tool used meanwhile)

- FIX: arrows of the back and zoom buttons hard to read (characters of the text) :   0.13.1
  - icons of the editors instead (back arrow, minus, plus, fit to the window), in the viewers too

- NEW: PDF editor, compression when saving, and light or dark theme switch in the status bar :   0.13.0
  - compression none, light (images at 200 dpi), medium (150 dpi) or strong (100 dpi): only the images are reduced
    and encoded again, the text stays text, the original is kept when the file is not smaller; also for a PDF saved
    without any other change
  - done by the server: Ghostscript first (on the Dolibarr server), Stirling PDF when Ghostscript is not set or fails;
    nothing is saved when the compression fails (message saying what to do)
  - setup « Server tools for the PDF editor »: path of Ghostscript, address and API key of Stirling PDF (stored
    encrypted, never sent to the browser), Test button showing their versions
  - setup: what the redaction really does (pages made images, checked, what is lost), and why Stirling PDF is not used
    to redact (its zone redaction only puts a box over the text, measured on 1.3.2 and 2.1.4)
  - switch light / dark next to the zoom of the editors, the new theme grows in a circle from the button, saved for
    the user (the theme of Dolibarr does not change)
  - FIX: messages of the server shown with HTML entities in the editors (é shown as &eacute;)

## 0.12

- FIX: image editor, the layers panel jumped when the first elements were added :   0.12.2
  - an element shows in the layers as soon as it is put, a text while it is written (its name follows)
  - « no element » line of the height of a layer, help always at the bottom of the panel, room kept for the count
    of the Layers button

- FIX: editors, name of the tool higher than the name of the file in the top bar when the tool has no option :   0.12.1

- NEW: image editor, layers panel :   0.12.0
  - panel on the right with the elements over the photo, the top one first, the photo always at the bottom
  - show or hide, lock (no longer selectable), rename (double click or F2), order by drag and drop or with
    Alt + up and down arrows; click on a layer selects its element
  - in the history of the editor (undo and redo), hidden layers left out of the image saved, said when saving
  - panel shown or hidden with the « Layers » button of the status bar, remembered by the browser

## 0.11

- FIX: editors, the Save button moved down when the options of the tool took several lines :   0.11.2
  - Save always at the top right, only the options of the tool wrap in their zone
  - status bar at the bottom like the image editors: zoom, help of the tool and size of the image (image editor),
    active page and help of the tool (PDF editor)

- FIX: same checks as document.php of Dolibarr 24 (security audit) :   0.11.1
  - check per object (checkUserAccessToDocumentObject): a user restricted to his own customers can no longer view
    nor edit the documents of the other thirdparties
  - hook downloadDocument called before the content is sent: the modules refusing or logging the downloads see the
    files opened in DocStudio too

- NEW: image editor, cutout (remove the background), taken over from Creative Banners :   0.11.0
  - tool « Remove the background » (W) with 4 modes: wand (click on the background, the pixels of its color around
    are removed, click again to bring them back, own tolerance per point), eraser and restore brushes (size,
    hardness, [ and ]), pen (Bezier paths keeping or removing their inside, soft edge, corner or smooth points)
  - option « White background from the borders » with its tolerance, « Start again »
  - own undo and redo (50 steps) until applied, then one step of the editor; applied when another tool is chosen
    or when saving, dropped with Cancel or Escape
  - the photo made keeps its source: opening the tool again goes on with the same cutout (restore brings back the
    pixels) until the image is cropped, turned, resized or changed otherwise
  - made for big photos: only the zone of the brush is computed again while painting
  - JPEG has no transparency: the removed background becomes white, said in the help of the tool

## 0.10

- FIX: document refused (dataroot) when a directory of the documents is a symbolic link to another disk :   0.10.2
  - a file that is itself a symbolic link leaving the documents is still refused

- FIX: superadmin refused on the documents of another entity (multicompany), reason of a refusal in the log :   0.10.1
  - the reason (entity, permission, thirdparty, dataroot) is also added to the message for administrators

- NEW: image editor for PNG, JPEG and WebP (lot 2b, cutout in a next version) :   0.10.0
  - same layout as the PDF editor: palette of tools on the left, options of the tool on top, shortcuts, pencil
    « Edit with DocStudio » on the images of the lists of documents (file associations: Editing)
  - image: crop (free or fixed ratio, thirds), rotate left or right, flip, resize, brightness and contrast with a
    live preview; the elements follow the image (texts and numbers stay readable)
  - mask a zone in the photo itself: pixels, blur (help says a blurred text may stay guessable) or plain color
  - elements editable until saving: arrow, line, rectangle, ellipse (Shift: straight angles, square, circle),
    drawing, highlighter, numbers 1, 2, 3..., text with font, size, color, bold, italic, outline, background, shadow
  - undo and redo (50 steps, Ctrl+Z, Ctrl+Y), delete the selected element, zoom (Ctrl + wheel, fit, 1:1)
  - photo shown in the orientation of the camera, images up to 50 million pixels
  - saving as a copy or instead of the original: real type of the content checked against the extension, image
    decoded and encoded again by the server (no EXIF, GPS position nor data hidden after the image), transparency
    of PNG and WebP kept, JPEG on white
  - Fabric.js 7.4.0 embedded (MIT)

## 0.9

- NEW: PDF editor, cross and filled circle next to the check :   0.9.0
  - group Check / Cross / Filled circle in the palette (small triangle, right click or long press), same use:
    click to put, click again to remove
  - written as vectors in the PDF like the check

## 0.8

- NEW: PDF editor, Check tool to tick the boxes of a form :   0.8.0
  - click on a box: a check centered on it, click on the check again: removed (shortcut K)
  - size and color in the options, « Remove the last element » for the checks too
  - written as vector paths in the PDF (sharp when printed), also on rotated pages

## 0.7

- FIX: PDF editor, shadow of the page flashing when the mouse is on the page actions, grey frame around the pages :   0.7.2
  - the preview no longer animates the shadow (a huge spread was animated): a frame and a badge fade in (opacity
    only, 150 ms), nothing else moves; no animation with « reduce motion »
  - the 3 page actions are one group: moving from one to the other keeps the preview on
  - no transparent border around the pages: the shadow hugs the page, outline only in the preview

- FIX: PDF editor, active page marked by a deeper shadow only, without outline :   0.7.1
  - the outline stays for the preview of the page actions (mouse on rotate or delete)

- NEW: PDF editor, active page shown: the page the rotation and the deletion apply to :   0.7.0
  - outline and shadow on the active page (the one filling the view, or the one clicked with the Select tool),
    same outline on its thumbnail, « Page 3 / 4 » in the top bar
  - with the mouse on rotate left, rotate right or delete: page highlighted with a badge « Page 3 / 4 », tooltips
    naming the page (« Rotate page 3 right »)

## 0.6

- NEW: PDF editor, split directly between the pages of the document :   0.6.0
  - more space between the pages, scissors on a dashed line in it, label « Split the PDF after this page » on hover,
    same split as the scissors of the panel (both shown at once)

## 0.5

- FIX: PDF editor, a deleted page did not look deleted, scissors out of the toolbar and on the dashed line :   0.5.1
  - deleted page: stamp « Deleted page » and veil over it, button « Keep the page », delete button of the palette
    pressed while the page on screen is deleted
  - splitting only from the scissors between the pages of the panel, bigger and centered on their dashed line

- NEW: PDF editor laid out like the image editors: palette of tools on the left, options of the tool on top :   0.5.0
  - vertical palette with icons: Select, Text, Draw / Highlight / Straight line (group opened by its small triangle,
    a right click or a long press, the button shows the last tool used), Image, Redact, Crop, then the actions on
    the current page: rotate left, rotate right, split after it, delete it
  - top bar: name of the tool, its options only (font, bold, size, color, same frame for every page, remove the
    last zone, choose an image) and a short help
  - keyboard shortcuts of the tools: V, T, B, H, L, I, X, C
  - icons of Tabler Icons 3.50.0 (MIT) in an inline sprite (no request)

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
