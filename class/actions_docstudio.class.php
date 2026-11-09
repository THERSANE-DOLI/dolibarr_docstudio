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
		$extensions = $registry->getSupportedExtensions();
		if (empty($extensions)) {
			return 0;
		}

		ob_start();
		docstudioPrintJsonData('docstudio-links-config', array(
			'viewUrl' => dol_buildpath('/docstudio/view.php', 1),
			'documentPath' => DOL_URL_ROOT.'/document.php',
			'extensions' => $extensions,
			'label' => $langs->transnoentitiesnoconv('DocStudioOpenWithDocStudio'),
			'newTabLabel' => $langs->transnoentitiesnoconv('DocStudioOpenInNewTab'),
			'closeLabel' => $langs->transnoentitiesnoconv('DocStudioCloseDialog'),
		));
		print '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag(docstudioAssetUrl('/docstudio/js/docstudio-links.js')).'"></script>'."\n";
		$this->resprints = ob_get_clean();

		return 0;
	}
}
