# CHANGELOG DOCSTUDIO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## 0.12

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
