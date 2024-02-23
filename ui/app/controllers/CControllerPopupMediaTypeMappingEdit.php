<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerPopupMediaTypeMappingEdit extends CController {

	protected function init() {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'add_media_type_mapping' =>		'in 1',
			'userdirectory_mediaid' =>		'id',
			'name' =>						'string',
			'mediatypeid' =>				'db media_type.mediatypeid',
			'attribute' =>					'string',
			'period' =>						'time_periods',
			'severity' =>					'int32|ge 0|le '.(pow(2, TRIGGER_SEVERITY_COUNT) - 1),
			'active' =>						'in '.implode(',', [MEDIA_STATUS_ACTIVE, MEDIA_STATUS_DISABLED])
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(
				(new CControllerResponseData([
					'main_block' => json_encode([
						'error' => [
							'title' => _('Invalid media type mapping configuration.'),
							'messages' => array_column(get_and_clear_messages(), 'message')
						]
					])
				]))->disableView()
			);
		}

		return $ret;
	}

	/**
	 * @throws APIException
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_AUTHENTICATION);
	}

	/**
	 * @throws Exception
	 */
	protected function doAction(): void {
		$data = [
			'add_media_type_mapping' => 0,
			'userdirectory_mediaid' => 0,
			'name' => '',
			'attribute' => '',
			'mediatypeid' => 0,
			'period' => ZBX_DEFAULT_INTERVAL,
			'severity' => $this->hasInput('add_media_type_mapping') ? pow(2, TRIGGER_SEVERITY_COUNT) - 1 : 0,
			'active' => MEDIA_STATUS_ACTIVE
		];
		$this->getInputs($data, array_keys($data));
		$data += [
			'severities' => [],
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		];

		foreach (CSeverityHelper::getSeverities() as $severity) {
			if (pow(2, $severity['value']) & $data['severity']) {
				$data['severities'][] = $severity['value'];
			}
		}

		$data['db_mediatypes'] = API::MediaType()->get(['output' => ['name', 'mediatypeid']]);
		CArrayHelper::sort($data['db_mediatypes'], ['name']);
		$data['db_mediatypes'] = array_column($data['db_mediatypes'], 'name', 'mediatypeid');

		$this->setResponse(new CControllerResponseData($data));
	}
}
