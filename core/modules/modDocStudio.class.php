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
 * 	\defgroup   docstudio     Module DocStudio
 *  \brief      View documents (PDF, images, text, CSV...) in Dolibarr through pluggable viewers.
 *
 *  \file       htdocs/docstudio/core/modules/modDocStudio.class.php
 *  \ingroup    docstudio
 *  \brief      Description and activation file for module DocStudio
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module DocStudio
 */
class modDocStudio extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		$this->numero = 141932;
		$this->rights_class = 'docstudio';
		$this->family = "ecm";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "DocStudioDescription";
		$this->descriptionlong = "DocStudioDescriptionLong";
		$this->editor_name = 'THERSANE';
		$this->editor_url = 'https://www.thersane.fr';
		$this->version = '0.8.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'docstudio@docstudio';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(
				'/docstudio/css/docstudio-links.css',
			),
			'js' => array(),
			// printCommonFooter on every page adds the « Open with DocStudio » links to the lists of documents
			'hooks' => array(
				'data' => array('all'),
				'entity' => '0',
			),
			// External users may receive the permission « View the documents of their own thirdparty »
			'moduleforexternal' => 1,
			'websitetemplates' => 0,
			'captcha' => 0
		);

		$this->dirs = array();
		$this->config_page_url = array("setup.php@docstudio");
		$this->hidden = getDolGlobalInt('MODULE_DOCSTUDIO_DISABLED');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("docstudio@docstudio");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(23, -3);
		$this->need_javascript_ajax = 1;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array();

		if (!isModEnabled("docstudio")) {
			$conf->docstudio = new stdClass();
			$conf->docstudio->enabled = 0;
		}

		// Tab of the user cards, only when the administrator left a choice of opening to the users
		$this->tabs = array(
			array('data' => 'user:+docstudio:DocStudioOpeningTab:docstudio@docstudio:getDolGlobalInt("DOCSTUDIO_HAS_USER_CHOICES"):/docstudio/user_opening.php?id=__ID__'),
		);
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// Internal users need no permission of their own: viewing a document follows the permission of the document
		// itself (the same check as document.php). External users need this one in addition, and only see the
		// documents of their own thirdparty (same filter as document.php).
		$this->rights = array();
		$r = 0;
		$o = 1;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", ($o * 10) + 1);
		$this->rights[$r][1] = 'DocStudioRightExternalRead';
		$this->rights[$r][4] = 'external';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->menu = array();
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		// Nothing is stored in database: enabling the module again is always harmless
		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
