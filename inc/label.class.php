<?php

use Com\Tecnick\Barcode\Barcode;

final class PluginPatchpanelLabel
{
    public static function getPortUrl(int $portId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['url_base'] .
            '/plugins/patchpanel/front/panelport.form.php?id=' .
            $portId;
    }

    public static function getQrDataUri(string $value): string
    {
        $barcode = new Barcode();
        $qr = $barcode->getBarcodeObj(
            'QRCODE,H',
            $value,
            180,
            180,
            'black',
            [6, 6, 6, 6]
        )->setBackgroundColor('white');

        return 'data:image/png;base64,' . base64_encode($qr->getPngData());
    }

    public static function getRackName(int $panelId): string
    {
        global $DB;

        $relation = $DB->request([
            'SELECT' => [
                'glpi_racks.name AS rack_name',
                Item_Rack::getTable() . '.position',
            ],
            'FROM' => Item_Rack::getTable(),
            'INNER JOIN' => [
                Rack::getTable() => [
                    'FKEY' => [
                        Item_Rack::getTable() => 'racks_id',
                        Rack::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                Item_Rack::getTable() . '.itemtype' => PluginPatchpanelPanel::class,
                Item_Rack::getTable() . '.items_id' => $panelId,
            ],
            'LIMIT' => 1,
        ])->current();

        if (!$relation) {
            return '';
        }
        return sprintf(
            __('%1$s, rack unit %2$d', 'patchpanel'),
            $relation['rack_name'],
            (int) $relation['position']
        );
    }

    public static function getPorts(
        PluginPatchpanelPanel $panel,
        int $from,
        int $to
    ): array {
        global $DB;

        $max = (int) $panel->fields['port_count'];
        $from = max(1, min($max, $from));
        $to = max($from, min($max, $to));

        return iterator_to_array($DB->request([
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'WHERE' => [
                'plugin_patchpanel_panels_id' => (int) $panel->getID(),
                'number' => ['>=', $from],
                [
                    'number' => ['<=', $to],
                ],
            ],
            'ORDER' => ['number ASC'],
        ]));
    }
}
