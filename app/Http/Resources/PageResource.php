<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'subtitle'     => $this->subtitle,
            'slug'         => $this->slug,
            'path_prefix'  => $this->path_prefix,
            'url'          => '/' . ($this->path_prefix ? $this->path_prefix . '/' : '') . $this->slug,
            'content'      => $this->content,
            'is_published' => $this->is_published,
            'show_in_nav'  => $this->show_in_nav,
            'nav_title'    => $this->nav_title,
            'sort_order'   => $this->sort_order,
            'updated_at'   => $this->updated_at->toIso8601String(),
        ];
    }
}
