<?php
/*
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once dirname(__FILE__).'/../common/testMultiselectDialogs.php';

/**
 * Test for checking that multiselects' dialogs do not contain any errors before and after filling.
 */
class testMultiselectsLatestData extends testMultiselectDialogs {

	public static function getCheckDialogsData() {
		return [
			// #0.
			[
				[
					'fields' => [
						'Host groups' => 'Zabbix servers'
					]
				]
			],
			// #1.
			[
				[
					'fields' => [
						'Hosts' => 'ЗАББИКС Сервер'
					]
				]
			]
		];
	}

	/**
	 * @dataProvider getCheckDialogsData
	 */
	public function testMultiselectsLatestData_CheckDialogs($data) {
		$this->page->login()->open('zabbix.php?action=latest.view');
		$filter_form = $this->query('name:zbx_filter')->asForm()->one();
		$multiselects = [['Host groups' => 'Host groups'], ['Hosts' => 'Hosts', 'Host group' => 'Host groups']];

		// Check all multiselects in filter before the first multiselect is filled.
		$this->checkMultiselectDialogs($filter_form, $multiselects);
		$filter_form->fill($data['fields']);

		// Check all multiselects in filter after the first multiselect is filled.
		$this->checkMultiselectDialogs($filter_form, $multiselects);

		$this->query('button:Reset')->waitUntilClickable()->one()->click();
	}
}
