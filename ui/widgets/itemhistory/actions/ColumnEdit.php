<?php declare(strict_types = 0);
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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


namespace Widgets\ItemHistory\Actions;

use API,
	CController,
	CControllerResponseData,
	CNumberParser,
	CParser;

use Widgets\ItemHistory\Includes\CWidgetFieldColumnsList;

class ColumnEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// Validation is done by CWidgetFieldColumnsList
		$fields = [
			'name' =>				'string',
			'base_color' =>			'string',
			'itemid' =>				'int32',
			'highlights' =>			'array',
			'display' =>			'int32',
			'max_length' =>			'int32',
			'min' =>				'string',
			'max' =>				'string',
			'thresholds' =>			'array',
			'history' =>			'int32',
			'monospace_font' =>		'int32',
			'local_time' =>			'int32',
			'show_thumbnail' =>		'int32',
			'edit' =>				'in 1',
			'update' =>				'in 1',
			'templateid' =>			'string'
		];

		$ret = $this->validateInput($fields) && $this->validateFields();

		if (!$ret) {
			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode([
					'error' => [
						'messages' => array_column(get_and_clear_messages(), 'message')
					]
				], JSON_THROW_ON_ERROR)]))->disableView()
			);
		}

		return $ret;
	}

	protected function validateFields(): bool {
		if (!$this->hasInput('update')) {
			return true;
		}

		$input = $this->getInputAll();

		$field = new CWidgetFieldColumnsList('columns', '');

		if (!$this->hasInput('edit') && !$this->hasInput('update')) {
			$input += self::getColumnDefaults();
		}

		unset($input['edit'], $input['update'], $input['templateid']);
		$field->setValue([$input]);

		$errors = $field->validate(true);
		array_map('error', $errors);

		return !$errors;
	}

	protected function checkPermissions(): bool {
		return true;
	}

	protected function doAction(): void {
		$input = $this->getInputAll();
		unset($input['update']);

		$item_ms = [];
		$item_value_type = null;

		if (array_key_exists('itemid', $input) && $input['itemid'] !== '' ) {
			$output = ['itemid', 'value_type'];
			$output[] = $this->hasInput('templateid') ? 'name' : 'name_resolved';

			$db_item = API::Item()->get([
				'output' => $output,
				'itemids' => [$input['itemid']],
				'selectHosts' => ['name'],
				'webitems' => true
			]);

			$db_item = $db_item[0];

			if ($db_item) {
				$item_ms = [
					'id' => $db_item['itemid'],
					'prefix' => $db_item['hosts'][0]['name'].NAME_DELIMITER,
					'name' => $this->hasInput('templateid') ? $db_item['name'] : $db_item['name_resolved']
				];

				$item_value_type = $db_item['value_type'];
			}
			else {
				$item_ms = [
					'id' => $input['itemid'],
					'name' => _('Inaccessible item')
				];
			}
		}

		if (!$this->hasInput('update')) {
			$data = [
				'action' => $this->getAction(),
				'colors' => CWidgetFieldColumnsList::DEFAULT_COLOR_PALETTE,
				'ms_item' => $item_ms,
				'item_value_type' => $item_value_type,
				'templateid' => $this->hasInput('templateid') ? $this->getInput('templateid') : null,
				'errors' => hasErrorMessages() ? getMessages() : null,
				'user' => [
					'debug_mode' => $this->getDebugMode()
				]
			] + $input + self::getColumnDefaults();

			$this->setResponse(new CControllerResponseData($data));

			return;
		}

		$number_parser = new CNumberParser(['with_size_suffix' => true, 'with_time_suffix' => true]);

		$thresholds = [];

		if (array_key_exists('thresholds', $input)) {
			foreach ($input['thresholds'] as $threshold) {
				$order_threshold = trim($threshold['threshold']);

				if ($order_threshold !== '' && $number_parser->parse($order_threshold) == CParser::PARSE_SUCCESS) {
					$thresholds[] = $threshold + ['order_threshold' => $number_parser->calcValue()];
				}
			}

			unset($input['thresholds']);
		}

		if ($thresholds) {
			uasort($thresholds,
				static function (array $threshold_1, array $threshold_2): int {
					return $threshold_1['order_threshold'] <=> $threshold_2['order_threshold'];
				}
			);

			$input['thresholds'] = [];

			foreach ($thresholds as $threshold) {
				unset($threshold['order_threshold']);

				$input['thresholds'][] = $threshold;
			}
		}

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode($input, JSON_THROW_ON_ERROR)]))->disableView()
		);
	}

	/**
	 * Retrieve the default configuration values for column in Item history widget.
	 *
	 * @return array
	 */
	private static function getColumnDefaults(): array {
		static $column_defaults;

		if ($column_defaults === null) {
			$column_defaults = [
				'name' => '',
				'itemid' => '',
				'base_color' => '',
				'display' => CWidgetFieldColumnsList::DISPLAY_AS_IS,
				'min' => '',
				'max' => '',
				'max_length' => CWidgetFieldColumnsList::SINGLE_LINE_LENGTH_DEFAULT,
				'thresholds' => [],
				'highlights' => [],
				'history' => CWidgetFieldColumnsList::HISTORY_DATA_AUTO,
				'monospace_font' => 0,
				'local_time' => 0,
				'show_thumbnail' => 0
			];
		}

		return $column_defaults;
	}
}
