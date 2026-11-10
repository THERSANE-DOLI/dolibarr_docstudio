<?php
/* Copyright (C) 2026		John BOTELLA				<john.botella@thersane.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/docstudio/class/actions_docstudio.class.php
 * \ingroup docstudio
 * \brief   Hooks of DocStudio on the pages of Dolibarr
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';


/**
 * Class ActionsDocStudio
 */
class ActionsDocStudio extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var mixed[] */
	public $results = array();

	/** @var ?string */
	public $resprints;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Viewers of DocStudio for the attachments of a mail of inbox (« Open with… » of inbox, lot 5). Inbox shows the
	 * page in its own window, which has the name, the download and the opening in a tab (embed=2: not repeated by the
	 * page); it adds « force=1 » when the user opens an attachment not scanned by the antivirus.
	 *
	 * @param array{mail:int,attachments:array<int,array{part:int,name:string,extension:string,size:int}>} $parameters
	 * @param mixed       $object      Not used
	 * @param string      $action      Not used
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function inboxAttachmentViewers($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		dol_include_once('/docstudio/class/docstudio.class.php');
		if (!isModEnabled('docstudio') || empty($parameters['attachments']) || !is_array($parameters['attachments'])) {
			return 0;
		}
		$registry = new DocStudio($this->db);
		if ($registry->getProvider('inbox') === null) {
			return 0;
		}
		$langs->load('docstudio@docstudio');
		$out = array();
		foreach ($parameters['attachments'] as $part => $a) {
			$extension = isset($a['extension']) ? (string) $a['extension'] : '';
			$viewers = $registry->getViewersFor($extension, isset($a['size']) ? (int) $a['size'] : 0);
			// The viewers the administrator allows for this type, like the lists of documents (all when none)
			$allowed = array_intersect_key($viewers, array_flip($registry->getUserChoices($extension)));
			if (count($allowed)) {
				$viewers = $allowed;
			}
			foreach ($viewers as $code => $viewer) {
				$out[(int) $part][] = array(
					'code' => $code,
					'label' => $viewer['label'],
					'url' => DocStudio::buildUrl('view.php', 'inbox', array('mail' => (int) $parameters['mail'], 'part' => (int) $part, 'force' => 0)).'&viewer='.rawurlencode($code).'&embed=2',
				);
			}
		}
		$this->results['inboxAttachmentViewers'] = $out;

		// « Annotate and file » (C2): the editors saving through DocStudio, inbox adds the piece chosen to the address
		$editors = array();
		if (empty($user->socid)) {
			foreach ($parameters['attachments'] as $part => $a) {
				$extension = isset($a['extension']) ? (string) $a['extension'] : '';
				if ($registry->getHandler($extension, $user, DocStudio::ACTION_EDIT) === DocStudio::HANDLER_NONE) {
					continue;
				}
				$list = $registry->getViewersFor($extension, isset($a['size']) ? (int) $a['size'] : 0, DocStudio::ACTION_EDIT);
				$list = array_intersect_key($list, array_flip($registry->getUserChoices($extension, DocStudio::ACTION_EDIT)));
				foreach ($list as $code => $viewer) {
					if (!empty($viewer['inplace'])) {
						continue;
					}
					$editors[(int) $part][] = array(
						'code' => $code,
						'label' => $viewer['label'],
						'url' => DocStudio::buildUrl('edit.php', 'inbox', array('mail' => (int) $parameters['mail'], 'part' => (int) $part, 'force' => 0)).'&viewer='.rawurlencode($code),
					);
				}
			}
		}
		$this->results['inboxAttachmentEditors'] = $editors;

		return 0;
	}

	/**
	 * Add the « Open with DocStudio » links to the lists of documents of the page
	 *
	 * @param array<string,mixed> $parameters Hook metadatas (context, etc...)
	 * @param CommonObject|null   $object     The object to process
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                            0
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs, $conf;

		// Internal users, and external users with the permission of DocStudio; not on public pages
		if (!isModEnabled('docstudio') || empty($user->id) || (!empty($user->socid) && !$user->hasRight('docstudio', 'external', 'read'))
			|| (isset($parameters['zone']) && $parameters['zone'] !== 'private')
			|| !empty($conf->dol_hide_topmenu) && GETPOST('dol_openinpopup', 'aZ09')) {
			return 0;
		}

		dol_include_once('/docstudio/class/docstudio.class.php');
		dol_include_once('/docstudio/lib/docstudio.lib.php');
		$langs->load('docstudio@docstudio');

		$registry = new DocStudio($this->db);
		$handlers = $registry->getHandlers($user);
		if (empty($handlers)) {
			return 0;
		}

		ob_start();
		docstudioPrintJsonData('docstudio-links-config', array(
			'viewUrl' => dol_buildpath('/docstudio/view.php', 1),
			'documentPath' => DOL_URL_ROOT.'/document.php',
			'handlers' => $handlers,
			// Editing: internal users only, the editor checks the permission to write again
			'editHandlers' => empty($user->socid) ? $registry->getHandlers($user, DocStudio::ACTION_EDIT) : array(),
			'editUrl' => dol_buildpath('/docstudio/edit.php', 1),
			'editLabel' => $langs->transnoentitiesnoconv('DocStudioEdit'),
			'dolibarrHandler' => DocStudio::HANDLER_DOLIBARR,
			'noneHandler' => DocStudio::HANDLER_NONE,
			'label' => $langs->transnoentitiesnoconv('DocStudioOpenWithDocStudio'),
		));
		print '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag(docstudioAssetUrl('/docstudio/js/docstudio-links.js')).'"></script>'."\n";
		$this->resprints = ob_get_clean();

		return 0;
	}
}
