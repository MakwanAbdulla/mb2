<?php

namespace App\Services;

class WidgetService
{
    protected array $widgets = [];

    /**
     * Register a widget.
     *
     * @param string      $tag         Tag name, e.g. 'membership-fees'
     * @param string      $view        Full Laravel view name, e.g. 'components.cms.membership-fees'
     * @param string      $label       Human-readable label (Swedish)
     * @param string      $description Description shown in the editor reference panel
     * @param array       $params      Param definitions: [['name'=>'slug','required'=>true,'description'=>'...']]
     * @param string|null $example     Full example string (defaults to "[{tag}]")
     */
    public function register(
        string  $tag,
        string  $view,
        string  $label,
        string  $description,
        array   $params = [],
        ?string $example = null,
    ): void {
        $this->widgets[$tag] = [
            'tag'         => $tag,
            'view'        => $view,
            'label'       => $label,
            'description' => $description,
            'params'      => $params,
            'example'     => $example ?? "[$tag]",
        ];
    }

    /**
     * All registered widgets, for the editor reference panel.
     */
    public function all(): array
    {
        return array_values($this->widgets);
    }

    /**
     * Replace all widget tags in $html with rendered view output.
     * Supports [tag] and [tag key="value" key2="value2"].
     * On render failure the tag is replaced with an HTML comment so the
     * rest of the page continues to work.
     */
    public function render(?string $html): string
    {
        if (! $html) {
            return '';
        }

        return preg_replace_callback(
            '/\[([a-z0-9-]+)((?:\s+[a-z0-9_-]+=(?:"[^"]*"|\'[^\']*\'))*)\s*\]/',
            function (array $matches) {
                $tag = $matches[1];

                if (! isset($this->widgets[$tag])) {
                    return $matches[0]; // unknown tag — leave as-is
                }

                $params = [];
                preg_match_all('/([a-z0-9_-]+)="([^"]*)"/', $matches[2], $attrMatches, PREG_SET_ORDER);
                foreach ($attrMatches as $attr) {
                    $params[$attr[1]] = $attr[2];
                }

                try {
                    return view($this->widgets[$tag]['view'], ['params' => $params])->render();
                } catch (\Throwable $e) {
                    report($e);

                    try {
                        \App\Models\AuditLog::record(
                            action: 'widget_error',
                            area: 'cms',
                            subject: "Widget [{$tag}] kraschade: " . $e->getMessage(),
                            changes: [
                                'tag'    => $tag,
                                'params' => $params,
                                'url'    => request()->fullUrl(),
                                'error'  => $e->getMessage(),
                            ],
                            userId: null,
                        );
                    } catch (\Throwable) {
                        // If audit logging also fails, silently ignore — the page must still render.
                    }

                    return '<!-- widget error: ' . e($tag) . ' -->';
                }
            },
            $html,
        );
    }
}
