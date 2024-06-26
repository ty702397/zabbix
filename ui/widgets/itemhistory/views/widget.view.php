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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


/**
 * Item history widget view.
 *
 * @var CView $this
 * @var array $data
 */

use Widgets\ItemHistory\Widget;

use Widgets\ItemHistory\Includes\{
	WidgetForm,
	CWidgetFieldColumnsList
};

$table = (new CTableInfo())->addClass($data['show_thumbnail'] ? 'show-thumbnail' : null);

if ($data['error'] !== null) {
	$table->setNoDataMessage($data['error']);
}
else {
	$columns_names_at_top = $data['layout'] == WidgetForm::LAYOUT_VERTICAL && count($data['columns']) > 1;

	if ($data['show_column_header'] != WidgetForm::COLUMN_HEADER_OFF) {
		$table_header = $data['show_timestamp']
			? [
				(new CColHeader(
					(new CSpan(_x('Timestamp', 'compact table header')))
						->addClass($data['show_column_header'] == WidgetForm::COLUMN_HEADER_VERTICAL
							? ZBX_STYLE_TEXT_VERTICAL
							: null
						)
				))
					->addClass(ZBX_STYLE_CELL_WIDTH)
					->addClass(ZBX_STYLE_NOWRAP)
			]
			: [];

		if ($data['layout'] == WidgetForm::LAYOUT_VERTICAL) {
			foreach ($data['columns'] as $column) {
				$table_header[] = (new CColHeader(
					(new CSpan($column['name']))
						->addClass($data['show_column_header'] == WidgetForm::COLUMN_HEADER_VERTICAL
							? ZBX_STYLE_TEXT_VERTICAL
							: null
						)->setTitle($column['name'])
				))->setColSpan($column['has_bar'] ? 2 : 1);
			}
		}
		else {
			$has_bar = array_filter(array_column($data['columns'], 'has_bar'));

			$table_header[] = (new CColHeader(
				(new CSpan(_x('Name', 'compact table header')))
					->addClass($data['show_column_header'] == WidgetForm::COLUMN_HEADER_VERTICAL
						? ZBX_STYLE_TEXT_VERTICAL
						: null
					)
					->setTitle(_x('Name', 'compact table header'))
			))
				->addClass(ZBX_STYLE_NOWRAP)
				->addClass(ZBX_STYLE_CELL_WIDTH);

			$table_header[] = (new CColHeader(
				(new CSpan(_x('Value', 'compact table header')))
					->addClass($data['show_column_header'] == WidgetForm::COLUMN_HEADER_VERTICAL
						? ZBX_STYLE_TEXT_VERTICAL
						: null
					)->setTitle(_x('Value', 'compact table header'))
			))->setColSpan($has_bar ? 2 : 1);
		}

		$table->setHeader($table_header);
	}

	$history_values = [];

	foreach ($data['columns'] as $columnid => $column) {
		foreach ($column['item_values'] as $item_value) {
			$history_item = [
				'columnid' => $columnid,
				'itemid' => $column['itemid'],
				'clock' => $item_value['clock'],
				'ns' => $item_value['ns']
			];

			$color = $column['base_color'];

			switch ($column['item_value_type']) {
				case ITEM_VALUE_TYPE_FLOAT:
				case ITEM_VALUE_TYPE_UINT64:
					if (!$column['has_bar']) {
						if (array_key_exists('thresholds', $column)) {
							foreach ($column['thresholds'] as $threshold) {
								$threshold_value = $column['has_binary_units']
									? $threshold['threshold_binary']
									: $threshold['threshold'];

								if ($item_value['value'] < $threshold_value) {
									break;
								}

								$color = $threshold['color'];
							}
						}

						$history_item['value'][] = (new CCol($item_value['formatted_value']))
							->addClass($data['layout'] == WidgetForm::LAYOUT_VERTICAL ? 'has-broadcast-data' : null)
							->setAttribute('data-itemid', $column['itemid'])
							->setAttribute('data-clock', $item_value['clock'].'.'.$item_value['ns'])
							->setAttribute('bgcolor', $color !== '' ? '#'.$color : null)
							->setHint(
								(new CDiv($item_value['value']))->addClass(ZBX_STYLE_HINTBOX_WRAP)
							);

						break;
					}

					$bar_gauge = (new CBarGauge())
						->setValue($item_value['value'])
						->setAttribute('fill', $color !== '' ? '#'.$color : Widget::DEFAULT_FILL)
						->setAttribute('min',  $column['has_binary_units'] ? $column['min_binary'] : $column['min'])
						->setAttribute('max',  $column['has_binary_units'] ? $column['max_binary'] : $column['max']);

					if ($column['display'] == CWidgetFieldColumnsList::DISPLAY_BAR) {
						$bar_gauge->setAttribute('solid', 1);
					}

					if (array_key_exists('thresholds', $column)) {
						foreach ($column['thresholds'] as $threshold) {
							$bar_gauge->addThreshold($threshold['threshold'], '#'.$threshold['color']);
						}
					}

					$history_item['value'][] = (new CCol($bar_gauge))
						->addClass($data['layout'] == WidgetForm::LAYOUT_VERTICAL ? 'has-broadcast-data' : null)
						->setAttribute('data-itemid', $column['itemid'])
						->setAttribute('data-clock', $item_value['clock'].'.'.$item_value['ns']);
					$history_item['value'][] = (new CCol(
						(new CDiv($item_value['formatted_value']))
					))
						->addClass($data['layout'] == WidgetForm::LAYOUT_VERTICAL ? 'has-broadcast-data' : null)
						->setAttribute('data-itemid', $column['itemid'])
						->setAttribute('data-clock', $item_value['clock'].'.'.$item_value['ns'])
						->addStyle('width: 0;')
						->addClass(ZBX_STYLE_NOWRAP)
						->setHint(
							(new CDiv($item_value['value']))->addClass(ZBX_STYLE_HINTBOX_WRAP)
						);

					break;

				case ITEM_VALUE_TYPE_LOG:
					if (array_key_exists('local_time', $column) && $column['local_time'] != 0) {
						$history_item['clock'] = $item_value['timestamp'];
					}
				case ITEM_VALUE_TYPE_STR:
				case ITEM_VALUE_TYPE_TEXT:
					if (array_key_exists('highlights', $column)) {
						foreach ($column['highlights'] as $highlight) {
							if (@preg_match('('.$highlight['pattern'].')', $item_value['value'])) {
								$color = $highlight['color'];
								break;
							}
						}
					}

					$value = $column['display'] == CWidgetFieldColumnsList::DISPLAY_SINGLE_LINE
						? substr($item_value['value'], 0, $column['max_length'])
						: $item_value['value'];

					$history_item['value'][] = (new CCol())
						->addClass($data['layout'] == WidgetForm::LAYOUT_VERTICAL ? 'has-broadcast-data' : null)
						->setAttribute('data-itemid', $column['itemid'])
						->setAttribute('data-clock', $item_value['clock'].'.'.$item_value['ns'])
						->addItem(($column['display'] != CWidgetFieldColumnsList::DISPLAY_HTML
							? new CDiv($value)
							: new CJsScript($value)
						))
						->addClass($column['display'] == CWidgetFieldColumnsList::DISPLAY_SINGLE_LINE
							? ZBX_STYLE_NOWRAP
							: null
						)
						->addClass($column['monospace_font']
							? ZBX_STYLE_MONOSPACE_FONT
							: null
						)
						->setAttribute('bgcolor', $color !== '' ? '#'.$color : null);

					break;

				case ITEM_VALUE_TYPE_BINARY:
					$history_item['value'][] = (new CCol(
						(new CButton(null, _('Show')))->addClass(ZBX_STYLE_BTN)
					))
						->addClass('binary-thumbnail')
						->addClass($data['layout'] == WidgetForm::LAYOUT_VERTICAL ? 'has-broadcast-data' : null)
						->setAttribute('bgcolor', $color !== '' ? '#'.$color : null)
						->setAttribute('data-itemid', $column['itemid'])
						->setAttribute('data-clock', $item_value['clock'].'.'.$item_value['ns']);
			}

			$history_values[] = $history_item;
		}
	}

	$sort_order = $data['sortorder'] == WidgetForm::NEW_VALUES_TOP
		? ZBX_SORT_DOWN
		: ZBX_SORT_UP;

	CArrayHelper::sort($history_values, [
		['field' => 'clock', 'order' => $sort_order],
		['field' => 'ns', 'order' => $sort_order]
	]);

	if (!$columns_names_at_top) {
		$history_values = array_slice($history_values, 0, $data['show_lines']);

		foreach($history_values as $history_item) {
			$table_row = $data['show_timestamp']
				? [
					(new CCol(
						zbx_date2str(DATE_TIME_FORMAT_SECONDS, $history_item['clock'])
					))->addClass(ZBX_STYLE_NOWRAP)
				]
				: [];

			if ($data['layout'] == WidgetForm::LAYOUT_HORIZONTAL) {
				$table->setHeadingColumn(0);
				$table_row[] = (new CCol($data['columns'][$history_item['columnid']]['name']))
					->addClass(ZBX_STYLE_NOWRAP);
			}

			$table_row[] = $history_item['value'][0];

			if ($data['columns'][$history_item['columnid']]['has_bar']) {
				$table_row[] = $history_item['value'][1];
			}

			$table->addRow($data['layout'] == WidgetForm::LAYOUT_HORIZONTAL
				? (new CRow($table_row))
					->addClass('has-broadcast-data')
					->setAttribute('data-itemid', $history_item['itemid'])
					->setAttribute('data-clock', $history_item['clock'].'.'.$history_item['ns'])
				: $table_row
			);
		}
	}
	else {
		$clock = 0;
		$row_values = [];

		do {
			$history_item = array_shift($history_values);

			if (($history_item === null && $row_values)
					|| ($history_item !== null && (
						($clock != 0 && $history_item['clock'] != $clock)
							|| array_key_exists($history_item['columnid'], $row_values)))) {
				$table_row = $data['show_timestamp']
					? [
						(new CCol(
							zbx_date2str(DATE_TIME_FORMAT_SECONDS, $clock)
						))->addClass(ZBX_STYLE_NOWRAP)
					]
					: [];

				foreach ($data['columns'] as $columnid => $column) {
					if (array_key_exists($columnid, $row_values)) {
						$values = $row_values[$columnid];
					}
					else {
						$values = $column['has_bar']
							? ['', '']
							: [''];
					}

					foreach ($values as $value) {
						$table_row[] = $value;
					}
				}

				$table->addRow($table_row);

				$row_values = [];
			}

			if ($history_item !== null) {
				$clock = $history_item['clock'];
				$row_values[$history_item['columnid']] = $history_item['value'];
			}
		} while ($history_item !== null && $table->getNumRows() < $data['show_lines']);
	}
}

(new CWidgetView($data))
	->addItem($table)
	->show();
