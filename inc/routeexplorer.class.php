<?php

final class PluginPatchpanelRouteExplorer
{
    public static function search(
        string $query = '',
        string $impactType = '',
        int $impactId = 0
    ): array {
        global $DB;

        $query = trim($query);
        $terms = preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $impactType = self::normalizeImpactType($impactType);
        $impactId = max(0, $impactId);
        if (!$terms && ($impactType === '' || $impactId <= 0)) {
            return [];
        }
        $entityCondition = getEntitiesRestrictCriteria(
            PluginPatchpanelPanel::getTable(),
            'entities_id',
            '',
            true
        );
        $results = [];

        foreach ($DB->request([
            'SELECT' => [
                PluginPatchpanelPanelPort::getTable() . '.*',
                PluginPatchpanelPanel::getTable() . '.name AS panel_name',
                PluginPatchpanelPanel::getTable() . '.locations_id',
            ],
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'INNER JOIN' => [
                PluginPatchpanelPanel::getTable() => [
                    'FKEY' => [
                        PluginPatchpanelPanelPort::getTable() => 'plugin_patchpanel_panels_id',
                        PluginPatchpanelPanel::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                PluginPatchpanelPanel::getTable() . '.is_deleted' => 0,
                $entityCondition,
            ],
            'ORDER' => [
                PluginPatchpanelPanel::getTable() . '.name ASC',
                PluginPatchpanelPanelPort::getTable() . '.number ASC',
            ],
        ]) as $row) {
            $steps = PluginPatchpanelRoute::getStepsForPort((int) $row['id']);
            if ($impactId > 0 && !self::containsReference($steps, $impactType, $impactId)) {
                continue;
            }
            if ($terms && !self::matchesTerms($row, $steps, $terms)) {
                continue;
            }

            $row['steps'] = $steps;
            $row['impact_components'] = self::getImpactComponents($steps);
            $results[] = $row;
        }

        return $results;
    }

    private static function normalizeImpactType(string $itemtype): string
    {
        return in_array($itemtype, [NetworkEquipment::class, NetworkPort::class], true)
            ? $itemtype
            : '';
    }

    private static function containsReference(array $steps, string $itemtype, int $itemsId): bool
    {
        if ($itemtype === '' || $itemsId <= 0) {
            return true;
        }
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === $itemtype && (int) ($step['id'] ?? 0) === $itemsId) {
                return true;
            }
        }
        return false;
    }

    private static function matchesTerms(array $row, array $steps, array $terms): bool
    {
        $parts = [
            $row['panel_name'] ?? '',
            $row['label'] ?? '',
            $row['number'] ?? '',
            $row['media'] ?? '',
        ];
        foreach ($steps as $step) {
            $parts[] = $step['label'] ?? '';
            $parts[] = $step['type'] ?? '';
        }
        $haystack = mb_strtolower(implode(' ', $parts));
        foreach ($terms as $term) {
            if (!str_contains($haystack, $term)) {
                return false;
            }
        }
        return true;
    }

    private static function getImpactComponents(array $steps): array
    {
        $components = [];
        foreach ($steps as $step) {
            if (($step['type'] ?? '') !== NetworkEquipment::class || !empty($step['broken'])) {
                continue;
            }
            $key = $step['type'] . ':' . (int) $step['id'];
            $components[$key] = $step;
        }
        return array_values($components);
    }

    public static function getImpactLabel(string $itemtype, int $itemsId): string
    {
        if ($itemsId <= 0 || !in_array($itemtype, [NetworkEquipment::class, NetworkPort::class], true)) {
            return '';
        }
        $item = new $itemtype();
        return $item->getFromDB($itemsId) && $item->canViewItem() ? $item->getName() : '';
    }

    public static function render(string $query, string $impactType, int $impactId): void
    {
        global $CFG_GLPI;

        $results = self::search($query, $impactType, $impactId);
        $explorerUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/routes.php';
        $impactLabel = self::getImpactLabel($impactType, $impactId);
        $hasFilter = trim($query) !== '' || $impactLabel !== '';

        echo "<div class='container-fluid patchpanel-explorer'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<div><h1 class='h2 mb-1'>" . htmlescape(__('Physical route explorer', 'patchpanel')) . '</h1>';
        echo "<p class='text-muted mb-0'>" .
            htmlescape(__('Search every registered object in the cable route and inspect shared infrastructure impact.', 'patchpanel')) .
            '</p></div>';
        echo "<a class='btn btn-outline-secondary ms-auto' href='" .
            htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.php') . "'>";
        echo "<i class='ti ti-layout-grid'></i> " . htmlescape(__('Patch panels', 'patchpanel')) . '</a></div>';

        if ($impactLabel !== '') {
            echo "<div class='alert alert-warning d-flex align-items-center gap-2'>";
            echo "<i class='ti ti-affiliate'></i><span>" .
                sprintf(
                    htmlescape(__('%1$d patch panel routes depend on %2$s.', 'patchpanel')),
                    count($results),
                    htmlescape($impactLabel)
                ) . '</span>';
            echo "<a class='btn btn-sm btn-outline-secondary ms-auto' href='" .
                htmlescape($explorerUrl) . "'>" . htmlescape(__('Clear impact filter', 'patchpanel')) . '</a></div>';
        }

        echo "<form class='card card-body mb-3' method='get' action='" . htmlescape($explorerUrl) . "'>";
        echo "<label class='form-label'>" . htmlescape(__('Route search', 'patchpanel')) . '</label>';
        echo "<div class='d-flex flex-wrap gap-2'>";
        echo Html::input('q', [
            'value' => $query,
            'placeholder' => __('Panel, endpoint, device, switch, port or firewall', 'patchpanel'),
        ]);
        echo "<button class='btn btn-primary' type='submit'><i class='ti ti-search'></i> " .
            htmlescape(__('Search')) . '</button>';
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($explorerUrl) . "'>" .
            htmlescape(__('Reset')) . '</a></div>';
        echo "<div class='form-text'>" .
            htmlescape(__('All words must occur somewhere in the same physical route.', 'patchpanel')) .
            '</div></form>';

        echo "<div class='d-flex align-items-center mb-2'><strong>" .
            sprintf(htmlescape(_n('%d route', '%d routes', count($results), 'patchpanel')), count($results)) .
            '</strong></div>';
        if (!$hasFilter) {
            echo "<div class='alert alert-info'>" .
                htmlescape(__('Enter one or more terms to search physical routes.', 'patchpanel')) .
                '</div>';
        } elseif (!$results) {
            echo "<div class='alert alert-info'>" .
                htmlescape(__('No physical routes match these filters.', 'patchpanel')) . '</div>';
        }
        foreach ($results as $row) {
            $portUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' .
                (int) $row['id'];
            echo "<article class='card mb-3 patchpanel-explorer-result'><div class='card-header'>";
            echo "<div><a class='fw-bold' href='" . htmlescape($portUrl) . "'>" .
                htmlescape($row['panel_name']) . ' / #' . (int) $row['number'] . '</a>';
            echo "<div class='text-muted small'>" . htmlescape($row['label'] ?: '-') . '</div></div>';
            echo "<a class='btn btn-sm btn-outline-primary ms-auto' href='" . htmlescape($portUrl) . "'>" .
                htmlescape(__('Open port', 'patchpanel')) . '</a></div><div class="card-body">';
            PluginPatchpanelRoute::renderSteps($row['steps']);
            if ($row['impact_components']) {
                echo "<div class='patchpanel-impact-links mt-3'><span class='text-muted small'>" .
                    htmlescape(__('Impact analysis:', 'patchpanel')) . '</span>';
                foreach ($row['impact_components'] as $component) {
                    $url = $explorerUrl . '?' . http_build_query([
                        'impact_type' => $component['type'],
                        'impact_id' => $component['id'],
                    ]);
                    echo "<a class='btn btn-sm btn-outline-warning' data-impact-id='" .
                        (int) $component['id'] . "' href='" . htmlescape($url) . "'>";
                    echo "<i class='ti ti-affiliate'></i> " . htmlescape($component['label']) . '</a>';
                }
                echo '</div>';
            }
            echo '</div></article>';
        }
        echo '</div>';
    }
}
