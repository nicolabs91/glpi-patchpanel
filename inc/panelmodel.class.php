<?php

class PluginPatchpanelPanelModel extends CommonDropdown
{
    public static $rightname = 'networking';

    public static function getTypeName($nb = 0): string
    {
        return _n('Patch panel model', 'Patch panel models', $nb, 'patchpanel');
    }

    public function prepareInputForAdd($input): array|false
    {
        return $this->normalizeLayout($input);
    }

    public function prepareInputForUpdate($input): array|false
    {
        return $this->normalizeLayout($input);
    }

    private function normalizeLayout(array $input): array
    {
        if (isset($input['port_count'])) {
            $input['port_count'] = max(1, min(512, (int) $input['port_count']));
        }
        if (isset($input['rows'])) {
            $input['rows'] = max(1, min(8, (int) $input['rows']));
        }
        if (isset($input['media'])) {
            $input['media'] = PluginPatchpanelPanelPort::normalizeMedia((string) $input['media']);
        }
        return $input;
    }

    public static function getDefinition(int $id): ?array
    {
        $model = new self();
        if ($id <= 0 || !$model->getFromDB($id)) {
            return null;
        }

        return [
            'port_count' => max(1, (int) $model->fields['port_count']),
            'rows' => max(1, (int) $model->fields['rows']),
            'media' => PluginPatchpanelPanelPort::normalizeMedia((string) $model->fields['media']),
        ];
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __('Name') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]);
        echo '</td><td>' . __('Number of ports', 'patchpanel') . '</td><td>';
        echo Html::input('port_count', [
            'type' => 'number',
            'value' => $this->fields['port_count'] ?? 24,
            'min' => 1,
            'max' => 512,
        ]);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __('Rows', 'patchpanel') . "</td><td>";
        Dropdown::showNumber('rows', [
            'value' => $this->fields['rows'] ?? 1,
            'min' => 1,
            'max' => 8,
        ]);
        echo '</td><td>' . __('Media', 'patchpanel') . '</td><td>';
        Dropdown::showFromArray('media', PluginPatchpanelPanelPort::getMediaOptions(), [
            'value' => $this->fields['media'] ?? 'copper',
        ]);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . _n('Comment', 'Comments', 1) . "</td><td colspan='3'>";
        Html::textarea([
            'name' => 'comment',
            'value' => $this->fields['comment'] ?? '',
            'rows' => 8,
        ]);
        echo '</td></tr>';

        $this->showFormButtons($options);
        return true;
    }
}
